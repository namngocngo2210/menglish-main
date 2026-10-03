<?php

namespace App\Services\StaffAttendance\Approvals;

use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\User;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * "Đơn chấm công": bổ sung công, xin đi muộn / về sớm, xin nghỉ do nhân sự gửi từ điện thoại.
 * Người có staff_checkin.approve duyệt đơn của nhân sự trong phạm vi chi nhánh (không tự duyệt đơn của mình),
 * duyệt / từ chối ngay trong hộp Việc cần duyệt.
 */
class StaffAttendanceRequestApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly StaffAttendanceService $attendance) {}

    public function key(): string
    {
        return 'staff_attendance_request';
    }

    public function label(): string
    {
        return 'Đơn chấm công & nghỉ';
    }

    public function group(): string
    {
        return self::GROUP_HR;
    }

    public function indexUrl(): string
    {
        return route('approvals.index', ['group' => ApprovalInboxService::groupSlug($this->group())]);
    }

    public function canView(User $user): bool
    {
        return $user->can('staff_checkin.approve');
    }

    protected function query(User $user): Builder
    {
        return $this->attendance->reviewQuery($user);
    }

    protected function with(): array
    {
        return ['user:id,name,employee_code', 'branch:id,name'];
    }

    /** @param  StaffAttendanceRequest  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: ($model->user?->name ?? 'Nhân sự #'.$model->user_id).': '.$model->typeLabel().' '.$model->periodLabel(),
            subtitle: self::limit($model->reason),
            url: route('staff-attendance.index', ['date' => $model->date_from->toDateString(), 'search' => $model->user?->employee_code ?: $model->user?->name]),
            createdAt: $model->created_at,
            meta: array_filter([
                'Nhân sự' => $model->user?->name.($model->user?->employee_code ? ' ('.$model->user->employee_code.')' : ''),
                'Cơ sở' => $model->branch?->name,
                'Loại đơn' => $model->typeLabel(),
                'Ngày' => $model->periodLabel(),
                'Chấm công ngày này' => $this->currentPunch($model),
                'Lý do' => $model->reason,
            ]),
        );
    }

    /** Giờ đã chấm của ngày trong đơn (đơn 1 ngày), để người duyệt đối chiếu. */
    private function currentPunch(StaffAttendanceRequest $request): ?string
    {
        if ($request->type === StaffAttendanceRequest::TYPE_LEAVE) {
            return null;
        }
        $attendance = StaffAttendance::forDay($request->user_id, $request->date_from);
        if (! $attendance?->check_in_at && ! $attendance?->check_out_at) {
            return 'Chưa chấm';
        }

        return 'Vào '.($attendance->check_in_at?->format('H:i') ?? '—').', ra '.($attendance->check_out_at?->format('H:i') ?? '—').' · '.$attendance->statusLabel();
    }

    public function supports(User $user, string $action): bool
    {
        return $this->canView($user);
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        $request = StaffAttendanceRequest::find($id);
        if (! $request) {
            return ApprovalResult::failure('Đơn không còn chờ duyệt.');
        }
        try {
            $this->attendance->approve($request, $user);
        } catch (ValidationException $e) {
            return ApprovalResult::failure(collect($e->errors())->flatten()->first() ?? 'Không duyệt được.');
        }

        return ApprovalResult::success('Đã duyệt đơn '.$request->typeLabel().' của '.$request->user?->name.'.');
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        $request = StaffAttendanceRequest::find($id);
        if (! $request) {
            return ApprovalResult::failure('Đơn không còn chờ duyệt.');
        }
        try {
            $this->attendance->reject($request, $user, $reason);
        } catch (ValidationException $e) {
            return ApprovalResult::failure(collect($e->errors())->flatten()->first() ?? 'Không từ chối được.');
        }

        return ApprovalResult::success('Đã từ chối đơn '.$request->typeLabel().' của '.$request->user?->name.'.');
    }

    public function watchedModels(): array
    {
        return [StaffAttendanceRequest::class];
    }
}
