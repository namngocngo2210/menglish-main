<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\MiniTestScore;
use App\Models\StudentAttendance;
use App\Models\TeacherTimesheet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Cổng Giáo viên: lịch dạy hôm nay, check-in nhiều ca cùng lúc, và điểm danh
 * lớp học. Chỉ dành cho giáo viên / trợ giảng (và admin để xem).
 */
class TeacherPortalController extends Controller
{
    /** Các vai trò được truy cập cổng giáo viên. */
    private const TEACHER_ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant', 'academic_staff', 'academic_lead'];

    private function guardTeacher(): void
    {
        $user = Auth::user();
        abort_unless(
            $user && ($user->hasAnyRole(self::TEACHER_ROLES) || $user->hasRole('admin')),
            403,
            'Chỉ giáo viên / trợ giảng mới truy cập được cổng này.'
        );
    }

    /** Các lớp giáo viên hiện tại phụ trách (chính / trợ giảng / GVNN). */
    private function myClasses(int $teacherId)
    {
        return ClassModel::with(['branch', 'course', 'students'])
            ->where('status', 'active')
            ->where(function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId)
                    ->orWhere('assistant_id', $teacherId)
                    ->orWhere('foreign_teacher_id', $teacherId);
            })
            ->orderBy('name')
            ->get();
    }

    private function authorizeClass(ClassModel $class): void
    {
        $user = Auth::user();
        abort_unless($user && (
            $user->hasRole('admin')
            || $user->hasAnyRole(['academic_staff', 'academic_lead', 'manager'])
            || in_array($user->id, [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id], true)
        ), 403, 'Bạn không được phân công phụ trách lớp này.');
    }

    /**
     * Màn hình "Check-in của tôi" — lịch dạy hôm nay + trạng thái check-in.
     */
    public function home(Request $request)
    {
        $this->guardTeacher();
        $teacher = Auth::user();
        $today = now()->toDateString();

        $classes = $this->myClasses($teacher->id);

        $checkins = TeacherTimesheet::where('user_id', $teacher->id)
            ->whereDate('teaching_date', $today)
            ->get()
            ->keyBy('class_id');

        $attendanceDone = StudentAttendance::whereDate('session_date', $today)
            ->whereIn('class_id', $classes->pluck('id'))
            ->select('class_id')
            ->distinct()
            ->pluck('class_id')
            ->flip();

        $shifts = $classes->map(function ($class) use ($checkins, $attendanceDone) {
            $ts = $checkins->get($class->id);

            return [
                'class' => $class,
                'scheduled_time' => $class->schedule_text,
                'checked_in' => $ts && ! empty($ts->checkin_time),
                'checkin_time' => $ts?->checkin_time,
                'attendance_done' => $attendanceDone->has($class->id),
                'student_count' => $class->students->count(),
            ];
        });

        $stats = [
            'total' => $shifts->count(),
            'checked_in' => $shifts->where('checked_in', true)->count(),
            'attendance_done' => $shifts->where('attendance_done', true)->count(),
        ];

        return view('teacher.home', compact('teacher', 'shifts', 'stats', 'today'));
    }

    /**
     * Check-in NHIỀU ca cùng lúc (tick chọn các lớp rồi bấm check-in).
     */
    public function checkin(Request $request)
    {
        $this->guardTeacher();
        $teacher = Auth::user();

        $validated = $request->validate([
            'class_ids' => 'required|array|min:1',
            'class_ids.*' => 'exists:classes,id',
        ], [
            'class_ids.required' => 'Vui lòng chọn ít nhất 1 ca dạy để check-in.',
        ]);

        $now = now();
        if (\App\Models\PayrollPeriod::isLockedFor($now)) {
            $message = \App\Models\PayrollPeriod::lockedMessage($now);

            return back()->withErrors(['class_ids' => $message])->with('error', $message);
        }

        $count = 0;
        foreach ($validated['class_ids'] as $classId) {
            $class = ClassModel::findOrFail($classId);
            $this->authorizeClass($class);
            $session = ClassSession::where('class_id', $classId)
                ->whereDate('date', $now->toDateString())
                ->where(function ($query) use ($teacher) {
                    $query->where('teacher_id', $teacher->id)->orWhere('assistant_id', $teacher->id);
                })->first();
            $identity = $session
                ? ['user_id' => $teacher->id, 'class_session_id' => $session->id]
                : [
                    'user_id' => $teacher->id,
                    'class_id' => $classId,
                    'teaching_date' => $now->toDateString(),
                ];
            $hours = $session
                ? max(0.5, Carbon::parse($session->start_time)->diffInMinutes(Carbon::parse($session->end_time)) / 60)
                : 2;
            TeacherTimesheet::updateOrCreate(
                $identity,
                [
                    'class_id' => $classId,
                    'class_session_id' => $session?->id,
                    'teaching_date' => $now->toDateString(),
                    'scheduled_time' => $session ? $session->start_time->format('H:i').'-'.$session->end_time->format('H:i') : $class->schedule_text,
                    'checkin_time' => $now->format('H:i'),
                    'hours' => $hours,
                    'type' => 'regular',
                    'status' => 'pending_review',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ]
            );
            $count++;
        }

        return back()->with('success', "Đã check-in thành công {$count} ca dạy hôm nay!");
    }

    /**
     * Màn hình điểm danh học sinh của 1 lớp cho buổi hôm nay.
     */
    public function attendance(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::with(['students' => fn ($q) => $q->orderBy('name')])->findOrFail($classId);
        $this->authorizeClass($class);
        $today = now()->toDateString();
        $session = ClassSession::where('class_id', $classId)->whereDate('date', $today)->first();

        $existing = StudentAttendance::where('class_id', $classId)
            ->whereDate('session_date', $today)
            ->get()
            ->keyBy('student_id');

        return view('teacher.attendance', compact('class', 'existing', 'today'));
    }

    /**
     * Lưu điểm danh cho buổi hôm nay.
     */
    public function attendanceStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $teacher = Auth::user();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);

        $validated = $request->validate([
            'status' => 'required|array',
            'status.*' => 'in:present,absent,late,excused',
            'note' => 'nullable|array',
        ]);

        $today = now()->toDateString();
        $session = ClassSession::where('class_id', $classId)->whereDate('date', $today)
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)->where('status', '!=', 'cancelled')
            ->orderBy('start_time')->first();
        $count = 0;
        foreach ($validated['status'] as $studentId => $status) {
            abort_unless($class->students()->whereKey($studentId)->exists(), 422, 'Học viên không thuộc lớp này.');
            StudentAttendance::updateOrCreate(
                [
                    'class_id' => $classId,
                    'student_id' => $studentId,
                    'session_date' => $today,
                ],
                [
                    'user_id' => $teacher->id,
                    'class_session_id' => $session?->id,
                    'status' => $status,
                    'review_status' => 'pending_review',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                    'note' => $validated['note'][$studentId] ?? null,
                ]
            );
            $count++;
        }

        // Đã điểm danh nghĩa là buổi đã diễn ra: chốt buổi để không bị xếp lại/ghi đè về sau.
        $session?->update(['status' => 'completed']);

        return redirect()->route('teacher.home')
            ->with('success', "Đã lưu điểm danh {$count} học sinh lớp {$class->name}!");
    }

    // ───────────────────────── GIAO BÀI TẬP VỀ NHÀ ─────────────────────────
    public function homework(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $homeworks = Homework::where('class_id', $classId)->latest('due_date')->latest('id')->get();

        return view('teacher.homework', compact('class', 'homeworks'));
    }

    public function homeworkStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        Homework::create([
            'class_id' => $classId,
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        return back()->with('success', 'Đã giao bài tập về nhà cho lớp!');
    }

    public function homeworkDestroy(Request $request, int $classId, int $homeworkId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        Homework::where('class_id', $classId)->where('id', $homeworkId)->firstOrFail()->delete();

        return back()->with('success', 'Đã xoá bài tập!');
    }

    // ───────────────────────── NHẬP ĐIỂM MINI TEST ─────────────────────────
    public function scores(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::with(['students' => fn ($q) => $q->orderBy('name')])->findOrFail($classId);
        $this->authorizeClass($class);
        $testDate = $request->input('test_date', now()->toDateString());
        $testName = $request->input('name', 'Mini Test');

        $existing = MiniTestScore::where('class_id', $classId)
            ->where('name', $testName)
            ->whereDate('test_date', $testDate)
            ->get()
            ->keyBy('student_id');

        return view('teacher.scores', compact('class', 'existing', 'testDate', 'testName'));
    }

    public function scoresStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'test_date' => 'required|date',
            'max_score' => 'required|numeric|min:1',
            'score' => 'required|array',
            'score.*' => 'nullable|numeric|min:0',
            'note' => 'nullable|array',
        ]);

        $count = 0;
        foreach ($validated['score'] as $studentId => $score) {
            if ($score === null || $score === '') {
                continue;
            }
            abort_unless($class->students()->whereKey($studentId)->exists(), 422, 'Học viên không thuộc lớp này.');
            abort_if((float) $score > (float) $validated['max_score'], 422, 'Điểm không được vượt quá điểm tối đa.');
            MiniTestScore::updateOrCreate(
                [
                    'class_id' => $classId,
                    'student_id' => $studentId,
                    'name' => $validated['name'],
                    'test_date' => $validated['test_date'],
                ],
                [
                    'user_id' => Auth::id(),
                    'score' => $score,
                    'max_score' => $validated['max_score'],
                    'note' => $validated['note'][$studentId] ?? null,
                ]
            );
            $count++;
        }

        return redirect()->route('teacher.home')
            ->with('success', "Đã lưu điểm {$count} học sinh (bài {$validated['name']}) lớp {$class->name}!");
    }

    // ───────────────────────── NHẬN XÉT BỔ SUNG & STUBS ─────────────────────────
    public function remarks(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::with(['students' => fn ($q) => $q->orderBy('name')])->findOrFail($classId);
        $this->authorizeClass($class);
        $today = now()->toDateString();

        $attendance = StudentAttendance::where('class_id', $classId)
            ->whereDate('session_date', $today)
            ->get()
            ->keyBy('student_id');

        // Use AcademicRecord or a similar JSON store for now to store remarks since there isn't a dedicated table for generic teacher remarks without ClassReport.
        $record = AcademicRecord::where('module', 'teacher_remarks')
            ->where('record_code', $classId.'-'.$today)
            ->first();

        $existing = collect($record ? $record->data : []);

        return view('teacher.remarks', compact('class', 'today', 'attendance', 'existing'));
    }

    public function remarksStore(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);
        $validated = $request->validate([
            'remarks' => 'nullable|array',
        ]);

        $today = now()->toDateString();

        AcademicRecord::updateOrCreate(
            ['module' => 'teacher_remarks', 'record_code' => $classId.'-'.$today],
            [
                'title' => 'Nhận xét lớp '.$classId.' ngày '.$today,
                'status' => 'completed',
                'user_id' => Auth::id(),
                'data' => $validated['remarks'] ?? [],
            ]
        );

        return redirect()->route('teacher.home')->with('success', 'Đã lưu nhận xét buổi học!');
    }

    // ─────────────────────────────────────────────
    // Các màn hình bổ sung của Cổng GV (dữ liệu thật)
    // ─────────────────────────────────────────────

    /**
     * GV xem các chặng đang dạy và gửi yêu cầu cấp đề test về cho học vụ.
     */
    public function orderTest(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::with(['course', 'syllabusAssignments' => fn ($q) => $q->where('user_id', Auth::id())])
            ->findOrFail($classId);
        $this->authorizeClass($class);

        $assignments = $class->syllabusAssignments;

        // Lịch sử yêu cầu đề test của GV cho lớp này
        $requests = AcademicRecord::where('screen_key', '03_Cong_Giao_Vien/09_order_test')
            ->where('data->class_id', (string) $class->id)
            ->latest()
            ->take(10)
            ->get();

        return view('teacher.order-test', compact('class', 'assignments', 'requests'));
    }

    public function submitOrderTest(Request $request, int $classId)
    {
        $this->guardTeacher();
        $class = ClassModel::findOrFail($classId);
        $this->authorizeClass($class);

        $validated = $request->validate([
            'stage_name' => 'required|string|max:255',
            'test_type' => 'required|in:mini,big',
            'note' => 'nullable|string|max:1000',
        ]);

        AcademicRecord::create([
            'screen_key' => '03_Cong_Giao_Vien/09_order_test',
            'module' => 'teacher_portal',
            'record_code' => 'ORDTEST-'.strtoupper(Str::random(6)),
            'title' => 'Yêu cầu đề '.($validated['test_type'] === 'big' ? 'Big Test' : 'Mini Test').': '.$validated['stage_name'],
            'status' => 'pending',
            'data' => [
                'teacher_id' => Auth::id(),
                'teacher_name' => Auth::user()->name,
                'class_id' => (string) $class->id,
                'class_name' => $class->name,
                'stage_name' => $validated['stage_name'],
                'test_type' => $validated['test_type'],
                'note' => $validated['note'] ?? '',
                'created_at' => now()->format('d/m/Y H:i'),
            ],
            'user_id' => Auth::id(),
        ]);

        AdminNotification::create([
            'title' => 'GV yêu cầu đề test: '.Auth::user()->name,
            'message' => 'Giáo viên '.Auth::user()->name.' yêu cầu đề '.($validated['test_type'] === 'big' ? 'Big Test' : 'Mini Test')." cho chặng \"{$validated['stage_name']}\" của lớp {$class->name}".($validated['note'] ? " — Ghi chú: {$validated['note']}" : ''),
            'type' => 'info',
            'is_read' => false,
        ]);

        return redirect()->back()->with('success', 'Đã gửi yêu cầu đề test tới Ban Học thuật.');
    }

    /**
     * Bảng điểm Big Test các lớp GV phụ trách (GV chính hoặc GVNN).
     */
    public function bigTestReport(Request $request)
    {
        $this->guardTeacher();
        $teacherId = Auth::id();

        $results = BigTestResult::with(['student', 'bigTest.classModel', 'grader'])
            ->whereHas('bigTest.classModel', fn ($q) => $q->where(function ($sub) use ($teacherId) {
                $sub->where('teacher_id', $teacherId)->orWhere('foreign_teacher_id', $teacherId);
            }))
            ->latest()
            ->paginate(20)->withQueryString();

        return view('teacher.big-test-report', compact('results'));
    }

    /**
     * Tổng hợp hoạt động giảng dạy của GV: giờ dạy đã duyệt, điểm danh đã chấm, điểm đã nhập.
     */
    public function generalReport(Request $request)
    {
        $this->guardTeacher();
        $teacherId = Auth::id();

        // Nhận cả dạng ?period=YYYY-MM (input type=month) lẫn ?month=&year=
        $period = $request->input('period');
        $month = (int) $request->input('month', $period ? substr($period, 5, 2) : now()->month);
        $year = (int) $request->input('year', $period ? substr($period, 0, 4) : now()->year);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $timesheets = TeacherTimesheet::where('user_id', $teacherId)
            ->whereBetween('teaching_date', [$start->toDateString(), $end->toDateString()])
            ->get();
        $attendanceMarked = StudentAttendance::where('user_id', $teacherId)
            ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
            ->count();
        $scoresEntered = MiniTestScore::where('user_id', $teacherId)
            ->whereBetween('test_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $myClasses = ClassModel::with('course')
            ->where(function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId)->orWhere('foreign_teacher_id', $teacherId);
            })
            ->where('status', '!=', 'cancelled')
            ->get();

        return view('teacher.general-report', compact(
            'month', 'year', 'timesheets', 'attendanceMarked', 'scoresEntered', 'myClasses'
        ));
    }
}
