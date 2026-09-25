<?php

namespace Database\Seeders;

use App\Http\Controllers\CrmController;
use App\Http\Controllers\PlacementTestController;
use App\Http\Controllers\TrialGuestController;
use App\Http\Controllers\TuitionController;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\Holiday;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\CrmStageService;
use App\Services\PlacementRubricService;
use App\Services\SessionScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;

/**
 * Dữ liệu demo Phase 1 (khách → test online → học thử → chốt → vào lớp / lớp chờ) cho 2 chi nhánh
 * Cầu Giấy (CG) và Ba Đình (BD), dùng tài khoản của UserSeeder.
 *
 * - Chỉ chạy ở local / testing / staging hoặc khi SEED_DEMO=true (xem DatabaseSeeder).
 * - Idempotent: khóa học / lớp / ngày nghỉ theo mã DEMO-*, học viên có sẵn theo mã HV-DEMO-*, khách theo SĐT
 *   (đã có thì bỏ qua cả luồng của khách đó). Chạy lại không tạo trùng.
 * - Đi qua service / controller thật để giữ đúng quy tắc nghiệp vụ: CrmStageService (chuyển bước + lịch sử),
 *   SessionScheduleService (sinh buổi học, bỏ ngày nghỉ), CrmController (thêm khách, hẹn test, đặt học thử,
 *   Chốt & Xếp lớp, gán lớp, xác nhận chính thức), PlacementTestController (chấm theo thang khối lớp),
 *   TrialGuestController (GV nhận xét học thử), TuitionController (Kế toán duyệt phiếu thu).
 */
class DemoPhase1Seeder extends Seeder
{
    /** Tài khoản UserSeeder theo chi nhánh. */
    private const BRANCH_STAFF = [
        'CG' => [
            'digit' => 1,
            'manager' => 'manager@menglish.edu.vn',
            'academic' => 'nva@menglish.edu.vn',
            'sales' => 'tranmaia@menglish.edu.vn',
            'accountant' => 'ketoan2@menglish.edu.vn',
            'teacher' => 'nguyenvanan@menglish.edu.vn',
            'teacher2' => 'gv.cohuu1@menglish.edu.vn',
            'assistant' => 'ta.tuan@menglish.edu.vn',
        ],
        'BD' => [
            'digit' => 2,
            'manager' => 'manager.bd@menglish.edu.vn',
            'academic' => 'giaovu2@menglish.edu.vn',
            'sales' => 'hoangthinh@menglish.edu.vn',
            'accountant' => 'ttb@menglish.edu.vn',
            'teacher' => 'gv.cohuu2@menglish.edu.vn',
            'teacher2' => 'gv.cohuu2@menglish.edu.vn',
            'assistant' => 'ta.yen@menglish.edu.vn',
        ],
    ];

    /** Điểm chấm mẫu theo khối lớp: [Nghe, Đọc & Viết, Nói, lớp chọn lại (null = theo đề xuất)]. */
    private const GRADES = [
        'TEST-G1-G2' => ['khoi_1_2', 7, 11, 6, null],
        'TEST-G2-G3' => ['khoi_2_3', 12, 13, 8, null],
        'TEST-G3-G4' => ['khoi_3_4', 9, 12, 6, null],
        'TEST-G4-G5' => ['khoi_4_5', 13, 12, 9, 'FAM 2 (NỬA SAU)'],
        'TEST-G6-G7' => ['khac', 7, 6, 7, 'KET (lớp 6–7) — Học thuật xếp'],
    ];

    private SessionScheduleService $schedule;

    private CrmStageService $stages;

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, Course> */
    private array $courses = [];

    private Branch $branch;

    /** Giờ hẹn test kế tiếp (mỗi khách 1 khung giờ để người chấm không trùng lịch). */
    private int $testSlot = 0;

    public function run(): void
    {
        $this->schedule = app(SessionScheduleService::class);
        $this->stages = app(CrmStageService::class);

        if (! User::where('email', 'admin@menglish.edu.vn')->exists()) {
            $this->command?->warn('DemoPhase1Seeder: chưa có tài khoản UserSeeder — bỏ qua.');

            return;
        }

        // Đề test theo khối lớp (preset thật, idempotent).
        $this->call([GradeTestsSeeder::class, SpeakingTestsSeeder::class], true);
        $this->seedCourses();

        $originalRequest = app('request');
        try {
            $this->seedBranches();
        } finally {
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }
        $this->printSummary();
    }

    private function seedBranches(): void
    {
        foreach (self::BRANCH_STAFF as $code => $emails) {
            $branch = Branch::where('code', $code)->first();
            if (! $branch) {
                continue;
            }
            $this->branch = $branch;
            $this->testSlot = 0;
            $this->staff = collect($emails)->except('digit')->map(fn (string $email) => User::where('email', $email)->firstOrFail())->all();
            $this->seedHolidayAndClasses($code);
            $this->seedRosterStudents($code, (int) $emails['digit']);
            foreach ($this->customerSpecs($code) as $index => $spec) {
                $this->seedCustomer($spec, (int) $emails['digit'], $index + 1);
            }
        }
    }

    // ── Khóa học, lớp, buổi học ─────────────────────────────────────────────

    private function seedCourses(): void
    {
        $starters = CourseLevel::query()->updateOrCreate(['code' => 'DEMO-STARTERS'], [
            'name' => 'Starters (Pre-A1)', 'level_group' => 'Cambridge YLE', 'target' => 'Pre-A1 Starters', 'duration' => '36 buổi', 'lessons_count' => 36, 'is_active' => true,
        ]);
        $movers = CourseLevel::query()->updateOrCreate(['code' => 'DEMO-MOVERS'], [
            'name' => 'Movers (A1)', 'level_group' => 'Cambridge YLE', 'target' => 'A1 Movers', 'duration' => '36 buổi', 'lessons_count' => 36, 'is_active' => true,
        ]);

        foreach ([
            'FAM0' => ['# Pre Starters (FAM 0)', $starters->id, 8000000],
            'FAM1' => ['# Starters (FAM 1)', $starters->id, 9000000],
            'FAM2' => ['# Movers (FAM 2)', $movers->id, 9500000],
        ] as $key => [$name, $levelId, $fee]) {
            $this->courses[$key] = Course::query()->updateOrCreate(['code' => "DEMO-{$key}"], [
                'name' => $name, 'course_level_id' => $levelId, 'tuition_fee' => $fee, 'total_lessons' => 36,
                'description' => 'Khóa demo Phase 1 (thiếu nhi, Cambridge YLE).', 'is_active' => true,
            ]);
        }
    }

    private function seedHolidayAndClasses(string $code): void
    {
        // Ngày nghỉ riêng của chi nhánh rơi vào lịch lớp FAM 1 (Thứ 4 đầu tiên sau 5 ngày): buổi ngày đó không được sinh.
        $holidayDate = today()->addDays(5);
        while ($holidayDate->dayOfWeekIso !== 3) {
            $holidayDate->addDay();
        }
        $holiday = Holiday::query()->firstOrCreate(['code' => "HOL-DEMO-{$code}"], [
            'name' => '# Nghỉ bảo trì cơ sở '.$this->branch->name,
            'start_date' => $holidayDate->toDateString(),
            'end_date' => $holidayDate->toDateString(),
            'is_system_wide' => false,
        ]);
        $holiday->branches()->syncWithoutDetaching([$this->branch->id]);

        $monday = today()->startOfWeek();
        $this->classes = [
            'FAM1' => $this->seedClass("DEMO-{$code}-FAM1", '# Starters FAM 1 · K26 ('.$code.')', 'FAM1', 'active', $monday->copy()->subWeeks(4), [
                ['day' => 'Thứ 2', 'start' => '17:30', 'end' => '19:00', 'shift' => 'Ca chiều'],
                ['day' => 'Thứ 4', 'start' => '17:30', 'end' => '19:00', 'shift' => 'Ca chiều'],
                ['day' => 'Thứ 6', 'start' => '17:30', 'end' => '19:00', 'shift' => 'Ca chiều'],
            ], 'P101', $this->staff['teacher'], 12),
            'FAM2' => $this->seedClass("DEMO-{$code}-FAM2", '# Movers FAM 2 · K27 ('.$code.')', 'FAM2', 'upcoming', today()->addDays(9), [
                ['day' => 'Thứ 3', 'start' => '18:00', 'end' => '19:30', 'shift' => 'Ca tối'],
                ['day' => 'Thứ 5', 'start' => '18:00', 'end' => '19:30', 'shift' => 'Ca tối'],
            ], 'P102', $this->staff['teacher2'], 10),
            'FAM0' => $this->seedClass("DEMO-{$code}-FAM0", '# Pre Starters FAM 0 · K25 ('.$code.')', 'FAM0', 'active', $monday->copy()->subWeeks(2), [
                ['day' => 'Thứ 7', 'start' => '08:00', 'end' => '09:30', 'shift' => 'Ca sáng'],
                ['day' => 'Chủ nhật', 'start' => '08:00', 'end' => '09:30', 'shift' => 'Ca sáng'],
            ], 'P103', $this->staff['teacher'], 6),
        ];
    }

    /** Lớp + buổi học sinh bằng SessionScheduleService (bỏ ngày nghỉ); buổi đã qua đánh dấu đã dạy. */
    private function seedClass(string $code, string $name, string $courseKey, string $status, Carbon $from, array $slots, string $room, User $teacher, int $capacity): ClassModel
    {
        $course = $this->courses[$courseKey];
        $class = ClassModel::query()->firstOrCreate(['code' => $code], [
            'name' => $name,
            'course_id' => $course->id,
            'program' => 'Cambridge YLE',
            'level' => strtoupper(trim(str_replace('#', '', $course->name))),
            'branch_id' => $this->branch->id,
            'teacher_id' => $teacher->id,
            'assistant_id' => $this->staff['assistant']->id,
            'room' => $room,
            'max_capacity' => $capacity,
            'min_students' => min(ClassModel::DEFAULT_MIN_STUDENTS, $capacity),
            'tuition_fee' => $course->tuition_fee,
            'status' => $status,
            'start_date' => $from->toDateString(),
            'end_date' => $from->copy()->addWeeks(16)->toDateString(),
            'schedule_text' => collect($slots)->map(fn (array $slot) => "{$slot['day']} {$slot['start']}-{$slot['end']}")->implode('; '),
            'notes' => 'Lớp demo Phase 1.',
        ]);

        if (! $class->sessions()->exists()) {
            $sessions = $this->schedule->generate($slots, $class->start_date, $class->end_date, $this->branch->id);
            foreach ($sessions as $session) {
                $date = Carbon::parse($session['date']);
                ClassSession::create([
                    'class_id' => $class->id,
                    'branch_id' => $class->branch_id,
                    'date' => $session['date'],
                    'shift_name' => $session['shift'],
                    'start_time' => $session['start'],
                    'end_time' => $session['end'],
                    'room' => $room,
                    'teacher_id' => $class->teacher_id,
                    'assistant_id' => $class->assistant_id,
                    'status' => $date->lt(today()) ? 'completed' : 'scheduled',
                ]);
            }
        }

        return $class;
    }

    /** Học viên đang học sẵn trong lớp (không đi qua CRM) để sĩ số demo thật: FAM 1 còn chỗ, FAM 2 chưa đủ ngưỡng, FAM 0 đầy. */
    private function seedRosterStudents(string $code, int $digit): void
    {
        $names = ['Nguyễn Minh Châu', 'Trần Bảo Ngọc', 'Lê Hoàng Nam', 'Phạm Khánh Linh', 'Đỗ Gia Huy', 'Vũ Tuệ An', 'Hoàng Nhật Minh', 'Bùi Thảo Vy', 'Đặng Quốc Khánh', 'Ngô Hà My', 'Phan Đức Anh', 'Trịnh Mai Chi', 'Lý Minh Khôi'];
        $plan = ['FAM1' => 5, 'FAM2' => 2, 'FAM0' => 6];
        $n = 0;
        foreach ($plan as $classKey => $count) {
            $class = $this->classes[$classKey];
            for ($i = 0; $i < $count; $i++, $n++) {
                $student = Student::query()->firstOrCreate(['code' => sprintf('HV-DEMO-%s-%02d', $code, $n + 1)], [
                    'name' => '# '.$names[$n % count($names)],
                    'phone' => sprintf('035%d%06d', $digit, $n + 1),
                    'branch_id' => $this->branch->id,
                    'current_class_id' => $class->id,
                    'target' => trim(str_replace('#', '', $class->course?->name ?? '')),
                    'total_lessons' => 36,
                    'status' => $class->status === 'upcoming' ? Student::INITIAL_STATUS : 'studying',
                ]);
                ClassEnrollment::query()->firstOrCreate(['student_id' => $student->id, 'class_id' => $class->id], [
                    'enrolled_at' => $class->start_date,
                    'status' => 'completed',
                    'account_sent' => true,
                    'zalo_group_added' => true,
                    'curriculum_delivered' => true,
                    'confirmed_at' => $class->start_date,
                    'confirmed_by' => $this->staff['academic']->id,
                ]);
            }
        }
    }

    // ── Khách CRM theo từng giai đoạn ──────────────────────────────────────

    /**
     * 14 khách / chi nhánh: đủ 8 bước pipeline + Thất bại. age: tuổi hồ sơ (ngày); idle: số ngày từ hoạt động cuối;
     * follow: hạn liên hệ tiếp theo (giờ, âm = quá hạn).
     *
     * @return list<array<string, mixed>>
     */
    private function customerSpecs(string $code): array
    {
        $cg = $code === 'CG';

        return [
            ['flow' => 'new', 'name' => $cg ? 'Nguyễn Gia Bảo' : 'Trần An Nhiên', 'parent' => $cg ? 'Nguyễn Thu Hà' : 'Trần Văn Hùng', 'source' => 'Facebook Ads',
                'interest' => 'Starters (FAM 1)', 'age' => 0.15, 'idle' => 0.15, 'follow' => 20,
                'notes' => []],
            ['flow' => 'new', 'name' => $cg ? 'Lê Minh Đăng' : 'Phạm Bảo Anh', 'parent' => $cg ? 'Lê Thị Hạnh' : 'Phạm Quang Huy', 'source' => 'Website / Hotline',
                'interest' => 'Pre Starters (FAM 0)', 'age' => 3, 'idle' => 3, 'follow' => -24,
                'notes' => []],
            ['flow' => 'consulting', 'name' => $cg ? 'Vũ Khánh Vy' : 'Đỗ Minh Quân', 'parent' => $cg ? 'Vũ Thanh Tâm' : 'Đỗ Thị Lan', 'source' => 'Bạn bè giới thiệu',
                'interest' => 'Starters (FAM 1)', 'age' => 6, 'idle' => 4, 'follow' => -48,
                'notes' => [['call', 'Gọi tư vấn: PH muốn học cuối tuần, hẹn gửi lịch lớp.']]],
            ['flow' => 'consulting_trial', 'name' => $cg ? 'Hoàng Tuấn Kiệt' : 'Ngô Phương Thảo', 'parent' => $cg ? 'Hoàng Mai Anh' : 'Ngô Đức Mạnh', 'source' => 'Tiktok Organic',
                'interest' => 'Starters (FAM 1)', 'age' => 5, 'idle' => 1, 'follow' => 72,
                'notes' => [['call', 'PH muốn cho con học thử trước khi làm bài test.'], ['message', 'Đã gửi Zalo lịch học thử lớp FAM 1.']]],
            ['flow' => 'test_scheduled', 'test' => $cg ? 'TEST-G2-G3' : 'TEST-G3-G4', 'name' => $cg ? 'Đặng Hải Đăng' : 'Lý Gia Hân', 'parent' => $cg ? 'Đặng Thu Trang' : 'Lý Văn Tài', 'source' => 'Google Ads',
                'interest' => $cg ? 'Starters (FAM 1)' : 'Movers (FAM 2)', 'age' => 7, 'idle' => 1, 'follow' => 30,
                'notes' => [['call', 'Tư vấn lộ trình, PH đồng ý cho con làm bài test online.']]],
            ['flow' => 'testing', 'test' => $cg ? 'TEST-G3-G4' : 'TEST-G4-G5', 'name' => $cg ? 'Bùi Ngọc Diệp' : 'Phan Tuấn Minh', 'parent' => $cg ? 'Bùi Văn Long' : 'Phan Thị Nga', 'source' => 'Facebook Ads',
                'interest' => 'Movers (FAM 2)', 'age' => 8, 'idle' => 0.5, 'follow' => 6,
                'notes' => [['message', 'Đã gửi link làm bài test online qua Zalo.']]],
            ['flow' => 'tested', 'test' => $cg ? 'TEST-G1-G2' : 'TEST-G3-G4', 'name' => $cg ? 'Trịnh Bảo Châu' : 'Mai Anh Khoa', 'parent' => $cg ? 'Trịnh Văn Nam' : 'Mai Thị Hoa', 'source' => 'Sự kiện Offline',
                'interest' => $cg ? 'Starters (FAM 1)' : 'Movers (FAM 2)', 'age' => 10, 'idle' => 1, 'follow' => 24,
                'notes' => [['meet', 'PH đến cơ sở, con làm bài test và thi nói với Học vụ.']]],
            ['flow' => 'result_sent', 'test' => $cg ? 'TEST-G2-G3' : 'TEST-G4-G5', 'name' => $cg ? 'Phạm Nhật Linh' : 'Đinh Quốc Bảo', 'parent' => $cg ? 'Phạm Hồng Nhung' : 'Đinh Văn Hải', 'source' => 'Bạn bè giới thiệu',
                'interest' => $cg ? 'Starters (FAM 1)' : 'Movers (FAM 2)', 'age' => 12, 'idle' => 2, 'follow' => -5,
                'notes' => [['message', 'Đã gửi kết quả test và nhận xét cho PH qua Zalo.'], ['call', 'PH hài lòng buổi học thử, đang cân nhắc học phí.']]],
            ['flow' => 'waiting', 'test' => $cg ? 'TEST-G3-G4' : 'TEST-G6-G7', 'name' => $cg ? 'Nguyễn Thảo My' : 'Cao Minh Tú', 'parent' => $cg ? 'Nguyễn Văn Đức' : 'Cao Thị Yến', 'source' => 'Facebook Ads',
                'interest' => 'Movers (FAM 2)', 'age' => 15, 'idle' => 0, 'follow' => null,
                'notes' => [['call', 'PH chốt khóa FAM 2 nhưng chỉ học được tối Thứ 3 / Thứ 5 — chờ lớp mới.']]],
            ['flow' => 'won_paid', 'name' => $cg ? 'Lê Bảo Ngọc' : 'Tạ Minh Hiếu', 'parent' => $cg ? 'Lê Quang Vinh' : 'Tạ Thu Hằng', 'source' => 'Bạn bè giới thiệu',
                'interest' => 'Starters (FAM 1)', 'age' => 20, 'idle' => 0, 'follow' => null,
                'notes' => [['meet', 'PH là phụ huynh cũ giới thiệu, đồng ý đăng ký luôn không cần test.']]],
            ['flow' => 'won_unpaid', 'test' => $cg ? 'TEST-G4-G5' : 'TEST-G3-G4', 'name' => $cg ? 'Võ Hoàng Long' : 'Hà Khánh Ngân', 'parent' => $cg ? 'Võ Thị Thu' : 'Hà Văn Bình', 'source' => 'Google Ads',
                'interest' => 'Movers (FAM 2)', 'age' => 14, 'idle' => 0, 'follow' => null,
                'notes' => [['call', 'PH hẹn chuyển khoản học phí trong tuần.']]],
            ['flow' => 'won_assigned', 'test' => $cg ? 'TEST-G1-G2' : 'TEST-G2-G3', 'name' => $cg ? 'Kiều Anh Thư' : 'Lâm Gia Phúc', 'parent' => $cg ? 'Kiều Văn Sơn' : 'Lâm Thị Ngọc', 'source' => 'Tiktok Organic',
                'interest' => 'Starters (FAM 1)', 'age' => 18, 'idle' => 0, 'follow' => null,
                'notes' => [['call', 'PH đóng học phí, chờ Học vụ xếp lớp phù hợp lịch.']]],
            ['flow' => 'lost', 'name' => $cg ? 'Dương Quang Huy' : 'Tô Ngọc Ánh', 'parent' => $cg ? 'Dương Thị Mến' : 'Tô Văn Toàn', 'source' => 'Facebook Ads',
                'interest' => 'Starters (FAM 1)', 'age' => 25, 'idle' => 20, 'follow' => null, 'reason' => 'Học phí vượt ngân sách gia đình',
                'notes' => [['call', 'PH thấy học phí cao, xin suy nghĩ thêm.']]],
            ['flow' => 'lost_after_test', 'test' => $cg ? 'TEST-G2-G3' : 'TEST-G1-G2', 'name' => $cg ? 'Chu Minh Trí' : 'Quách Bảo Vy', 'parent' => $cg ? 'Chu Thị Hoa' : 'Quách Văn Lợi', 'source' => 'Google Ads',
                'interest' => 'Starters (FAM 1)', 'age' => 21, 'idle' => 9, 'follow' => null, 'reason' => 'Chọn trung tâm gần nhà hơn',
                'notes' => [['call', 'PH báo đã đăng ký trung tâm gần nhà.']]],
        ];
    }

    private function seedCustomer(array $spec, int $digit, int $index): void
    {
        $phone = sprintf('039%d%06d', $digit, $index);
        if (CrmCustomer::withTrashed()->where('phone', $phone)->orWhere('phone_normalized', $phone)->exists()) {
            return;
        }

        DB::transaction(function () use ($spec, $digit, $index, $phone) {
            $createdAt = now()->subMinutes((int) round($spec['age'] * 1440));

            // Sale nhập khách qua form thật (kiểm tra SĐT, lịch sử đầu tiên).
            $this->asUser($this->staff['sales'], CrmController::class, 'storeCustomer', [
                'name' => '# '.$spec['name'],
                'phone' => $phone,
                'parent_name' => $spec['parent'],
                'parent_phone' => sprintf('038%d%06d', $digit, $index),
                'source' => $spec['source'],
                'branch_id' => $this->branch->id,
                'course_interest' => $spec['interest'],
                'deal_value' => $this->courseFor($spec['interest'])->tuition_fee,
                'next_follow_up_at' => $spec['follow'] !== null ? now()->addHours($spec['follow'])->format('Y-m-d H:i') : null,
                'notes' => 'Khách demo Phase 1 — PH '.$spec['parent'].'.',
            ]);
            $customer = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();

            foreach ($spec['notes'] as [$type, $content]) {
                $this->note($customer, $this->staff['sales'], $type, $content);
            }

            $this->runFlow($customer, $spec);

            $customer->refresh();
            $customer->forceFill(['created_at' => $createdAt])->saveQuietly();
            if ($customer->appointment_at && $customer->stage !== 'test_scheduled') {
                // Khách đã làm test: lịch hẹn nằm trong quá khứ (giữa ngày tạo và hiện tại).
                $customer->forceFill(['appointment_at' => $createdAt->copy()->addSeconds((int) ($createdAt->diffInSeconds(now()) / 3))])->saveQuietly();
            }
            $this->backdateHistories($customer, $createdAt, now()->subMinutes((int) round($spec['idle'] * 1440)));
        });
    }

    private function runFlow(CrmCustomer $customer, array $spec): void
    {
        $academic = $this->staff['academic'];
        $flow = $spec['flow'];

        match ($flow) {
            'new' => null,
            'consulting' => $this->moveTo($customer, 'consulting'),
            'consulting_trial' => $this->bookTrial($this->moveTo($customer, 'consulting'), 'Học thử trước khi test.'),
            'test_scheduled' => $this->scheduleTest($customer, $spec['test']),
            'testing' => $this->takeTest($this->scheduleTest($customer, $spec['test']), $spec['test']),
            'tested' => $this->bookTrial($this->grade($customer, $spec['test']), 'Học thử lớp đúng trình độ sau test.'),
            'result_sent' => $this->attendedTrial($this->moveTo($this->grade($customer, $spec['test']), 'result_sent')),
            'waiting' => $this->close($this->moveTo($this->grade($customer, $spec['test']), 'result_sent'), null, 'FAM2', false),
            'won_paid' => $this->confirm($this->approveReceipts($this->close($this->moveTo($customer, 'consulting'), 'FAM1', null, true))),
            'won_unpaid' => $this->close($this->grade($customer, $spec['test']), 'FAM2', null, false),
            'won_assigned' => $this->assign($this->close($this->grade($customer, $spec['test']), null, 'FAM1', true), 'FAM1'),
            'lost' => $this->stages->move($this->moveTo($customer, 'consulting'), CrmCustomer::STAGE_LOST, $academic, $spec['reason']),
            'lost_after_test' => $this->stages->move($this->grade($customer, $spec['test']), CrmCustomer::STAGE_LOST, $academic, $spec['reason']),
        };
    }

    /** CM (Học vụ) chuyển tiến từng bước tới $stage. */
    private function moveTo(CrmCustomer $customer, string $stage): CrmCustomer
    {
        while ($customer->stage !== $stage) {
            $this->stages->move($customer, $this->stages->nextStage($customer->stage), $this->staff['academic']);
        }

        return $customer;
    }

    /** Học vụ hẹn test online (gán đề + người chấm) → "Hẹn test". */
    private function scheduleTest(CrmCustomer $customer, string $testCode): CrmCustomer
    {
        $this->moveTo($customer, 'consulting');
        $this->asUser($this->staff['academic'], CrmController::class, 'schedulePlacementTest', [
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => sprintf('%02d:00', 8 + ($this->testSlot++ % 10)),
            'appointment_type' => 'online',
            'assigned_test_id' => PlacementTest::where('code', $testCode)->value('id'),
            'examiner_id' => $this->staff['academic']->id,
            'notes' => 'Gửi link test qua Zalo PH.',
        ], ['id' => $customer->id]);

        return $customer->refresh();
    }

    /** Khách mở link test + nộp bài (phần trắc nghiệm tự chấm, chờ Học vụ chấm Viết / Nói) → "Test". */
    private function takeTest(CrmCustomer $customer, string $testCode): PlacementTestSubmission
    {
        $test = PlacementTest::where('code', $testCode)->firstOrFail();
        $this->stages->advanceTo($customer, 'testing', null, "Thí sinh mở link làm bài test [{$test->code}].");
        $group = PlacementRubricService::detectGradeGroup($test->code);
        $max = PlacementRubricService::maxScores($group);
        $answers = collect($test->questions ?? [])->filter(fn (array $q) => filled($q['correct_answer'] ?? null))
            ->mapWithKeys(fn (array $q, int $i) => [$q['id'] ?? $i => $i % 3 === 0 ? 'X' : $q['correct_answer']])->all();

        $submission = PlacementTestSubmission::create([
            'placement_test_id' => $test->id,
            'customer_id' => $customer->id,
            'candidate_name' => $customer->name,
            'candidate_phone' => $customer->phone,
            'grade_group' => $group,
            'listening_score' => round($max['listening'] * 0.6 * 2) / 2,
            'reading_score' => round($max['reading_writing'] * 0.5 * 2) / 2,
            'writing_content' => 'My name is '.trim(str_replace('#', '', $customer->name)).'. I like playing football with my friends.',
            'answers' => $answers + ['speaking_self_rate' => 'intermediate'],
            'status' => PlacementTestSubmission::STATUS_PENDING,
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $customer->assigned_user_id,
            'type' => 'test',
            'content' => "Học viên đã nộp bài test trực tuyến [{$test->title}] (bài #{$submission->id}), chờ Học vụ chấm điểm. Nộp qua link test riêng của lead.",
        ]);

        return $submission;
    }

    /** Học vụ chấm theo thang khối lớp (Nói nhập tay) qua màn chấm bài → "Đã test". */
    private function grade(CrmCustomer $customer, string $testCode): CrmCustomer
    {
        $submission = $this->takeTest($this->scheduleTest($customer, $testCode), $testCode);
        [$group, $listening, $readingWriting, $speaking, $chosen] = self::GRADES[$testCode];

        $this->asUser($this->staff['academic'], PlacementTestController::class, 'updateResult', [
            'grade_group' => $group,
            'listening_score' => $listening,
            'reading_writing_score' => $readingWriting,
            'speaking_score' => $speaking,
            'chosen_class' => $chosen,
            'teacher_comments' => 'Con tự tin, cần luyện thêm phát âm âm cuối.',
        ], ['id' => $submission->id]);

        return $customer->refresh();
    }

    /** CM đặt học thử vào buổi sắp tới của lớp FAM 1 (≤ 2 buổi / khách). */
    private function bookTrial(CrmCustomer $customer, string $note): CrmCustomer
    {
        $session = $this->classes['FAM1']->sessions()->where('status', 'scheduled')->whereDate('date', '>', today())->orderBy('date')->first();
        if ($session) {
            $this->asUser($this->staff['academic'], CrmController::class, 'storeTrialBooking', [
                'class_session_ids' => [$session->id], 'notes' => $note,
            ], ['id' => $customer->id]);
        }

        return $customer->refresh();
    }

    /** Buổi học thử đã diễn ra: GV của buổi điểm danh + nhận xét (lưu theo khách). */
    private function attendedTrial(CrmCustomer $customer): CrmCustomer
    {
        $session = $this->classes['FAM1']->sessions()->whereDate('date', '<', today())->orderByDesc('date')->first();
        if (! $session) {
            return $customer;
        }
        $booking = CrmTrialBooking::create([
            'customer_id' => $customer->id,
            'class_id' => $session->class_id,
            'class_session_id' => $session->id,
            'booked_by' => $this->staff['academic']->id,
            'status' => 'scheduled',
            'notes' => 'Học thử sau khi có kết quả test.',
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $this->staff['academic']->id,
            'type' => 'trial',
            'content' => 'Đặt lịch học thử: '.$this->classes['FAM1']->name.' ('.$session->date->format('d/m/Y').' '.$session->start_time?->format('H:i').').',
        ]);
        $this->asUser($this->staff['teacher'], TrialGuestController::class, 'feedback', [
            'status' => 'attended',
            'rating' => 4,
            'remarks' => ['grammar' => 'Khá', 'attitude' => 'Hăng hái, mạnh dạn phát biểu', 'result' => 'Theo kịp bài, làm đúng 8/10 câu'],
            'feedback' => 'Con hòa nhập nhanh, phát âm rõ; cần luyện thêm từ vựng chủ đề trường học. Phù hợp lớp FAM 1.',
        ], ['booking' => $booking]);

        return $customer->refresh();
    }

    /**
     * Chốt & Xếp lớp (Quản lý cơ sở): có lớp → Đã chốt; không lớp ($courseKey) → Chờ xếp lớp.
     * Đã đóng phí → phiếu thu chờ Kế toán duyệt; chưa đóng → task "Nhắc thu học phí".
     */
    private function close(CrmCustomer $customer, ?string $classKey, ?string $courseKey, bool $paid): CrmCustomer
    {
        $course = $classKey ? $this->classes[$classKey]->course : $this->courses[$courseKey];
        $this->asUser($this->staff['manager'], CrmController::class, 'processClosingWizard', array_filter([
            'customer_id' => $customer->id,
            'class_id' => $classKey ? $this->classes[$classKey]->id : null,
            'course_id' => $classKey ? null : $course->id,
            'fee_paid_at_closing' => $paid ? 1 : 0,
            'paid_amount' => $paid ? (float) $course->tuition_fee : null,
            'payment_method' => $paid ? 'cash' : null,
            'bill_notes' => 'Chốt demo Phase 1.',
        ], fn ($value) => $value !== null));

        return $customer->refresh();
    }

    /** Kế toán chi nhánh duyệt phiếu thu lúc chốt → học phí đã đóng. */
    private function approveReceipts(CrmCustomer $customer): CrmCustomer
    {
        $receiptIds = TuitionReceipt::where('student_id', $customer->converted_student_id)->where('status', TuitionReceipt::STATUS_PENDING)->pluck('id');
        foreach ($receiptIds as $id) {
            $this->asUser($this->staff['accountant'], TuitionController::class, 'approveReceiptAction', [], ['id' => $id]);
        }

        return $customer;
    }

    /** Học vụ gán lớp cho học viên Chờ xếp lớp → Đã chốt. */
    private function assign(CrmCustomer $customer, string $classKey): CrmCustomer
    {
        $this->asUser($this->staff['academic'], CrmController::class, 'assignClass', ['class_id' => $this->classes[$classKey]->id], ['id' => $customer->id]);

        return $customer->refresh();
    }

    /** Xác nhận chính thức (đủ checklist: tài khoản, nhóm Zalo, giáo trình). */
    private function confirm(CrmCustomer $customer): CrmCustomer
    {
        $enrollment = ClassEnrollment::where('customer_id', $customer->id)->whereNull('confirmed_at')->first();
        if ($enrollment) {
            $this->asUser($this->staff['academic'], CrmController::class, 'confirmEnrollment', [
                'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => 1, 'action' => 'confirm',
            ], ['enrollment' => $enrollment]);
        }

        return $customer;
    }

    private function note(CrmCustomer $customer, User $user, string $type, string $content): void
    {
        CrmCustomerHistory::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'type' => $type, 'content' => $content]);
    }

    private function courseFor(string $interest): Course
    {
        return match (true) {
            str_contains($interest, 'FAM 0') => $this->courses['FAM0'],
            str_contains($interest, 'FAM 2') => $this->courses['FAM2'],
            default => $this->courses['FAM1'],
        };
    }

    /** Dàn đều thời điểm nhật ký của khách từ ngày tạo tới hoạt động cuối (để demo "số ngày ở giai đoạn", cảnh báo bỏ quên). */
    private function backdateHistories(CrmCustomer $customer, Carbon $start, Carbon $end): void
    {
        $end = $end->max($start);
        $ids = CrmCustomerHistory::where('customer_id', $customer->id)->orderBy('id')->pluck('id');
        $count = $ids->count();
        foreach ($ids as $i => $id) {
            $at = $count > 1 ? $start->copy()->addSeconds((int) ($start->diffInSeconds($end) * $i / ($count - 1))) : $start->copy();
            CrmCustomerHistory::whereKey($id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    /**
     * Gọi action controller thật với vai trò $user (bỏ qua middleware; controller tự kiểm tra phạm vi / quy tắc).
     * Lỗi validate / lỗi nghiệp vụ (withErrors) ném ra để seed dừng lại thay vì tạo dữ liệu sai.
     */
    private function asUser(User $user, string $controller, string $method, array $input = [], array $parameters = []): mixed
    {
        $request = Request::create('/demo-seed', 'POST', $input);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        Auth::setUser($user);
        session()->forget('errors');

        $response = app()->call([app($controller), $method], ['request' => $request] + $parameters);

        $errors = session()->pull('errors');
        if ($errors instanceof ViewErrorBag && $errors->any()) {
            throw new RuntimeException("DemoPhase1Seeder: {$controller}@{$method} báo lỗi: ".implode(' ', $errors->all()));
        }

        return $response;
    }

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }
        $demoCustomers = CrmCustomer::where('phone', 'like', '039%');
        $rows = [
            ['crm_customers (demo)', (clone $demoCustomers)->count()],
            ['crm_customer_histories (demo)', CrmCustomerHistory::whereIn('customer_id', (clone $demoCustomers)->select('id'))->count()],
            ['crm_trial_bookings', CrmTrialBooking::count()],
            ['placement_tests', PlacementTest::count()],
            ['placement_test_submissions', PlacementTestSubmission::count()],
            ['classes (DEMO-*)', ClassModel::where('code', 'like', 'DEMO-%')->count()],
            ['class_sessions', ClassSession::count()],
            ['students', Student::count()],
            ['class_enrollments', ClassEnrollment::count()],
        ];
        $this->command->table(['Bảng', 'Số dòng'], $rows);
        $this->command->line('Khách demo theo giai đoạn: '.(clone $demoCustomers)->selectRaw('stage, count(*) as c')->groupBy('stage')->pluck('c', 'stage')
            ->map(fn ($count, $stage) => CrmCustomer::stageLabel($stage).": {$count}")->implode(' · '));
    }
}
