<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\ZaloZnsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SyllabusController extends Controller
{
    public function documents()
    {
        $curriculums = SyllabusCurriculum::with(['course', 'units'])->latest()->get();
        $courses = Course::all();

        return view('syllabus.documents', compact('curriculums', 'courses'));
    }

    public function storeDocument(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:syllabus_curriculums,code',
            'title' => 'required|string|max:255',
            'course_id' => 'required|exists:courses,id',
            'version' => 'required|string|max:20',
        ]);

        $doc = SyllabusCurriculum::create($validated + ['file_type' => 'PDF', 'file_size' => '35 MB']);

        return redirect()->route('syllabus.documents')
            ->with('status', "Đã tải lên giáo trình {$doc->title} thành công!");
    }

    public function builder()
    {
        $curriculum = SyllabusCurriculum::with('units')->first();
        $units = $curriculum ? $curriculum->units : collect();

        return view('syllabus.builder', compact('curriculum', 'units'));
    }

    public function storeUnit(Request $request)
    {
        $validated = $request->validate([
            'curriculum_id' => 'required|exists:syllabus_curriculums,id',
            'unit_number' => 'required|integer',
            'title' => 'required|string|max:255',
            'objectives' => 'nullable|string',
            'vocabulary_focus' => 'nullable|string',
            'grammar_focus' => 'nullable|string',
            'homework_guide' => 'nullable|string',
        ]);

        $unit = SyllabusUnit::create($validated);

        return redirect()->route('syllabus.builder')
            ->with('status', "Đã lưu nội dung soạn thảo {$unit->title} vào CSDL!");
    }

    public function assignments()
    {
        $assignments = SyllabusAssignment::with(['teacher', 'curriculum', 'classModel'])->latest()->get();
        $teachers = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['teacher', 'academic_lead']))->get();
        $curriculums = SyllabusCurriculum::all();
        $classes = ClassModel::where('status', '!=', 'cancelled')->get();

        return view('syllabus.assignments', compact('assignments', 'teachers', 'curriculums', 'classes'));
    }

    public function storeAssignment(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'curriculum_id' => 'required|exists:syllabus_curriculums,id',
            'class_id' => 'required|exists:classes,id',
            'assigned_chapters' => 'required|string',
            'stage_name' => 'required|string|max:255',
            'deadline' => 'required|date',
        ]);

        SyllabusAssignment::create($validated + ['progress_percent' => 0, 'status' => 'in_progress']);

        return redirect()->route('syllabus.assignments')
            ->with('status', 'Đã phân công nhiệm vụ soạn bài cho giáo viên thành công!');
    }

    public function versions()
    {
        $curriculums = SyllabusCurriculum::with('course')->get();

        return view('syllabus.versions', compact('curriculums'));
    }

    public function teacherView()
    {
        $user = Auth::user();
        $curriculumIds = SyllabusAssignment::where('user_id', $user->id)->pluck('curriculum_id');
        $curriculums = SyllabusCurriculum::with(['course', 'units'])
            ->when(! $user->hasAnyRole(['admin', 'manager', 'academic_lead']), fn ($query) => $query->whereIn('id', $curriculumIds))
            ->latest()->get();
        $courses = Course::all();
        $curriculum = $curriculums->first();
        $units = $curriculum ? $curriculum->units : collect();

        return view('syllabus.teacher-view', compact('curriculums', 'courses', 'curriculum', 'units'));
    }

    public function teacherPropose()
    {
        $curriculums = SyllabusCurriculum::with(['course', 'units'])->latest()->get();

        return view('syllabus.teacher-propose', compact('curriculums'));
    }

    public function teacherAdjust()
    {
        $classes = ClassModel::all();
        $requests = SyllabusAdjustmentRequest::with(['classModel', 'teacher'])->latest()->get();

        return view('syllabus.teacher-adjust', compact('classes', 'requests'));
    }

    public function adjustmentRequests()
    {
        $requests = SyllabusAdjustmentRequest::with(['classModel', 'teacher', 'approver'])->latest()->get();
        $classes = ClassModel::all();

        return view('syllabus.adjustment-requests', compact('requests', 'classes'));
    }

    public function storeAdjustmentRequest(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'request_type' => 'required|string',
            'reason' => 'required|string',
        ]);

        SyllabusAdjustmentRequest::create([
            'class_id' => $validated['class_id'],
            'user_id' => Auth::id(),
            'request_type' => $validated['request_type'],
            'reason' => $validated['reason'],
            'status' => 'pending',
        ]);

        return redirect()->route('syllabus.adjustment-requests')
            ->with('status', 'Đã gửi yêu cầu điều chỉnh tiến độ giáo trình!');
    }

    public function approveAdjustmentRequest($id)
    {
        $req = SyllabusAdjustmentRequest::findOrFail($id);
        $req->update([
            'status' => 'approved',
            'approver_id' => Auth::id(),
        ]);

        return redirect()->back()->with('status', "Đã phê duyệt yêu cầu điều chỉnh tiến độ cho lớp {$req->classModel?->name}!");
    }

    public function rejectAdjustmentRequest($id)
    {
        $req = SyllabusAdjustmentRequest::findOrFail($id);
        $req->update([
            'status' => 'rejected',
            'approver_id' => Auth::id(),
        ]);

        return redirect()->back()->with('status', "Đã từ chối yêu cầu điều chỉnh tiến độ cho lớp {$req->classModel?->name}!");
    }

    public function bigTestDistribution()
    {
        $classes = ClassModel::all();
        $bigTests = BigTest::with(['classModel', 'proctor'])->latest()->get();

        return view('syllabus.big-tests-distribution', compact('classes', 'bigTests'));
    }

    public function storeBigTest(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'class_id' => 'required|exists:classes,id',
            'test_type' => 'required|string',
            'scheduled_at' => 'required|date',
            'room' => 'required|string',
        ]);

        $code = 'BT-'.date('Y').'-'.str_pad(BigTest::count() + 1, 2, '0', STR_PAD_LEFT);

        $bt = BigTest::create($validated + [
            'code' => $code,
            'proctor_id' => Auth::id(),
            'passcode' => 'MEN'.rand(1000, 9999),
            'is_distributed' => false,
            'status' => 'draft',
        ]);

        return redirect()->route('syllabus.big-tests.distribution')
            ->with('status', "Đã tạo bản nháp Big Test {$bt->title} ({$bt->code}); cần duyệt trước khi phân phối.");
    }

    public function approveAndDistributeBigTest(int $id)
    {
        $test = BigTest::findOrFail($id);
        $test->update([
            'status' => 'distributed',
            'is_distributed' => true,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'distributed_at' => now(),
        ]);

        return redirect()->back()->with('status', "Đã duyệt và phân phối đề {$test->code}.");
    }

    public function bigTestSchedules()
    {
        $bigTests = BigTest::with(['classModel', 'proctor'])->latest()->get();

        return view('syllabus.big-test-schedules', compact('bigTests'));
    }

    /**
     * Gửi nhắc lịch thi Big Test tới toàn bộ học viên của lớp qua Cổng PH/HS
     * (inbox thông báo) và ghi log AdminNotification cho học vụ.
     */
    public function sendBigTestReminder(Request $request, int $id)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        abort_unless($test->classModel, 422, 'Đợt thi chưa gắn lớp học.');
        abort_unless($test->scheduled_at, 422, 'Đợt thi chưa có lịch giờ thi.');

        $students = $test->classModel->students()
            ->whereIn('status', ['studying', 'deferred'])
            ->get();

        $when = $test->scheduled_at->format('H:i d/m/Y');
        $sent = 0;
        foreach ($students as $student) {
            AcademicRecord::firstOrCreate(
                [
                    'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao',
                    'record_code' => 'BIGTEST-REMIND-'.$test->id.'-'.$student->id,
                ],
                [
                    'module' => 'student_portal',
                    'title' => 'Nhắc lịch Big Test: '.$test->title,
                    'status' => 'active',
                    'data' => [
                        'type' => 'big_test_reminder',
                        'title' => 'Nhắc lịch thi: '.$test->title,
                        'content' => 'Học viên '.$student->name.' có bài thi "'.$test->title.'" lúc '.$when.' tại phòng '.$test->room.'. Vui lòng có mặt sớm 15 phút.',
                        'unread' => true,
                        'icon' => 'alarm',
                        'bg_color' => 'bg-purple-100',
                        'text_color' => 'text-purple-600',
                        'student_id' => $student->id,
                        'created_at' => now()->toDateTimeString(),
                    ],
                    'user_id' => $student->user_id,
                ]
            );
            $sent++;
        }

        AdminNotification::create([
            'title' => 'Đã gửi nhắc lịch Big Test: '.$test->title,
            'message' => "Đợt thi [{$test->code}] {$test->title} lúc {$when} — đã gửi nhắc tới {$sent} học viên của lớp {$test->classModel->name}.",
            'type' => 'info',
            'is_read' => false,
        ]);

        return redirect()->back()
            ->with('status', "Đã gửi nhắc lịch {$test->title} tới {$sent} học viên của lớp {$test->classModel->name}.");
    }

    public function bigTestResults(Request $request, $id = null)
    {
        $testId = $id ?: $request->query('test_id');
        $allTests = BigTest::with('classModel')->latest()->get();
        $test = $testId ? BigTest::with('classModel')->find($testId) : $allTests->first();
        $results = $test ? BigTestResult::with('student')->where('big_test_id', $test->id)->get() : collect();
        $students = $test?->classModel?->students()->orderBy('name')->get() ?? collect();

        return view('syllabus.big-tests-results', compact('test', 'allTests', 'results', 'students'));
    }

    public function storeBigTestResults(Request $request, int $id)
    {
        $test = BigTest::findOrFail($id);
        abort_unless($test->is_distributed, 422, 'Đề thi chưa được duyệt và phân phối.');

        $validated = $request->validate([
            'results' => ['required', 'array'],
            'results.*.student_id' => ['required', 'exists:students,id'],
            'results.*.listening_score' => ['required', 'numeric', 'between:0,10'],
            'results.*.reading_score' => ['required', 'numeric', 'between:0,10'],
            'results.*.writing_score' => ['required', 'numeric', 'between:0,10'],
            'results.*.speaking_score' => ['required', 'numeric', 'between:0,10'],
            'results.*.progress_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $classStudentIds = $test->classModel?->students()->pluck('id') ?? collect();
        foreach ($validated['results'] as $row) {
            abort_unless($classStudentIds->contains((int) $row['student_id']), 422, 'Học viên không thuộc lớp thi.');
            $overall = round(collect([
                $row['listening_score'], $row['reading_score'], $row['writing_score'], $row['speaking_score'],
            ])->avg(), 1);
            BigTestResult::updateOrCreate(
                ['big_test_id' => $test->id, 'student_id' => $row['student_id']],
                $row + [
                    'overall_score' => $overall,
                    'status' => 'pending_review',
                    'graded_by' => Auth::id(),
                    'approved_by' => null,
                    'approved_at' => null,
                ]
            );
        }

        return redirect()->back()->with('status', 'Đã lưu điểm; kết quả đang chờ Học thuật duyệt.');
    }

    public function approveBigTestResults(int $id)
    {
        $test = BigTest::findOrFail($id);
        BigTestResult::where('big_test_id', $test->id)
            ->where('status', 'pending_review')
            ->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return redirect()->back()->with('status', 'Đã duyệt kết quả Big Test; có thể gửi cho phụ huynh.');
    }

    public function sendZaloResults($id)
    {
        $test = BigTest::with('classModel')->findOrFail($id);
        abort_unless($test->is_distributed, 422, 'Đề thi chưa được duyệt và phân phối.');
        $results = BigTestResult::with('student')->where('big_test_id', $id)->where('status', 'approved')->get();
        abort_if($results->isEmpty(), 422, 'Chưa có kết quả đã duyệt để gửi phụ huynh.');
        $count = 0;
        foreach ($results as $res) {
            $student = $res->student;
            ZaloZnsService::sendBigTestResult(
                phone: $student?->phone ?? '0912345678',
                studentName: $student?->name ?? 'Học viên',
                className: $test->classModel?->name ?? 'Lớp MEnglish',
                testTitle: $test->title,
                listening: $res->listening_score,
                reading: $res->reading_score,
                writing: $res->writing_score,
                speaking: $res->speaking_score,
                overall: $res->overall_score,
                progressNote: $res->progress_note ?? ''
            );

            $res->update([
                'parent_notified' => true,
                'notified_at' => now(),
            ]);
            $count++;
        }

        return redirect()->back()->with('status', "Đã kích hoạt gửi tin nhắn Zalo ZNS thành công cho {$count} phụ huynh học viên kỳ thi [{$test->title}]!");
    }

    public function sendSingleZaloResult($resultId)
    {
        $res = BigTestResult::with(['student', 'bigTest.classModel'])->findOrFail($resultId);
        abort_unless($res->status === 'approved', 422, 'Kết quả chưa được Học thuật duyệt.');
        $student = $res->student;
        $test = $res->bigTest;

        $response = ZaloZnsService::sendBigTestResult(
            phone: $student?->phone ?? '0912345678',
            studentName: $student?->name ?? 'Học viên',
            className: $test?->classModel?->name ?? 'Lớp MEnglish',
            testTitle: $test?->title ?? 'Big Test',
            listening: $res->listening_score,
            reading: $res->reading_score,
            writing: $res->writing_score,
            speaking: $res->speaking_score,
            overall: $res->overall_score,
            progressNote: $res->progress_note ?? ''
        );

        $res->update([
            'parent_notified' => true,
            'notified_at' => now(),
        ]);

        $studentName = $student?->name ?? 'Học viên';

        return redirect()->back()->with('status', "Đã gửi thông báo điểm qua Zalo ZNS đến Phụ huynh em {$studentName} thành công!");
    }
}
