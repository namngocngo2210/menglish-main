<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\DocumentCodeGenerator;
use App\Services\FirstMonthCareService;
use App\Services\StudentDeferralService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentProfileController extends Controller
{
    /** Loại buổi hiển thị trong lộ trình (buổi phụ đạo chỉ hiện nếu học viên có điểm danh). */
    private const ROADMAP_SESSION_TYPES = [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP];

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Student::visibleTo($user)->with(['branch', 'currentClass.course'])->latest();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($classId = $request->input('class_id')) {
            $query->inClasses((int) $classId);
        }

        $status = $request->input('status');
        if ($status && array_key_exists($status, Student::STATUSES)) {
            $query->where('status', $status);
        }

        $students = $query->paginate($request->perPage(15))->withQueryString();
        $branches = $this->visibleBranches($user);
        $classes = $this->visibleClasses($user)->orderBy('name')->get(['id', 'name', 'code', 'branch_id']);

        return view('students.index', compact('students', 'branches', 'classes'));
    }

    public function storeStudent(Request $request, DocumentCodeGenerator $codes)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'current_class_id' => 'nullable|exists:classes,id',
            'target' => 'nullable|string|max:100',
        ]);

        $branchId = $validated['branch_id'] ?? null;
        if (! $user->hasRole('admin')) {
            $allowed = Student::branchIdsFor($user);
            $branchId ??= $user->branch_id;
            if ($branchId && ! in_array((int) $branchId, $allowed, true)) {
                throw ValidationException::withMessages(['branch_id' => 'Bạn chỉ được tạo học viên cho chi nhánh mình phụ trách.']);
            }
        }

        $class = null;
        if (! empty($validated['current_class_id'])) {
            $class = $this->visibleClasses($user)->find($validated['current_class_id']);
            if (! $class) {
                throw ValidationException::withMessages(['current_class_id' => 'Lớp không thuộc phạm vi bạn quản lý.']);
            }
            if ($branchId && $class->branch_id && (int) $class->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages(['current_class_id' => 'Học viên và lớp phải thuộc cùng chi nhánh.']);
            }
            $branchId ??= $class->branch_id;
        }

        $student = DB::transaction(function () use ($validated, $codes, $branchId, $class) {
            if ($class) {
                $this->assertClassHasSeat($class, 'current_class_id');
            }

            $student = Student::create([
                'code' => $codes->studentCode(),
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'branch_id' => $branchId,
                'current_class_id' => $class?->id,
                'target' => $validated['target'] ?? null,
                // BA chốt Q5: hồ sơ mới luôn bắt đầu ở "Chờ khai giảng".
                'status' => Student::INITIAL_STATUS,
            ]);

            if ($class) {
                ClassEnrollment::create([
                    'student_id' => $student->id,
                    'class_id' => $class->id,
                    'enrolled_at' => now(),
                    'curriculum_delivered' => false,
                    'zalo_group_added' => false,
                    'status' => 'pending',
                ]);
            }

            return $student;
        });

        return redirect()->route('students.index')
            ->with('status', "Đã tạo mới hồ sơ học viên {$student->name} ({$student->code}) thành công!");
    }

    public function enrollments(Request $request)
    {
        $user = $request->user();
        $visibleStudentIds = Student::visibleTo($user)->select('id');

        $enrollments = ClassEnrollment::with(['student', 'classModel.teacher', 'classModel.branch'])
            ->whereIn('student_id', $visibleStudentIds)
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();
        $classes = $this->visibleClasses($user)->orderBy('name')->get();
        $students = Student::visibleTo($user)->where('status', '!=', Student::STATUS_DROPPED)->orderBy('name')->get();

        return view('students.enrollments', compact('enrollments', 'classes', 'students'));
    }

    public function storeEnrollment(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'class_id' => 'required|exists:classes,id',
        ]);

        $student = Student::visibleTo($request->user())->findOrFail($validated['student_id']);
        $class = $this->visibleClasses($request->user())->findOrFail($validated['class_id']);
        $this->assertCanJoinClass($student, $class);

        DB::transaction(function () use ($student, $class) {
            $this->assertClassHasSeat($class);

            ClassEnrollment::create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'enrolled_at' => now(),
                'curriculum_delivered' => false,
                'zalo_group_added' => false,
                'status' => 'pending',
            ]);

            // Cập nhật lớp hiện tại của học sinh
            $student->update(['current_class_id' => $class->id]);
        });

        return redirect()->route('students.enrollments')
            ->with('status', 'Đã xếp lớp. Học vụ cần hoàn tất checklist giáo trình và nhóm lớp.');
    }

    /**
     * "Liên kết lớp khác": thêm học viên vào một lớp nữa (học song song) mà không đổi lớp chính.
     * Kiểm tra cùng chi nhánh, chưa có trong lớp và lớp còn chỗ.
     */
    public function linkClass(Request $request, $id)
    {
        $student = $this->findVisibleStudent($request->user(), $id);
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
        ]);

        $class = $this->visibleClasses($request->user())->find($validated['class_id']);
        if (! $class) {
            throw ValidationException::withMessages(['class_id' => 'Lớp không thuộc phạm vi bạn quản lý.']);
        }
        $this->assertCanJoinClass($student, $class);

        DB::transaction(function () use ($student, $class) {
            $this->assertClassHasSeat($class);

            ClassEnrollment::create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'enrolled_at' => now(),
                'curriculum_delivered' => false,
                'zalo_group_added' => false,
                'status' => 'pending',
            ]);

            // Học viên chưa có lớp chính (vd. đang "Chờ xếp lớp") => lớp vừa liên kết thành lớp chính.
            if (! $student->current_class_id) {
                $student->update(['current_class_id' => $class->id]);
            }
        });

        return redirect()->route('students.show', $student->id)
            ->with('status', "Đã liên kết học viên {$student->name} với lớp {$class->name}.");
    }

    public function updateEnrollmentHandoff(Request $request, $id)
    {
        $visibleStudentIds = Student::visibleTo($request->user())->select('id');
        $enrollment = ClassEnrollment::whereIn('student_id', $visibleStudentIds)->findOrFail($id);
        if ($enrollment->status === Student::ENROLLMENT_DROPPED) {
            return redirect()->route('students.enrollments')
                ->with('error', 'Học viên đã thôi học, không cập nhật bàn giao cho lượt xếp lớp này.');
        }

        $curriculumDelivered = $request->boolean('curriculum_delivered');
        $zaloGroupAdded = $request->boolean('zalo_group_added');

        $enrollment->update([
            'curriculum_delivered' => $curriculumDelivered,
            'zalo_group_added' => $zaloGroupAdded,
            'status' => $curriculumDelivered && $zaloGroupAdded ? 'completed' : 'pending',
        ]);

        return redirect()->route('students.enrollments')
            ->with('status', $enrollment->status === 'completed'
                ? 'Đã hoàn tất bàn giao học viên vào lớp.'
                : 'Đã cập nhật checklist; bàn giao vẫn đang chờ hoàn tất.');
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $student = $this->findVisibleStudent($user, $id);
        $student->load(['branch', 'currentClass.course', 'currentClass.teacher', 'currentClass.branch', 'tuition.receipts', 'enrollments.classModel']);

        $classIds = $student->activeClassIds();
        $classes = ClassModel::with(['teacher', 'branch'])->whereIn('id', $classIds)->get();

        // Điểm danh thật của học viên (mới nhất trước).
        $attendances = StudentAttendance::with(['classModel', 'classSession'])
            ->where('student_id', $student->id)
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->get();
        $attendanceBySession = $attendances->whereNotNull('class_session_id')->keyBy('class_session_id');

        // Lộ trình = các buổi học thật của (các) lớp học viên đang theo, cùng buổi học viên có điểm danh.
        $sessions = ClassSession::with(['classModel', 'teacher'])
            ->where(function (Builder $q) use ($classIds, $attendanceBySession) {
                $q->where(fn (Builder $q) => $q->whereIn('class_id', $classIds)->whereIn('type', self::ROADMAP_SESSION_TYPES))
                    ->orWhereIn('id', $attendanceBySession->keys()->all());
            })
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $attendanceStats = [
            'recorded' => $attendances->count(),
            'present' => $attendances->whereIn('status', ['present', 'late'])->count(),
            'absent' => $attendances->whereIn('status', ['absent', 'excused'])->count(),
            'scheduled' => $sessions->count(),
        ];
        $attendanceStats['rate'] = $attendanceStats['recorded'] > 0
            ? (int) round($attendanceStats['present'] / $attendanceStats['recorded'] * 100)
            : null;

        $linkableClasses = collect();
        if ($user->can('student.assign_class') && $student->status !== Student::STATUS_DROPPED) {
            $linkableClasses = $this->visibleClasses($user)
                ->whereNotIn('id', $classIds)
                ->when($student->branch_id, fn (Builder $q) => $q->where('branch_id', $student->branch_id))
                ->whereNotIn('status', ['completed', 'cancelled', 'closed'])
                ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)])
                ->orderBy('name')
                ->get();
        }

        $care = app(FirstMonthCareService::class)->checklist($student);

        return view('students.show', compact('student', 'classes', 'sessions', 'attendances', 'attendanceBySession', 'attendanceStats', 'linkableClasses', 'care'));
    }

    public function updateStudent(Request $request, $id)
    {
        $student = $this->findVisibleStudent($request->user(), $id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'target' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(array_keys(Student::STATUSES))],
            'notes' => 'nullable|string|max:500',
        ]);

        // Form sửa hồ sơ không gửi trạng thái; đổi trạng thái cần quyền riêng (student.change_status).
        if (! $request->user()->can('student.change_status') || empty($validated['status'])) {
            unset($validated['status']);
        }

        $student->update($validated);

        return redirect()->route('students.show', $student->id)
            ->with('status', "Cập nhật hồ sơ học viên {$student->name} thành công!");
    }

    /**
     * Đổi trạng thái học tập (6 trạng thái BA chốt) qua action riêng, tách khỏi sửa hồ sơ.
     * Chuyển sang Thôi học sẽ bỏ học viên khỏi lớp đang học (xem Student::booted).
     */
    public function updateStudentStatus(Request $request, $id, StudentDeferralService $deferrals)
    {
        $student = $this->findVisibleStudent($request->user(), $id);
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Student::STATUSES))],
        ]);

        $oldLabel = $student->status_label;
        $wasDeferred = $student->status === 'deferred';
        $student->update(['status' => $validated['status']]);

        // Rời "Bảo lưu" bằng tay: bỏ đóng băng học phí để nhắc nợ / số buổi chạy lại như thường.
        if ($wasDeferred && $student->status !== 'deferred' && $student->tuition) {
            $deferrals->releaseTuition($student->tuition, null, $request->user()?->name ?? 'hệ thống');
        }

        $message = "Đã chuyển trạng thái học viên {$student->name} từ \"{$oldLabel}\" sang \"{$student->status_label}\".";
        if ($student->wasChanged('status') && $student->status === Student::STATUS_DROPPED) {
            $message .= ' Học viên đã được đưa ra khỏi danh sách lớp đang học; lịch sử học tập vẫn được giữ.';
        }

        return redirect()->route('students.show', $student->id)->with('status', $message);
    }

    /** "Kết thúc bảo lưu" thủ công (kết thúc sớm hoặc học viên bảo lưu không có hạn). */
    public function endDeferral(Request $request, $id, StudentDeferralService $deferrals)
    {
        $student = $this->findVisibleStudent($request->user(), $id);
        $newStatus = $deferrals->end($student, $request->user());

        if ($newStatus === null) {
            return redirect()->route('students.show', $student->id)
                ->withErrors(['status' => 'Học viên không ở trạng thái Bảo lưu.']);
        }

        return redirect()->route('students.show', $student->id)
            ->with('status', "Đã kết thúc bảo lưu cho học viên {$student->name}: chuyển sang \"".Student::STATUSES[$newStatus].'".');
    }

    public function destroyStudent(Request $request, $id)
    {
        $student = $this->findVisibleStudent($request->user(), $id);
        $name = $student->name;
        $student->delete();

        return redirect()->route('students.index')
            ->with('status', "Đã xóa hồ sơ học viên {$name}!");
    }

    /**
     * Hồ sơ học viên theo phân quyền: server chỉ render các phần người xem có quyền
     * (học phí chỉ khi có tuition.view), không còn chế độ đổi vai trò phía trình duyệt.
     */
    public function scoped(Request $request, $id)
    {
        $user = $request->user();
        $student = $this->findVisibleStudent($user, $id);
        $student->load(['branch', 'currentClass']);

        $canViewTuition = $user->can('tuition.view');
        $canViewAcademic = $user->can('attendance_student.view') || $user->can('student.update');
        $canViewContact = $user->can('student.update') || $canViewTuition;

        if ($canViewTuition) {
            $student->load('tuition');
        }

        $attendanceStats = null;
        if ($canViewAcademic) {
            $records = StudentAttendance::where('student_id', $student->id)->pluck('status');
            $present = $records->filter(fn ($s) => in_array($s, ['present', 'late'], true))->count();
            $attendanceStats = [
                'recorded' => $records->count(),
                'present' => $present,
                'rate' => $records->count() > 0 ? (int) round($present / $records->count() * 100) : null,
            ];
        }

        return view('students.scoped', compact('student', 'canViewTuition', 'canViewAcademic', 'canViewContact', 'attendanceStats'));
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /** Tìm học viên theo id hoặc mã trong phạm vi người dùng được xem; không có => 404. */
    private function findVisibleStudent(?User $user, $id): Student
    {
        return Student::visibleTo($user)
            ->where(fn (Builder $q) => $q->where('id', $id)->orWhere('code', $id))
            ->firstOrFail();
    }

    private function visibleBranches(User $user)
    {
        return $user->hasRole('admin')
            ? Branch::orderBy('name')->get()
            : Branch::whereIn('id', Student::branchIdsFor($user))->orderBy('name')->get();
    }

    /** Lớp người dùng được thao tác: admin tất cả; vai trò chi nhánh theo chi nhánh; GV/TA lớp mình dạy. */
    private function visibleClasses(User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return ClassModel::query();
        }

        if ($user->hasAnyRole(Student::BRANCH_SCOPED_ROLES)) {
            return ClassModel::query()->whereIn('branch_id', Student::branchIdsFor($user));
        }

        return ClassModel::query()->visibleTo($user);
    }

    private function assertCanJoinClass(Student $student, ClassModel $class): void
    {
        if ($student->status === Student::STATUS_DROPPED) {
            throw ValidationException::withMessages(['class_id' => 'Học viên đã thôi học, không thể xếp vào lớp.']);
        }
        if ($student->branch_id && $class->branch_id && (int) $student->branch_id !== (int) $class->branch_id) {
            throw ValidationException::withMessages(['class_id' => 'Học viên và lớp phải thuộc cùng chi nhánh.']);
        }
        if (ClassEnrollment::where('student_id', $student->id)->where('class_id', $class->id)
            ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)->exists()) {
            throw ValidationException::withMessages(['class_id' => 'Học viên đã có trong danh sách của lớp này.']);
        }
    }

    /** Kiểm tra sĩ số (max_capacity = 0 nghĩa là không giới hạn). Gọi trong transaction, khóa dòng lớp. */
    private function assertClassHasSeat(ClassModel $class, string $field = 'class_id'): void
    {
        $locked = ClassModel::whereKey($class->id)->lockForUpdate()->first();
        if (! $locked || $locked->max_capacity <= 0) {
            return;
        }

        $taken = ClassEnrollment::where('class_id', $class->id)
            ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)
            ->count();

        if ($taken >= $locked->max_capacity) {
            throw ValidationException::withMessages([$field => "Lớp {$class->name} đã đủ sĩ số ({$locked->max_capacity} học viên)."]);
        }
    }
}
