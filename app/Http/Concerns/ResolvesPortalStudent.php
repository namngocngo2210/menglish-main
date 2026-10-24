<?php

namespace App\Http\Concerns;

use App\Models\Student;
use App\Support\Portal\PortalNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Học viên đang xem trên cổng học viên (Pages/Portal/*): tài khoản học viên / phụ huynh thấy học viên liên kết với mình,
 * nhân sự có quyền student.view thấy học viên trong phạm vi dữ liệu. Dùng chung cho StudentPortalController và
 * PracticeController.
 */
trait ResolvesPortalStudent
{
    protected function canManageStudents(): bool
    {
        $user = Auth::user();

        return (bool) ($user && $user->can('student.view'));
    }

    /**
     * Helper lấy danh sách học viên và học viên đang chọn
     */
    protected function getActiveStudent($studentId = null)
    {
        $students = Student::with(['currentClass.teacher', 'tuition.receipts'])
            ->when(
                $this->canManageStudents(),
                fn ($query) => $query->visibleTo(Auth::user()),
                fn ($query) => $query->linkedTo(Auth::user()),
            )
            // Học viên vừa chốt (Chờ khai giảng) / nghỉ hè vẫn dùng cổng học viên.
            ->whereIn('status', ['active', 'waiting_start', 'studying', 'summer_break'])
            ->get();

        $student = $studentId ? $students->firstWhere('id', $studentId) : $students->first();
        if ($studentId && ! $student) {
            abort(403, 'Bạn không được phép truy cập hồ sơ học viên này.');
        }
        if (! $student && $students->isNotEmpty()) {
            $student = $students->first();
        }

        abort_if(! $student && ! $this->canManageStudents(), 403, 'Tài khoản chưa được liên kết với hồ sơ học viên.');

        return [$students, $student];
    }

    /**
     * Props chung của các màn cổng học viên (Pages/Portal/*): học viên đang chọn, danh sách để đổi học viên (thanh trên)
     * và số thông báo chưa đọc (thanh điều hướng đáy).
     *
     * @param  array<string, mixed>  $extra  trường học viên riêng của từng màn
     * @return array{student: array<string, mixed>|null, students: list<array<string, mixed>>, unreadCount: int}
     */
    protected function portalProps(Collection $students, ?Student $student, array $extra = []): array
    {
        return [
            'student' => $student ? [
                'id' => $student->id,
                'name' => $student->name,
                'code' => $student->code,
                'class_name' => $student->currentClass?->name,
                ...$extra,
            ] : null,
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'class_name' => $s->currentClass?->name,
            ])->values()->all(),
            'unreadCount' => PortalNotifications::unreadCount($student),
        ];
    }
}
