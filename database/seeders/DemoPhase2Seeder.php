<?php

namespace Database\Seeders;

use App\Http\Controllers\HolidayController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\SyllabusController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\WorkTaskController;
use App\Models\AcademicRecord;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\CourseLevel;
use App\Models\Holiday;
use App\Models\Homework;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\FirstMonthCareService;
use App\Services\SessionScheduleService;
use App\Services\SupportListService;
use Carbon\Carbon;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu demo Phase 2 (vận hành lớp học) dựng trên lớp / học viên của DemoPhase1Seeder:
 * giáo trình → chặng → unit → buổi gắn trình độ; mở chặng cho lớp; điểm danh theo buổi (vắng → danh sách bổ trợ),
 * nhận xét, mini test, bài tập + bài nộp; buổi bổ trợ; order đề + Big Test ở các trạng thái (chờ duyệt / đã duyệt /
 * đã gửi PH, có học viên vắng thi), một chặng đã đóng và chặng kế tự mở; ngày nghỉ thêm sau có buổi học bù;
 * việc trợ giảng; việc chăm sóc tháng đầu; sinh nhật; nhắc lịch Big Test.
 *
 * - Chạy cùng điều kiện với DemoPhase1Seeder (local / testing / staging hoặc SEED_DEMO=true).
 * - Idempotent: giáo trình theo mã DEMO-SYL-*, luồng mỗi lớp chỉ chạy khi lớp chưa từng được mở chặng, ngày nghỉ theo
 *   mã HOL-DEMO2-*, việc TA theo mô tả đánh dấu, chăm sóc tháng đầu / sinh nhật / nhắc Big Test vốn idempotent.
 * - Đi qua controller / service thật: SyllabusController (mở chặng, tạo & duyệt Big Test, duyệt order, nhập / duyệt
 *   kết quả, gửi Zalo — ép chế độ sandbox), TeacherPortalController (điểm danh, nhận xét, mini test, bài tập, order đề),
 *   StudentPortalController (nộp / chấm bài), WorkTaskController (xếp & hoàn thành buổi bổ trợ, giao việc TA),
 *   HolidayController (thêm ngày nghỉ → hủy + xếp bù), FirstMonthCareService, lệnh sinh nhật / nhắc Big Test.
 */
class DemoPhase2Seeder extends Seeder
{
    use InvokesControllersAsUser;

    private const BRANCH_STAFF = [
        'CG' => [
            'academic' => 'nva@menglish.edu.vn',
            'teacher' => 'nguyenvanan@menglish.edu.vn',
            'assistant' => 'ta.tuan@menglish.edu.vn',
            'foreign' => 'gv.native1@menglish.edu.vn',
            'student_login' => ['hocvien1@menglish.edu.vn' => 'HV-DEMO-CG-01', 'hocvien2@menglish.edu.vn' => 'HV-DEMO-CG-08'],
        ],
        'BD' => [
            'academic' => 'giaovu2@menglish.edu.vn',
            'teacher' => 'gv.cohuu2@menglish.edu.vn',
            'assistant' => 'ta.yen@menglish.edu.vn',
            'foreign' => null,
            'student_login' => ['hocvien3@menglish.edu.vn' => 'HV-DEMO-BD-01'],
        ],
    ];

    /**
     * Giáo trình demo: mã => [tiêu đề, mã trình độ, [chặng => [unit => [buổi...]]]].
     */
    private const CURRICULA = [
        'DEMO-SYL-STARTERS' => ['Cambridge Starters (demo)', 'DEMO-STARTERS', [
            ['Chặng 1: Làm quen', 'Big Test chặng 1 — Starters', [
                'Hello & Classroom' => ['Chào hỏi, tên, tuổi', 'Đồ vật lớp học', 'Màu sắc & số 1–10'],
                'My Family' => ['Thành viên gia đình', 'Tính từ miêu tả người', 'Ôn tập Unit 1–2'],
            ]],
            ['Chặng 2: Mở rộng', 'Big Test chặng 2 — Starters', [
                'Animals' => ['Con vật nuôi', 'Con vật trong vườn thú', 'Can / can\'t'],
                'Food & Drinks' => ['Đồ ăn, đồ uống', 'I like / I don\'t like', 'Ôn tập Unit 3–4'],
            ]],
            ['Chặng 3: Luyện đề Starters', 'Big Test cuối khóa — Starters', [
                'Listening practice' => ['Listening Part 1–2', 'Listening Part 3–4', 'Mock Listening'],
                'Reading & Writing practice' => ['R&W Part 1–3', 'R&W Part 4–5', 'Mock test tổng hợp'],
            ]],
        ]],
        'DEMO-SYL-MOVERS' => ['Cambridge Movers (demo)', 'DEMO-MOVERS', [
            ['Chặng 1: Nền tảng Movers', 'Big Test chặng 1 — Movers', [
                'Daily routines' => ['Hoạt động hằng ngày', 'Thì hiện tại đơn', 'Giờ giấc'],
                'Places in town' => ['Địa điểm trong thành phố', 'Chỉ đường', 'Ôn tập Unit 1–2'],
            ]],
            ['Chặng 2: Luyện đề Movers', 'Big Test cuối khóa — Movers', [
                'Past simple' => ['Quá khứ đơn (động từ thường)', 'Quá khứ đơn (bất quy tắc)', 'Kể chuyện cuối tuần'],
                'Mock tests' => ['Listening mock', 'R&W mock', 'Speaking mock'],
            ]],
        ]],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    private User $admin;

    private User $lead;

    private string $branchCode;

    public function run(): void
    {
        if (! User::where('email', 'academiclead@menglish.edu.vn')->exists() || ! ClassModel::where('code', 'like', 'DEMO-%')->exists()) {
            $this->command?->warn('DemoPhase2Seeder: chưa có tài khoản UserSeeder / lớp DemoPhase1Seeder — bỏ qua.');

            return;
        }

        $this->admin = User::where('email', 'admin@menglish.edu.vn')->firstOrFail();
        $this->lead = User::where('email', 'academiclead@menglish.edu.vn')->firstOrFail();

        $originalRequest = app('request');
        $originalZaloMode = config('services.zalo.mode');
        // Demo không bao giờ gửi Zalo thật: ZaloZnsService ở chế độ sandbox chỉ ghi log và trả về thành công.
        config(['services.zalo.mode' => 'sandbox']);
        try {
            $this->seedCurricula();
            foreach (self::BRANCH_STAFF as $code => $emails) {
                $this->branchCode = $code;
                $this->staff = collect($emails)->except('student_login')->filter()
                    ->map(fn (string $email) => User::where('email', $email)->firstOrFail())->all();
                $this->linkStudentLogins($emails['student_login']);
                $this->seedBranch($code);
            }
            $this->seedFirstMonthCare();
            $this->seedBirthdays();
            Artisan::call('bigtests:remind-upcoming');
        } finally {
            config(['services.zalo.mode' => $originalZaloMode]);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }

        $this->printSummary();
    }

    // ── Giáo trình → Chặng → Unit → Buổi, gắn Trình độ ─────────────────────────────

    private function seedCurricula(): void
    {
        foreach (self::CURRICULA as $code => [$title, $levelCode, $stages]) {
            if (SyllabusCurriculum::where('code', $code)->exists()) {
                continue;
            }
            DB::transaction(function () use ($code, $title, $levelCode, $stages) {
                $curriculum = SyllabusCurriculum::create([
                    'code' => $code, 'title' => $title, 'version' => 'v1', 'stage_name' => $stages[0][0],
                    'description' => 'Giáo trình demo Phase 2 (Giáo trình → Chặng → Unit → Buổi).',
                ]);
                $unitNo = 1;
                $sessionNo = 1;
                foreach ($stages as $i => [$stageName, $bigTestTitle, $units]) {
                    $stage = $i === 0
                        ? $curriculum->stages()->firstOrFail()
                        : SyllabusStage::create(['curriculum_id' => $curriculum->id, 'position' => $i + 1, 'name' => $stageName]);
                    $stage->update(['big_test_title' => $bigTestTitle, 'description' => "Mục tiêu {$stageName}."]);
                    foreach ($units as $unitTitle => $lessons) {
                        $unit = SyllabusUnit::create([
                            'curriculum_id' => $curriculum->id, 'stage_id' => $stage->id, 'unit_number' => $unitNo++, 'title' => $unitTitle,
                        ]);
                        foreach ($lessons as $lessonTitle) {
                            SyllabusLesson::create([
                                'unit_id' => $unit->id, 'session_no' => $sessionNo++, 'title' => $lessonTitle,
                                'objectives' => "HV nắm được: {$lessonTitle}.",
                                'homework_guide' => 'Làm Workbook trang tương ứng, quay video đọc từ vựng.',
                            ]);
                        }
                    }
                }
                CourseLevel::where('code', $levelCode)->update(['syllabus_curriculum_id' => $curriculum->id]);
            });
        }
    }

    // ── Theo chi nhánh ──────────────────────────────────────────────────────────

    private function linkStudentLogins(array $logins): void
    {
        foreach ($logins as $email => $studentCode) {
            $user = User::where('email', $email)->first();
            $student = Student::where('code', $studentCode)->first();
            if ($user && $student && ! $student->user_id && ! Student::where('user_id', $user->id)->exists()) {
                $student->update(['user_id' => $user->id, 'email' => $student->email ?: $email]);
            }
        }
    }

    private function seedBranch(string $code): void
    {
        $fam1 = ClassModel::where('code', "DEMO-{$code}-FAM1")->first();
        $fam0 = ClassModel::where('code', "DEMO-{$code}-FAM0")->first();
        if (! $fam1 || ! $fam0) {
            return;
        }

        if ($code === 'CG') {
            $this->assignForeignTeacher($fam1);
        }

        // Mỗi lớp: luồng chỉ chạy lần đầu (lớp chưa từng được mở chặng).
        foreach ([$fam1, $fam0] as $class) {
            if (SyllabusAssignment::where('class_id', $class->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($class, $code) {
                $this->openStage($class);
                $this->seedTeaching($class);
                match ([$code, $class->code === "DEMO-{$code}-FAM1"]) {
                    ['CG', true] => $this->bigTestSentAndStageAdvanced($class),
                    ['CG', false] => $this->bigTestApproved($class),
                    ['BD', true] => $this->bigTestPendingReview($class),
                    ['BD', false] => $this->bigTestUpcoming($class),
                };
                $this->seedSupportSessions($class);
            });
        }

        $this->seedRescheduledHoliday($fam1);
        $this->seedAssistantTasks($fam1);
    }

    /** GVNN cho lớp FAM 1 Cầu Giấy (buổi sắp tới), chỉ khi không trùng lịch. */
    private function assignForeignTeacher(ClassModel $class): void
    {
        $foreign = $this->staff['foreign'] ?? null;
        if (! $foreign || $class->foreign_teacher_id) {
            return;
        }
        $future = $class->sessions()->where('status', 'scheduled')->whereDate('date', '>=', today())->get();
        $candidate = $future->map(fn (ClassSession $s) => [
            'date' => $s->date->toDateString(), 'start' => $s->start_time->format('H:i'), 'end' => $s->end_time->format('H:i'), 'room' => $s->room,
        ])->all();
        if (app(SessionScheduleService::class)->findConflict($candidate, $class->branch_id, [$foreign->id], null, $class->id)) {
            return;
        }
        $class->update(['foreign_teacher_id' => $foreign->id]);
        ClassSession::whereIn('id', $future->modelKeys())->update(['foreign_teacher_id' => $foreign->id]);
    }

    /** Học thuật mở chặng đầu cho lớp (giáo trình theo trình độ của khóa), lùi ngày mở về ngày khai giảng. */
    private function openStage(ClassModel $class): void
    {
        $this->asUser($this->lead, SyllabusController::class, 'storeAssignment', ['class_id' => $class->id]);
        SyllabusAssignment::open()->where('class_id', $class->id)->update(['opened_at' => $class->start_date->copy()->setTime(8, 0)]);
    }

    /** Buổi đã dạy: điểm danh (vắng → bổ trợ), nhận xét, mini test, bài tập + bài nộp. */
    private function seedTeaching(ClassModel $class): void
    {
        $teacher = $this->staff['teacher'];
        $roster = $class->rosterStudents()->values();
        $past = $this->pastSessions($class);
        // Để lại buổi gần nhất chưa điểm danh (demo "điểm danh bù"); điểm danh tối đa 6 buổi trước đó.
        $toMark = $past->slice(max(0, $past->count() - 7), 6)->values();

        foreach ($toMark as $i => $session) {
            $statuses = [];
            $notes = [];
            foreach ($roster as $j => $student) {
                $statuses[$student->id] = match (true) {
                    ($i + $j) % 9 === 4 => 'absent',
                    ($i * 3 + $j) % 13 === 7 => 'excused',
                    ($i + 2 * $j) % 7 === 3 => 'late',
                    default => 'present',
                };
                if ($statuses[$student->id] === 'excused') {
                    $notes[$student->id] = 'PH xin nghỉ (ốm)';
                }
            }
            // Học vụ điểm danh thay GV ở 1 buổi (recorded_by = Học vụ).
            $actor = $i === 1 ? $this->staff['academic'] : $teacher;
            $this->asUser($actor, TeacherPortalController::class, 'attendanceStore', [
                'class_session_id' => $session->id, 'status' => $statuses, 'note' => $notes,
            ], ['classId' => $class->id]);
        }
        // Điểm danh cũ hơn 3 ngày đã được Học vụ rà soát (cổng HV tính chuyên cần theo điểm danh đã duyệt).
        StudentAttendance::where('class_id', $class->id)->whereDate('session_date', '<', today()->subDays(3))
            ->update(['review_status' => 'approved', 'reviewed_by' => $this->staff['academic']->id, 'reviewed_at' => now()]);

        // Nhận xét buổi học: buổi hôm nay qua màn GV; các buổi đã điểm danh trước đó ghi cùng định dạng.
        $remark = fn (int $k) => $roster->mapWithKeys(fn (Student $s, int $j) => [$s->id => [
            'monsters' => '+'.(($j + $k) % 4 + 2),
            'grammar' => ['Tốt', 'Khá', 'Cần cố gắng'][($j + $k) % 3],
            'attitude' => ['Hăng hái', 'Tập trung', 'Hơi mất tập trung'][($j * 2 + $k) % 3],
            'result' => ['Đạt mục tiêu buổi', 'Đạt 8/10 bài tập', 'Cần ôn lại từ vựng'][($j + 2 * $k) % 3],
            'comment' => 'Nhận xét buổi '.($k + 1).' cho '.trim(str_replace('#', '', $s->name)).'.',
        ]])->all();
        foreach ($toMark->take(-2) as $k => $session) {
            AcademicRecord::updateOrCreate(
                ['module' => 'teacher_remarks', 'record_code' => TeacherPortalController::remarkRecordCode($session)],
                ['screen_key' => TeacherPortalController::REMARKS_SCREEN_KEY, 'title' => "Nhận xét lớp {$class->id} ngày ".$session->date->toDateString(),
                    'status' => 'completed', 'user_id' => $teacher->id, 'data' => $remark($k)]
            );
        }
        $this->asUser($teacher, TeacherPortalController::class, 'remarksStore', ['remarks' => $remark(5)], ['classId' => $class->id]);

        // Mini test (thang 10): học viên dưới 7 tự vào danh sách bổ trợ.
        foreach ($toMark->filter(fn ($s, $i) => $i % 3 === 2)->values() as $k => $session) {
            $scores = $roster->mapWithKeys(fn (Student $s, int $j) => [$s->id => [9, 8.5, 6, 7.5, 5.5, 10, 8][($j + $k) % 7]])->all();
            $this->asUser($teacher, TeacherPortalController::class, 'scoresStore', [
                'name' => 'Mini Test Unit '.($k + 1), 'test_date' => $session->date->toDateString(), 'max_score' => 10, 'score' => $scores,
            ], ['classId' => $class->id]);
        }

        // Bài tập về nhà + bài nộp (HV có tài khoản tự nộp; còn lại Học vụ nộp hộ từ ảnh PH gửi) + GV chấm 1 bài.
        foreach (['Workbook Unit 1 trang 4–5' => -3, 'Quay video đọc từ vựng Unit 2' => 4] as $title => $dueOffset) {
            $this->asUser($teacher, TeacherPortalController::class, 'homeworkStore', [
                'title' => $title, 'description' => 'Hoàn thành và nộp trên Cổng học viên.', 'due_date' => today()->addDays($dueOffset)->toDateString(),
            ], ['classId' => $class->id]);
        }
        foreach ($roster->take(3) as $j => $student) {
            $submitter = $student->user_id ? User::find($student->user_id) : $this->staff['academic'];
            $this->asUser($submitter, StudentPortalController::class, 'submitHomework', [
                'student_id' => $student->id, 'homework_type' => ['workbook', 'video', 'vocabulary'][$j], 'notes' => 'Con đã làm xong bài.',
            ]);
        }
        $submission = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap')
            ->where('data->student_id', (string) $roster->first()->id)->latest('id')->first();
        if ($submission) {
            $this->asUser($teacher, StudentPortalController::class, 'markSubmission', ['score' => '9/10', 'feedback' => 'Làm bài cẩn thận, chữ đẹp.'], ['id' => $submission->id]);
        }
    }

    /** CG FAM 1: Big Test chặng 1 đã duyệt và gửi PH (1 HV vắng thi) → chặng 1 đóng, chặng 2 tự mở; order chặng 2 chờ duyệt. */
    private function bigTestSentAndStageAdvanced(ClassModel $class): void
    {
        $past = $this->pastSessions($class);
        $examSession = $past->get(max(0, $past->count() - 8)) ?? $past->first();
        $test = $this->scheduleBigTest($class, $examSession->date->copy()->setTime(17, 30), 'approved');
        $this->enterResults($test, $class, absentIndex: 2);
        $this->asUser($this->lead, SyllabusController::class, 'approveBigTestResults', [], ['id' => $test->id]);
        $this->asUser($this->lead, SyllabusController::class, 'sendZaloResults', [], ['id' => $test->id]);

        // Lùi mốc đóng / mở chặng về sau ngày thi (dữ liệu demo tạo cùng lúc).
        $switchAt = $examSession->date->copy()->addDay()->setTime(9, 0);
        SyllabusAssignment::where('class_id', $class->id)->where('status', SyllabusAssignment::STATUS_CLOSED)->update(['closed_at' => $switchAt]);
        SyllabusAssignment::open()->where('class_id', $class->id)->update(['opened_at' => $switchAt]);
        BigTestResult::where('big_test_id', $test->id)->update(['notified_at' => $switchAt]);

        $open = SyllabusAssignment::open()->where('class_id', $class->id)->first();
        if ($open) {
            $this->asUser($this->staff['teacher'], TeacherPortalController::class, 'submitOrderTest', [
                'stage_name' => $open->stage_name, 'test_type' => 'big', 'exam_date' => today()->addDays(6)->toDateString(),
                'note' => 'Đề cuối chặng 2, cần thêm phần Speaking.',
            ], ['classId' => $class->id]);
        }
    }

    /** CG FAM 0: Big Test chặng 1 đã chấm và Học thuật đã duyệt, chưa gửi PH (chặng vẫn mở). */
    private function bigTestApproved(ClassModel $class): void
    {
        $examSession = $this->pastSessions($class)->last();
        $test = $this->scheduleBigTest($class, $examSession->date->copy()->setTime(8, 0), 'approved');
        $this->enterResults($test, $class, absentIndex: 1);
        $this->asUser($this->lead, SyllabusController::class, 'approveBigTestResults', [], ['id' => $test->id]);
    }

    /** BD FAM 1: Big Test vừa thi, GV đã nhập kết quả (có HV vắng thi) — chờ Học thuật duyệt. */
    private function bigTestPendingReview(ClassModel $class): void
    {
        $examSession = $this->pastSessions($class)->last();
        $test = $this->scheduleBigTest($class, $examSession->date->copy()->setTime(17, 30), 'approved');
        $this->enterResults($test, $class, absentIndex: 0);
    }

    /** BD FAM 0: order đã duyệt kèm link đề, đợt thi trong 5 ngày tới (nhắc lịch 7 ngày). Thêm 1 order bị từ chối. */
    private function bigTestUpcoming(ClassModel $class): void
    {
        $examAt = today()->addDays(5)->setTime(8, 0);
        $this->scheduleBigTest($class, $examAt, 'approved');

        $this->asUser($this->staff['teacher'], TeacherPortalController::class, 'submitOrderTest', [
            'stage_name' => 'Mini test Unit 2', 'test_type' => 'mini', 'exam_date' => today()->addDays(2)->toDateString(), 'note' => 'Đề 15 phút.',
        ], ['classId' => $class->id]);
        $order = BigTestOrder::where('class_id', $class->id)->where('status', 'pending')->latest('id')->first();
        $this->asUser($this->lead, SyllabusController::class, 'rejectBigTestOrder', [
            'rejection_reason' => 'Dùng đề mini test có sẵn trong kho tài liệu chặng 1.',
        ], ['id' => $order->id]);
    }

    /**
     * GV order đề → Học thuật tạo đợt thi (tự gắn chặng đang mở) → duyệt order kèm link + gắn đợt thi (đợt thi được
     * phân phối). Đợt thi đã qua: order lưu thẳng (màn order chỉ nhận ngày thi từ hôm nay), ngày tạo lùi về trước.
     */
    private function scheduleBigTest(ClassModel $class, Carbon $examAt, string $orderStatus): BigTest
    {
        $open = SyllabusAssignment::open()->where('class_id', $class->id)->firstOrFail();
        $teacher = $this->staff['teacher'];
        $title = ($open->stage?->big_test_title ?: 'Big Test '.$open->stage_name).' · '.trim(str_replace('#', '', $class->name));

        if ($examAt->isFuture()) {
            $this->asUser($teacher, TeacherPortalController::class, 'submitOrderTest', [
                'stage_name' => $open->stage_name, 'test_type' => 'big', 'exam_date' => $examAt->toDateString(), 'note' => 'Đề Big Test cuối chặng.',
            ], ['classId' => $class->id]);
            $order = BigTestOrder::where('class_id', $class->id)->where('status', 'pending')->latest('id')->firstOrFail();
        } else {
            $order = BigTestOrder::create([
                'code' => 'ORDTEST-'.strtoupper(substr(md5($class->code.$examAt), 0, 6)),
                'class_id' => $class->id, 'teacher_id' => $teacher->id, 'stage_name' => $open->stage_name, 'test_type' => 'big',
                'exam_date' => $examAt->toDateString(), 'due_date' => $examAt->copy()->subDays(BigTestOrder::LEAD_DAYS)->toDateString(),
                'note' => 'Đề Big Test cuối chặng.', 'status' => 'pending',
            ]);
            $order->forceFill(['created_at' => $examAt->copy()->subDays(10), 'updated_at' => $examAt->copy()->subDays(10)])->saveQuietly();
        }

        $this->asUser($this->lead, SyllabusController::class, 'storeBigTest', [
            'title' => $title, 'class_id' => $class->id, 'test_type' => 'stage_end',
            'scheduled_at' => $examAt->format('Y-m-d H:i'), 'room' => $class->room ?: 'P101',
        ]);
        $test = BigTest::where('class_id', $class->id)->where('title', $title)->latest('id')->firstOrFail();

        if ($orderStatus === 'approved') {
            $this->asUser($this->lead, SyllabusController::class, 'approveBigTestOrder', [
                'test_link' => 'https://drive.google.com/demo-de/'.strtolower($test->code), 'big_test_id' => $test->id,
            ], ['id' => $order->id]);
        }

        return $test->fresh();
    }

    /** GV nhập kết quả Big Test (4 kỹ năng, 1 học viên vắng thi, có học viên dưới 7 → bổ trợ). */
    private function enterResults(BigTest $test, ClassModel $class, int $absentIndex): void
    {
        $bands = [[8, 8.5, 7.5, 8], [6, 6.5, 5.5, 6], [9, 9, 8.5, 9.5], [7, 7.5, 7, 7.5], [5.5, 6, 6.5, 5], [8.5, 8, 9, 8], [7.5, 7, 8, 8]];
        $rows = $class->rosterStudents()->values()->map(function (Student $s, int $j) use ($absentIndex, $bands) {
            if ($j === $absentIndex) {
                return ['student_id' => $s->id, 'is_absent' => 1, 'progress_note' => 'Vắng thi (ốm) — thi bù theo lịch Học vụ.'];
            }
            [$l, $r, $w, $sp] = $bands[$j % count($bands)];

            return ['student_id' => $s->id, 'listening_score' => $l, 'reading_score' => $r, 'writing_score' => $w, 'speaking_score' => $sp,
                'progress_note' => $l >= 7 ? 'Tiến bộ rõ ở kỹ năng Nghe.' : 'Cần luyện thêm Đọc & Viết.'];
        })->all();

        $this->asUser($this->staff['teacher'], SyllabusController::class, 'storeBigTestResults', ['results' => $rows], ['id' => $test->id]);
    }

    /** Học vụ xếp buổi bổ trợ từ danh sách (vắng học): 1 buổi đã dạy xong (TA hoàn thành) + 1 buổi sắp tới. */
    private function seedSupportSessions(ClassModel $class): void
    {
        $items = ClassReportStudentSupport::where('class_id', $class->id)->where('source', SupportListService::SOURCE_ATTENDANCE)
            ->whereDoesntHave('supportSession')->orderBy('id')->take(2)->get();
        foreach ($items as $k => $item) {
            $date = $k === 0 ? today()->subDays(2) : today()->addDay();
            $this->asUser($this->staff['academic'], WorkTaskController::class, 'storeSupportSession', [
                'class_report_student_support_id' => $item->id, 'class_id' => $class->id, 'student_id' => $item->student_id,
                'teacher_id' => $this->staff['assistant']->id, 'session_date' => $date->toDateString(),
                'start_time' => $class->code === "DEMO-{$this->branchCode}-FAM0" ? '10:00' : '15:30',
                'end_time' => $class->code === "DEMO-{$this->branchCode}-FAM0" ? '11:00' : '16:30',
                'room' => 'Phòng bổ trợ', 'reason' => $item->reason,
            ]);
            if ($k === 0) {
                $support = SupportSession::where('class_report_student_support_id', $item->id)->firstOrFail();
                $this->asUser($this->staff['assistant'], WorkTaskController::class, 'completeSupportSession', [
                    'completion_note' => 'Đã dạy bù nội dung buổi vắng, HV làm được bài tập.',
                ], ['id' => $support->id]);
            }
        }
    }

    /** Ngày nghỉ thêm sau (Admin) rơi vào 1 buổi sắp tới của lớp FAM 1 → buổi bị hủy, tự xếp buổi học bù cuối lịch. */
    private function seedRescheduledHoliday(ClassModel $class): void
    {
        $code = "HOL-DEMO2-{$this->branchCode}";
        if (Holiday::withTrashed()->where('code', $code)->exists()) {
            return;
        }
        $session = $class->sessions()->where('type', ClassSession::TYPE_REGULAR)->where('status', 'scheduled')
            ->whereDate('date', '>=', today()->addDays(10))->orderBy('date')->first();
        if (! $session) {
            return;
        }
        $this->asUser($this->admin, HolidayController::class, 'store', [
            'code' => $code, 'name' => '# Nghỉ đột xuất (sự kiện cơ sở '.$this->branchCode.')',
            'start_date' => $session->date->toDateString(), 'end_date' => $session->date->toDateString(),
            'is_system_wide' => 0, 'branch_ids' => [$class->branch_id],
        ]);
    }

    /** Học vụ giao việc trực ca cho trợ giảng (hôm qua + hôm nay); TA hoàn thành 1 việc có ảnh, 1 việc chờ xác nhận. */
    private function seedAssistantTasks(ClassModel $class): void
    {
        $assistant = $this->staff['assistant'];
        $marker = 'Demo Phase 2 — việc trực ca';
        if (WorkTask::where('assignee_id', $assistant->id)->where('description', 'like', '%'.$marker.'%')->exists()) {
            return;
        }
        $lastId = (int) WorkTask::withTrashed()->max('id');
        foreach ([today()->subDay(), today()] as $date) {
            $this->asUser($this->staff['academic'], WorkTaskController::class, 'taAssignStore', [
                'assistant_id' => $assistant->id, 'assign_date' => $date->toDateString(), 'branch_id' => $class->branch_id,
                'tasks' => [
                    ['category' => 'before', 'content' => 'Chuẩn bị phòng, máy chiếu, in phiếu bài tập', 'attach_class' => '1', 'class_id' => $class->id, 'session' => 'Ca chiều'],
                    ['category' => 'during', 'content' => 'Hỗ trợ GV kiểm tra bài tập về nhà, ghi nhận HV chưa nộp', 'attach_class' => '1', 'class_id' => $class->id, 'session' => 'Ca chiều'],
                    ['category' => 'after', 'content' => 'Nhắn Zalo nhóm lớp nội dung buổi học + bài tập', 'attach_class' => '1', 'class_id' => $class->id, 'session' => 'Ca chiều'],
                ],
            ]);
        }
        $created = WorkTask::where('id', '>', $lastId)->where('assignee_id', $assistant->id)->orderBy('id')->get();
        foreach ($created as $task) {
            $task->update(['description' => $task->description.' · '.$marker]);
        }
        $tasks = $created->filter(fn (WorkTask $task) => $task->due_date->isSameDay(today()->subDay()))->values();
        if ($first = $tasks->get(0)) {
            $this->asUser($assistant, WorkTaskController::class, 'completeTask', [
                'note' => 'Đã chuẩn bị xong.', 'proof_image_url' => 'https://drive.google.com/demo-anh/phong-hoc.jpg',
            ], ['id' => $first->id]);
        }
        if ($second = $tasks->get(1)) {
            $this->asUser($assistant, WorkTaskController::class, 'completeTask', ['note' => '2 HV chưa nộp bài, đã báo GV.'], ['id' => $second->id]);
        }
    }

    // ── Toàn hệ thống ───────────────────────────────────────────────────────────

    /** Chăm sóc tháng đầu: chạy lại lệnh hằng ngày cho 14 ngày qua (như cron đã chạy) — idempotent. */
    private function seedFirstMonthCare(): void
    {
        $care = app(FirstMonthCareService::class);
        for ($d = 14; $d >= 0; $d--) {
            $care->run(today()->subDays($d));
        }
    }

    /** Sinh nhật: mỗi chi nhánh 1 học viên sinh nhật hôm nay, 1 học viên sinh nhật tuần sau. */
    private function seedBirthdays(): void
    {
        foreach (['CG', 'BD'] as $code) {
            foreach (['02' => 0, '03' => 7] as $suffix => $offset) {
                Student::where('code', "HV-DEMO-{$code}-{$suffix}")->whereNull('dob')
                    ->update(['dob' => today()->addDays($offset)->setYear(2017)->toDateString()]);
            }
        }
        Artisan::call('students:send-birthday-notifications');
    }

    /** Buổi chính khóa / học bù đã qua (không hủy), theo ngày. */
    private function pastSessions(ClassModel $class): Collection
    {
        return $class->sessions()->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])
            ->where('status', '!=', 'cancelled')->whereDate('date', '<', today())
            ->orderBy('date')->orderBy('start_time')->get()->values();
    }

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }
        $demoClassIds = ClassModel::where('code', 'like', 'DEMO-%')->pluck('id');
        $rows = [
            ['syllabus_curriculums (DEMO-SYL-*)', SyllabusCurriculum::where('code', 'like', 'DEMO-SYL-%')->count()],
            ['syllabus_stages / units / lessons', SyllabusStage::whereHas('curriculum', fn ($q) => $q->where('code', 'like', 'DEMO-SYL-%'))->count()
                .' / '.SyllabusUnit::whereHas('curriculum', fn ($q) => $q->where('code', 'like', 'DEMO-SYL-%'))->count()
                .' / '.SyllabusLesson::whereHas('curriculum', fn ($q) => $q->where('code', 'like', 'DEMO-SYL-%'))->count()],
            ['syllabus_assignments (mở / đóng)', SyllabusAssignment::open()->whereIn('class_id', $demoClassIds)->count().' / '
                .SyllabusAssignment::where('status', SyllabusAssignment::STATUS_CLOSED)->whereIn('class_id', $demoClassIds)->count()],
            ['student_attendances (vắng / có phép)', StudentAttendance::whereIn('class_id', $demoClassIds)->count().' ('
                .StudentAttendance::whereIn('class_id', $demoClassIds)->where('status', 'absent')->count().' / '
                .StudentAttendance::whereIn('class_id', $demoClassIds)->where('status', 'excused')->count().')'],
            ['nhận xét buổi học', AcademicRecord::where('module', 'teacher_remarks')->count()],
            ['mini_test_scores', MiniTestScore::whereIn('class_id', $demoClassIds)->count()],
            ['homework / bài nộp', Homework::whereIn('class_id', $demoClassIds)->count().' / '
                .AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap')->count()],
            ['danh sách bổ trợ (theo nguồn)', ClassReportStudentSupport::whereIn('class_id', $demoClassIds)->selectRaw('source, count(*) c')->groupBy('source')
                ->pluck('c', 'source')->map(fn ($c, $s) => (SupportListService::SOURCE_LABELS[$s] ?? $s).": {$c}")->implode(', ')],
            ['support_sessions', SupportSession::whereIn('class_id', $demoClassIds)->count()],
            ['big_test_orders (theo trạng thái)', BigTestOrder::whereIn('class_id', $demoClassIds)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')
                ->map(fn ($c, $s) => "{$s}: {$c}")->implode(', ')],
            ['big_tests', BigTest::whereIn('class_id', $demoClassIds)->count()],
            ['big_test_results (theo trạng thái, vắng)', BigTestResult::whereHas('bigTest', fn ($q) => $q->whereIn('class_id', $demoClassIds))
                ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c, $s) => "{$s}: {$c}")->implode(', ')
                .'; vắng: '.BigTestResult::whereHas('bigTest', fn ($q) => $q->whereIn('class_id', $demoClassIds))->where('is_absent', true)->count()],
            ['buổi hủy do nghỉ lễ / buổi học bù', ClassSession::whereIn('class_id', $demoClassIds)->whereNotNull('holiday_id')->count().' / '
                .ClassSession::whereIn('class_id', $demoClassIds)->where('type', ClassSession::TYPE_MAKEUP)->count()],
            ['việc TA (demo) / chăm sóc tháng đầu', WorkTask::where('description', 'like', '%Demo Phase 2%')->count().' / '
                .WorkTask::whereNotNull('care_milestone')->count()],
        ];
        $this->command->table(['Phase 2', 'Số dòng'], $rows);
    }
}
