<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentProfileController extends Controller
{
    public function index(Request $request)
    {
        $query = Student::with(['branch', 'currentClass.course'])->latest();

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

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $students = $query->paginate($request->perPage(15))->withQueryString();
        $branches = Branch::all();
        $classes = ClassModel::all();

        return view('students.index', compact('students', 'branches', 'classes'));
    }

    public function storeStudent(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'current_class_id' => 'nullable|exists:classes,id',
            'target' => 'nullable|string|max:100',
        ]);

        $count = Student::count() + 1;
        $code = 'HV-'.str_pad($count, 5, '0', STR_PAD_LEFT);

        $student = Student::create([
            'code' => $code,
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'current_class_id' => $validated['current_class_id'] ?? null,
            'target' => $validated['target'] ?? 'IELTS 6.5',
            'status' => 'studying',
        ]);

        return redirect()->route('students.index')
            ->with('status', "Đã tạo mới hồ sơ học viên {$student->name} ({$student->code}) thành công!");
    }

    public function enrollments(Request $request)
    {
        $enrollments = ClassEnrollment::with(['student', 'classModel.teacher', 'classModel.branch'])
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();
        $classes = ClassModel::all();
        $students = Student::all();

        return view('students.enrollments', compact('enrollments', 'classes', 'students'));
    }

    public function storeEnrollment(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'class_id' => 'required|exists:classes,id',
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $class = ClassModel::findOrFail($validated['class_id']);
        if ($student->branch_id && $class->branch_id && $student->branch_id !== $class->branch_id) {
            throw ValidationException::withMessages(['class_id' => 'Học viên và lớp phải thuộc cùng chi nhánh.']);
        }
        if (ClassEnrollment::where('student_id', $student->id)->where('class_id', $class->id)->exists()) {
            throw ValidationException::withMessages(['class_id' => 'Học viên đã có trong danh sách bàn giao của lớp này.']);
        }

        ClassEnrollment::create([
            'student_id' => $validated['student_id'],
            'class_id' => $validated['class_id'],
            'enrolled_at' => now(),
            'curriculum_delivered' => false,
            'zalo_group_added' => false,
            'status' => 'pending',
        ]);

        // Cập nhật lớp hiện tại của học sinh
        $student->update(['current_class_id' => $validated['class_id']]);

        return redirect()->route('students.enrollments')
            ->with('status', 'Đã xếp lớp. Học vụ cần hoàn tất checklist giáo trình và nhóm lớp.');
    }

    public function updateEnrollmentHandoff(Request $request, $id)
    {
        $enrollment = ClassEnrollment::findOrFail($id);
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

    public function show($id)
    {
        $student = Student::with(['branch', 'currentClass.course', 'currentClass.teacher', 'currentClass.branch', 'currentClass.timesheets.teacher', 'tuition.receipts'])
            ->where('id', $id)
            ->orWhere('code', $id)
            ->first();

        if (! $student) {
            $student = Student::with(['branch', 'currentClass.course', 'currentClass.teacher', 'currentClass.branch', 'currentClass.timesheets.teacher', 'tuition.receipts'])->first();
        }

        if (! $student) {
            return redirect()->route('students.index')->with('error', 'Chưa có dữ liệu học viên trong hệ thống.');
        }

        $syllabusUnits = SyllabusUnit::orderBy('unit_number')->get();

        return view('students.show', compact('student', 'syllabusUnits'));
    }

    public function updateStudent(Request $request, $id)
    {
        $student = Student::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'target' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
        ]);

        $student->update($validated);

        return redirect()->route('students.show', $student->id)
            ->with('status', "Cập nhật hồ sơ học viên {$student->name} thành công!");
    }

    /**
     * Đổi trạng thái học tập (đang học / bảo lưu / thôi học / hoàn thành)
     * qua action riêng, tách khỏi sửa hồ sơ.
     */
    public function updateStudentStatus(Request $request, $id)
    {
        $student = Student::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $validated = $request->validate([
            'status' => ['required', 'in:studying,deferred,dropped,graduated'],
        ]);

        $oldLabel = $student->status_label;
        $student->update(['status' => $validated['status']]);

        return redirect()->route('students.show', $student->id)
            ->with('status', "Đã chuyển trạng thái học viên {$student->name} từ \"{$oldLabel}\" sang \"{$student->status_label}\".");
    }

    public function destroyStudent($id)
    {
        $student = Student::where('id', $id)->orWhere('code', $id)->firstOrFail();
        $name = $student->name;
        $student->delete();

        return redirect()->route('students.index')
            ->with('status', "Đã xóa hồ sơ học viên {$name}!");
    }

    public function scoped($id)
    {
        $student = Student::with(['branch', 'currentClass', 'tuition'])
            ->where('id', $id)
            ->orWhere('code', $id)
            ->first();

        if (! $student) {
            $student = Student::with(['branch', 'currentClass', 'tuition'])->first();
        }

        if (! $student) {
            return redirect()->route('students.index')->with('error', 'Chưa có dữ liệu học viên trong hệ thống.');
        }

        return view('students.scoped', compact('student'));
    }
}
