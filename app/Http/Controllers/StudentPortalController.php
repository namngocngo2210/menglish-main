<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ResolvesPortalStudent;
use App\Models\AcademicRecord;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\Homework;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\Survey;
use App\Models\SyllabusAssignment;
use App\Services\SafeUploadService;
use App\Services\SessionLessonService;
use App\Services\Tuition\PaymentReportService;
use App\Support\DataScope;
use App\Support\Money;
use App\Support\Portal\PortalNotifications;
use App\Support\TuitionPaymentHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StudentPortalController extends Controller
{
    use ResolvesPortalStudent;

    /** Đuôi file được phép khi nộp bài tập (theo nội dung file). */
    private const HOMEWORK_EXTENSIONS = [
        ...SafeUploadService::IMAGES, ...SafeUploadService::DOCUMENTS, ...SafeUploadService::VIDEO, ...SafeUploadService::AUDIO,
    ];

    /** Đuôi file bản ghi phát âm (theo nội dung file): audio + mp4 vì Safari ghi âm ra MP4. */
    private const PRONUNCIATION_EXTENSIONS = [...SafeUploadService::AUDIO, 'mp4'];

    /** Số hạng mục bài tập cố định trên màn "Học tập của tôi" (video, từ vựng, workbook...). */
    private const HOMEWORK_CATEGORY_COUNT = 6;

    /** Số ngày lịch học sắp tới hiển thị ở trang chủ học viên. */
    private const UPCOMING_DAYS = 14;

    /**
     * Quyền GHI trên hồ sơ cổng học viên (cập nhật liên hệ, nộp / xóa bài, đánh dấu đã đọc, khảo sát…): chủ hồ sơ (tài khoản
     * học viên / phụ huynh), hoặc nhân sự có quyền student.update với học viên nằm trong phạm vi dữ liệu của mình.
     * Chỉ có student.view (xem) thì không được sửa; xem dữ liệu đi qua getActiveStudent().
     */
    protected function authorizeStudent(Student $student): void
    {
        $user = Auth::user();
        abort_unless($user && (
            $student->isLinkedTo($user)
            || ($user->can('student.update') && Student::query()->visibleTo($user)->whereKey($student->id)->exists())
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
        // Chấm bài nộp: quyền homework.grade (mặc định giáo viên, trợ giảng; Admin). Lớp: lớp mình dạy / trợ giảng / GVNN;
        // phạm vi Lớp học "Toàn hệ thống" (Admin) → mọi lớp.
        abort_unless($user && $user->can('homework.grade'), 403);

        return ClassModel::query()
            ->when(! DataScope::isAll($user, 'class'), function ($query) use ($user) {
                $query->where(function ($classes) use ($user) {
                    $classes->where('teacher_id', $user->id)
                        ->orWhere('assistant_id', $user->id)
                        ->orWhere('foreign_teacher_id', $user->id);
                });
            });
    }

    /** Điểm dạng gọn: 8.50 → "8.5", 10.00 → "10". */
    private static function trimScore(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
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

            $unreadNotifsCount = PortalNotifications::unreadCount($student);

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

        return Inertia::render('Portal/AppShell', [
            ...$this->portalProps($students, $student, $student ? [
                'phone' => $student->phone,
                'status_label' => $student->status_label,
                'has_tuition' => $student->tuition !== null,
                'tuition_total' => $student->tuition?->total_amount,
            ] : []),
            'pendingHomeworksCount' => $pendingHomeworksCount,
            'submittedHomeworksCount' => $submittedHomeworksCount,
            'unreadNotifsCount' => $unreadNotifsCount,
            'pronunciationCount' => $pronunciationCount,
            'surveyCount' => $surveyCount,
            'hasFeedback' => $hasFeedback,
            'backUrl' => $request->user()?->can('system_category.manage')
                ? route('academic-system.index', ['cat' => '04_Cong_Phu_Huynh_Hoc_Sinh'])
                : null,
        ]);
    }

    // ─────────────────────────────────────────────
    // MÀN HÌNH #2: Trang chủ Phụ huynh / Học sinh
    // ─────────────────────────────────────────────
    public function studentHome(Request $request, $studentId = null)
    {
        [$students, $student] = $this->getActiveStudent($studentId);

        // Lịch sử thu học phí: mọi đợt nộp đã duyệt của mọi khoản học phí, "Thu khác" chỉ hiện tổng.
        $paymentHistory = $student ? TuitionPaymentHistory::forStudent($student) : null;
        $totalPaid = (float) ($paymentHistory['summary']['paid_amount'] ?? 0);
        $debtAmount = (float) ($paymentHistory['summary']['debt_amount'] ?? 0);
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

        $weekdays = ['', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

        return Inertia::render('Portal/Home', [
            ...$this->portalProps($students, $student, $student ? [
                'status_label' => $student->status_label,
                'dob' => $student->dob?->toDateString(),
                'phone' => $student->phone,
                'address' => $student->address,
                'notes' => $student->notes,
                'teacher_name' => $student->currentClass?->teacher?->name,
                'study_started_on' => $student->study_started_on?->format('d/m/Y'),
                'tenure_label' => $student->studyTenureLabel(),
            ] : []),
            'totalPaid' => $totalPaid,
            'debtAmount' => $debtAmount,
            'nextTermFee' => $nextTermFee,
            'paymentHistory' => $paymentHistory,
            'learningProgress' => [
                ...$learningProgress,
                'latest_big_test' => $learningProgress['latest_big_test']?->overall_score,
            ],
            'upcomingDays' => self::UPCOMING_DAYS,
            'upcomingSessions' => $upcomingSessions->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'label' => $weekdays[$s->date->isoWeekday()].', '.$s->date->format('d/m').' · '.$s->start_time?->format('H:i').'-'.$s->end_time?->format('H:i'),
                'class_name' => $s->classModel?->name,
                'room_label' => $s->roomLabel(),
                'cancelled' => $s->status === 'cancelled',
                'type' => $s->type,
            ])->values()->all(),
            'attendanceHistory' => $attendanceHistory->map(fn (StudentAttendance $a) => [
                'id' => $a->id,
                'date' => ($a->classSession?->date ?? $a->session_date)?->format('d/m/Y'),
                'class_name' => $a->classModel?->name,
                'note' => $a->note,
                'present' => in_array($a->status, ['present', 'late'], true),
                'status_label' => $a->status_label,
            ])->values()->all(),
            'bigTestResults' => $bigTestResults->map(fn (BigTestResult $r) => [
                'id' => $r->id,
                'title' => $r->bigTest?->title ?? 'Big Test',
                'is_absent' => (bool) $r->is_absent,
                'overall_score' => $r->overall_score,
                'listening_score' => $r->listening_score,
                'reading_score' => $r->reading_score,
                'writing_score' => $r->writing_score,
                'speaking_score' => $r->speaking_score,
                'progress_note' => $r->progress_note,
            ])->values()->all(),
            'studentClasses' => $studentClasses->pluck('name')->values()->all(),
        ]);
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
            'screen_key' => PaymentReportService::SCREEN_KEY,
            'module' => 'student_portal',
            'record_code' => 'YCHOCPHI-'.strtoupper(Str::random(6)),
            'title' => 'Báo đóng học phí: '.Money::format($validated['amount']).' - '.$studentName,
            'status' => PaymentReportService::STATUS_PENDING,
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
            // Không có học viên trong phạm vi → không trả bản ghi nào (không lộ dữ liệu học viên khác).
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            }, fn ($q) => $q->whereRaw('1 = 0'))
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

        // Buổi học gần đây (nhận xét của GV + phụ huynh chấm buổi), bảng điểm, bài tập mới nhất — dữ liệu thật của (các) lớp học viên.
        $remarks = [];
        $miniTests = collect();
        $bigTestResults = collect();
        $latestHomework = null;
        if ($student) {
            $classIds = $student->activeClassIds();
            $remarks = $this->recentSessionCards($student, $classIds);
            $miniTests = MiniTestScore::where('student_id', $student->id)->latest('test_date')->limit(10)->get();
            $bigTestResults = BigTestResult::with('bigTest')->where('student_id', $student->id)
                ->whereIn('status', ['approved', 'sent'])->latest('approved_at')->limit(5)->get();
            $latestHomework = Homework::with('classModel')->whereIn('class_id', $classIds)
                ->latest('due_date')->latest('id')->first();
        }

        return Inertia::render('Portal/Homework', [
            ...$this->portalProps($students, $student),
            'submissionsByType' => collect($submissionsByType)->map(fn (AcademicRecord $sub) => [
                'id' => $sub->id,
                'status' => $sub->status,
                'submitted_at' => $sub->data['submitted_at'] ?? $sub->created_at->format('d/m/Y H:i'),
                'score' => $sub->data['score'] ?? null,
                'attachment_path' => $sub->data['attachment_path'] ?? null,
                'attachment_name' => $sub->data['attachment_name'] ?? null,
                'notes' => $sub->data['notes'] ?? null,
                'feedback' => $sub->data['feedback'] ?? null,
            ])->all(),
            'completedCount' => $completedCount,
            'remarks' => $remarks,
            'feedbackCategories' => ClassSessionFeedback::PARENT_CATEGORIES,
            'miniTests' => $miniTests->map(fn (MiniTestScore $mt) => [
                'id' => $mt->id,
                'name' => $mt->name,
                'test_date' => $mt->test_date?->format('d/m/Y'),
                'score' => self::trimScore($mt->score),
                'max_score' => self::trimScore($mt->max_score),
            ])->values()->all(),
            'bigTestResults' => $bigTestResults->map(fn (BigTestResult $bt) => [
                'id' => $bt->id,
                'title' => $bt->bigTest?->title ?? 'Big Test',
                'scheduled_at' => $bt->bigTest?->scheduled_at?->format('d/m/Y') ?? '—',
                'is_absent' => (bool) $bt->is_absent,
                'overall_score' => $bt->overall_score,
            ])->values()->all(),
            'latestHomework' => $latestHomework ? [
                'title' => $latestHomework->title,
                'description' => $latestHomework->description,
                'class_name' => $latestHomework->classModel?->name,
                'due_date' => $latestHomework->due_date?->format('d/m/Y'),
            ] : null,
            // Chỉ tô cam mục chưa nộp đầu tiên khi bài GV giao sắp/đã tới hạn (≤ 2 ngày).
            'homeworkUrgent' => (bool) ($latestHomework?->due_date && $latestHomework->due_date->lte(now()->addDays(2)->endOfDay())),
        ]);
    }

    /** Số buổi gần nhất hiện ở mục "Nhận xét buổi học"; phụ huynh chấm được buổi trong khoảng RATE_DAYS ngày. */
    private const RECENT_SESSIONS = 5;

    private const RATE_DAYS = 14;

    /**
     * Các buổi học gần đây của học viên (chủ dự án 17/10/2026): nhận xét GV đã gửi (nếu có) và phiếu phụ huynh chấm buổi.
     * Buổi GV chưa nhận xét vẫn hiện để phụ huynh chấm được (vd. GV vào muộn). Buổi học viên vắng không chấm.
     *
     * @param  list<int>  $classIds
     * @return list<array<string, mixed>>
     */
    private function recentSessionCards(Student $student, array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }
        $sessions = ClassSession::with(['classModel:id,name', 'teacher:id,name'])
            ->whereIn('class_id', $classIds)
            ->whereNotIn('type', [ClassSession::TYPE_SUPPORT])
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', now()->subDays(30)->toDateString())
            ->whereDate('date', '<=', now()->toDateString())
            ->orderByDesc('date')->orderByDesc('start_time')
            ->get();
        if ($sessions->isEmpty()) {
            return [];
        }

        $codes = $sessions->flatMap(fn (ClassSession $s) => [TeacherPortalController::remarkRecordCode($s), $s->class_id.'-'.$s->date->toDateString()]);
        $records = AcademicRecord::where('module', 'teacher_remarks')->where('status', '!=', 'draft') // nhận xét "Lưu nháp" chưa hiện cho học viên
            ->whereIn('record_code', $codes->all())->get()->keyBy('record_code');
        // Buổi hôm nay chỉ hiện khi đã bắt đầu, đã điểm danh xong hoặc GV đã gửi nhận xét.
        $sessions = $sessions->filter(fn (ClassSession $s) => ! $s->date->isToday() || ! $s->start_time || $s->status === 'completed'
            || $s->date->copy()->setTimeFrom($s->start_time)->isPast()
            || $records->has(TeacherPortalController::remarkRecordCode($s)) || $records->has($s->class_id.'-'.$s->date->toDateString()))
            ->take(self::RECENT_SESSIONS)
            ->values();
        $attendance = StudentAttendance::where('student_id', $student->id)->whereIn('class_session_id', $sessions->pluck('id'))
            ->pluck('status', 'class_session_id');
        $feedback = ClassSessionFeedback::where('source', ClassSessionFeedback::SOURCE_PARENT)->where('student_id', $student->id)
            ->whereIn('class_session_id', $sessions->pluck('id'))->get()->keyBy('class_session_id');
        $linked = $student->isLinkedTo(Auth::user());
        $sessionNumbers = app(SessionLessonService::class)->sessionNumbers($sessions->pluck('class_id')->unique()->values()->all());

        return $sessions->map(function (ClassSession $s) use ($records, $attendance, $feedback, $linked, $student, $sessionNumbers) {
            $record = $records->get(TeacherPortalController::remarkRecordCode($s)) ?? $records->get($s->class_id.'-'.$s->date->toDateString());
            $remark = array_filter((array) data_get($record?->data, (string) $student->id, []), fn ($v) => filled($v));
            $mine = $feedback->get($s->id);
            $absent = in_array($attendance->get($s->id), ['absent', 'excused'], true);
            $no = $sessionNumbers[$s->id] ?? null;

            return [
                'session_id' => $s->id,
                'date' => $s->date->format('d/m/Y'),
                'label' => trim(($no ? 'Buổi '.$no.' · ' : '').$s->date->format('d/m').($s->start_time ? ' · '.$s->start_time->format('H:i') : '')),
                'class_name' => $s->classModel?->name,
                'teacher' => $s->teacher?->name,
                'remark' => $remark === [] ? null : collect(self::normalizeRemark($remark))->only(['monsters', 'grammar', 'attitude', 'result', 'comment'])->all(),
                'absent' => $absent,
                'feedback' => $mine ? [
                    'rating' => $mine->rating,
                    'categories' => $mine->categories ?? [],
                    'content' => $mine->content,
                    'editable' => $mine->parentEditable(),
                ] : null,
                'can_rate' => $linked && ! $absent && $s->date->gte(today()->subDays(self::RATE_DAYS)) && (! $mine || $mine->parentEditable()),
            ];
        })->all();
    }

    /**
     * Phụ huynh / học viên chấm một buổi học (1–5 sao, góp ý về…, nội dung). Mỗi học sinh một phiếu / buổi, sửa được trong 48 giờ.
     * Chỉ tài khoản liên kết với học viên gửi được (nhân sự xem hộ không chấm thay phụ huynh).
     */
    public function submitSessionFeedback(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'class_session_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::in(array_keys(ClassSessionFeedback::PARENT_CATEGORIES))],
            'content' => ['nullable', 'string', 'max:2000'],
        ], ['rating.required' => 'Vui lòng chọn số sao cho buổi học.']);

        $student = Student::findOrFail($data['student_id']);
        abort_unless($student->isLinkedTo(Auth::user()), 403, 'Chỉ phụ huynh / học viên của hồ sơ này mới đánh giá được buổi học.');
        $session = ClassSession::whereIn('class_id', $student->activeClassIds())->where('status', '!=', 'cancelled')
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)->findOrFail($data['class_session_id']);
        $notStarted = $session->date->isToday() && $session->start_time && $session->date->copy()->setTimeFrom($session->start_time)->isFuture();
        abort_if($session->date->gt(today()) || $notStarted, 422, 'Buổi học chưa diễn ra.');
        abort_if($session->date->lt(today()->subDays(self::RATE_DAYS)), 422, 'Buổi học này không còn nhận đánh giá.');
        $absent = StudentAttendance::where('student_id', $student->id)->where('class_session_id', $session->id)
            ->whereIn('status', ['absent', 'excused'])->exists();
        abort_if($absent, 422, 'Học viên vắng buổi này nên không đánh giá được.');

        $existing = ClassSessionFeedback::where('source', ClassSessionFeedback::SOURCE_PARENT)->where('student_id', $student->id)
            ->where('class_session_id', $session->id)->first();
        if ($existing && ! $existing->parentEditable()) {
            return back()->withErrors(['rating' => 'Đánh giá chỉ sửa được trong '.ClassSessionFeedback::PARENT_EDIT_HOURS.' giờ sau khi gửi.']);
        }

        ClassSessionFeedback::updateOrCreate(
            ['source' => ClassSessionFeedback::SOURCE_PARENT, 'student_id' => $student->id, 'class_session_id' => $session->id],
            [
                'class_id' => $session->class_id,
                'author_id' => Auth::id(),
                'rating' => $data['rating'],
                'categories' => array_values(array_unique($data['categories'] ?? [])),
                'content' => filled($data['content'] ?? null) ? trim($data['content']) : null,
            ],
        );

        return back()->with('success', $existing ? 'Đã cập nhật đánh giá buổi học.' : 'Cảm ơn anh/chị đã đánh giá buổi học!');
    }

    /** Nhận xét theo buổi lưu Monsters (Nhóm) / (Thưởng) riêng; bản cũ chỉ có "monsters". */
    private static function normalizeRemark(array $remark): array
    {
        if (blank($remark['monsters'] ?? null)) {
            $remark['monsters'] = collect(['Nhóm' => $remark['monsters_group'] ?? null, 'Thưởng' => $remark['monsters_bonus'] ?? null])
                ->filter(fn ($v) => filled($v))
                ->map(fn ($v, $k) => $k.' '.$v)
                ->implode(' · ') ?: null;
        }

        return $remark;
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
            // Không có học viên trong phạm vi → không trả bản ghi nào (không lộ dữ liệu học viên khác).
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest()
            ->get();

        // Bài nghe mẫu: file nghe giáo viên đính kèm bài tập của các lớp học viên đang học.
        $practiceItems = $student
            ? Homework::with('classModel:id,name')
                ->whereIn('class_id', $student->activeClassIds())
                ->whereNotNull('audio_path')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (Homework $homework) => [
                    'title' => $homework->title,
                    'class_name' => $homework->classModel?->name,
                    'audio_url' => asset('storage/'.$homework->audio_path),
                ])
            : collect();

        return Inertia::render('Portal/Pronunciation', [
            ...$this->portalProps($students, $student),
            'history' => $history->map(fn (AcademicRecord $rec) => [
                'id' => $rec->id,
                'title' => $rec->title,
                'status' => $rec->status,
                'submitted_at' => $rec->data['submitted_at'] ?? $rec->created_at->format('d/m/Y H:i'),
                'duration' => $rec->data['duration'] ?? null,
                'score' => $rec->data['score'] ?? null,
                'feedback' => $rec->data['feedback'] ?? null,
                'audio_path' => $rec->data['audio_path'] ?? null,
            ])->values()->all(),
            'practiceItems' => $practiceItems->values()->all(),
        ]);
    }

    /**
     * CRUD: Nộp bản ghi âm phát âm (MH #4)
     */
    public function submitPronunciation(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'unit_title' => 'required|string|max:255',
            'duration' => 'nullable|string|max:20',
            // Bản ghi từ micro (MediaRecorder): Chrome/Edge ra webm, Safari ra mp4 (m4a).
            'audio_file' => 'required|file|max:51200|mimes:'.implode(',', self::PRONUNCIATION_EXTENSIONS), // 50MB
        ], [
            'audio_file.required' => 'Chưa có bản ghi âm. Hãy chạm micro để thu âm trước khi nộp.',
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $this->authorizeStudent($student);
        $studentName = $student->name;

        $audioPath = SafeUploadService::store($request->file('audio_file'), 'pronunciation_records', self::PRONUNCIATION_EXTENSIONS, 'audio_file');

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
                'audio_path' => '/storage/'.$audioPath,
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
        $notifications = AcademicRecord::where('screen_key', PortalNotifications::SCREEN_KEY)
            // Không có học viên trong phạm vi → không trả bản ghi nào (không lộ dữ liệu học viên khác).
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest()
            ->get();

        return Inertia::render('Portal/Notifications', [
            ...$this->portalProps($students, $student),
            'notifications' => $notifications->map(fn (AcademicRecord $notif) => [
                'id' => $notif->id,
                'title' => $notif->title,
                'content' => $notif->data['content'] ?? '',
                'time' => $notif->data['created_at'] ?? $notif->created_at->format('d/m/Y H:i'),
                'unread' => ! empty($notif->data['unread']),
                'icon' => $notif->data['icon'] ?? 'notifications',
                'bg_color' => $notif->data['bg_color'] ?? 'bg-primary-container/10',
                'text_color' => $notif->data['text_color'] ?? 'text-primary',
            ])->values()->all(),
        ]);
    }

    /**
     * CRUD: Đánh dấu tất cả thông báo là đã đọc (MH #5)
     */
    public function markNotificationsRead(Request $request)
    {
        $studentId = $request->input('student_id');
        $student = Student::findOrFail($studentId);
        $this->authorizeStudent($student);

        $query = AcademicRecord::where('screen_key', PortalNotifications::SCREEN_KEY);
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
        $this->authorizeStudentRecord($record, PortalNotifications::SCREEN_KEY);
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
        $this->authorizeStudentRecord($record, PortalNotifications::SCREEN_KEY);
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
            // Không có học viên trong phạm vi → không trả bản ghi nào (không lộ dữ liệu học viên khác).
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest()
            ->get();

        return Inertia::render('Portal/Survey', [
            ...$this->portalProps($students, $student),
            'surveys' => $surveys,
            'pastSurveys' => $pastSurveys->map(fn (AcademicRecord $ps) => [
                'id' => $ps->id,
                'title' => $ps->title,
                'rating' => $ps->data['rating'] ?? 5,
                'feedback' => $ps->data['feedback'] ?? '',
                'submitted_at' => $ps->data['submitted_at'] ?? $ps->created_at->format('d/m/Y H:i'),
            ])->values()->all(),
        ]);
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
            // Không có học viên trong phạm vi → không trả bản ghi nào (không lộ dữ liệu học viên khác).
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest()
            ->first();

        // Danh sách tất cả feedback trước đây của học viên này
        $historyFeedbacks = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')
            // Không có học viên trong phạm vi → không trả bản ghi nào (không lộ dữ liệu học viên khác).
            ->when($student, function ($q) use ($student) {
                $q->where('data->student_id', (string) $student->id);
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest()
            ->get();

        $saved = $lastFeedback?->data ?? [];

        return Inertia::render('Portal/Feedback', [
            ...$this->portalProps($students, $student),
            'lastFeedback' => $lastFeedback ? [
                'id' => $lastFeedback->id,
                'rating' => (int) ($saved['muc_do_hai_long'] ?? 0),
                'content' => (string) ($saved['noi_dung_feedback'] ?? ''),
                'fb_hoc_thuat' => ! empty($saved['fb_hoc_thuat']),
                'fb_giao_vien' => ! empty($saved['fb_giao_vien']),
                'fb_khac' => ! empty($saved['fb_khac']),
            ] : null,
            'stageName' => $stageName,
            'className' => $className,
            'showSuccess' => session()->has('success') || session('feedback_success'),
            'feedbackSuccess' => (bool) session('feedback_success'),
        ]);
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

        return Inertia::render('Portal/TeacherSubmissions', [
            'currentClass' => $class ? ['id' => $class->id, 'name' => $class->name] : null,
            'classes' => $classes->map(fn (ClassModel $c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values()->all(),
            'activeTab' => $activeTab,
            'submissions' => $submissions->map(fn (AcademicRecord $sub) => [
                'id' => $sub->id,
                'status' => $sub->status,
                'student_name' => $sub->data['student_name'] ?? null,
                'submitted_at' => $sub->data['submitted_at'] ?? $sub->created_at->format('H:i, d/m/Y'),
                'notes' => $sub->data['notes'] ?? null,
                'audio_path' => $sub->data['audio_path'] ?? null,
                'unit_title' => $sub->data['unit_title'] ?? null,
                'attachment_path' => $sub->data['attachment_path'] ?? null,
                'attachment_name' => $sub->data['attachment_name'] ?? null,
                'homework_label' => $sub->data['homework_label'] ?? null,
                'score' => $sub->data['score'] ?? null,
            ])->values()->all(),
        ]);
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
