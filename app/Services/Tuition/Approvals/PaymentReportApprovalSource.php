<?php

namespace App\Services\Tuition\Approvals;

use App\Models\AcademicRecord;
use App\Models\Student;
use App\Models\User;
use App\Services\Tuition\PaymentReportService;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\ApprovalResult;
use App\Support\Approvals\QueryApprovalSource;
use App\Support\TuitionBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Học viên / phụ huynh báo đã đóng học phí (nút "Báo đóng" ở Trang chủ cổng học viên). Không có màn gốc riêng:
 * người duyệt phiếu thu (tuition.approve, phạm vi chi nhánh học phí) xác nhận / từ chối ngay trong hộp Cần duyệt, rồi lập phiếu thu.
 */
class PaymentReportApprovalSource extends QueryApprovalSource
{
    public function __construct(private readonly PaymentReportService $reports) {}

    public function key(): string
    {
        return 'payment_report';
    }

    public function label(): string
    {
        return 'Học viên báo đã đóng học phí';
    }

    public function group(): string
    {
        return self::GROUP_TUITION;
    }

    public function indexUrl(): string
    {
        return route('approvals.index', ['group' => self::GROUP_TUITION]);
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'tuition.view', 'tuition.approve');
    }

    protected function query(User $user): Builder
    {
        $query = AcademicRecord::query()
            ->where('screen_key', PaymentReportService::SCREEN_KEY)
            ->where('status', PaymentReportService::STATUS_PENDING);

        $branchIds = TuitionBranchScope::branchIds($user);
        if ($branchIds !== null) {
            $studentIds = TuitionBranchScope::students(Student::query(), $branchIds)
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
            $query->whereIn('data->student_id', $studentIds);
        }

        return $query;
    }

    /** @param  AcademicRecord  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        $student = Student::with('tuition:id,student_id')->find(data_get($model->data, 'student_id'));

        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: 'Báo đóng học phí · '.($student?->name ?? data_get($model->data, 'student_name')),
            subtitle: self::limit(data_get($model->data, 'content'), 60),
            // Mở form lập phiếu thu cho khoản học phí của học viên.
            url: route('tuition.receipts.create', array_filter(['tuition_id' => $student?->tuition?->id])),
            createdAt: $model->created_at,
            amount: (float) data_get($model->data, 'amount', 0),
            meta: array_filter([
                'Học viên' => $student ? $student->name.' ('.$student->code.')' : data_get($model->data, 'student_name'),
                'Nội dung' => self::limit(data_get($model->data, 'content'), 300),
                'Gửi lúc' => data_get($model->data, 'submitted_at'),
            ]),
        );
    }

    public function supports(User $user, string $action): bool
    {
        return $this->canView($user);
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return $this->resolve($user, $id, fn (AcademicRecord $report) => $this->reports->confirm($report, $user), 'Đã xác nhận, học viên được báo qua hộp thư.');
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return $this->resolve($user, $id, fn (AcademicRecord $report) => $this->reports->reject($report, $user, $reason), 'Đã từ chối, học viên được báo qua hộp thư.');
    }

    private function resolve(User $user, int $id, callable $action, string $message): ApprovalResult
    {
        if (! $this->canView($user)) {
            return ApprovalResult::failure('Bạn không có quyền thực hiện thao tác này.');
        }

        return DB::transaction(function () use ($user, $id, $action, $message) {
            $report = $this->query($user)->whereKey($id)->lockForUpdate()->first();
            if (! $report) {
                return ApprovalResult::failure('Yêu cầu không còn chờ xác nhận hoặc ngoài phạm vi của bạn.');
            }
            $action($report);

            return ApprovalResult::success($message);
        });
    }

    public function watchedModels(): array
    {
        return [AcademicRecord::class];
    }
}
