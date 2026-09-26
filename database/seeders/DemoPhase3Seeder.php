<?php

namespace Database\Seeders;

use App\Http\Controllers\CrmController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PenaltyController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\TuitionController;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\CrmCustomer;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StudentTuition;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\CrmStageService;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dữ liệu demo Phase 3 (buổi dạy → chấm công → phạt / hoa hồng / KPI → bảng lương → duyệt → nhân viên xem lương),
 * dựng trên tài khoản UserSeeder, lớp / buổi học / học viên của DemoPhase1Seeder + DemoPhase2Seeder.
 *
 * Mốc thời gian tương đối với hôm nay: L = tháng trước (kỳ lương ĐÃ DUYỆT, khóa), C = tháng này (kỳ lương ĐANG SOÁT,
 * đã tính). Mọi sự kiện được xếp theo thời gian và chạy với "đồng hồ" đặt đúng lúc xảy ra (check-in lúc vào ca, duyệt
 * kỳ tháng trước ngày 2 tháng này…), để các quy tắc phụ thuộc thời gian (hạn nộp phạt 2 ngày, gate 30 ngày, khóa kỳ)
 * chạy đúng như thật.
 *
 * - Đơn giá buổi riêng từng GV / TA (GV part-time có 2 phiên bản: tháng trước 220.000đ/buổi, từ tháng này 250.000đ/buổi).
 * - Chấm công từ buổi học thật: GV / TA tự check-in đúng ngày buổi học; 1 buổi GV quên check-in → Học vụ chấm công tay
 *   (lý do + giờ vào/ra), thử chấm lại lần 2 bị từ chối (không tạo bản ghi); 1 ca chấm tay không có buổi trên lịch bị
 *   từ chối khi duyệt; Học vụ duyệt các ca hằng ngày.
 * - Kỷ luật đủ các bước: chờ giải trình → đã giải trình → xác nhận lỗi / quyết phạt kèm số tiền → nộp trực tiếp; 1 biên
 *   bản quá hạn nộp → trừ vào kỳ tháng trước (đã trừ khi duyệt), 1 biên bản quá hạn trừ ở kỳ tháng này; 1 biên bản hủy.
 * - KPI Học vụ (6 nhóm / 15 mục) cho 2 Học vụ × 2 tháng, do Quản lý chi nhánh chấm.
 * - Hoa hồng: bậc mặc định 3/4/5% theo số HS chốt; 4 khách mới do Sale chốt qua Chốt & Xếp lớp: 1 khách chốt từ tháng
 *   trước nữa, đủ 3/3 mốc chăm sóc → trả trong kỳ tháng trước; 3 khách chốt đầu tháng trước → hoãn (chưa đủ 30 ngày),
 *   tháng này 2 khách đủ 3/3 mốc → trả, 1 khách 2/3 mốc → tiếp tục hoãn. Khách đã trả hoa hồng hoàn phí → Admin chọn thu
 *   hồi → trừ vào kỳ tháng này của Sale.
 * - Bảng % thưởng tái tục (BA chốt 0 nghỉ → 1%, 1 nghỉ → 0,7%; các mốc khác chờ BA).
 * - Kỳ tháng trước: Kế toán tính → nhập bậc KPI giữ HS / KPI tự do / thuế TNCN / phụ cấp → tính lại → Admin duyệt (khóa).
 *   Kỳ tháng này: Kế toán tính + nhập tay + tính lại, để ở "Đang soát".
 *
 * Chạy cùng điều kiện với DemoPhase1Seeder / DemoPhase2Seeder (DatabaseSeeder). Idempotent: đánh dấu bằng ghi chú
 * "[demo-p3]" trên đơn giá GV — đã có thì bỏ qua toàn bộ luồng (chỉ in số liệu). Toàn bộ chạy trong 1 transaction.
 */
class DemoPhase3Seeder extends Seeder
{
    use InvokesControllersAsUser;

    public const MARKER = '[demo-p3]';

    /** Mật khẩu chung của tài khoản demo: config('access.seed_password'). */
    private const STAFF = [
        'admin' => 'admin@menglish.edu.vn',
        'manager_cg' => 'manager@menglish.edu.vn',
        'manager_bd' => 'manager.bd@menglish.edu.vn',
        'accountant_cg' => 'ketoan2@menglish.edu.vn',
        'accountant_bd' => 'ttb@menglish.edu.vn',
        'lead' => 'academiclead@menglish.edu.vn',
        'academic_cg' => 'nva@menglish.edu.vn',
        'academic_bd' => 'giaovu2@menglish.edu.vn',
        'sales_cg' => 'tranmaia@menglish.edu.vn',
        'sales_bd' => 'hoangthinh@menglish.edu.vn',
        'teacher_cg' => 'nguyenvanan@menglish.edu.vn',
        'teacher_ft_cg' => 'gv.cohuu1@menglish.edu.vn',
        'teacher_bd' => 'gv.cohuu2@menglish.edu.vn',
        'foreign' => 'gv.native1@menglish.edu.vn',
        'assistant_cg' => 'ta.tuan@menglish.edu.vn',
        'assistant_bd' => 'ta.yen@menglish.edu.vn',
    ];

    /** Hồ sơ lương (nhập ở màn Nhân sự): lương cơ bản Full-time, loại hợp đồng GV "teacher". */
    private const SALARY_PROFILES = [
        'teacher_cg' => ['contract_type' => 'Bán thời gian', 'base_salary' => 0],
        'teacher_ft_cg' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 12000000],
        'teacher_bd' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 11000000],
        'academic_cg' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 9000000],
        'academic_bd' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 8500000],
        'lead' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 15000000],
        'sales_cg' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 7000000],
        'sales_bd' => ['contract_type' => 'Toàn thời gian', 'base_salary' => 7000000],
    ];

    /** Khoản Kế toán nhập tay trên phiếu lương mỗi kỳ (giữ khi tính lại). */
    private const MANUAL_INPUTS = [
        'teacher_cg' => ['retention_tier' => 20000, 'foreign_session_pay' => 0, 'lines' => [['kind' => 'earning', 'label' => 'Gửi xe', 'amount' => 100000]]],
        'assistant_cg' => ['retention_tier' => 15000, 'lines' => []],
        'assistant_bd' => ['retention_tier' => 15000, 'lines' => [['kind' => 'deduction', 'label' => 'Tạm ứng', 'amount' => 100000]]],
        'teacher_ft_cg' => ['kpi_manual_amount' => 800000, 'tax_deduction' => 300000, 'lines' => [['kind' => 'earning', 'label' => 'Phụ cấp trách nhiệm', 'amount' => 500000]]],
        'teacher_bd' => ['kpi_manual_amount' => 1000000, 'tax_deduction' => 200000, 'lines' => [['kind' => 'earning', 'label' => 'Hỗ trợ thỏa thuận', 'amount' => 300000]]],
        'lead' => ['kpi_manual_amount' => 1500000, 'tax_deduction' => 750000, 'lines' => []],
        'academic_cg' => ['tax_deduction' => 100000, 'lines' => [['kind' => 'earning', 'label' => 'Gửi xe', 'amount' => 100000]]],
        'academic_bd' => ['tax_deduction' => 50000, 'lines' => []],
        'sales_cg' => ['kpi_manual_amount' => 0, 'tax_deduction' => 0, 'lines' => [['kind' => 'earning', 'label' => 'Thưởng khác', 'amount' => 200000]]],
        'sales_bd' => ['kpi_manual_amount' => 0, 'tax_deduction' => 0, 'lines' => []],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, CrmCustomer> */
    private array $customers = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> */
    private array $events = [];

    private Carbon $realNow;

    private Carbon $lastMonth;

    private Carbon $thisMonth;

    /** Kết quả các bước "phải bị từ chối" (in ra cuối seed). */
    private array $rejections = [];

    public function run(): void
    {
        if (! User::where('email', self::STAFF['lead'])->exists() || ! ClassModel::where('code', 'DEMO-CG-FAM1')->exists()) {
            $this->command?->warn('DemoPhase3Seeder: chưa có tài khoản UserSeeder / lớp DemoPhase1Seeder — bỏ qua.');

            return;
        }
        if (TeacherHourlyRate::where('note', 'like', '%'.self::MARKER.'%')->exists()) {
            $this->printSummary();

            return;
        }

        $this->staff = collect(self::STAFF)->map(fn (string $email) => User::where('email', $email)->firstOrFail())->all();
        foreach (['CG', 'BD'] as $code) {
            foreach (['FAM1', 'FAM0', 'FAM2'] as $key) {
                $this->classes["{$code}-{$key}"] = ClassModel::where('code', "DEMO-{$code}-{$key}")->firstOrFail();
            }
        }

        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $this->realNow = now()->copy();
        $this->thisMonth = $this->realNow->copy()->startOfMonth();
        $this->lastMonth = $this->thisMonth->copy()->subMonthNoOverflow()->startOfMonth();

        try {
            DB::transaction(function () {
                $this->seedSalaryProfiles();
                $this->backdateEarlierSeeds();
                $this->planEvents();
                $this->runEvents();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }

        $this->printSummary();
    }

    // ── Hồ sơ lương ─────────────────────────────────────────────────────────────

    private function seedSalaryProfiles(): void
    {
        foreach (self::SALARY_PROFILES as $key => $profile) {
            $this->staff[$key]->forceFill($profile)->save();
        }
    }

    /**
     * Dữ liệu mẫu của seeder trước (MasterEntitySeeder) mang ngày vi phạm / ngày dạy trong quá khứ nhưng thời điểm ghi là
     * lúc seed; đưa thời điểm ghi về đúng ngày đó để lịch sự kiện (tính / duyệt kỳ tháng trước) nhất quán.
     */
    private function backdateEarlierSeeds(): void
    {
        foreach ([Penalty::class => 'violation_date', TeacherTimesheet::class => 'teaching_date'] as $model => $column) {
            $model::query()->whereDate($column, '<', $this->thisMonth->toDateString())->get()
                ->each(function ($row) use ($column) {
                    $at = Carbon::parse($row->{$column})->setTime(18, 0);
                    $row->newQuery()->whereKey($row->getKey())->update(['created_at' => $at, 'updated_at' => $at]);
                });
        }
    }

    // ── Lịch sự kiện ────────────────────────────────────────────────────────────

    private function event(Carbon $at, Closure $callback): void
    {
        $this->events[] = [$at->copy(), count($this->events), $callback];
    }

    /**
     * Chạy sự kiện theo thời gian. Mốc rơi vào sau "bây giờ − 75 phút" (đầu tháng, tháng ngắn…) được kéo về mốc đó, giữ
     * thứ tự khai báo; khoảng 75 phút cuối dành cho Kế toán tính / nhập tay / tính lại kỳ tháng này.
     */
    private function runEvents(): void
    {
        $ceiling = $this->realNow->copy()->subMinutes(75);
        $events = array_map(fn (array $e) => [$e[0]->lt($ceiling) ? $e[0] : $ceiling->copy(), $e[1], $e[2]], $this->events);
        usort($events, fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
        foreach ($events as [$at, , $callback]) {
            Carbon::setTestNow($at);
            $callback();
        }
    }

    private function planEvents(): void
    {
        $L = $this->lastMonth;
        $C = $this->thisMonth;

        // Cấu hình: bậc hoa hồng mặc định, bảng % thưởng tái tục, đơn giá buổi riêng (có phiên bản cũ / mới).
        $this->event($L->copy()->subMonthNoOverflow()->setTime(8, 0), fn () => $this->seedPayrollConfig());

        // Hoa hồng: khách chốt tháng trước nữa (chưa đóng phí) → đóng phí đầu tháng trước.
        $this->event($L->copy()->subDays(12)->setTime(10, 0), fn () => $this->closeCustomer('K4', paid: false));
        $this->event($L->copy()->addDays(2)->setTime(10, 0), function () {
            foreach (['K1', 'K2', 'K3'] as $key) {
                $this->closeCustomer($key, paid: true);
            }
        });
        $this->event($L->copy()->addDays(2)->setTime(16, 0), function () {
            foreach (['K1', 'K2', 'K3'] as $key) {
                $this->approvePendingReceipts($this->customers[$key], $this->staff['accountant_cg']);
            }
        });
        $this->event($L->copy()->addDays(4)->setTime(10, 0), fn () => $this->collectFee('K4'));
        $this->event($L->copy()->addDays(4)->setTime(15, 0), fn () => $this->approvePendingReceipts($this->customers['K4'], $this->staff['manager_cg']));
        $this->event($L->copy()->addDays(20)->setTime(11, 0), fn () => $this->tickCare('K4', 3));

        // Chấm công từ buổi học thật + duyệt hằng ngày.
        $this->planTimesheets();

        // Kỷ luật.
        $this->planPenalties();

        // KPI Học vụ tháng trước (chấm cuối tháng) và tháng này.
        $this->event($L->copy()->endOfMonth()->setTime(17, 0), fn () => $this->evaluateAcademicKpi($L, 0));
        $this->event($this->realNow->copy()->subHours(3), fn () => $this->evaluateAcademicKpi($C, 1));

        // Kỳ lương tháng trước: Kế toán tính ngày 1, nhập tay, tính lại; Admin duyệt ngày 2.
        $this->event($C->copy()->setTime(8, 0), fn () => $this->calculateLastMonth());
        $this->event($C->copy()->addDay()->setTime(9, 0), function () {
            $period = $this->periodFor($this->lastMonth);
            $this->asUser($this->staff['admin'], PayrollController::class, 'approvePeriod', [], ['id' => $period->id]);
        });

        // Tháng này: tick mốc chăm sóc, hoàn phí có thu hồi hoa hồng.
        $this->event($C->copy()->addDays(4)->setTime(10, 0), function () {
            $this->tickCare('K1', 3);
            $this->tickCare('K2', 3);
            $this->tickCare('K3', 2);
        });
        $this->event($C->copy()->addDays(9)->setTime(9, 30), fn () => $this->refundWithClawback('K4'));

        // Kỳ lương tháng này: Kế toán tính + nhập tay + tính lại (để "Đang soát", chưa duyệt). Luôn là sự kiện cuối.
        $this->event($this->realNow, fn () => $this->calculatePeriod($this->thisMonth));
    }

    // ── Cấu hình lương ──────────────────────────────────────────────────────────

    private function seedPayrollConfig(): void
    {
        $admin = $this->staff['admin'];
        if (! CommissionTier::query()->byStudents()->exists()) {
            foreach (config('payroll.commission.default_tiers', []) as $tier) {
                $this->asUser($admin, PayrollController::class, 'storeCommissionTier', $tier + ['effective_from' => $this->lastMonth->copy()->subYear()->toDateString()]);
            }
        }

        // Bảng % thưởng tái tục: BA chốt 0 nghỉ → 1%, 1 nghỉ → 0,7%; 2, 3 nghỉ và vượt bảng chờ BA (0%).
        $this->asUser($admin, PayrollController::class, 'storeSettings', [
            'insurance_rate_percent' => 10.5, 'union_rate_percent' => 0.5, 'academic_kpi_fund' => 2000000,
            'renewal' => [
                ['quits' => 0, 'percent' => 1, 'pending' => 0],
                ['quits' => 1, 'percent' => 0.7, 'pending' => 0],
                ['quits' => 2, 'percent' => 0, 'pending' => 1],
                ['quits' => 3, 'percent' => 0, 'pending' => 1],
            ],
            'renewal_beyond_percent' => 0,
        ]);

        // Đơn giá buổi riêng (Quản lý cơ sở — teacher_rate.manage): mỗi lần đổi giá là 1 phiên bản theo ngày hiệu lực.
        $manager = $this->staff['manager_cg'];
        $since = $this->lastMonth->copy()->subMonthNoOverflow()->startOfMonth();
        $rates = [
            ['teacher_cg', 220000, $since, 'Đơn giá buổi GV part-time (phiên bản cũ) '.self::MARKER],
            ['teacher_cg', 250000, $this->thisMonth, 'Tăng đơn giá sau đánh giá thử việc '.self::MARKER],
            ['assistant_cg', 120000, $since, 'Đơn giá buổi trợ giảng '.self::MARKER],
            ['assistant_bd', 120000, $since, 'Đơn giá buổi trợ giảng '.self::MARKER],
            ['foreign', 450000, $since, 'Đơn giá buổi GVNN '.self::MARKER],
        ];
        foreach ($rates as [$key, $amount, $from, $note]) {
            $this->asUser($manager, PayrollController::class, 'storePersonalTeacherRate', [
                'user_id' => $this->staff[$key]->id, 'hourly_rate' => $amount, 'rate_unit' => TeacherHourlyRate::UNIT_SESSION,
                'effective_from' => $from->toDateString(), 'note' => $note,
            ]);
        }
    }

    // ── Chấm công ──────────────────────────────────────────────────────────────

    private function planTimesheets(): void
    {
        $classIds = collect($this->classes)->only(['CG-FAM1', 'CG-FAM0', 'BD-FAM1', 'BD-FAM0'])->map->id->values()->all();
        $sessions = ClassSession::with('classModel')->whereIn('class_id', $classIds)
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $this->lastMonth->toDateString())
            ->whereDate('date', '<', $this->realNow->toDateString())
            ->orderBy('date')->orderBy('start_time')->get();

        // GV CG quên check-in 1 buổi của FAM 1 (buổi thứ 2 trong tháng trước, không có thì tháng này).
        $cgFam1 = $sessions->where('class_id', $this->classes['CG-FAM1']->id)->values();
        $forgotten = $cgFam1->filter(fn (ClassSession $s) => $s->date->lt($this->thisMonth))->values()->get(1) ?? $cgFam1->get(1);

        foreach ($sessions as $session) {
            $start = $session->date->copy()->setTimeFromTimeString($session->start_time->format('H:i'));
            if ($forgotten && $session->is($forgotten)) {
                $this->event($start->copy()->setTime(21, 0), fn () => $this->manualTimesheetForForgotten($session));
            } elseif ($session->teacher_id) {
                $this->event($start->copy()->subMinutes(10), fn () => $this->checkin(User::findOrFail($session->teacher_id), $session));
            }
            // Trợ giảng check-in các buổi FAM 1.
            if ($session->assistant_id && str_ends_with($session->classModel->code, 'FAM1')) {
                $this->event($start->copy()->subMinutes(5), fn () => $this->checkin(User::findOrFail($session->assistant_id), $session));
            }
        }

        // Ca chấm tay không có buổi học trên lịch (workshop): chờ duyệt → bị từ chối khi duyệt.
        $noSessionDay = $this->thisMonth->copy()->addDays(3);
        while ($this->classes['CG-FAM1']->sessions()->whereDate('date', $noSessionDay->toDateString())->exists()) {
            $noSessionDay->addDay();
        }
        if ($noSessionDay->lt($this->realNow->copy()->startOfDay())) {
            $this->event($noSessionDay->copy()->setTime(18, 0), fn () => $this->asUser($this->staff['academic_cg'], PayrollController::class, 'storeTimesheet', [
                'user_id' => $this->staff['assistant_cg']->id, 'class_id' => $this->classes['CG-FAM1']->id,
                'teaching_date' => $noSessionDay->toDateString(), 'time_in' => '14:00', 'time_out' => '16:00', 'type' => 'workshop',
                'notes' => 'Hỗ trợ workshop phụ huynh ngoài lịch học (TA đề nghị tính công).',
            ]));
        }

        // Học vụ chi nhánh duyệt ca của ngày hôm trước lúc 07:30 mỗi ngày; cuối cùng rà các ca còn chờ.
        for ($day = $this->lastMonth->copy()->addDay(); $day->lte($this->realNow); $day->addDay()) {
            $at = $day->copy()->setTime(7, 30);
            $this->event($at, fn () => $this->reviewPendingTimesheets($at->toDateString()));
        }
        $this->event($this->realNow->copy()->subMinutes(80), fn () => $this->reviewPendingTimesheets($this->realNow->copy()->addDay()->toDateString()));
    }

    /** GV / TA tự check-in buổi học thật hôm nay (TeacherPortalController@checkin). */
    private function checkin(User $user, ClassSession $session): void
    {
        $this->asUser($user, TeacherPortalController::class, 'checkin', ['session_ids' => [$session->id]]);
    }

    /** GV quên check-in → Học vụ chấm công tay (bắt buộc lý do + giờ vào/ra); chấm lại lần 2 bị chặn. */
    private function manualTimesheetForForgotten(ClassSession $session): void
    {
        $input = [
            'user_id' => $session->teacher_id, 'class_id' => $session->class_id, 'teaching_date' => $session->date->toDateString(),
            'time_in' => $session->start_time->format('H:i'), 'time_out' => $session->end_time->format('H:i'), 'type' => 'regular',
            'notes' => 'GV quên check-in trên cổng GV — đối chiếu sổ điểm danh và camera lớp, xác nhận có dạy.',
        ];
        $academic = $this->staff['academic_cg'];
        $this->asUser($academic, PayrollController::class, 'storeTimesheet', $input);

        $before = TeacherTimesheet::count();
        try {
            $this->asUser($academic, PayrollController::class, 'storeTimesheet', ['notes' => 'Nhập lại lần 2 (trùng buổi).'] + $input);
            throw new RuntimeException('DemoPhase3Seeder: chấm công tay trùng buổi không bị chặn.');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'không bị chặn') || TeacherTimesheet::count() !== $before) {
                throw $e;
            }
            $this->rejections[] = ['Chấm công tay trùng buổi '.$session->date->format('d/m/Y'), trim(substr($e->getMessage(), strpos($e->getMessage(), 'báo lỗi:') + 10))];
        }
    }

    /**
     * Học vụ chi nhánh duyệt ca chờ duyệt có ngày dạy trước $beforeDate: ca gắn buổi học thật → hợp lệ; ca không có buổi
     * học trên lịch → từ chối (chỉ tính công buổi dạy có thật).
     */
    private function reviewPendingTimesheets(string $beforeDate): void
    {
        $pending = TeacherTimesheet::with('classModel')->where('status', 'pending_review')
            ->whereDate('teaching_date', '<', $beforeDate)->orderBy('id')->get();
        foreach ($pending as $timesheet) {
            $reviewer = $timesheet->classModel?->branch_id === $this->classes['BD-FAM1']->branch_id ? $this->staff['academic_bd'] : $this->staff['academic_cg'];
            $input = $timesheet->class_session_id
                ? ['decision' => 'valid']
                : ['decision' => 'invalid', 'rejection_reason' => 'Không có buổi học thật trên lịch lớp ngày này — không tính công.'];
            $this->asUser($reviewer, PayrollController::class, 'reviewTimesheet', $input, ['id' => $timesheet->id]);
        }
    }

    // ── Kỷ luật ────────────────────────────────────────────────────────────────

    private function planPenalties(): void
    {
        $L = $this->lastMonth;
        $C = $this->thisMonth;
        $now = $this->realNow;
        $cgSessionDay = ClassSession::where('class_id', $this->classes['CG-FAM1']->id)->whereBetween('date', [$L->toDateString(), $L->copy()->endOfMonth()->toDateString()])
            ->orderBy('date')->value('date');
        $violationDay = $cgSessionDay ? Carbon::parse($cgSessionDay)->min($L->copy()->addDays(20)) : $L->copy()->addDays(9);
        $bdDay = $L->copy()->addDays(14);

        // Tháng trước — quá hạn nộp → trừ lương kỳ tháng trước (đã trừ khi Admin duyệt).
        $this->penaltyFlow('P1', $violationDay->copy()->setTime(18, 0), [
            'reporter' => 'academic_cg', 'user' => 'teacher_cg', 'category' => 'operations', 'type' => 'Đến muộn > 15 phút không báo trước',
            'class' => 'CG-FAM1', 'notes' => 'Vào lớp 17:50, lớp phải chờ, Học vụ ghi nhận.',
            'explain' => 'Em bị hỏng xe trên đường, đã nhắn Zalo cho TA nhưng chưa báo Học vụ. Em xin rút kinh nghiệm.',
            'decider' => 'manager_cg', 'fine' => 100000, 'note' => 'Vi phạm lần đầu — phạt mức thấp nhất.',
        ]);
        // Tháng trước — lỗi chuyên môn, HT quyết phạt, nhân sự nộp trực tiếp trong hạn → không trừ lương.
        $this->penaltyFlow('P2', $bdDay->copy()->setTime(9, 0), [
            'reporter' => 'academic_bd', 'user' => 'teacher_bd', 'category' => 'academic', 'type' => 'Chậm nộp nhận xét buổi học (> 24h)',
            'class' => 'BD-FAM1', 'notes' => 'Nhận xét buổi học nộp sau 2 ngày.',
            'explain' => 'Tuần này em chấm Big Test nên nộp nhận xét muộn, em đã bổ sung đầy đủ.',
            'decider' => 'lead', 'fine' => 200000, 'note' => 'Lỗi chuyên môn — HT chốt.', 'paid_by' => 'manager_bd',
        ]);
        // Tháng này.
        $this->penaltyFlow('P3', $C->copy()->addDays(2)->setTime(18, 0), [
            'reporter' => 'academic_cg', 'user' => 'assistant_cg', 'category' => 'operations', 'type' => 'Không check-in / điểm danh đúng giờ',
            'class' => 'CG-FAM1', 'explain' => 'Điện thoại hết pin nên em check-in muộn, em đã báo GV.',
            'decider' => 'manager_cg', 'fine' => null, 'note' => 'Xác nhận lỗi, nhắc nhở lần 1 (chưa phạt tiền).',
        ]);
        $this->penaltyFlow('P4', $C->copy()->addDays(7)->setTime(9, 0), [
            'reporter' => 'lead', 'user' => 'teacher_cg', 'category' => 'academic', 'type' => 'Không nộp giáo án / bài tập đúng hạn',
            'class' => 'CG-FAM0', 'explain' => 'Em nộp giáo án muộn 1 ngày do lịch dạy dày.',
            'decider' => 'lead', 'fine' => 150000, 'note' => 'Nộp trong 2 ngày, quá hạn trừ lương.',
        ]);
        $this->penaltyFlow('P5', $now->copy()->subDays(3)->setTime(9, 0), [
            'reporter' => 'academic_bd', 'user' => 'teacher_bd', 'category' => 'operations', 'type' => 'Vi phạm nội quy trung tâm',
            'class' => 'BD-FAM0', 'explain' => 'Em để quên chìa khóa tủ tài liệu, đã gửi lại Học vụ ngay hôm sau.',
            'decider' => 'manager_bd', 'fine' => 100000, 'note' => 'Hạn nộp 2 ngày.', 'decided_after_hours' => 40,
        ]);
        $this->penaltyFlow('P6', $now->copy()->subDays(2)->setTime(10, 0), [
            'reporter' => 'academic_bd', 'user' => 'assistant_bd', 'category' => 'operations', 'type' => 'Đến muộn > 15 phút không báo trước',
            'class' => 'BD-FAM1', 'explain' => 'Em bị tắc đường do mưa lớn, đã báo GV chính.',
        ]);
        $this->penaltyFlow('P7', $now->copy()->subDay()->setTime(10, 0), [
            'reporter' => 'lead', 'user' => 'teacher_ft_cg', 'category' => 'academic', 'type' => 'Dạy sai tiến độ giáo trình', 'class' => 'CG-FAM2',
        ]);
        $this->penaltyFlow('P8', $C->copy()->addDays(5)->setTime(11, 0), [
            'reporter' => 'academic_cg', 'user' => 'assistant_cg', 'category' => 'operations', 'type' => 'Nghỉ dạy không phép', 'class' => 'CG-FAM0',
            'cancel_by' => 'manager_cg',
        ]);
    }

    /**
     * Một biên bản đi qua các bước theo $flow: ghi nhận → (giải trình sau 6 giờ) → (chốt sau 1 ngày: xác nhận lỗi hoặc
     * quyết phạt kèm số tiền) → (nộp trực tiếp sau 1 ngày) | (hủy).
     */
    private function penaltyFlow(string $key, Carbon $at, array $flow): void
    {
        $find = fn () => Penalty::where('notes', 'like', "%[{$key}]%")->firstOrFail();

        $this->event($at, fn () => $this->asUser($this->staff[$flow['reporter']], PenaltyController::class, 'storePenalty', [
            'user_id' => $this->staff[$flow['user']]->id, 'error_category' => $flow['category'], 'violation_type' => $flow['type'],
            'violation_date' => $at->toDateString(), 'class_id' => $this->classes[$flow['class']]->id,
            'notes' => trim(($flow['notes'] ?? '').' ['.$key.']'),
        ]));
        if (isset($flow['cancel_by'])) {
            $this->event($at->copy()->addHours(3), fn () => $this->asUser($this->staff[$flow['cancel_by']], PenaltyController::class, 'cancelPenalty', [], ['id' => $find()->id]));

            return;
        }
        if (isset($flow['explain'])) {
            $this->event($at->copy()->addHours(6), fn () => $this->asUser($this->staff[$flow['user']], PenaltyController::class, 'explain', ['explanation' => $flow['explain']], ['id' => $find()->id]));
        }
        if (array_key_exists('fine', $flow)) {
            $decidedAt = $at->copy()->addHours($flow['decided_after_hours'] ?? 25);
            $this->event($decidedAt, fn () => $this->asUser($this->staff[$flow['decider']], PenaltyController::class, 'confirmPenalty', array_filter([
                'decision' => $flow['fine'] ? 'fine' : 'error', 'amount' => $flow['fine'], 'decision_note' => $flow['note'] ?? null,
            ], fn ($v) => $v !== null), ['id' => $find()->id]));
            if (isset($flow['paid_by'])) {
                $this->event($decidedAt->copy()->addDay(), fn () => $this->asUser($this->staff[$flow['paid_by']], PenaltyController::class, 'markPaidPenalty', [], ['id' => $find()->id]));
            }
        }
    }

    // ── KPI Học vụ ─────────────────────────────────────────────────────────────

    /** Quản lý chi nhánh chấm KPI tháng cho Học vụ chi nhánh mình (15 mục, 6 nhóm). */
    private function evaluateAcademicKpi(Carbon $month, int $variant): void
    {
        $criteria = KpiCriterion::active()->ordered()->get();
        foreach ([['manager_cg', 'academic_cg'], ['manager_bd', 'academic_bd']] as $i => [$managerKey, $staffKey]) {
            $scores = $criteria->values()->mapWithKeys(fn (KpiCriterion $c, int $j) => [$c->id => match (($j + $i * 3 + $variant * 5) % 7) {
                0 => 50,
                3 => $i === 1 ? 0 : 100,
                default => 100,
            }])->all();
            $this->asUser($this->staff[$managerKey], KpiController::class, 'evaluateStore', [
                'month' => $month->month, 'year' => $month->year, 'score' => $scores,
                'comment' => 'Đánh giá KPI tháng '.$month->format('m/Y').' (demo Phase 3).',
            ], ['userId' => $this->staff[$staffKey]->id]);
        }
    }

    // ── Hoa hồng: khách mới ────────────────────────────────────────────────────

    private const CUSTOMERS = [
        'K1' => ['# Đoàn Minh Khang', 'Đoàn Thu Hương', 1],
        'K2' => ['# Lưu Bảo Trâm', 'Lưu Văn Thành', 2],
        'K3' => ['# Tống Gia Hưng', 'Tống Thị Mai', 3],
        'K4' => ['# Mạc Anh Thư', 'Mạc Văn Toản', 4],
    ];

    /** Sale nhập khách → CM (Học vụ) chuyển "Đang tư vấn" → Quản lý Chốt & Xếp lớp FAM 1 Cầu Giấy. */
    private function closeCustomer(string $key, bool $paid): void
    {
        [$name, $parent, $n] = self::CUSTOMERS[$key];
        $phone = sprintf('0377%06d', $n);
        $class = $this->classes['CG-FAM1'];

        $this->asUser($this->staff['sales_cg'], CrmController::class, 'storeCustomer', [
            'name' => $name, 'phone' => $phone, 'parent_name' => $parent, 'parent_phone' => sprintf('0367%06d', $n),
            'source' => 'Bạn bè giới thiệu', 'branch_id' => $class->branch_id, 'course_interest' => 'Starters (FAM 1)',
            'deal_value' => $class->course->tuition_fee, 'notes' => 'Khách demo Phase 3 (hoa hồng tuyển sinh).',
        ]);
        $customer = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
        app(CrmStageService::class)->move($customer, 'consulting', $this->staff['academic_cg']);

        $this->asUser($this->staff['manager_cg'], CrmController::class, 'processClosingWizard', array_filter([
            'customer_id' => $customer->id, 'class_id' => $class->id,
            'fee_paid_at_closing' => $paid ? 1 : 0,
            'paid_amount' => $paid ? (float) $class->course->tuition_fee : null,
            'payment_method' => $paid ? 'cash' : null,
            'bill_notes' => 'Chốt demo Phase 3.',
        ], fn ($v) => $v !== null));

        $this->customers[$key] = $customer->refresh();
    }

    /** Kế toán lập phiếu thu học phí (tiền mặt) cho khách chốt chưa đóng phí. */
    private function collectFee(string $key): void
    {
        $tuition = StudentTuition::where('student_id', $this->customers[$key]->converted_student_id)->orderBy('id')->firstOrFail();
        $amount = (float) $tuition->final_amount - (float) $tuition->paid_amount;
        $this->asUser($this->staff['accountant_cg'], TuitionController::class, 'storeReceipt', [
            'student_tuition_id' => $tuition->id, 'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => 'cash',
            'payer_name' => self::CUSTOMERS[$key][1], 'notes' => 'PH đóng học phí tại quầy (demo Phase 3).', 'submit_action' => 'submit',
        ]);
    }

    private function approvePendingReceipts(CrmCustomer $customer, User $approver): void
    {
        $ids = TuitionReceipt::where('student_id', $customer->converted_student_id)->where('status', TuitionReceipt::STATUS_PENDING)->pluck('id');
        foreach ($ids as $id) {
            $this->asUser($approver, TuitionController::class, 'approveReceiptAction', [], ['id' => $id]);
        }
    }

    /** Quản lý cơ sở tick mốc chăm sóc tháng đầu ở checklist CRM (gate hoa hồng: đủ 3/3). */
    private function tickCare(string $key, int $count): void
    {
        $items = array_slice(['session_1', 'session_4_5', 'day_30'], 0, $count);
        $this->asUser($this->staff['manager_cg'], CrmController::class, 'updateCareChecklist', [
            'items' => $items, 'note' => $count === 3 ? 'Đủ 3 mốc chăm sóc tháng đầu.' : 'PH chưa phản hồi cuộc gọi mốc đủ 30 ngày.',
        ], ['id' => $this->customers[$key]->id]);
    }

    /** Kế toán lập hồ sơ hoàn phí; Admin duyệt và chọn THU HỒI hoa hồng (khách đã được trả hoa hồng kỳ trước). */
    private function refundWithClawback(string $key): void
    {
        $studentId = $this->customers[$key]->converted_student_id;
        $this->asUser($this->staff['accountant_cg'], TuitionController::class, 'storeRefundRequest', [
            'student_id' => $studentId, 'type' => 'refund', 'refund_amount' => 3000000,
            'reason' => 'Gia đình chuyển công tác vào TP.HCM, xin hoàn phần học phí chưa học.',
            // A6 "Hoàn phí": ưu tiên chuyển nhượng — hoàn tiền phải ghi lý do không chuyển nhượng.
            'no_transfer_reason' => 'Không có học viên nhận buổi dư, phụ huynh yêu cầu hoàn tiền.',
        ]);
        $refund = TuitionRefundRequest::where('student_id', $studentId)->where('status', 'pending')->latest('id')->firstOrFail();
        // Admin duyệt kèm ảnh bằng chứng chi tiền (bắt buộc từ Phase 4).
        $this->withRefundProof(fn () => $this->asUser($this->staff['admin'], TuitionController::class, 'approveRefundRequest', ['clawback_commission' => 1], ['id' => $refund->id]));
    }

    /**
     * Gắn ảnh bằng chứng (PNG 1×1) vào request mà InvokesControllersAsUser dựng: request được bind vào container
     * trước khi gọi controller, nên gắn file khi container resolve 'request'.
     */
    private function withRefundProof(Closure $callback): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'refund-proof');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $file = new \Illuminate\Http\UploadedFile($path, 'uy-nhiem-chi.png', 'image/png', null, true);
        $pending = true;
        app()->rebinding('request', function ($app, $request) use ($file, &$pending) {
            if ($pending && $request->getPathInfo() === '/demo-seed') {
                $request->files->set('proof_image', $file);
                $pending = false;
            }
        });

        try {
            return $callback();
        } finally {
            $pending = false;
            @unlink($path);
        }
    }

    // ── Kỳ lương ───────────────────────────────────────────────────────────────

    private function periodFor(Carbon $month): ?PayrollPeriod
    {
        return PayrollPeriod::where('year', $month->year)->where('month', $month->month)->first();
    }

    /** Kế toán tạo (hoặc tính lại) kỳ, nhập các khoản tay trên phiếu, rồi "Đồng bộ & Tính lại". */
    private function calculatePeriod(Carbon $month): PayrollPeriod
    {
        $accountant = $this->staff['accountant_cg'];
        $period = $this->periodFor($month);
        if ($period) {
            $this->asUser($accountant, PayrollController::class, 'calculatePeriod', [], ['id' => $period->id]);
        } else {
            $this->asUser($accountant, PayrollController::class, 'storePeriod', ['month' => $month->month, 'year' => $month->year]);
            $period = $this->periodFor($month);
        }

        Carbon::setTestNow(now()->addMinutes(30));
        foreach (self::MANUAL_INPUTS as $key => $input) {
            $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->staff[$key]->id)->first();
            if (! $record) {
                continue;
            }
            $this->asUser($accountant, PayrollController::class, 'adjustRecord', $input + [
                'adjustment_notes' => 'Kế toán nhập tay kỳ '.$month->format('m/Y').' (demo Phase 3).',
            ], ['id' => $record->id]);
        }
        Carbon::setTestNow(now()->addMinutes(15));
        $this->asUser($accountant, PayrollController::class, 'calculatePeriod', [], ['id' => $period->id]);

        return $period->refresh();
    }

    private function calculateLastMonth(): void
    {
        $this->calculatePeriod($this->lastMonth);
    }

    // ── Tổng kết ───────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }
        $countBy = fn ($query, string $column) => $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k')
            ->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ');
        $rows = [
            ['teacher_hourly_rates (demo, đ/buổi)', TeacherHourlyRate::where('note', 'like', '%'.self::MARKER.'%')->count()],
            ['teacher_timesheets theo trạng thái', $countBy(TeacherTimesheet::query(), 'status')],
            ['teacher_timesheets theo nguồn', $countBy(TeacherTimesheet::query(), 'source')],
            ['penalties theo trạng thái', $countBy(Penalty::query(), 'status')],
            ['kpi_evaluations (đã chốt)', KpiEvaluation::where('status', 'confirmed')->count()],
            ['commission_tiers theo số HS (đang hiệu lực)', CommissionTier::query()->byStudents()->whereNull('effective_to')->orderBy('min_students')->get()
                ->map(fn ($t) => $t->new_sale_percent.'%')->implode(' / ')],
            ['commission_items theo trạng thái', $countBy(CommissionItem::query(), 'status')],
            ['commission_adjustments (thu hồi)', CommissionAdjustment::count().' ('.number_format((float) CommissionAdjustment::sum('amount'), 0, ',', '.').'đ)'],
            ['tuition_refund_requests (có thu hồi)', TuitionRefundRequest::where('clawback_commission', true)->count()],
            ['payroll_periods', PayrollPeriod::orderBy('start_date')->get()->map(fn ($p) => "{$p->code} {$p->status} (".$p->records()->count().' phiếu)')->implode(', ')],
        ];
        $this->command->table(['Phase 3', 'Số dòng'], $rows);
        foreach ($this->rejections as [$what, $why]) {
            $this->command->line("Bị từ chối đúng quy tắc — {$what}: {$why}");
        }

        foreach ([$this->lastMonth ?? now()->subMonthNoOverflow()->startOfMonth(), $this->thisMonth ?? now()->startOfMonth()] as $month) {
            $period = PayrollPeriod::where('year', $month->year)->where('month', $month->month)->first();
            if (! $period) {
                continue;
            }
            $money = fn ($v) => number_format((float) $v, 0, ',', '.');
            $records = $period->records()->with('user')->orderBy('employee_type')->orderBy('id')->get();
            $this->command->line("{$period->code} — {$period->status_label} — tổng thực lĩnh ".$money($period->total_amount).'đ');
            $this->command->table(
                ['Nhân sự', 'Loại', 'Buổi', 'Lương CB / buổi', 'KPI', 'Hoa hồng (hoãn)', 'Tái tục', 'Cộng tự do', 'BHXH+CĐ', 'TNCN', 'Phạt', 'Thu hồi', 'Trừ tự do', 'Thực lĩnh'],
                $records->map(fn (PayrollRecord $r) => [
                    $r->user?->name, $r->salary_role_label, $r->teaching_sessions,
                    $money($r->isPartTime() ? $r->teaching_salary : $r->base_salary), $money($r->kpi_bonus),
                    $money($r->commission_bonus).((float) $r->commission_deferred > 0 ? ' ('.$money($r->commission_deferred).')' : ''),
                    $money($r->renew_bonus), $money($r->allowance), $money((float) $r->insurance_deduction + (float) $r->union_deduction),
                    $money($r->tax_deduction), $money($r->penalty_deduction), $money($r->commission_clawback), $money($r->other_deduction), $money($r->net_salary),
                ])->all()
            );
        }
    }
}
