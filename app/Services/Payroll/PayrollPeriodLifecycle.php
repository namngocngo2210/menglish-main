<?php

namespace App\Services\Payroll;

use App\Models\AdminNotification;
use App\Models\BackgroundTask;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\DataScopeGuard;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Chuyển trạng thái, tất toán và ghi lịch sử nguyên tử theo từng kỳ lương. */
class PayrollPeriodLifecycle
{
    public function transition(PayrollPeriod $period, string $toStatus): PayrollPeriod
    {
        return DataScopeGuard::bypass(fn () => DB::transaction(function () use ($period, $toStatus) {
            $locked = PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->canTransitionTo($toStatus), 422, 'Không thể chuyển trạng thái kỳ lương từ trạng thái hiện tại.');
            if ($toStatus === PayrollPeriod::STATUS_CLOSED) {
                // Keep source rows stable through snapshot/KPI/attendance checks and the final payroll lock.
                $this->periodTimesheets($locked)->withoutGlobalScope('academic_active')
                    ->orderBy('id')->lockForUpdate()->get(['id']);
            }
            if ($reason = $this->blockReason($locked, $toStatus, false)) {
                throw ValidationException::withMessages(['period' => $reason]);
            }

            if ($toStatus === PayrollPeriod::STATUS_CLOSED && ($reason = $this->attendanceBlockReason($locked))) {
                abort(400, $reason);
            }

            $fromStatus = $locked->status;
            $locked->update(['status' => $toStatus]);
            $recordStatus = match ($toStatus) {
                PayrollPeriod::STATUS_DRAFT => 'pending',
                PayrollPeriod::STATUS_PAID => 'paid',
                default => 'confirmed',
            };
            $locked->records()->update(['status' => $recordStatus]);
            if ($toStatus === PayrollPeriod::STATUS_CLOSED) {
                // Query Builder avoids updated_at changes that would invalidate the payroll calculation snapshot.
                $this->periodTimesheets($locked)->whereRaw("COALESCE(reconcile_status, 'da_chot') = ?", [TeacherTimesheet::RECONCILE_CLOSED])
                    ->toBase()->update(['payroll_locked' => true]);
            } elseif ($toStatus === PayrollPeriod::STATUS_DRAFT) {
                $this->periodTimesheets($locked)->toBase()->update(['payroll_locked' => false]);
            }
            if ($toStatus === PayrollPeriod::STATUS_PUBLISHED) {
                $this->settleAndNotify($locked);
            }
            $locked->transitions()->create([
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);

            return $locked;
        }));
    }

    /** Dùng chung điều kiện cho nút thao tác và kiểm tra trong khóa dòng. */
    public function blockReason(PayrollPeriod $period, string $toStatus, bool $includeAttendanceGate = true): ?string
    {
        if ($toStatus === PayrollPeriod::STATUS_CLOSED) {
            if (BackgroundTask::pendingExists(BackgroundTask::PAYROLL_CALCULATE, fn ($q) => $q->where('payload->period_id', $period->id))) {
                return 'Kỳ lương đang được tính — đợi lần tính hiện tại xong rồi chốt bảng lương.';
            }
            if (! $period->canApproveAt()) {
                return 'Chưa thể chốt bảng lương: chốt công và chốt lỗi vào hết ngày '.$period->attendanceCloseAt()->format('d/m')
                    .' (cuối tháng + '.PayrollPeriod::closeAfterDays().' ngày) — vui lòng chốt từ ngày '.$period->attendanceCloseAt()->addDay()->format('d/m').'.';
            }
            if ($period->calculated_at === null || $period->hasChangesSinceCalculation()) {
                return 'Chấm công hoặc biên bản phạt của kỳ đã thay đổi sau lần tính gần nhất — vui lòng bấm "Đồng bộ & Tính lại" trước khi duyệt.';
            }
            $records = $period->records()->with('user')->get();
            $negativeNet = $records->filter(fn (PayrollRecord $record) => $record->netShortfall() > 0)
                ->sortBy(fn (PayrollRecord $record) => $record->user?->name);
            if ($negativeNet->isNotEmpty()) {
                return 'Chưa thể chốt bảng lương: '.$negativeNet->count().' nhân sự có thực lĩnh âm (khấu trừ lớn hơn thu nhập) — '
                    .$negativeNet->map(fn (PayrollRecord $record) => ($record->user?->name ?? 'Chưa cập nhật').' – thiếu '.Money::format($record->netShortfall()))->implode('; ')
                    .'. Kế toán có quyền điều chỉnh lương: Chuyển phạt sang kỳ sau (nút cạnh biên bản trên bảng lương / phiếu lương / màn Biên bản), '
                    .'hoặc thêm khoản cộng / giảm khoản trừ nhập tay trên phiếu (không áp dụng khoản cộng cho Trợ giảng). Đồng bộ & Tính lại rồi chốt lại.';
            }
            $pending = $records->filter(fn (PayrollRecord $record) => $record->kpi_state[0] === 'pending');
            if ($pending->isNotEmpty()) {
                return 'Chưa thể chốt bảng lương: còn '.$pending->count().' nhân sự chưa chốt KPI ('
                    .$pending->take(5)->map(fn ($r) => $r->user?->name)->filter()->implode(', ')
                    .($pending->count() > 5 ? '…' : '').'). Chốt KPI rồi bấm "Đồng bộ & Tính lại".';
            }
            if ($includeAttendanceGate && ($reason = $this->attendanceBlockReason($period))) {
                return $reason;
            }
        }
        if ($toStatus === PayrollPeriod::STATUS_PUBLISHED && $period->hasChangesSinceCalculation()) {
            return 'Dữ liệu đã thay đổi sau khi chốt — vui lòng bấm Mở lại để tính lại, Đồng bộ & Tính lại, rồi chốt lại trước khi Gửi GV xem.';
        }

        return null;
    }

    private function periodTimesheets(PayrollPeriod $period): Builder
    {
        return TeacherTimesheet::whereBetween('teaching_date', [$period->start_date, $period->end_date])
            ->when($period->isAllowListed(), fn (Builder $query) => $query->whereIn('user_id', $period->staff_allowlist));
    }

    private function attendanceBlockReason(PayrollPeriod $period): ?string
    {
        $query = $this->periodTimesheets($period)->where('status', '<>', 'invalid')->notClosed();
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }
        $rows = $query->with(['teacher', 'classModel'])->orderBy('teaching_date')->orderBy('id')->limit(10)->get();

        return 'Chưa thể chốt bảng lương: còn '.$count.' dòng công chưa chốt — '
            .$rows->map(fn (TeacherTimesheet $row) => ($row->teacher?->name ?? 'Chưa cập nhật').' – '
                .$row->teaching_date->format('d/m').' – '.($row->classModel?->name ?? 'Chưa cập nhật'))->implode('; ')
            .($count > 10 ? '; và '.($count - 10).' dòng khác' : '').'.';
    }

    public function actions(PayrollPeriod $period, User $user): array
    {
        $definitions = [
            PayrollPeriod::STATUS_CLOSED => ['approve', 'Chốt bảng lương', 'payroll.approve'],
            PayrollPeriod::STATUS_DRAFT => ['reopen', 'Mở lại để tính lại', 'payroll.approve'],
            PayrollPeriod::STATUS_PUBLISHED => ['publish', 'Gửi GV xem', 'payroll.approve'],
            PayrollPeriod::STATUS_PAID => ['mark-paid', 'Đánh dấu đã trả', 'payroll.mark_paid'],
        ];
        $actions = [];
        foreach (PayrollPeriod::TRANSITIONS[$period->status] ?? [] as $toStatus) {
            [$key, $label, $permission] = $definitions[$toStatus];
            if (! $user->can($permission)) {
                continue;
            }
            $reason = DataScopeGuard::bypass(fn () => $this->blockReason($period, $toStatus));
            $actions[] = ['key' => $key, 'label' => $label, 'route' => route('payroll.periods.'.$key, $period->id), 'enabled' => $reason === null, 'reason' => $reason];
        }

        return $actions;
    }

    private function settleAndNotify(PayrollPeriod $period): void
    {
        // Chỉ đóng dấu "deducted" các biên bản thực sự đã trừ vào bản ghi lương của kỳ này
        Penalty::withoutViewerScope()->whereIn('payroll_record_id', $period->records()->select('id'))
            ->whereIn('status', Penalty::payableStatuses())
            ->update(['status' => 'deducted']);

        // Thu hồi hoa hồng đã trừ trong kỳ → tất toán, không trừ lại ở kỳ sau
        CommissionAdjustment::whereIn('payroll_record_id', $period->records()->select('id'))
            ->whereNull('settled_at')
            ->update(['settled_at' => now()]);

        // Khoản hoa hồng đạt gate kép được trả trong kỳ → đã trả (khoản còn hoãn chờ kỳ sau)
        CommissionItem::whereIn('payroll_record_id', $period->records()->select('id'))
            ->whereNull('settled_at')
            ->update(['settled_at' => now(), 'status' => CommissionItem::STATUS_PAID]);

        // Gửi phiếu lương: nhân sự được xem và dữ liệu nguồn của kỳ bị khóa.
        $now = now();
        $notifications = $period->records()->whereHas('user')->get(['user_id', 'net_salary'])
            ->map(fn (PayrollRecord $record) => [
                'user_id' => $record->user_id,
                'type' => 'payroll_published',
                'title' => "Phiếu lương {$period->title} đã được gửi",
                'message' => 'Thực nhận '.Money::format((float) $record->net_salary).' — xem chi tiết tại "Lương của tôi".',
                'data' => json_encode(['payroll_period_id' => $period->id, 'link' => route('portal.my-salary', ['period_id' => $period->id])], JSON_UNESCAPED_UNICODE),
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
        AdminNotification::insert($notifications);
    }
}
