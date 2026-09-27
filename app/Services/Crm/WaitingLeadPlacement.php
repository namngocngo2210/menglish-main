<?php

namespace App\Services\Crm;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Services\CrmStageService;
use Illuminate\Validation\ValidationException;

/**
 * Xếp lớp cho học viên đã chốt từ CRM đang "Chờ xếp lớp" — dùng chung cho nút Gán lớp (CRM) và các màn
 * xếp lớp / liên kết lớp của Học viên, để lead, học phí và lượt ghi danh luôn khớp nhau dù xếp từ màn nào.
 */
class WaitingLeadPlacement
{
    public function __construct(private CrmStageService $stages) {}

    /** Lead Chờ xếp lớp của học viên (khóa dòng; gọi trong transaction). */
    public function waitingLeadFor(Student $student): ?CrmCustomer
    {
        return CrmCustomer::query()
            ->where('converted_student_id', $student->id)
            ->where('stage', 'waiting_class')
            ->lockForUpdate()
            ->first();
    }

    /** Lớp phải thuộc khóa học đã chốt (học phí tính theo khóa đó). */
    public function assertMatchesClosedCourse(CrmCustomer $customer, ClassModel $class): void
    {
        if ($customer->waiting_course_id && $class->course_id !== $customer->waiting_course_id) {
            throw ValidationException::withMessages(['class_id' => 'Lớp phải thuộc khóa học đã chốt ('.($customer->waitingCourse?->name ?? 'khóa đã chọn').').']);
        }
    }

    /** Học viên đã có lượt ghi danh còn hiệu lực trong lớp này. */
    public function assertNotInClass(Student $student, ClassModel $class): void
    {
        if (ClassEnrollment::where('student_id', $student->id)->where('class_id', $class->id)
            ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)->exists()) {
            throw ValidationException::withMessages(['class_id' => 'Học viên đã có trong danh sách của lớp này.']);
        }
    }

    /**
     * Sau khi ghi danh: gắn lượt ghi danh với lead (vào hàng "Xác nhận chính thức"), gắn lớp cho học phí chưa có lớp,
     * lead Chờ xếp lớp → Đã chốt.
     */
    public function complete(CrmCustomer $customer, Student $student, ClassModel $class, ClassEnrollment $enrollment, ?User $actor): void
    {
        $enrollment->update(['customer_id' => $customer->id]);
        StudentTuition::where('student_id', $student->id)->whereNull('class_id')->update(['class_id' => $class->id]);
        $customer->update(['waiting_since' => null]);
        $this->stages->advanceTo($customer, 'won', $actor, "Học vụ gán lớp {$class->name} cho học viên {$student->code}.");
    }
}
