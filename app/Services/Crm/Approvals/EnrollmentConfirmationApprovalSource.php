<?php

namespace App\Services\Crm\Approvals;

use App\Models\ClassEnrollment;
use App\Models\CrmCustomer;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Khách chốt — Xác nhận chính thức" (màn crm.confirmations): ghi danh từ CRM chờ Học vụ / Quản lý xác nhận hồ sơ
 * (gửi tài khoản, vào nhóm Zalo, nhận giáo trình). Cần tick đủ checklist thật → chỉ link sang màn gốc.
 */
class EnrollmentConfirmationApprovalSource extends QueryApprovalSource
{
    public function key(): string
    {
        return 'crm_confirmation';
    }

    public function label(): string
    {
        return 'Xác nhận chính thức (CRM)';
    }

    public function group(): string
    {
        return self::GROUP_ACADEMIC;
    }

    public function indexUrl(): string
    {
        return route('crm.confirmations');
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'lead.view', 'student.assign_class');
    }

    protected function query(User $user): Builder
    {
        return ClassEnrollment::query()
            ->whereIn('customer_id', CrmCustomer::query()->visibleTo($user)->select('id'))
            ->whereIn('status', ['pending', 'completed'])
            ->whereNull('confirmed_at');
    }

    protected function with(): array
    {
        return ['student:id,name,code', 'classModel:id,name,code', 'customer:id,assigned_user_id', 'customer.assignedUser:id,name'];
    }

    /** @param  ClassEnrollment  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        $missing = collect(ClassEnrollment::CONFIRMATION_CHECKLIST)->filter(fn (string $label, string $field) => ! $model->{$field});

        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: ($model->student?->name ?? 'Học viên #'.$model->student_id).' → '.($model->classModel?->name ?? 'Lớp #'.$model->class_id),
            subtitle: $missing->isEmpty() ? 'Đủ hồ sơ, chờ xác nhận' : 'Còn thiếu: '.$missing->implode(', '),
            url: route('crm.confirmations', array_filter(['search' => $model->student?->code])),
            createdAt: $model->created_at,
            meta: array_filter([
                'Học viên' => $model->student ? $model->student->name.' ('.$model->student->code.')' : null,
                'Lớp' => $model->classModel?->name,
                'Tư vấn phụ trách' => $model->customer?->assignedUser?->name,
                'Ngày ghi danh' => $model->enrolled_at?->format('d/m/Y'),
            ] + collect(ClassEnrollment::CONFIRMATION_CHECKLIST)->mapWithKeys(fn (string $label, string $field) => [$label => $model->{$field} ? 'Rồi' : 'Chưa'])->all()),
        );
    }

    public function watchedModels(): array
    {
        return [ClassEnrollment::class];
    }
}
