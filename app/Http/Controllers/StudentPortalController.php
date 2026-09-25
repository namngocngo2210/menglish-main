<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\MiniTestScore;
use App\Models\Homework;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\Survey;
use App\Models\SyllabusAssignment;
use App\Models\TuitionReceipt;
use App\Services\SafeUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentPortalController extends Controller
{
    /** Đuôi file được phép khi nộp bài tập (theo nội dung file). */
    private const HOMEWORK_EXTENSIONS = [
        ...SafeUploadService::IMAGES, ...SafeUploadService::DOCUMENTS, ...SafeUploadService::VIDEO, ...SafeUploadService::AUDIO,
    ];

    private const TEACHER_ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'];

    /** Số hạng mục bài tập cố định trên màn "Học tập của tôi" (video, từ vựng, workbook...). */
    private const HOMEWORK_CATEGORY_COUNT = 6;

    /** Số ngày lịch học sắp tới hiển thị ở trang chủ học viên. */
    private const UPCOMING_DAYS = 14;

    protected function canManageStudents(): bool
    {
        $user = Auth::user();

        return (bool) ($user && ! $user->hasRole('student') && $user->can('student.view'));
    }

    protected function authorizeStudent(Student $student): void
    {
        $user = Auth::user();
        abort_unless($user && (
            $this->canManageStudents()
            || (int) $student->user_id === (int) $user->id
            || ($student->email && strcasecmp($student->email, $user->email) === 0)
        ), 403, 'Bạn không được phép truy cập hồ sơ học viên này.');
    }

    protected function authorizeStudentRecord(AcademicRecord $record, string $screenKey): Student
    {
        abort_unless($record->screen_key === $screenKey, 404);
        $student = Student::findOrFail(data_get($record->data, 'student_id'));
        $this->authorizeStudent($student);

        return $student;
    }

    protected function teacherClassesQuery()
    {
        $user = Auth::user();
        abort_unless($user && ($user->hasRole('admin') || $user->hasAnyRole(self::TEACHER_ROLES)), 403);

        return ClassModel::query()
            ->when(! $user->hasRole('admin'), function ($query) use ($user) {
                $query->where(function ($classes) use ($user) {
                    $classes->where('teacher_id', $user->id)
                        ->orWhere('assistant_id', $user->id)
                        ->orWhere('foreign_teacher_id', $user->id);
                });
            });
    }

    /**
     * Helper lấy danh sách học viên và học viên đang chọn
     */
    protected function getActiveStudent($studentId = null)
    {
        $students = Student::with(['currentClass.teacher', 'tuition.receipts'])
            ->when(! $this->canManageStudents(), function ($query) {
                $user = Auth::user();
                $query->where(function ($students) use ($user) {
                    $students->where('user_id', $user->id)->orWhere('email', $user->email);
                });
            })
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

    // ─────────────────────────────────────────────
    // MÀN HÌNH #1: App Shell Phụ huynh / Học sinh
    // ─────────────────────────────────────────────
    public function appShell(Request $request)
    {
        [$students, $student] = $this->getActiveStudent($request->query('student_id'));

        // Thống kê thực tế từ DB cho học viên đang chọn
        $submittedHomeworksCount = 0;
        $unreadNotifsCount = 0;
        $pronunciationCount = 0;
        $surveyCount = 0;
        $hasFeedback = false;

        if ($student) {
            // Chỉ hiển thị thông báo thật (không còn tự tạo thông báo mẫu mỗi lần mở trang).
            // Số hạng mục bài tập (6 loại) đã nộp — đếm theo loại, không đếm lượt nộp lại.
            $submittedHomeworksCount = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap')
                ->where('data->student_id', (string) $student->id)
                ->get()
                ->pluck('data.homework_type')
                ->filter()
                ->unique()
                ->count();

            $unreadNotifsCount = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao')
                ->where('data->student_id', (string) $student->id)
                ->where('data->unread', true)
                ->count();

            $pronunciationCount = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am')
                ->where('data->student_id', (string) $student->id)
                ->count();

            $surveyCount = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat')
                ->where('data->student_id', (string) $student->id)
                ->count();

            $hasFeedback = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')
                ->where('data->student_id', (string) $student->id)
                ->exists();
        }

        $pendingHomeworksCount = max(0, self::HOMEWORK_CATEGORY_COUNT - $submittedHomeworksCount);

        return view('portal.app-shell', compact(
            'students',
            'student',
            'pendingHomeworksCount',
            'submittedHomeworksCount',
            'unreadNotifsCount',
            'pronunciationCount',
            'surveyCount',
            'hasFeedback'
        ));
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #2: Trang chủ Phụ huynh / Học sinh
    // ─────────────────────────────────────────────
    public function studentHome(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        $tuition = $student?->tuition;
        $totalPaid = $tuition ? (float) $tuition->paid_amount : 0;
        $debtAmount = $tuition ? (float) $tuition->debt_amount : 0;
        $nextTermFee = (float) ($student?->currentClass?->course?->tuition_fee ?? 0);
        $learningProgress = [
            'attendance_present' => 0,
            'attendance_total' => 0,
            'homework_submitted' => 0,
            'homework_total' => 0,
            'latest_big_test' => null,
        ];

        $upcomingSessions = collect();
        $attendanceHistory = collect();
        $bigTestResults = collect();
        $studentClasses = collect();

        if ($student) {
            // Lớp chính + lớp liên kết (lượt xếp lớp còn hiệu lực).
            $classIds = $student->activeClassIds();
            $studentClasses = ClassModel::with('teacher')->whereIn('id', $classIds)->orderBy('name')->get();

            $approvedAttendances = StudentAttendance::where('student_id', $student->id)->where('review_status', 'approved');
            $learningProgress['attendance_total'] = (clone $approvedAttendances)->count();
            $learningProgress['attendance_present'] = (clone $approvedAttendances)->whereIn('status', ['present', 'late'])->count();
            $learningProgress['homework_total'] = Homework::whereIn('class_id', $classIds)->count();
            $learningProgress['homework_submitted'] = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap')
                ->where('data->student_id', (string) $student->id)->count();

            // Lịch học sắp tới: buổi chính khóa / học bù của các lớp + buổi phụ đạo của riêng học viên.
            $upcomingSessions = ClassSession::with(['classModel', 'teacher'])
                ->whereDate('date', '>=', now()->toDateString())
                ->whereDate('date', '<=', now()->addDays(self::UPCOMING_DAYS)->toDateString())
                ->where(function ($q) use ($classIds, $student) {
                    $q->where(fn ($q) => $q->whereIn('class_id', $classIds)
                        ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP]))
                        ->orWhere(fn ($q) => $q->where('type', ClassSession::TYPE_SUPPORT)
                            ->whereHas('supportSession', fn ($s) => $s->where('student_id', $student->id)));
                })
                ->orderBy('date')->orderBy('start_time')
                ->limit(20)
                ->get();

            $attendanceHistory = StudentAttendance::with(['classModel', 'classSession'])
                ->where('student_id', $student->id)
                ->orderByDesc('session_date')->orderByDesc('id')
                ->limit(10)
                ->get();

            // Kết quả Big Test chỉ hiện khi đã duyệt hoặc đã gửi phụ huynh.
            $bigTestResults = BigTestResult::with('bigTest')
                ->where('student_id', $student->id)
                ->whereIn('status', ['approved', 'sent'])
                ->latest('approved_at')->latest('id')
                ->get();
            $learningProgress['latest_big_test'] = $bigTestResults->first();
        }

        // Lịch sử biên lai đóng học phí thực tế từ DB
        $receipts = $tuition?->receipts()->where('status', 'approved')->latest()->get();
        if (! $receipts || $receipts->isEmpty()) {
            $receipts = TuitionReceipt::where('student_id', $student?->id)->where('status', 'approved')->latest()->get();
        }

        return view('portal.home', compact(
            'student',
            'students',
            'tuition',
            'totalPaid',
            'debtAmount',
            'nextTermFee',
            'receipts',
            'learningProgress',
            'upcomingSessions',
            'attendanceHistory',
            'bigTestResults',
            'studentClasses'
        ));
    }

    /**
     * CRUD: Cập nhật thông tin liên hệ / ghi chú học viên (MH #2)
     */
    public function updateProfile(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        $this->authorizeStudent($student);

        $validated = $request->validate([
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $student->update($validated);

        return back()->with('success', 'Đã cập nhật thông tin học viên thành công!');
    }

    /**
     * CRUD: Gửi yêu cầu hỗ trợ / xác nhận đóng học phí (MH #2)
     */
    public function submitTuitionRequest(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'amount' => 'required|numeric|min:1000',
            'content' => 'required|string|max:500',
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $this->authorizeStudent($student);
        $studentName = $student->name;

        AcademicRecord::create([
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/02_trang_chu_phu_huynh_hoc_sinh',
            'module' => 'student_portal',
            'record_code' => 'YCHOCPHI-'.strtoupper(Str::random(6)),
            'title' => 'Báo đóng học phí: '.number_format($validated['amount']).'đ - '.$studentName,
            'status' => 'pending',
            'data' => [
                'student_id' => (string) $validated['student_id'],
                'student_name' => $studentName,
                'amount' => $validated['amount'],
                'content' => $validated['content'],
                'submitted_at' => now()->format('d/m/Y H:i'),
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Đã gửi thông báo đóng học phí tới bộ phận Kế toán thành công!');
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #3: Học tập của tôi — Nộp bài tập
    // ─────────────────────────────────────────────
    public function studentHomework(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        // Lấy tất cả bài nộp thực tế từ DB của học viên này
        $submissions = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap')
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            })
            ->latest()
            ->get();

        // Nhóm bài nộp theo loại bài tập để check trạng thái
        $submissionsByType = [];
        foreach ($submissions as $sub) {
            $type = $sub->data['homework_type'] ?? null;
            if ($type && ! isset($submissionsByType[$type])) {
                $submissionsByType[$type] = $sub;
            }
        }

        $completedCount = count($submissionsByType);

        // Nhận xét buổi học, bảng điểm, bài tập mới nhất — dữ liệu thật của (các) lớp học viên.
        $remarks = collect();
        $miniTests = collect();
        $bigTestResults = collect();
        $latestHomework = null;
        if ($student) {
            $classIds = $student->activeClassIds();
            $remarks = AcademicRecord::where('module', 'teacher_remarks')
                ->where(function ($q) use ($classIds) {
                    foreach ($classIds as $classId) {
                        $q->orWhere('record_code', 'like', $classId.'-%');
                    }
                })
                ->when($classIds === [], fn ($q) => $q->whereRaw('1 = 0'))
                ->latest()
                ->limit(20)
                ->get()
                ->filter(fn (AcademicRecord $record) => ! empty(array_filter((array) data_get($record->data, (string) $student->id, []))))
                ->take(3)
                ->map(fn (AcademicRecord $record) => [
                    'date' => preg_match('/-(\d{4}-\d{2}-\d{2})$/', (string) $record->record_code, $m) ? \Carbon\Carbon::parse($m[1]) : $record->created_at,
                    'remark' => (array) data_get($record->data, (string) $student->id, []),
                ])
                ->values();
            $miniTests = MiniTestScore::where('student_id', $student->id)->latest('test_date')->limit(10)->get();
            $bigTestResults = BigTestResult::with('bigTest')->where('student_id', $student->id)
                ->whereIn('status', ['approved', 'sent'])->latest('approved_at')->limit(5)->get();
            $latestHomework = Homework::with('classModel')->whereIn('class_id', $classIds)
                ->latest('due_date')->latest('id')->first();
        }

        return view('portal.student-homework', compact(
            'student',
            'students',
            'submissions',
            'submissionsByType',
            'completedCount',
            'remarks',
            'miniTests',
            'bigTestResults',
            'latestHomework'
        ));
    }

    /**
     * CRUD: Nộp bài tập mới (MH #3)
     */
    public function submitHomework(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'homework_type' => 'required|string', // video, vocabulary, workbook, extra_book, bgd_book, quiz
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|max:102400|mimes:'.implode(',', self::HOMEWORK_EXTENSIONS), // Tối đa 100MB
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $this->authorizeStudent($student);
        $studentName = $student->name;

        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = SafeUploadService::store($file, 'homework_submissions', self::HOMEWORK_EXTENSIONS, 'attachment');
        }

        $typeLabels = [
            'video' => 'Quay video bài học',
            'vocabulary' => 'Viết từ vựng & Chụp ảnh',
            'workbook' => 'Làm Workbook bài tập',
            'extra_book' => 'Sách bổ trợ',
            'bgd_book' => 'Sách Bộ Giáo dục',
            'quiz' => 'Làm Quiz trực tuyến',
        ];

        AcademicRecord::create([
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap',
            'module' => 'student_portal',
            'record_code' => 'SUB-'.strtoupper(Str::random(6)),
            'title' => 'Bài nộp: '.($typeLabels[$validated['homework_type']] ?? $validated['homework_type']).' - '.$studentName,
            'status' => 'submitted',
            'data' => [
                'student_id' => (string) $validated['student_id'],
                'student_name' => $studentName,
                'homework_type' => $validated['homework_type'],
                'homework_label' => $typeLabels[$validated['homework_type']] ?? $validated['homework_type'],
                'attachment_path' => $attachmentPath ? '/storage/'.$attachmentPath : null,
                'attachment_name' => $attachmentName ?? ($validated['homework_type'] === 'video' ? 'video_bai_tap.mp4' : 'bai_lam.jpg'),
                'notes' => $validated['notes'] ?? 'Con đã hoàn thành bài tập đầy đủ theo hướng dẫn của cô.',
                'submitted_at' => now()->format('d/m/Y H:i'),
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Đã nộp bài tập thành công! Giáo viên sẽ nhận được thông báo để kiểm tra và chấm điểm.');
    }

    /**
     * CRUD: Cập nhật ghi chú hoặc file bài nộp (MH #3)
     */
    public function updateHomework(Request $request, $id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap');

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|max:102400|mimes:'.implode(',', self::HOMEWORK_EXTENSIONS),
        ]);

        $data = $record->data ?? [];
        if ($request->has('notes')) {
            $data['notes'] = $validated['notes'];
        }

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = SafeUploadService::store($file, 'homework_submissions', self::HOMEWORK_EXTENSIONS, 'attachment');
            $data['attachment_path'] = '/storage/'.$path;
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        $data['updated_at_custom'] = now()->format('d/m/Y H:i');
        $record->data = $data;
        $record->save();

        return back()->with('success', 'Đã cập nhật bài nộp thành công!');
    }

    /**
     * CRUD: Hủy / Xóa bài nộp (MH #3)
     */
    public function deleteHomework($id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap');

        if (! empty($record->data['attachment_path'])) {
            $relativePath = str_replace('/storage/', '', $record->data['attachment_path']);
            Storage::disk('public')->delete($relativePath);
        }

        $record->delete();

        return back()->with('success', 'Đã xóa bài nộp thành công! Bạn có thể nộp lại bài mới.');
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #4: Luyện phát âm & Ghi âm giọng nói AI
    // ─────────────────────────────────────────────
    public function studentPronunciation(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        // Lấy lịch sử luyện tập thực tế từ CSDL
        $history = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am')
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            })
            ->latest()
            ->get();

        return view('portal.pronunciation', compact('student', 'students', 'history'));
    }

    /**
     * CRUD: Nộp bản ghi âm phát âm (MH #4)
     */
    public function submitPronunciation(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'unit_title' => 'required|string',
            'duration' => 'nullable|string',
            'audio_file' => 'nullable|file|max:51200|mimes:'.implode(',', SafeUploadService::AUDIO), // 50MB
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $this->authorizeStudent($student);
        $studentName = $student->name;

        $audioPath = null;
        if ($request->hasFile('audio_file')) {
            $file = $request->file('audio_file');
            $audioPath = SafeUploadService::store($file, 'pronunciation_records', SafeUploadService::AUDIO, 'audio_file');
        }

        // Chưa có dịch vụ chấm phát âm tự động: bài nộp chờ giáo viên chấm
        // (màn "Chấm bài nộp" của giáo viên, tab Phát âm). Không sinh điểm ngẫu nhiên.
        AcademicRecord::create([
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am',
            'module' => 'student_portal',
            'record_code' => 'AUDIO-'.strtoupper(Str::random(6)),
            'title' => 'Bản ghi âm: '.$validated['unit_title'].' - '.$studentName,
            'status' => 'pending_review',
            'data' => [
                'student_id' => (string) $validated['student_id'],
                'student_name' => $studentName,
                'unit_title' => $validated['unit_title'],
                'duration' => $validated['duration'] ?? null,
                'audio_path' => $audioPath ? '/storage/'.$audioPath : null,
                'score' => null,
                'submitted_at' => now()->format('d/m/Y H:i'),
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Đã nộp bài ghi âm! Bài đang chờ giáo viên chấm.');
    }

    /**
     * CRUD: Xóa bản ghi âm phát âm (MH #4)
     */
    public function deletePronunciation($id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am');

        if (! empty($record->data['audio_path'])) {
            $relativePath = str_replace('/storage/', '', $record->data['audio_path']);
            Storage::disk('public')->delete($relativePath);
        }

        $record->delete();

        return back()->with('success', 'Đã xóa bản ghi âm thành công!');
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #5: Danh sách thông báo
    // ─────────────────────────────────────────────
    public function studentNotifications(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        // Lấy danh sách thông báo thật từ CSDL (không tự tạo thông báo mẫu).
        $notifications = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao')
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            })
            ->latest()
            ->get();

        return view('portal.notifications', compact('student', 'students', 'notifications'));
    }

    /**
     * CRUD: Đánh dấu tất cả thông báo là đã đọc (MH #5)
     */
    public function markNotificationsRead(Request $request)
    {
        $studentId = $request->input('student_id');
        $student = Student::findOrFail($studentId);
        $this->authorizeStudent($student);

        $query = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao');
        if ($studentId) {
            $query->where('data->student_id', (string) $studentId);
        }

        $records = $query->get();
        foreach ($records as $record) {
            $data = $record->data;
            $data['unread'] = false;
            $record->data = $data;
            $record->save();
        }

        return back()->with('success', 'Đã đánh dấu tất cả thông báo là đã đọc!');
    }

    /**
     * CRUD: Đánh dấu 1 thông báo là đã đọc (MH #5)
     */
    public function markSingleNotificationRead($id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao');
        $data = $record->data;
        $data['unread'] = false;
        $record->data = $data;
        $record->save();

        return back()->with('success', 'Đã đánh dấu thông báo là đã đọc.');
    }

    /**
     * CRUD: Xóa thông báo (MH #5)
     */
    public function deleteNotification($id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao');
        $record->delete();

        return back()->with('success', 'Đã xóa thông báo thành công.');
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #6: Khảo sát & Đánh giá chất lượng
    // ─────────────────────────────────────────────
    public function studentSurvey(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        // Đợt khảo sát đang mở do học vụ tạo (bảng surveys) — không còn danh sách cứng
        $surveys = Survey::query()
            ->active()
            ->orderBy('deadline')
            ->get()
            ->map(function (Survey $survey) {
                $deadlineText = $survey->deadline?->format('d/m/Y') ?? 'Chưa đặt hạn';

                return [
                    'id' => $survey->id,
                    'title' => $survey->title,
                    'description' => $survey->description,
                    'deadline' => $deadlineText,
                    'is_active' => true,
                    'is_urgent' => $survey->deadline?->isToday() ?? false,
                    'status_text' => 'Hạn: '.($survey->deadline?->isToday() ? 'Hôm nay' : $deadlineText),
                ];
            })
            ->values()
            ->all();

        // Lịch sử các bài khảo sát đã làm từ CSDL
        $pastSurveys = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat')
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            })
            ->latest()
            ->get();

        return view('portal.survey', compact('student', 'students', 'surveys', 'pastSurveys'));
    }

    /**
     * CRUD: Gửi ý kiến khảo sát (MH #6)
     */
    public function submitSurvey(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'survey_title' => 'required|string',
            'feedback' => 'required|string|min:5',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $this->authorizeStudent($student);
        $studentName = $student->name;

        // Truy vết đợt khảo sát đang mở tương ứng (nếu tiêu đề khớp đợt học vụ tạo)
        $surveyId = Survey::active()->where('title', $validated['survey_title'])->value('id');

        AcademicRecord::create([
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat',
            'module' => 'student_portal',
            'record_code' => 'SURVEY-'.strtoupper(Str::random(6)),
            'title' => 'Khảo sát: '.$validated['survey_title'].' - '.$studentName,
            'status' => 'completed',
            'data' => [
                'student_id' => (string) $validated['student_id'],
                'student_name' => $studentName,
                'survey_title' => $validated['survey_title'],
                'survey_id' => $surveyId,
                'rating' => (int) ($validated['rating'] ?? 5),
                'feedback' => $validated['feedback'],
                'submitted_at' => now()->format('d/m/Y H:i'),
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Cảm ơn bạn đã gửi khảo sát! Trung tâm sẽ tiếp nhận và cải thiện chất lượng dịch vụ.');
    }

    /**
     * CRUD: Xóa bài khảo sát (MH #6)
     */
    public function deleteSurvey($id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat');
        $record->delete();

        return back()->with('success', 'Đã xóa bài khảo sát thành công! Bạn có thể thực hiện lại.');
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #7: Phụ huynh gửi Feedback chặng học
    // ─────────────────────────────────────────────
    public function studentFeedback(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        // Chặng đang học của lớp (giao chặng); chưa có chặng thì là góp ý chung.
        $stageName = ($student?->current_class_id
            ? SyllabusAssignment::where('class_id', $student->current_class_id)->where('status', 'in_progress')->latest()->value('stage_name')
            : null) ?: 'Góp ý chung';
        $className = $student?->currentClass?->name ?? 'Chưa xếp lớp';

        // Lấy feedback đã gửi của chặng này từ CSDL
        $lastFeedback = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            })
            ->latest()
            ->first();

        // Danh sách tất cả feedback trước đây của học viên này
        $historyFeedbacks = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            })
            ->latest()
            ->get();

        return view('portal.feedback', compact(
            'student',
            'students',
            'lastFeedback',
            'historyFeedbacks',
            'stageName',
            'className'
        ));
    }

    /**
     * CRUD: Gửi hoặc cập nhật feedback chặng học (MH #7)
     */
    public function submitFeedback(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'stage_name' => 'required|string',
            'muc_do_hai_long' => 'nullable|integer|min:0|max:5',
            'fb_hoc_thuat' => 'nullable|string',
            'fb_giao_vien' => 'nullable|string',
            'fb_khac' => 'nullable|string',
            'noi_dung_feedback' => 'nullable|string',
        ]);

        $rating = (int) ($validated['muc_do_hai_long'] ?? 0);
        $hasCategory = ! empty($validated['fb_hoc_thuat']) || ! empty($validated['fb_giao_vien']) || ! empty($validated['fb_khac']);
        $hasContent = ! empty(trim($validated['noi_dung_feedback'] ?? ''));

        if ($rating === 0 && ! $hasCategory && ! $hasContent) {
            return back()->withErrors(['validation' => 'Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.']);
        }

        $student = Student::findOrFail($validated['student_id']);
        $this->authorizeStudent($student);
        $studentName = $student->name;

        $categories = [];
        if (! empty($validated['fb_hoc_thuat'])) {
            $categories[] = 'Học thuật';
        }
        if (! empty($validated['fb_giao_vien'])) {
            $categories[] = 'Giáo viên';
        }
        if (! empty($validated['fb_khac'])) {
            $categories[] = 'Khác';
        }

        AcademicRecord::updateOrCreate(
            [
                'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback',
                'data->student_id' => (string) $validated['student_id'],
                'data->stage_name' => $validated['stage_name'],
            ],
            [
                'module' => 'student_portal',
                'record_code' => 'FB-'.strtoupper(Str::random(6)),
                'title' => 'Feedback chặng: '.$validated['stage_name'].' - '.$studentName,
                'status' => 'submitted',
                'data' => [
                    'student_id' => (string) $validated['student_id'],
                    'student_name' => $studentName,
                    'stage_name' => $validated['stage_name'],
                    'class_name' => $student->currentClass?->name,
                    'muc_do_hai_long' => $rating,
                    'categories' => $categories,
                    'fb_hoc_thuat' => ! empty($validated['fb_hoc_thuat']),
                    'fb_giao_vien' => ! empty($validated['fb_giao_vien']),
                    'fb_khac' => ! empty($validated['fb_khac']),
                    'noi_dung_feedback' => $validated['noi_dung_feedback'] ?? '',
                    'submitted_at' => now()->format('d/m/Y H:i'),
                ],
                'user_id' => Auth::id(),
            ]
        );

        return back()->with('feedback_success', true)->with('success', 'Đã ghi nhận feedback thành công! Bạn có thể điều chỉnh bất cứ lúc nào trong thời gian đợt mở.');
    }

    /**
     * CRUD: Xóa feedback chặng học (MH #7)
     */
    public function deleteFeedback($id)
    {
        $record = AcademicRecord::findOrFail($id);
        $this->authorizeStudentRecord($record, '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback');
        $record->delete();

        return back()->with('success', 'Đã xóa feedback thành công! Trạng thái đã được đặt lại ban đầu.');
    }

    // ─────────────────────────────────────────────
    // CỔNG GIÁO VIÊN: Chấm & Đánh giá bài nộp
    // ─────────────────────────────────────────────
    public function teacherSubmissions(Request $request, $classId = null)
    {
        $classes = $this->teacherClassesQuery()->with('teacher')->where('status', '!=', 'cancelled')->orderBy('name')->get();
        $class = $classId ? $classes->firstWhere('id', (int) $classId) : $classes->first();
        abort_if($classId && ! $class, 403, 'Bạn không phụ trách lớp này.');

        $types = ['video', 'vocabulary', 'workbook', 'extra_book', 'bgd_book', 'quiz', 'pronunciation'];
        $activeTab = in_array($request->query('type'), $types, true) ? $request->query('type') : 'video';

        // Chỉ bài nộp thật của học viên thuộc lớp đang chọn (danh sách lớp gồm cả học viên liên kết).
        $studentIds = $class ? $class->roster()->pluck('id') : collect();

        // Tab "Phát âm": bản ghi âm học viên nộp, chờ giáo viên chấm; các tab khác lọc theo loại bài tập.
        $screenKey = $activeTab === 'pronunciation'
            ? '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am'
            : '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap';
        $submissions = $studentIds->isEmpty()
            ? collect()
            : AcademicRecord::where('screen_key', $screenKey)
                ->whereIn('data->student_id', $studentIds->map(fn ($id) => (string) $id))
                ->when($activeTab !== 'pronunciation', fn ($q) => $q->where('data->homework_type', $activeTab))
                ->latest()
                ->get();

        return view('portal.teacher-submissions', compact('class', 'classes', 'activeTab', 'submissions'));
    }

    public function markSubmission(Request $request, $id)
    {
        $record = AcademicRecord::findOrFail($id);
        $isPronunciation = $record->screen_key === '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am';
        abort_unless($isPronunciation || $record->screen_key === '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap', 404);
        $student = Student::findOrFail(data_get($record->data, 'student_id'));
        abort_unless($this->teacherClassesQuery()->whereIn('id', $student->activeClassIds())->exists(), 404);

        $request->validate([
            // Bài phát âm bắt buộc giáo viên nhập điểm thật (thang 100).
            'score' => [$isPronunciation ? 'required' : 'nullable', 'string', 'max:20'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $record->data ?? [];
        $data['reviewed_at'] = now()->format('d/m/Y H:i');
        $data['reviewer_id'] = Auth::id();
        // Không tự điền điểm/nhận xét mẫu: chỉ lưu những gì giáo viên nhập.
        $data['score'] = filled($request->input('score')) ? trim((string) $request->input('score')) : null;
        $data['feedback'] = filled($request->input('feedback')) ? $request->input('feedback') : null;

        $record->status = 'reviewed';
        $record->data = $data;
        $record->save();

        return back()->with('success', 'Đã chấm điểm và gửi phản hồi bài nộp thành công!');
    }
}
