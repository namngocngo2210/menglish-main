<?php

namespace Database\Seeders;

use App\Http\Controllers\CrmController;
use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\TuitionController;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\Homework;
use App\Models\MaterialOrder;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStock;
use App\Models\StaffAttendanceRequest;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Services\MaterialOrderService;
use App\Services\Merchandise\StockService;
use App\Services\Sla\Sla;
use App\Services\Sla\SlaBreachService;
use App\Support\Roles;
use App\Support\StaffType;
use Carbon\Carbon;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Nguồn số liệu cho các tiêu chí KPI tự đếm (KpiAutoCounter) ở tháng trước (L) và tháng này (C), để phiếu KPI có số thật thay
 * vì số điền tay. Dựng trên lớp K27 / K28 và nhân sự của DemoPayrollSeeder, đi qua controller / service thật với đồng hồ tua:
 * - Học viên cho lớp K27 / K28: điểm danh từng buổi đã qua, mini test, bài tập về nhà + bài nộp (Học vụ nộp hộ). Lớp của GV
 *   "tốt" chuyên cần / BTVN cao, điểm test tăng; lớp của GV "kém" thấp, điểm giảm (chuyên cần, BTVN, kết quả / tiến bộ test).
 * - Báo cáo giảng dạy tháng của GV full-time / part-time cho các tháng trong kỳ KPI trước: nộp tối mùng 1, GV kém nộp mùng 4.
 * - Nghỉ dạy (DemoPayrollSeeder để trống giờ dạy buổi đó): GV part-time có nghỉ có phép / không phép ở cả hai tháng để đếm
 *   KPI; GVNN giữ đơn nghỉ mỗi tháng để đối chiếu quy tắc không phạt tự động. Buổi nghỉ sau cùng có đơn được duyệt.
 * - Sale / Học vụ: lead mới quá hạn liên hệ lần đầu (mở / quá hạn mốc SLA như CrmSlaService), học phí đến hạn chưa có lần
 *   nhắc (Học vụ kém) / đã nhắc (Học vụ tốt), order in ấn xử lý trễ + kiểm kê sách lệch, phiếu thu bị trả về tháng trước.
 *
 * Gọi từ DemoPayrollSeeder trước khi chốt phiếu KPI tháng trước (và một lần nữa cuối demo:luong cho CSDL đã có dữ liệu lương —
 * khi đó chỉ thêm dữ liệu, phiếu đã chốt giữ nguyên). Idempotent: học viên demo đầu tiên đã có thì bỏ qua. Mốc chưa tới (sau
 * "bây giờ") bỏ qua.
 */
class DemoKpiSourcesSeeder extends Seeder
{
    use InvokesControllersAsUser;

    public const MARKER = '[demo-kpi]';

    private const STUDENT_PREFIX = 'HV-DEMO-KPI-';

    /** Lớp demo lương => [số học viên, mức]. Mức theo người dạy: good / mid / bad. */
    private const CLASSES = [
        'DEMO-CG-SPK27' => [8, 'mid'],
        'DEMO-CG-IE27' => [8, 'bad'],
        'DEMO-DD-GT27' => [8, 'good'],
        'DEMO-CG-IF1' => [8, 'mid'],
        'DEMO-CG-SPK1' => [8, 'mid'],
        'DEMO-BD-GT1' => [7, 'mid'],
        'DEMO-DD-GT1' => [8, 'good'],
        'DEMO-DD-IE1' => [7, 'bad'],
    ];

    /** Điểm danh theo mức: [% vắng, % có phép, % muộn] (còn lại có mặt). */
    private const ATTENDANCE = ['good' => [2, 2, 6], 'mid' => [5, 5, 8], 'bad' => [14, 10, 8]];

    /** Mini test theo mức: [điểm khởi đầu, thay đổi mỗi lần test]. */
    private const SCORES = ['good' => [7.8, 0.4], 'mid' => [7.0, 0.2], 'bad' => [6.6, -0.3]];

    /** % học viên nộp bài tập đúng hạn theo mức. */
    private const HOMEWORK = ['good' => 95, 'mid' => 85, 'bad' => 55];

    private const NAMES = ['An Nhiên', 'Bảo Châu', 'Minh Khang', 'Gia Hân', 'Đức Thịnh', 'Khánh Vy', 'Tuấn Kiệt', 'Ngọc Diệp',
        'Hải Đăng', 'Thanh Trúc', 'Quang Vinh', 'Mai Anh', 'Phúc Lâm', 'Hoài Thương', 'Nhật Quang', 'Tường Vi'];

    private const FAMILY = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Vũ', 'Đặng', 'Bùi', 'Đỗ', 'Ngô'];

    /** GV nộp báo cáo tháng trễ (sau 12h mùng 2). */
    private const LATE_MONTHLY = ['gv.cohuu1@menglish.edu.vn', 'nguyenvanan@menglish.edu.vn'];

    /** Lead mới mỗi tháng: email => [số lead quá hạn liên hệ lần đầu, số lead liên hệ đúng hạn]. */
    private const LEADS = [
        'hoangthinh@menglish.edu.vn' => [3, 1],
        'levanvu@menglish.edu.vn' => [0, 2],
        'tranmaia@menglish.edu.vn' => [1, 2],
        'giaovu2@menglish.edu.vn' => [2, 1],
        'nva@menglish.edu.vn' => [0, 2],
    ];

    /**
     * Học phí đến hạn của học viên do Học vụ chốt: [email Học vụ, lớp, nhắc học phí trước hạn?, tháng (L | C), ngày đến hạn].
     * Học viên lấy lần lượt từ cuối danh sách lớp.
     */
    private const TUITIONS = [
        ['giaovu2@menglish.edu.vn', 'DEMO-DD-GT27', false, 'L', 10],
        ['giaovu2@menglish.edu.vn', 'DEMO-DD-GT27', false, 'L', 12],
        ['nva@menglish.edu.vn', 'DEMO-CG-IE27', true, 'L', 10],
        ['nva@menglish.edu.vn', 'DEMO-CG-SPK27', true, 'L', 11],
        ['giaovu2@menglish.edu.vn', 'DEMO-BD-GT1', false, 'C', 8],
        ['giaovu2@menglish.edu.vn', 'DEMO-BD-GT1', false, 'C', 9],
        ['nva@menglish.edu.vn', 'DEMO-CG-IF1', true, 'C', 8],
    ];

    /** Order in ấn mỗi tháng: [email người xử lý, cơ sở, xử lý trễ?]. */
    private const ORDERS = [
        ['giaovu2@menglish.edu.vn', 'BD', true],
        ['giaovu2@menglish.edu.vn', 'BD', true],
        ['nva@menglish.edu.vn', 'CG', false],
    ];

    private Carbon $realNow;

    private Carbon $thisMonth;

    private Carbon $lastMonth;

    /** @var array<string, User|null> */
    private array $users = [];

    /** @var array<string, Branch> */
    private array $branches = [];

    private int $phone = 0;

    public function run(): void
    {
        if (Student::where('code', 'like', self::STUDENT_PREFIX.'%')->exists()) {
            $this->command?->info('DemoKpiSourcesSeeder: đã có dữ liệu nguồn KPI demo — bỏ qua.');

            return;
        }
        if (! ClassModel::where('code', 'DEMO-CG-IF1')->exists() || ! $this->user('admin@menglish.edu.vn')) {
            $this->command?->warn('DemoKpiSourcesSeeder: chưa có lớp / nhân sự của DemoPayrollSeeder — bỏ qua.');

            return;
        }

        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $this->realNow = now()->copy();
        $this->thisMonth = $this->realNow->copy()->startOfMonth();
        $this->lastMonth = $this->thisMonth->copy()->subMonthNoOverflow()->startOfMonth();
        $this->branches = Branch::whereIn('code', ['CG', 'BD', 'DD'])->get()->keyBy('code')->all();

        try {
            DB::transaction(function () {
                $classes = ClassModel::whereIn('code', array_keys(self::CLASSES))->get()->keyBy('code');
                foreach ($classes as $code => $class) {
                    $this->seedClass($class, ...self::CLASSES[$code]);
                }
                $this->seedMonthlyReports();
                $this->seedLeave();
                foreach ([$this->lastMonth, $this->thisMonth] as $month) {
                    $this->seedLeads($month);
                    $this->seedOrders($month);
                }
                $this->seedTuitions($classes);
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }

        $this->command?->info('DemoKpiSourcesSeeder: '.Student::where('code', 'like', self::STUDENT_PREFIX.'%')->count().' học viên, '
            .StudentAttendance::whereIn('class_id', ClassModel::whereIn('code', array_keys(self::CLASSES))->select('id'))->count().' lượt điểm danh, '
            .StaffReport::where('type', 'monthly')->whereNotNull('period_key')->count().' báo cáo tháng GV.');
    }

    /** Đặt đồng hồ; mốc chưa tới thì không chạy. */
    private function when(Carbon $at, Closure $callback): void
    {
        if ($at->gte($this->realNow)) {
            return;
        }
        Carbon::setTestNow($at->copy());
        $callback();
        Carbon::setTestNow($this->realNow->copy());
    }

    private function user(string $email): ?User
    {
        return $this->users[$email] ??= User::where('email', $email)->first();
    }

    private function roll(string $key): int
    {
        return crc32($key) % 100;
    }

    /** Học vụ của cơ sở (điểm danh thay, nộp bài hộ); cơ sở chưa có Học vụ thì Admin. */
    private function academicFor(ClassModel $class, Carbon $at): User
    {
        $email = ['CG' => 'nva@menglish.edu.vn', 'BD' => 'giaovu2@menglish.edu.vn', 'DD' => 'giaovu.dd@menglish.edu.vn'][$class->branch?->code] ?? null;
        $user = $email ? $this->user($email) : null;

        return $user && (! $user->contract_start_date || $user->contract_start_date->lte($at)) ? $user : $this->user('admin@menglish.edu.vn');
    }

    // ── Lớp học: học viên, điểm danh, mini test, bài tập ────────────────────────

    private function seedClass(ClassModel $class, int $size, string $level): void
    {
        $start = Carbon::parse($class->start_date);
        $students = collect();
        $this->when($start->copy()->subDays(3)->setTime(10, 0), function () use ($class, $size, $start, &$students) {
            for ($i = 1; $i <= $size; $i++) {
                $this->phone++;
                $name = self::FAMILY[($this->phone * 3) % count(self::FAMILY)].' '.self::NAMES[$this->phone % count(self::NAMES)];
                $student = Student::create([
                    'code' => self::STUDENT_PREFIX.sprintf('%03d', $this->phone),
                    'name' => '# '.$name,
                    'phone' => sprintf('0377%06d', 900000 + $this->phone),
                    'parent_name' => 'PH em '.$name,
                    'parent_phone' => sprintf('0388%06d', 100000 + $this->phone),
                    'branch_id' => $class->branch_id,
                    'current_class_id' => $class->id,
                    'target' => $class->program,
                    'total_lessons' => 36,
                    'status' => 'studying',
                    'study_started_on' => $start->toDateString(),
                    'notes' => 'Học viên demo KPI '.self::MARKER.'.',
                ]);
                ClassEnrollment::create([
                    'student_id' => $student->id, 'class_id' => $class->id, 'enrolled_at' => $start, 'status' => 'completed',
                    'account_sent' => true, 'curriculum_delivered' => true,
                    'confirmed_at' => $start, 'confirmed_by' => $this->academicFor($class, $start)->id,
                ]);
                $students->push($student);
            }
        });
        if ($students->isEmpty()) {
            return;
        }

        $sessions = ClassSession::where('class_id', $class->id)->where('status', '!=', 'cancelled')
            ->whereDate('date', '<', $this->realNow->toDateString())->orderBy('date')->orderBy('start_time')->get();
        // Buổi GV chính có giờ dạy (khóa "buổi|người"); buổi GV nghỉ thì Học vụ điểm danh thay.
        $taught = TeacherTimesheet::whereIn('class_session_id', $sessions->modelKeys())->where('status', '!=', 'invalid')
            ->get(['class_session_id', 'user_id'])->mapWithKeys(fn (TeacherTimesheet $t) => [$t->class_session_id.'|'.$t->user_id => true]);
        [$base, $step] = self::SCORES[$level];
        $tests = 0;

        foreach ($sessions->values() as $i => $session) {
            $date = $session->date->toDateString();
            $end = $session->date->copy()->setTimeFromTimeString($session->end_time->format('H:i'));

            // Điểm danh ngay sau buổi: GV của buổi (trong khung điểm danh của GV), còn lại Học vụ.
            $this->when($end->copy()->addMinutes(10), function () use ($class, $session, $students, $level, $date, $taught, $end) {
                $teacher = $taught->has($session->id.'|'.$session->teacher_id) ? User::find($session->teacher_id) : null;
                $actor = $teacher && $session->withinTeacherAttendanceWindow() ? $teacher : $this->academicFor($class, $end);
                [$absent, $excused, $late] = self::ATTENDANCE[$level];
                $status = [];
                $note = [];
                foreach ($students as $student) {
                    $roll = $this->roll("{$student->id}|{$date}|att");
                    $status[$student->id] = match (true) {
                        $roll < $absent => 'absent',
                        $roll < $absent + $excused => 'excused',
                        $roll < $absent + $excused + $late => 'late',
                        default => 'present',
                    };
                    if ($status[$student->id] === 'absent') {
                        $note[$student->id] = 'Nghỉ không báo trước, đã nhắn PH';
                    } elseif ($status[$student->id] === 'excused') {
                        $note[$student->id] = 'PH xin nghỉ (ốm)';
                    }
                }
                $this->asUser($actor, TeacherPortalController::class, 'attendanceStore', [
                    'class_session_id' => $session->id, 'status' => $status, 'note' => $note,
                ], ['classId' => $class->id]);
            });

            // Mini test mỗi 3 buổi (buổi 3, 6, 9…), GV nhập điểm sau buổi.
            if ($i % 3 === 2) {
                $k = $tests++;
                $this->when($end->copy()->addMinutes(40), function () use ($class, $session, $students, $base, $step, $k, $date) {
                    $scores = $students->mapWithKeys(function (Student $s) use ($base, $step, $k) {
                        $offset = (crc32("{$s->id}|{$k}|score") % 21 - 10) / 10;

                        return [$s->id => max(3, min(10, round(($base + $step * $k + $offset) * 2) / 2))];
                    })->all();
                    $this->asUser(User::find($session->teacher_id) ?? $this->academicFor($class, now()), TeacherPortalController::class, 'scoresStore', [
                        'name' => 'Mini test '.($k + 1).' · '.$class->level, 'test_date' => $date, 'max_score' => 10, 'score' => $scores,
                    ], ['classId' => $class->id]);
                });
            }

            // Bài tập về nhà mỗi 3 buổi (buổi 2, 5, 8…), hạn 3 ngày; học viên nộp vào giỏ "Bài tập giấy" của BTVN buổi đó
            // tối hôm sau (Học vụ nộp hộ từ ảnh PH gửi).
            if ($i % 3 === 1) {
                $this->when($end->copy()->addMinutes(20), function () use ($class, $session) {
                    $this->asUser(User::find($session->teacher_id) ?? $this->academicFor($class, now()), TeacherPortalController::class, 'homeworkStore', [
                        'class_session_id' => $session->id, 'categories' => ['workbook'],
                        'items' => ['workbook' => 'Làm bài tập Unit trang '.(4 + $session->id % 20).'–'.(5 + $session->id % 20).', chụp ảnh nộp.'],
                        'due_date' => $session->date->copy()->addDays(3)->toDateString(),
                    ], ['classId' => $class->id]);
                });
                $this->when($session->date->copy()->addDay()->setTime(20, 30), function () use ($class, $session, $students, $level, $date) {
                    $homework = Homework::where('class_id', $class->id)->where('class_session_id', $session->id)->latest('id')->first();
                    if (! $homework) {
                        return;
                    }
                    $submitter = $this->academicFor($class, now());
                    foreach ($students as $student) {
                        if ($this->roll("{$student->id}|{$date}|hw") < self::HOMEWORK[$level]) {
                            $this->asUser($submitter, StudentPortalController::class, 'submitBasket', [
                                'student_id' => $student->id, 'note' => 'PH gửi ảnh bài làm qua Zalo.', 'file' => $this->homeworkPhoto(),
                            ], ['homework' => $homework->id, 'basket' => 'paper']);
                        }
                    }
                });
            }
        }

        // Điểm danh cũ hơn 3 ngày đã được Học vụ rà soát (như DemoPhase2Seeder).
        StudentAttendance::where('class_id', $class->id)->whereDate('session_date', '<', $this->realNow->copy()->subDays(3)->toDateString())
            ->update(['review_status' => 'approved', 'reviewed_by' => $this->academicFor($class, $this->realNow)->id, 'reviewed_at' => $this->realNow]);
    }

    /** Ảnh bài làm PH gửi (PNG 1×1 hợp lệ — SafeUploadService kiểm tra nội dung file). */
    private function homeworkPhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bai-lam.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    // ── Báo cáo giảng dạy tháng ────────────────────────────────────────────────

    private function seedMonthlyReports(): void
    {
        $sheets = app(KpiSheetService::class);
        $teachers = $sheets->staffQuery(Roles::TEACHER_FULLTIME)->get()->concat($sheets->staffQuery(Roles::TEACHER_PARTTIME)->get())
            ->unique('id')
            ->filter(fn (User $u) => in_array('teacher_monthly', StaffType::structuredReports($u), true)
                && (! $u->contract_start_date || $u->contract_start_date->lt($this->thisMonth)));

        foreach ($teachers as $teacher) {
            $period = KpiSheetService::periodFor($teacher, $this->lastMonth->month, $this->lastMonth->year);
            for ($month = $period['from']->copy()->startOfMonth(); $month->lt($this->thisMonth) && $month->lte($period['to']); $month->addMonthNoOverflow()) {
                $key = $month->format('Y-m');
                if (StaffReport::where('user_id', $teacher->id)->where('kind', 'teacher_monthly')->where('period_key', $key)->exists()) {
                    continue;
                }
                $next = $month->copy()->addMonthNoOverflow();
                $at = in_array($teacher->email, self::LATE_MONTHLY, true)
                    ? $next->copy()->setDay(4)->setTime(9, 15)
                    : $next->copy()->setTime(19, 0)->addMinutes(crc32("{$teacher->id}|{$key}") % 150);
                $this->when($at, fn () => $this->asUser($teacher, StaffReportController::class, 'teacherMonthlyStore', [
                    'month' => $key, 'intent' => 'submit',
                    'general' => [
                        'progress' => 'Các lớp bám đúng tiến độ giáo trình tháng '.$month->format('m/Y').'.',
                        'difficulties' => 'Một số học viên nghỉ nhiều, cần nhắc PH cho con học bù.',
                    ],
                ]));
            }
        }
    }

    // ── Nghỉ dạy có phép (buổi DemoPayrollSeeder để trống giờ dạy) ────────────

    /** Buổi vắng sau cùng của từng lớp: xin nghỉ 2 ngày trước, người có quyền duyệt sáng hôm trước; buổi vắng trước vẫn không phép. */
    private function seedLeave(): void
    {
        foreach ([
            ['gv.native1@menglish.edu.vn', ['DEMO-CG-SPK27', 'DEMO-CG-SPK1'], 'manager@menglish.edu.vn'],
            ['gv.banthoigian1@menglish.edu.vn', ['DEMO-DD-GT27'], 'admin@menglish.edu.vn'],
            ['gv.thaonguyen@menglish.edu.vn', ['DEMO-BD-GT1'], 'manager.bd@menglish.edu.vn'],
        ] as [$email, $classCodes, $managerEmail]) {
            $teacher = $this->user($email);
            $manager = $this->user($managerEmail);
            if (! $teacher || ! $manager) {
                continue;
            }
            foreach (ClassModel::whereIn('code', $classCodes)->get() as $class) {
                // Ca chấm tay do quên check-in có thể chỉ gắn lớp / ngày; vẫn là đã dạy, không chọn để lập đơn nghỉ.
                $taught = TeacherTimesheet::where('class_id', $class->id)->where('user_id', $teacher->id)
                    ->where('status', '!=', 'invalid')->get();
                $session = ClassSession::where('class_id', $class->id)->where('teacher_id', $teacher->id)
                    ->where('status', '!=', 'cancelled')->whereDate('date', '<', $this->realNow->toDateString())
                    ->whereNotIn('id', $taught->pluck('class_session_id')->filter())
                    ->whereNotIn('date', $taught->whereNull('class_session_id')->pluck('teaching_date')->map(fn ($date) => $date->toDateString()))
                    ->orderByDesc('date')->orderByDesc('start_time')->first();
                if (! $session) {
                    continue;
                }
                $day = $session->date->copy();
                $this->when($day->copy()->subDays(2)->setTime(21, 0), function () use ($teacher, $manager, $day) {
                    $request = StaffAttendanceRequest::create([
                        'user_id' => $teacher->id, 'branch_id' => $teacher->branch_id, 'type' => StaffAttendanceRequest::TYPE_LEAVE,
                        'date_from' => $day->toDateString(), 'date_to' => $day->toDateString(),
                        'reason' => 'Xin nghỉ buổi dạy để giải quyết giấy tờ cá nhân, đã bố trí giáo viên dạy thay.', 'status' => 'pending',
                    ]);
                    Carbon::setTestNow($day->copy()->subDay()->setTime(9, 10));
                    $request->update(['status' => 'approved', 'reviewed_by' => $manager->id, 'reviewed_at' => now()]);
                });
            }
        }
    }

    // ── Sale / Học vụ: lead mới, order in ấn + kiểm kê ─────────────────────────

    /** Lead mới: người phụ trách tự nhập; đúng hạn thì gọi trong 3 giờ, quá hạn thì mốc SLA liên hệ lần đầu ghi nhận trễ. */
    private function seedLeads(Carbon $month): void
    {
        $hours = (int) Sla::value('crm.first_contact');
        $breaches = app(SlaBreachService::class);
        $m = $month->eq($this->lastMonth) ? 0 : 1;
        foreach (array_keys(self::LEADS) as $u => $email) {
            $owner = $this->user($email);
            if (! $owner) {
                continue;
            }
            [$late, $onTime] = self::LEADS[$email];
            for ($k = 0; $k < $late + $onTime; $k++) {
                $isLate = $k < $late;
                $created = $month->copy()->addDays(2 + $k * 4 + $u)->setTime(9 + $k % 3, 30);
                $due = $created->copy()->addHours($hours);
                if ($due->copy()->addHour()->gte($this->realNow)) {
                    continue;
                }
                $phone = sprintf('03889%05d', $m * 1000 + $u * 50 + $k);
                $name = self::FAMILY[($u + $k) % count(self::FAMILY)].' '.self::NAMES[($u * 5 + $k + $m * 7) % count(self::NAMES)];
                $customer = null;
                $this->when($created, function () use ($owner, $phone, $name, &$customer) {
                    $this->asUser($owner, CrmController::class, 'storeCustomer', [
                        'name' => '# '.$name, 'phone' => $phone, 'parent_name' => 'PH em '.$name, 'source' => 'Facebook',
                        'branch_id' => $owner->branch_id ?? $this->branches['CG']->id, 'course_interest' => 'Giao tiếp Pro B1',
                        'notes' => 'Lead demo KPI '.self::MARKER.'.',
                    ]);
                    $customer = CrmCustomer::where('phone', $phone)->firstOrFail();
                });
                if (! $isLate) {
                    $this->when($created->copy()->addHours(2), fn () => CrmCustomerHistory::create([
                        'customer_id' => $customer->id, 'user_id' => $owner->id, 'type' => 'call',
                        'content' => 'Gọi PH tư vấn khóa học, hẹn test thử cuối tuần.',
                    ]));
                }
                $this->when($due->copy()->addHour(), function () use ($breaches, $customer, $owner, $created, $due, $isLate) {
                    $event = $breaches->open('crm.first_contact', 'crm_customer', $customer->id, $owner, $created, $due);
                    if (! $isLate) {
                        $breaches->resolve($event, $created->copy()->addHours(2));

                        return;
                    }
                    $breaches->breach($event, "{$customer->short_code} {$customer->name}", [
                        'Khách hàng' => "{$customer->short_code} — {$customer->name} ({$customer->phone})",
                        'Thêm vào hệ thống lúc' => $created->format('H:i d/m/Y'),
                        'Trạng thái hiện tại' => CrmCustomer::PIPELINE_STAGES[$customer->stage] ?? $customer->stage,
                        'Hoạt động hợp lệ đầu tiên' => 'Chưa có',
                    ], route('crm.customers.show', $customer->id), now());
                });
            }
        }
    }

    /** Order in ấn do GV gửi, Học vụ xử lý (trễ hạn hoặc trước hạn); Học vụ kém kiểm kê sách lệch số. */
    private function seedOrders(Carbon $month): void
    {
        $orders = app(MaterialOrderService::class);
        $requester = $this->user('gv.cohuu2@menglish.edu.vn') ?? $this->user('admin@menglish.edu.vn');
        foreach (self::ORDERS as $k => [$email, $branchCode, $late]) {
            $processor = $this->user($email);
            $branch = $this->branches[$branchCode] ?? null;
            if (! $processor || ! $branch) {
                continue;
            }
            $created = $month->copy()->addDays(3 + $k * 6)->setTime(14, 0);
            $order = null;
            $this->when($created, function () use ($orders, $requester, $branch, $created, $k, &$order) {
                $order = $orders->create($requester, [
                    'branch_id' => $branch->id, 'category' => MaterialOrder::CATEGORY_PRINTING,
                    'title' => ['In phiếu bài tập Unit 3 (30 bản)', 'In đề kiểm tra giữa khóa (25 bản)', 'In giấy khen cuối khóa (12 bản)'][$k % 3],
                    'quantity' => 30, 'use_date' => $created->copy()->addDays(4)->toDateString(),
                    'description' => 'Order demo KPI '.self::MARKER.'.',
                ]);
            });
            if (! $order) {
                continue;
            }
            $done = $late ? $order->due_at->copy()->addHours(5) : $order->due_at->copy()->subDay()->setTime(16, 0);
            $this->when($done, fn () => $orders->finish($order, $processor, MaterialOrder::STATUS_DONE, 'Đã in và để ở quầy lễ tân.'));
        }

        // Kiểm kê sách cuối tháng (Ba Đình) lệch 2 cuốn so với số trên hệ thống.
        $counter = $this->user('giaovu2@menglish.edu.vn');
        $item = MerchandiseItem::query()->orderBy('id')->first();
        if ($counter && $item && isset($this->branches['BD'])) {
            $this->when($month->copy()->addDays(20)->setTime(17, 30), function () use ($counter, $item) {
                Auth::setUser($counter);
                $current = (int) (MerchandiseStock::where('merchandise_item_id', $item->id)->where('branch_id', $this->branches['BD']->id)->value('quantity') ?? 0);
                app(StockService::class)->count($item->id, $this->branches['BD']->id, $current >= 2 ? $current - 2 : $current + 2, 'Kiểm kê cuối tháng '.self::MARKER);
            });
        }
    }

    // ── Học phí đến hạn: nhắc / không nhắc, phiếu thu bị trả về ─────────────────

    /** @param Collection<string, ClassModel> $classes */
    private function seedTuitions(Collection $classes): void
    {
        $taken = [];
        $rejected = false;
        foreach (self::TUITIONS as [$email, $code, $reminded, $monthKey, $dueDay]) {
            $owner = $this->user($email);
            $class = $classes->get($code);
            $student = $class ? Student::where('current_class_id', $class->id)->where('code', 'like', self::STUDENT_PREFIX.'%')
                ->whereNotIn('id', $taken)->orderByDesc('id')->first() : null;
            if (! $owner || ! $student) {
                continue;
            }
            $taken[] = $student->id;
            $month = $monthKey === 'L' ? $this->lastMonth : $this->thisMonth;
            $due = $month->copy()->setDay($dueDay);
            $fee = (float) ($class->tuition_fee ?: 4500000);
            $tuition = null;

            // Học vụ tư vấn, chốt khách thành học viên trước khai giảng; học phí khóa hạn ngày $dueDay.
            $this->when(Carbon::parse($class->start_date)->subDays(5)->setTime(10, 0), function () use ($owner, $student, $class, $due, $fee, &$tuition) {
                $this->asUser($owner, CrmController::class, 'storeCustomer', [
                    'name' => $student->name, 'phone' => $student->parent_phone, 'parent_name' => $student->parent_name, 'source' => 'Giới thiệu',
                    'branch_id' => $owner->branch_id ?? $class->branch_id, 'course_interest' => $class->program, 'deal_value' => $fee,
                    'notes' => 'Khách demo KPI '.self::MARKER.'.',
                ]);
                CrmCustomer::where('phone', $student->parent_phone)->firstOrFail()
                    ->forceFill(['stage' => 'won', 'converted_student_id' => $student->id])->saveQuietly();
                $tuition = StudentTuition::create([
                    'student_id' => $student->id, 'class_id' => $class->id, 'branch_id' => $class->branch_id,
                    'total_amount' => $fee, 'session_count' => 36, 'final_amount' => $fee, 'paid_amount' => 0, 'debt_amount' => $fee,
                    'due_date' => $due->toDateString(), 'status' => 'unpaid', 'notes' => 'Học phí khóa '.$class->level.' '.self::MARKER,
                ]);
            });
            if (! $tuition) {
                continue;
            }
            if ($reminded) {
                $this->when($due->copy()->subDays(2)->setTime(16, 0), fn () => $this->asUser($owner, TuitionController::class, 'markContacted', [
                    'note' => 'Đã gọi PH nhắc học phí, PH hẹn chuyển khoản cuối tuần.',
                ], ['id' => $tuition->id]));
            }
            // Tháng trước: Học vụ lập phiếu thu tiền mặt thiếu số hóa đơn giấy khớp sổ → Admin trả về.
            if ($reminded && $monthKey === 'L' && ! $rejected) {
                $rejected = true;
                $this->when($due->copy()->addDay()->setTime(17, 0), fn () => $this->asUser($owner, TuitionController::class, 'storeReceipt', [
                    'student_tuition_id' => $tuition->id, 'amount' => 2000000, 'tuition_amount' => 2000000, 'payment_method' => 'cash',
                    'payer_name' => $student->parent_name, 'paper_invoice_number' => 'HDG-DEMO-KPI-'.$tuition->id, 'notes' => 'Thu đợt 1 '.self::MARKER,
                    'submit_action' => 'submit',
                ]));
                $this->when($due->copy()->addDays(2)->setTime(9, 30), function () use ($tuition) {
                    $receipt = TuitionReceipt::where('student_tuition_id', $tuition->id)->latest('id')->firstOrFail();
                    $this->asUser($this->user('admin@menglish.edu.vn'), TuitionController::class, 'rejectReceiptAction', [
                        'rejection_reason' => 'Số hóa đơn giấy không khớp sổ hóa đơn của cơ sở, kiểm tra lại cuống phiếu.',
                    ], ['id' => $receipt->id]);
                });
            }
        }
    }
}
