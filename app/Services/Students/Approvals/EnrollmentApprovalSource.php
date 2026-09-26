<?php

namespace App\Services\Students\Approvals;

use App\Models\ClassEnrollment;
use App\Models\Student;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use App\Support\Approvals\QueryApprovalSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Xác nhận nhập học — lượt xếp lớp chưa hoàn tất bàn giao (màn students.enrollments). Hoàn tất cần tick checklist
 * thật (giáo trình, nhóm Zalo) nên chỉ link sang màn gốc. Học viên chốt từ CRM (có customer_id) đã nằm ở nguồn
 * "Xác nhận chính thức (CRM)" khi user thấy được CRM → không lặp.
 */
class EnrollmentApprovalSource extends QueryApprovalSource
{
    public function key(): string
    {
        return 'enrollment';
    }

    public function label(): string
    {
        return 'Xác nhận nhập học';
    }

    public function group(): string
    {
        return self::GROUP_ACADEMIC;
    }

    public function indexUrl(): string
    {
        return route('students.enrollments');
    }

    public function canView(User $user): bool
    {
        return self::allows($user, 'student.view', 'student.assign_class');
    }

    protected function query(User $user): Builder
    {
        return ClassEnrollment::query()
            ->whereIn('student_id', Student::visibleTo($user)->select('id'))
            ->where('status', 'pending')
            ->when($user->can('lead.view'), fn (Builder $q) => $q->whereNull('customer_id'));
    }

    protected function with(): array
    {
        return ['student:id,name,code', 'classModel:id,name,code'];
    }

    /** @param  ClassEnrollment  $model */
    protected function toItem(Model $model): ApprovalItem
    {
        $check = fn (bool $done, string $label) => ($done ? '✓ ' : '✗ ').$label;

        return new ApprovalItem(
            source: $this->key(),
            id: $model->id,
            title: ($model->student?->name ?? 'Học viên #'.$model->student_id).' → '.($model->classModel?->name ?? 'Lớp #'.$model->class_id),
            subtitle: $check((bool) $model->curriculum_delivered, 'Giáo trình').' · '.$check((bool) $model->zalo_group_added, 'Nhóm Zalo'),
            url: route('students.enrollments'),
            createdAt: $model->created_at,
            meta: array_filter([
                'Học viên' => $model->student ? $model->student->name.' ('.$model->student->code.')' : null,
                'Lớp' => $model->classModel?->name,
                'Ngày xếp lớp' => $model->enrolled_at?->format('d/m/Y'),
                'Đã nhận giáo trình' => $model->curriculum_delivered ? 'Rồi' : 'Chưa',
                'Đã vào nhóm Zalo' => $model->zalo_group_added ? 'Rồi' : 'Chưa',
            ]),
        );
    }

    public function watchedModels(): array
    {
        return [ClassEnrollment::class];
    }
}
