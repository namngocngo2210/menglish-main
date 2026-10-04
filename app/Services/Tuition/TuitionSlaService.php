<?php

namespace App\Services\Tuition;

use App\Models\AdminNotification;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Support\Money;
use App\Support\TuitionBranchScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * SLA học phí: (1) tiền mặt thu trong ngày phải nộp về TK công ty trước 19:00 cùng ngày;
 * (2) hoàn tiền / chuyển nhượng xử lý trong 1 tuần và trong tháng (TuitionRefundRequest::deadlineFor).
 * Chỉ nhắc (AdminNotification) — không tự phạt. Idempotent: chạy lại không tạo thông báo trùng.
 */
class TuitionSlaService
{
    public const TYPE_CASH_DEPOSIT = 'cash_deposit_overdue';

    public const TYPE_REFUND_DEADLINE = 'refund_deadline';

    /** Người dùng đang hoạt động có quyền (kể cả Admin, quyền cá nhân) và phạm vi học phí bao chi nhánh. */
    public function holders(string $permission, ?int $branchId): Collection
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->can($permission) && TuitionBranchScope::coversBranch($u, $branchId))
            ->values();
    }

    /** Phiếu tiền mặt thu trong ngày $now mà quá 19:00 vẫn chưa xác nhận nộp → báo người thu + người có quyền xác nhận. */
    public function notifyUndepositedCash(CarbonInterface $now): int
    {
        $sent = 0;

        TuitionReceipt::query()
            ->with(['tuition.student', 'student', 'creator'])
            ->where('payment_method', 'cash')
            ->whereIn('status', TuitionReceipt::REFERENCE_HOLDING_STATUSES)
            ->where('amount', '>', 0)
            ->whereDate('payment_date', $now->toDateString())
            ->whereNull('deposited_at')
            ->orderBy('id')
            ->get()
            ->each(function (TuitionReceipt $receipt) use ($now, &$sent) {
                if (! $receipt->isDepositLate($now)) {
                    return;
                }

                $student = $receipt->tuition?->student ?? $receipt->student;
                $branchId = $student?->branch_id ? (int) $student->branch_id : null;
                $recipients = $this->holders('tuition.confirm_deposit', $branchId)->pluck('id');
                if ($receipt->creator_id) {
                    $recipients->push((int) $receipt->creator_id);
                }

                foreach ($recipients->unique() as $userId) {
                    $exists = AdminNotification::query()
                        ->where('user_id', $userId)
                        ->where('type', self::TYPE_CASH_DEPOSIT)
                        ->where('data->receipt_id', $receipt->id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    AdminNotification::create([
                        'user_id' => $userId,
                        'type' => self::TYPE_CASH_DEPOSIT,
                        'title' => 'Tiền mặt chưa nộp về TK công ty',
                        'message' => 'Phiếu '.$receipt->receipt_number.' ('.Money::format($receipt->amount).', '
                            .($student?->name ?? 'học viên').') thu ngày '.$receipt->payment_date->format('d/m/Y')
                            .' chưa được xác nhận nộp về tài khoản công ty trước '.TuitionReceipt::DEPOSIT_CUTOFF.'.',
                        'data' => [
                            'receipt_id' => $receipt->id,
                            'link' => route('tuition.receipts.approve', ['selected_id' => $receipt->id, 'status' => 'all']),
                        ],
                        'is_read' => false,
                    ]);
                    $sent++;
                }
            });

        return $sent;
    }

    /** Hồ sơ hoàn phí / chuyển nhượng còn chờ duyệt, còn ≤ 1 ngày tới hạn hoặc đã quá hạn → nhắc người có quyền duyệt (mỗi hồ sơ / loại nhắc / ngày một lần). */
    public function notifyRefundDeadlines(CarbonInterface $now): int
    {
        $sent = 0;
        $today = $now->copy()->startOfDay();

        TuitionRefundRequest::query()
            ->with('student')
            ->where('status', 'pending')
            ->whereIn('type', [TuitionRefundRequest::TYPE_REFUND, TuitionRefundRequest::TYPE_TRANSFER])
            ->orderBy('id')
            ->get()
            ->each(function (TuitionRefundRequest $request) use ($now, $today, &$sent) {
                $deadline = $request->processing_deadline;
                if (! $deadline) {
                    return;
                }

                // Còn ≤ 1 ngày tính tới ngày hạn → "sắp hết hạn"; qua cuối ngày hạn → "quá hạn".
                $overdue = $now->gt($deadline);
                if (! $overdue && $deadline->copy()->startOfDay()->diffInDays($today, false) < -1) {
                    return;
                }
                $kind = $overdue ? 'overdue' : 'due_soon';

                $branchId = $request->student?->branch_id ? (int) $request->student->branch_id : null;
                $label = $request->type_label;
                foreach ($this->holders(TuitionRefundRequest::approvePermission($request->type), $branchId) as $user) {
                    $exists = AdminNotification::query()
                        ->where('user_id', $user->id)
                        ->where('type', self::TYPE_REFUND_DEADLINE)
                        ->where('data->request_id', $request->id)
                        ->where('data->kind', $kind)
                        ->where('data->date', $today->toDateString())
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    AdminNotification::create([
                        'user_id' => $user->id,
                        'type' => self::TYPE_REFUND_DEADLINE,
                        'title' => $overdue ? "Hồ sơ {$label} quá hạn xử lý" : "Hồ sơ {$label} sắp hết hạn xử lý",
                        'message' => "{$label} của ".($request->student?->name ?? 'học viên').' phải xử lý trong 1 tuần (cùng tháng), hạn '
                            .$deadline->format('d/m/Y').($overdue ? ' — đã quá hạn.' : ' — còn chưa tới 1 ngày.'),
                        'data' => [
                            'request_id' => $request->id,
                            'kind' => $kind,
                            'date' => $today->toDateString(),
                            'link' => route('tuition.refunds'),
                        ],
                        'is_read' => false,
                    ]);
                    $sent++;
                }
            });

        return $sent;
    }
}
