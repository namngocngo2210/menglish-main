<?php

namespace Database\Seeders;

use App\Http\Controllers\CrmController;
use App\Http\Controllers\CrmImportController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\SystemConfigController;
use App\Http\Controllers\TrialGuestController;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmBranchTransfer;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\Promotion;
use App\Models\SlaEvent;
use App\Models\SlaSetting;
use App\Models\User;
use App\Services\Crm\Approvals\BranchTransferApprovalSource;
use App\Services\Sla\Sla;
use App\Services\Sla\SlaBreachService;
use App\Support\Money;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dữ liệu demo CRM / Tuyển sinh phủ các case còn trống (cả đẹp lẫn xấu). Mốc thời gian tương đối với hôm nay (D(n) = n ngày
 * trước); mọi thao tác chạy qua controller / service thật với đồng hồ đặt về đúng lúc (không có sự kiện sau "bây giờ").
 * - Chuyển cơ sở (crm_branch_transfers): Học vụ CG xin chuyển khách sang Học vụ BD → Admin duyệt; 1 yêu cầu bị Admin từ
 *   chối (khách giữ cơ sở cũ); 1 yêu cầu BD → CG đang chờ duyệt (hộp Việc cần duyệt).
 * - Ưu đãi (promotions): % mặc định đang chạy (giảm tối đa), giảm tiền cố định, ưu đãi hè đã hết hạn, early bird giới hạn
 *   2 lượt đã dùng hết, ưu đãi anh chị em đã ngừng áp dụng, ưu đãi Tết chưa tới ngày, 1 ưu đãi riêng (ca đặc biệt, có lý
 *   do). Ưu đãi được áp thật vào hợp đồng khi chốt khách (Chốt & Xếp lớp) → phiếu học phí có giảm trừ, đếm lượt dùng.
 * - Chốt khách + Xác nhận chính thức: 4 khách chốt vào lớp (2 đã xác nhận đủ hồ sơ, 1 mới lưu tiến độ còn thiếu giáo
 *   trình, 1 vừa chốt chưa làm gì) + 1 khách chốt chờ xếp lớp. Màn xác nhận không có "từ chối" (chỉ lưu tiến độ / xác nhận).
 * - Học thử: 1 khách vắng mặt buổi 1 rồi xếp lại buổi 2 sắp tới; 1 buổi đặt rồi hủy (phụ huynh bận); 1 khách đã học thử đủ
 *   2 buổi (hết lượt, không xếp thêm được).
 * - SLA: cấu hình qua trang Cấu hình SLA (mức phạt gợi ý, ngưỡng chuyển trạng thái 48h…); sổ SLA (sla_events) đủ trạng thái
 *   qua SlaBreachService (cùng luật CrmSlaService): liên hệ lần đầu đúng hạn, quá hạn có biên bản phạt (1 khách sau đó mới
 *   gọi), phản hồi sau học thử đúng hạn / trễ (biên bản + việc quá hạn, xong trễ), theo dõi học phí tuần đầu quá hạn (chỉ
 *   cảnh báo), sắp hết hạn, còn hạn; liên hệ thất bại 3 lần liên tiếp (cảnh báo + việc báo cáo).
 * - Thùng rác: 1 khách nhập trùng đã xóa (khôi phục được ở "Khách đã xóa"); nhập Excel: 2 khách từ file hội thảo phụ huynh,
 *   2 dòng lỗi bị bỏ qua (SĐT sai, trùng khách có sẵn).
 *
 * Gọi từ DemoCoverageSeeder (cuối php artisan demo:luong); chạy được trên dữ liệu db:seed. Idempotent: ưu đãi
 * "# Ưu đãi khai giảng K28" đã có thì bỏ qua. Chỉ thêm dữ liệu; SLA chỉ chạy cho khách do seeder này tạo (lệnh sla:enforce
 * quét toàn bộ khách từ mốc bật SLA nên không gọi ở đây). Cấu hình SLA chỉ ghi cho mục chưa có dòng ghi đè.
 */
class DemoCoverageCrmSeeder extends Seeder
{
    use InvokesControllersAsUser;

    public const MARKER = '# Ưu đãi khai giảng K28';

    private const ADMIN = 'admin@menglish.edu.vn';

    private const MANAGER = 'manager@menglish.edu.vn';

    /** Học vụ phụ trách khách theo cơ sở. */
    private const OWNERS = ['CG' => 'nva@menglish.edu.vn', 'BD' => 'giaovu2@menglish.edu.vn'];

    /** Khách: khóa => [tên, SĐT, cơ sở, nguồn, khóa quan tâm, phụ huynh, tạo D(n), giờ]. Tên có tiền tố "# " như dữ liệu mẫu khác. */
    private const LEADS = [
        'transfer_ok' => ['# Phạm Gia Khánh', '0868200101', 'CG', 'Facebook Ads', 'Starters', 'Phạm Thu Hằng', 12, '09:20'],
        'transfer_no' => ['# Trần Ngọc Hân', '0868200102', 'CG', 'Bạn bè giới thiệu', 'Movers', 'Trần Văn Lực', 8, '10:05'],
        'transfer_wait' => ['# Đỗ Hải Yến', '0868200103', 'BD', 'Google Ads', 'Pre Starters', 'Đỗ Minh Hoà', 4, '14:10'],
        'close_confirmed' => ['# Nguyễn Minh Châu', '0868200104', 'CG', 'Bạn bè giới thiệu', 'Starters', 'Nguyễn Thị Lan', 16, '09:00'],
        'close_partial' => ['# Lê Bảo Long', '0868200105', 'BD', 'Facebook Ads', 'Starters', 'Lê Thị Hương', 15, '15:30'],
        'close_default' => ['# Vũ Thanh Tâm', '0868200106', 'CG', 'Website / Hotline', 'Movers', 'Vũ Đức Thắng', 10, '08:45'],
        'close_special' => ['# Đặng Quốc Anh', '0868200108', 'CG', 'Sự kiện Offline', 'Starters', 'Đặng Thị Mơ', 9, '10:40'],
        'close_fresh' => ['# Hoàng Mai Phương', '0868200107', 'CG', 'Tiktok Organic', 'Movers', 'Hoàng Văn Toàn', 5, '16:20'],
        'trial_no_show' => ['# Bùi Ngọc Ánh', '0868200109', 'BD', 'Facebook Ads', 'Pre Starters', 'Bùi Văn Khoa', 9, '11:15'],
        'trial_cancel' => ['# Phan Đức Huy', '0868200110', 'CG', 'Google Ads', 'Starters', 'Phan Thị Nga', 7, '09:50'],
        'trial_twice' => ['# Ngô Khánh Linh', '0868200111', 'CG', 'Bạn bè giới thiệu', 'Starters', 'Ngô Văn Thành', 14, '13:30'],
        'sla_on_time' => ['# Trịnh Hoài An', '0868200112', 'CG', 'Facebook Ads', 'Movers', 'Trịnh Thu Hà', 3, '09:00'],
        'sla_late' => ['# Lý Thu Trang', '0868200113', 'BD', 'Tiktok Organic', 'Starters', 'Lý Văn Nam', 4, '08:30'],
        'sla_overdue' => ['# Mai Quang Vinh', '0868200114', 'CG', 'Google Ads', 'IELTS', 'Mai Thị Thoa', 2, '14:00'],
        'failed_calls' => ['# Cao Thị Hồng', '0868200115', 'CG', 'Website / Hotline', 'Pre Starters', 'Cao Văn Bình', 3, '08:15'],
        'deleted' => ['# Đinh Văn Tùng', '0868200116', 'CG', 'Facebook Ads', 'Starters', 'Đinh Thị Hoa', 20, '10:00'],
    ];

    /** Dòng file nhập Excel (CSV) của hội thảo phụ huynh; 2 dòng cuối lỗi (SĐT sai, trùng khách sla_on_time). */
    private const IMPORT_ROWS = [
        ['# Tạ Minh Khôi', '0868 200 117', 'Tạ Văn Hùng', '0868 200 217', '', '12/03/2016', 'Nam', 'Cầu Giấy, Hà Nội', 'Hội thảo phụ huynh', 'Starters', 'Đăng ký tại hội thảo, muốn học cuối tuần', ''],
        ['# Kiều Bảo Ngọc', '0868 200 118', 'Kiều Thị Mai', '0868 200 218', '', '05/11/2015', 'Nữ', 'Dịch Vọng, Cầu Giấy', 'Hội thảo phụ huynh', 'Movers', 'Đã làm test thử tại hội thảo', ''],
        ['# Hà Gia Bảo', '12345', 'Hà Văn Phúc', '', '', '', 'Nam', '', 'Hội thảo phụ huynh', 'Pre Starters', '', ''],
        ['# Trịnh Hoài An', '0868 200 112', 'Trịnh Thu Hà', '', '', '', 'Nữ', '', 'Hội thảo phụ huynh', 'Movers', 'Đăng ký lại', ''],
    ];

    /**
     * Ưu đãi: khóa => [tên, loại, giá trị, giảm tối đa, bắt đầu D(n) (âm = sau hôm nay), kết thúc D(n) (null = không thời
     * hạn), giới hạn lượt, mặc định, mã khóa học, mô tả, tạo D(n)].
     */
    private const PROMOTIONS = [
        'default' => [self::MARKER, 'percent', 10, 1500000, 30, -60, null, true, null, 'Giảm 10% học phí (tối đa 1.500.000đ) cho khách chốt trong đợt khai giảng K28.', 32],
        'fixed' => ['# Giảm 500.000đ — học viên cũ giới thiệu', 'fixed', 500000, null, 60, null, null, false, null, 'Khách do học viên / phụ huynh cũ giới thiệu.', 60],
        'expired' => ['# Ưu đãi hè 2026', 'percent', 15, 2000000, 130, 40, null, false, null, 'Chương trình hè: giảm 15% khi đăng ký trước 31/08.', 131],
        'limited' => ['# Early bird Starters — 2 suất', 'fixed', 1000000, null, 25, -30, 2, false, 'DEMO-FAM1', 'Giảm 1.000.000đ cho 2 khách chốt sớm nhất khóa Starters.', 26],
        'stopped' => ['# Ưu đãi anh chị em ruột (Cầu Giấy)', 'percent', 5, null, 90, null, null, false, null, 'Anh / chị / em ruột cùng học tại cơ sở Cầu Giấy.', 91],
        'upcoming' => ['# Ưu đãi Tết 2027', 'fixed', 800000, null, -30, -75, null, false, null, 'Lì xì đầu năm cho khách chốt dịp Tết.', 7],
    ];

    /** Cấu hình SLA (trang Cấu hình SLA): khóa => [giá trị, bật, lập biên bản, mức phạt gợi ý]. */
    private const SLA_SETTINGS = [
        'crm.first_contact' => [24, true, true, 50000],
        'crm.status_move' => [48, true, true, 0],
        'crm.trial_feedback' => [24, true, true, 30000],
        'crm.tuition_followup' => [168, true, false, 0],
        'crm.failed_contacts' => [3, true, false, 0],
    ];

    private Carbon $realNow;

    private User $admin;

    /** @var array<string, User> */
    private array $owners = [];

    /** @var array<string, int> */
    private array $branches = [];

    /** @var array<string, CrmCustomer> */
    private array $leads = [];

    /** @var array<string, Promotion> */
    private array $promotions = [];

    private SlaBreachService $breaches;

    public function run(): void
    {
        $this->admin = User::where('email', self::ADMIN)->first() ?? new User;
        foreach (self::OWNERS as $code => $email) {
            $owner = User::where('email', $email)->first();
            $branchId = Branch::where('code', $code)->value('id');
            if ($owner && $branchId) {
                $this->owners[$code] = $owner;
                $this->branches[$code] = (int) $branchId;
            }
        }
        if (! $this->admin->exists || count($this->owners) < 2 || ! User::where('email', self::MANAGER)->exists()) {
            $this->command?->warn('DemoCoverageCrmSeeder: thiếu tài khoản Admin / Quản lý / Học vụ mẫu hoặc cơ sở CG, BD — bỏ qua.');

            return;
        }
        if (Promotion::where('name', self::MARKER)->exists()) {
            $this->command?->info('DemoCoverageCrmSeeder: đã có dữ liệu demo CRM bổ sung — bỏ qua.');

            return;
        }
        $phones = [...array_column(self::LEADS, 1), '0868200117', '0868200118'];
        if (CrmCustomer::withTrashed()->whereIn('phone_normalized', $phones)->orWhereIn('phone', $phones)->exists()) {
            $this->command?->warn('DemoCoverageCrmSeeder: SĐT khách mẫu (0868200xxx) đã có trong CRM — bỏ qua.');

            return;
        }

        $this->breaches = app(SlaBreachService::class);
        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $this->realNow = now()->copy();

        try {
            DB::transaction(function () {
                $this->slaSettings();
                $this->promotions();
                $this->transfers();
                $this->closings();
                $this->trials();
                $this->slaContacts();
                $this->trash();
                $this->import();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
            Sla::forget();
        }

        $this->command?->info('DemoCoverageCrmSeeder: '.count($this->leads).' khách, '.Promotion::where('name', 'like', '# %')->count().' ưu đãi, '
            .CrmBranchTransfer::whereIn('customer_id', collect($this->leads)->pluck('id'))->count().' yêu cầu chuyển cơ sở, '
            .SlaEvent::count().' mốc SLA.');
    }

    // ── Thời gian ──────────────────────────────────────────────────────────────

    /** $days ngày trước, lúc $time (giờ hôm nay chưa tới thì lùi về trước "bây giờ" vài phút). */
    private function ago(int $days, string $time): Carbon
    {
        return $this->realNow->copy()->subDays($days)->setTimeFromTimeString($time)->min($this->realNow->copy()->subMinutes(5));
    }

    private function at(Carbon $at): void
    {
        if ($at->gte($this->realNow)) {
            throw new RuntimeException("DemoCoverageCrmSeeder: mốc {$at} sau thời điểm hiện tại.");
        }
        Carbon::setTestNow($at->copy());
    }

    // ── Thao tác CRM thật ─────────────────────────────────────────────────────

    private function owner(CrmCustomer $customer): User
    {
        return User::findOrFail($customer->assigned_user_id);
    }

    /** Thêm khách qua form "Thêm khách mới" của Học vụ cơ sở. */
    private function lead(string $key): CrmCustomer
    {
        [$name, $phone, $branch, $source, $interest, $parent, $days, $time] = self::LEADS[$key];
        $this->at($this->ago($days, $time));
        $this->asUser($this->owners[$branch], CrmController::class, 'storeCustomer', [
            'name' => $name,
            'phone' => $phone,
            'parent_name' => $parent,
            'source' => $source,
            'branch_id' => $this->branches[$branch],
            'course_interest' => $interest,
            'notes' => 'Phụ huynh '.$parent.' quan tâm lớp '.$interest.'.',
        ]);

        return $this->leads[$key] = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
    }

    private function note(CrmCustomer $customer, Carbon $at, string $type, string $content, string $outcome = 'reached'): void
    {
        $this->at($at);
        $this->asUser($this->owner($customer), CrmController::class, 'addNote', ['type' => $type, 'content' => $content, 'outcome' => $outcome], ['id' => $customer->id]);
    }

    private function stage(CrmCustomer $customer, Carbon $at, string $stage): void
    {
        $this->at($at);
        $this->asUser($this->owner($customer), CrmController::class, 'updateStage', ['stage' => $stage], ['id' => $customer->id]);
    }

    // ── Cấu hình SLA ──────────────────────────────────────────────────────────

    private function slaSettings(): void
    {
        $this->at($this->ago(21, '16:30'));
        foreach (self::SLA_SETTINGS as $key => [$value, $enabled, $penalty, $amount]) {
            if (SlaSetting::where('rule_key', $key)->exists()) {
                continue; // Admin đã chỉnh: giữ nguyên.
            }
            $this->asUser($this->admin, SystemConfigController::class, 'updateSla', [
                'value' => $value, 'enabled' => $enabled, 'penalty' => $penalty, 'amount' => $amount,
            ], ['key' => $key]);
        }
    }

    // ── Ưu đãi ────────────────────────────────────────────────────────────────

    private function promotions(): void
    {
        $date = fn (?int $days, string $time) => $days === null ? null
            : ($days >= 0 ? $this->realNow->copy()->subDays($days) : $this->realNow->copy()->addDays(-$days))->setTimeFromTimeString($time)->format('Y-m-d H:i');
        $specs = collect(self::PROMOTIONS)->sortByDesc(fn (array $p) => $p[10]);
        foreach ($specs as $key => [$name, $type, $value, $max, $from, $to, $limit, $default, $course, $description, $created]) {
            $this->at($this->ago($created, '10:00'));
            $this->asUser($this->admin, PromotionController::class, 'store', array_filter([
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'max_discount_amount' => $max,
                'description' => $description,
                'course_id' => $course ? Course::where('code', $course)->value('id') : null,
                'branch_id' => $key === 'stopped' ? $this->branches['CG'] : null,
                'starts_at' => $date($from, '00:00'),
                'ends_at' => $date($to, '23:59'),
                'usage_limit' => $limit,
                'is_default' => $default ? 1 : null,
                'is_active' => 1,
            ], fn ($v) => $v !== null));
            $this->promotions[$key] = Promotion::where('name', $name)->latest('id')->firstOrFail();
        }
        // Quản lý cơ sở ngừng áp dụng ưu đãi anh chị em (đổi sang chính sách mới).
        $this->at($this->ago(3, '17:40'));
        $this->asUser(User::where('email', self::MANAGER)->firstOrFail(), PromotionController::class, 'toggle', [], ['promotion' => $this->promotions['stopped']]);
    }

    // ── Chuyển cơ sở ──────────────────────────────────────────────────────────

    private function transfers(): void
    {
        $approvals = app(BranchTransferApprovalSource::class);
        $cases = [
            // [khách, sang cơ sở, gửi D(n), lý do, quyết định D(n), duyệt?, ghi chú quyết định]
            ['transfer_ok', 'BD', [10, '15:20'], 'Gia đình chuyển nhà sang Ba Đình, muốn học gần nhà.', [9, '09:10'], true, null],
            ['transfer_no', 'BD', [6, '11:00'], 'Phụ huynh làm việc gần Ba Đình, muốn đưa đón tiện hơn.', [5, '08:50'], false,
                'Lớp Movers ở Ba Đình đã kín chỗ tháng này; giữ khách ở Cầu Giấy, tư vấn lớp tối thứ 3-5.'],
            ['transfer_wait', 'CG', [1, '15:30'], 'Khách đăng ký học thử ở Cầu Giấy (gần trường của bé).', null, null, null],
        ];
        foreach ($cases as [$key, $to, $sent, $reason, $decided, $approve, $note]) {
            $customer = $this->lead($key);
            $this->note($customer, $customer->created_at->copy()->addHours(2), 'call', 'Gọi tư vấn lộ trình, phụ huynh hỏi lớp ở cơ sở khác.');
            $this->at($this->ago(...$sent));
            $this->asUser($this->owner($customer), CrmController::class, 'reassignCustomer', [
                'branch_id' => $this->branches[$to],
                'assigned_user_id' => $this->owners[$to]->id,
                'reason' => $reason,
            ], ['id' => $customer->id]);
            if (! $decided) {
                continue;
            }
            $this->at($this->ago(...$decided));
            $transfer = CrmBranchTransfer::where('customer_id', $customer->id)->pending()->firstOrFail();
            $result = $approve ? $approvals->approve($this->admin, $transfer->id) : $approvals->reject($this->admin, $transfer->id, $note);
            if (! $result->ok) {
                throw new RuntimeException("DemoCoverageCrmSeeder: không xử lý được yêu cầu chuyển cơ sở: {$result->message}");
            }
        }
    }

    // ── Chốt khách, ưu đãi áp vào hợp đồng, xác nhận chính thức ─────────────────

    private function closings(): void
    {
        // [khách, D(n) chuyển Đang tư vấn, D(n) chốt, lớp (null = chờ xếp lớp), khóa, ưu đãi, xác nhận: [D(n), đủ hồ sơ?] | null]
        $cases = [
            ['close_confirmed', [16, '10:30'], [13, '10:00'], 'DEMO-CG-FAM1', 'DEMO-FAM1', 'limited', [[11, '16:00'], true]],
            ['close_partial', [15, '17:00'], [12, '11:00'], 'DEMO-BD-FAM1', 'DEMO-FAM1', 'limited', [[10, '09:30'], false]],
            ['close_default', [10, '10:00'], [8, '15:00'], 'DEMO-CG-FAM2', 'DEMO-FAM2', 'default', [[6, '17:00'], true]],
            ['close_special', [9, '14:00'], [6, '10:00'], null, 'DEMO-FAM1', 'special', null],
            ['close_fresh', [4, '09:00'], [2, '10:30'], 'DEMO-CG-FAM2', 'DEMO-FAM2', 'fixed', null],
        ];
        foreach ($cases as [$key, $consulting, $closed, $classCode, $courseCode, $promotion, $confirm]) {
            $customer = $this->lead($key);
            $this->stage($customer, $this->ago(...$consulting), 'consulting');
            $this->note($customer, $this->ago(...$consulting)->addMinutes(20), 'meet', 'Phụ huynh đến cơ sở, tư vấn lộ trình '.self::LEADS[$key][4].' và học phí.');
            $owner = $this->owner($customer);
            $closedAt = $this->ago(...$closed);
            if ($promotion === 'special') {
                // Ca đặc biệt: Học vụ tạo ưu đãi riêng ngay trong màn chốt (bắt buộc lý do, dùng 1 lần).
                $this->at($closedAt->copy()->subMinutes(15));
                $this->asUser($owner, PromotionController::class, 'store', [
                    'name' => '# Ưu đãi riêng — '.substr($customer->name, 2), 'type' => 'fixed', 'value' => 1500000, 'is_special' => 1,
                    'reason' => 'Gia đình có 2 bé đang học tại trung tâm, bố mới mất việc; Quản lý cơ sở đồng ý hỗ trợ 1.500.000đ.',
                ]);
                $this->promotions['special'] = Promotion::where('is_special', true)->where('created_by', $owner->id)->latest('id')->firstOrFail();
            }
            $course = Course::where('code', $courseCode)->first();
            $class = $classCode ? $this->enrollableClass($classCode, $customer->branch_id, $course, $closedAt) : null;
            if (! $class && ! $course) {
                $this->command?->warn("DemoCoverageCrmSeeder: không có lớp / khóa {$courseCode} để chốt {$customer->name} — bỏ qua.");

                continue;
            }
            $this->at($closedAt);
            $this->asUser($owner, CrmController::class, 'processClosingWizard', array_filter([
                'customer_id' => $customer->id,
                'class_id' => $class?->id,
                'course_id' => $class ? null : $course->id,
                'fee_paid_at_closing' => 0,
                'promotion_id' => $this->promotions[$promotion]->id,
                'bill_notes' => 'Chốt tại cơ sở, phụ huynh hẹn chuyển khoản trong tuần.',
            ], fn ($v) => $v !== null));
            $customer->refresh();
            $this->tuitionSla($customer);

            $enrollment = ClassEnrollment::where('customer_id', $customer->id)->first();
            if (! $confirm || ! $enrollment) {
                continue;
            }
            [$when, $complete] = $confirm;
            $this->at($this->ago(...$when));
            $this->asUser($owner, CrmController::class, 'confirmEnrollment', [
                'account_sent' => 1, 'zalo_group_added' => 1, 'curriculum_delivered' => $complete ? 1 : 0, 'action' => $complete ? 'confirm' : 'save',
            ], ['enrollment' => $enrollment]);
        }
    }

    /** Lớp nhận ghi danh của cơ sở cùng khóa (ưu tiên mã lớp mẫu), còn chỗ; không có thì chốt chờ xếp lớp. */
    private function enrollableClass(string $code, ?int $branchId, ?Course $course, Carbon $at): ?ClassModel
    {
        return ClassModel::with('course')->where('branch_id', $branchId)->where('code', 'like', 'DEMO-%')
            ->when($course, fn ($q) => $q->where('course_id', $course->id))
            ->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($u) => $u->where('status', 'upcoming')->where(fn ($d) => $d->whereNull('start_date')->orWhereDate('start_date', '>=', $at->toDateString()))))
            ->orderByRaw('code = ? desc', [$code])->orderBy('code')->get()
            ->first(fn (ClassModel $class) => $class->course?->is_active && $class->hasSeatsFor());
    }

    // ── Học thử ───────────────────────────────────────────────────────────────

    private function trials(): void
    {
        // Vắng mặt buổi 1 → xếp lại buổi sắp tới.
        $customer = $this->lead('trial_no_show');
        $this->stage($customer, $customer->created_at->copy()->addHours(3), 'consulting');
        if ($booking = $this->pastTrial($customer, 7, 3)) {
            $this->feedback($booking, 'no_show', null, 'Phụ huynh không đưa bé tới, không báo trước.');
            $this->note($customer, $this->sessionEnd($booking->session)->addHours(2), 'call', 'Phụ huynh báo bé bị sốt, xin xếp buổi học thử khác.');
            $this->futureTrial($customer, 'Lần 2 (buổi trước vắng do bé ốm). Bé hơi nhút nhát, cô gọi tên làm quen giúp.');
        }

        // Đặt buổi học thử sắp tới rồi hủy.
        $customer = $this->lead('trial_cancel');
        $this->stage($customer, $this->ago(6, '10:00'), 'consulting');
        if ($booking = $this->futureTrial($customer, 'Bé chưa học tiếng Anh ở trung tâm nào.', 180)) {
            $this->at($this->realNow->copy()->subMinutes(70));
            $this->asUser($this->owner($customer), CrmController::class, 'cancelTrialBooking', ['reason' => 'Phụ huynh bận đột xuất, hẹn lại sang tuần sau.'], ['id' => $customer->id, 'booking' => $booking]);
        }

        // Đã học thử đủ 2 buổi: buổi 1 Học vụ gọi phản hồi trong 24h; buổi 2 gọi trễ (SLA quá hạn, biên bản).
        $customer = $this->lead('trial_twice');
        $this->stage($customer, $customer->created_at->copy()->addMinutes(90), 'consulting');
        $first = $this->pastTrial($customer, 12, 8);
        if ($first) {
            $this->trialSla($first);
            $this->feedback($first, 'attended', 4, 'Bé tự tin, phát âm khá; cần luyện thêm nghe hiểu câu dài.');
            $this->note($customer, $this->sessionEnd($first->session)->addHours(18), 'call', 'Phụ huynh hài lòng buổi học thử, muốn bé học thêm 1 buổi lớp khác trước khi chốt.');
            $this->trialSla($first);
            $second = $this->pastTrial($customer, 6, 2, $this->sessionEnd($first->session)->addHours(20));
            if ($second) {
                $this->trialSla($second);
                $this->feedback($second, 'attended', 5, 'Bé hòa nhập nhanh, trả lời đúng hầu hết câu hỏi của cô.');
                $due = $this->sessionEnd($second->session)->addHours(Sla::value('crm.trial_feedback'));
                $this->trialSla($second, $due->copy()->addMinutes(10)); // quá hạn: biên bản + việc quá hạn.
                // Sáng hôm sau mới gọi (trễ): mốc đóng lại nhưng biên bản vẫn giữ.
                $late = $due->copy()->addDay()->setTime(9, 40)->min($this->realNow->copy()->subMinutes(30));
                if ($late->gt($due->copy()->addMinutes(10))) {
                    $this->note($customer, $late, 'call', 'Gọi phụ huynh sau buổi học thử 2 (trễ do dồn việc khai giảng). Phụ huynh cân nhắc học phí, hẹn trả lời cuối tuần.');
                    $this->trialSla($second, $late->copy()->addMinutes(10));
                }
            }
        }
    }

    /**
     * Buổi học thử đã diễn ra trong khoảng D($from)..D($to) (sau $after nếu có): buổi đã qua (đã "Hoàn thành") nên màn khách
     * không đặt được nữa — ghi lượt đặt như lúc Học vụ đặt (2 ngày trước buổi), rồi giáo viên điểm danh qua màn thật.
     */
    private function pastTrial(CrmCustomer $customer, int $from, int $to, ?Carbon $after = null): ?CrmTrialBooking
    {
        $session = $this->sessions($customer->branch_id)
            ->whereBetween('date', [$this->realNow->copy()->subDays($from)->toDateString(), $this->realNow->copy()->subDays($to)->toDateString()])
            ->when($after, fn ($q) => $q->whereDate('date', '>', $after->toDateString()))
            ->orderByDesc('date')->get()->first(fn (ClassSession $s) => $this->sessionEnd($s)->lt($this->realNow));
        if (! $session) {
            $this->command?->warn("DemoCoverageCrmSeeder: không có buổi học đã qua để học thử cho {$customer->name}.");

            return null;
        }
        $this->at($session->date->copy()->subDays(2)->setTime(10, 0)->max($customer->created_at->copy()->addHours(4))->max($after ?? $customer->created_at));
        $booking = CrmTrialBooking::create([
            'customer_id' => $customer->id,
            'class_id' => $session->class_id,
            'class_session_id' => $session->id,
            'booked_by' => $customer->assigned_user_id,
            'status' => 'scheduled',
        ]);
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $customer->assigned_user_id,
            'type' => 'trial',
            'content' => 'Đặt lịch học thử: '.$session->classModel?->name.' ('.$session->date->format('d/m/Y').' '.$session->start_time?->format('H:i').').',
        ]);

        return $booking->load('session.classModel');
    }

    /** Đặt buổi học thử sắp tới qua màn khách ($minutesAgo phút trước). */
    private function futureTrial(CrmCustomer $customer, string $notes, int $minutesAgo = 90): ?CrmTrialBooking
    {
        $at = $this->realNow->copy()->subMinutes($minutesAgo);
        $session = $this->sessions($customer->branch_id)->where('status', 'scheduled')
            ->whereBetween('date', [$at->toDateString(), $at->copy()->addDays(6)->toDateString()])
            ->whereHas('classModel', fn ($q) => $q->whereIn('status', ['active', 'upcoming']))
            ->orderBy('date')->orderBy('start_time')->get()->first(fn (ClassSession $s) => $this->sessionEnd($s)->gt($this->realNow->copy()->addHours(2)));
        if (! $session) {
            $this->command?->warn("DemoCoverageCrmSeeder: không có buổi học sắp tới để xếp học thử cho {$customer->name}.");

            return null;
        }
        $this->at($at);
        $this->asUser($this->owner($customer), CrmController::class, 'storeTrialBooking', ['class_session_id' => $session->id, 'notes' => $notes], ['id' => $customer->id]);

        return CrmTrialBooking::where('customer_id', $customer->id)->where('class_session_id', $session->id)->firstOrFail();
    }

    /** Giáo viên buổi đó điểm danh / nhận xét khách học thử (màn Nhận xét học thử), 20 phút sau giờ tan. */
    private function feedback(CrmTrialBooking $booking, string $status, ?int $rating, string $text): void
    {
        $session = $booking->session;
        $teacher = User::find($session->teacher_id ?: $session->classModel?->teacher_id) ?? $this->admin;
        $this->at($this->sessionEnd($session)->addMinutes(20));
        $this->asUser($teacher, TrialGuestController::class, 'feedback', array_filter([
            'status' => $status,
            'rating' => $rating,
            'remarks' => $status === 'attended' ? ['grammar' => 'Khá', 'attitude' => 'Hăng hái, tập trung', 'result' => 'Phù hợp lớp hiện tại'] : null,
            'feedback' => $text,
        ]), ['booking' => $booking]);
    }

    private function sessions(?int $branchId)
    {
        return ClassSession::with('classModel')->where('status', '!=', 'cancelled')->whereNotNull('start_time')
            ->whereHas('classModel', fn ($q) => $q->where('code', 'like', 'DEMO-%-FAM%')->where('branch_id', $branchId));
    }

    private function sessionEnd(ClassSession $session): Carbon
    {
        return $session->end_time ? $session->date->copy()->setTimeFrom($session->end_time) : $session->date->copy()->endOfDay();
    }

    // ── SLA (cùng luật CrmSlaService, chỉ cho khách của seeder) ────────────────

    /** Liên hệ lần đầu / chuyển trạng thái: lệnh quét 15 phút sau khi hết khung giờ kể từ lúc thêm khách. */
    private function customerWindow(CrmCustomer $customer, string $rule): void
    {
        $due = $customer->created_at->copy()->addHours(Sla::value($rule));
        $runAt = $due->copy()->addMinutes(15);
        if ($runAt->gte($this->realNow)) {
            return; // chưa hết khung giờ: lệnh quét chưa mở mốc.
        }
        $this->at($runAt);
        $event = $this->breaches->open($rule, 'crm_customer', $customer->id, $customer->assignedUser, $customer->created_at, $due);
        $types = $rule === 'crm.first_contact' ? CrmCustomerHistory::CARE_TYPES : ['stage_change', 'lost'];
        $firstAt = $customer->histories()->whereIn('type', $types)->where('created_at', '<=', $runAt)
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', CrmCustomerHistory::OUTCOME_FAILED))->min('created_at');
        if ($firstAt && Carbon::parse($firstAt)->lte($due)) {
            $this->breaches->resolve($event, Carbon::parse($firstAt));

            return;
        }
        $this->breaches->breach($event, "{$customer->code} {$customer->name}", [
            'Khách hàng' => "{$customer->code} — {$customer->name} ({$customer->phone})",
            'Thêm vào hệ thống lúc' => $customer->created_at->format('H:i d/m/Y'),
            'Trạng thái hiện tại' => CrmCustomer::PIPELINE_STAGES[$customer->stage] ?? $customer->stage,
            'Hoạt động hợp lệ đầu tiên' => $firstAt ? Carbon::parse($firstAt)->format('H:i d/m/Y') : 'Chưa có',
        ], route('crm.customers.show', $customer->id), now());
    }

    /** Phản hồi sau học thử: mở mốc + giao việc khi buổi kết thúc, quét lại lúc $runAt (mặc định: hạn + 10 phút). */
    private function trialSla(CrmTrialBooking $booking, ?Carbon $runAt = null): void
    {
        $customer = $booking->customer()->with('assignedUser')->first();
        $ends = $this->sessionEnd($booking->session);
        $due = $ends->copy()->addHours(Sla::value('crm.trial_feedback'));
        $event = SlaEvent::where('rule_key', 'crm.trial_feedback')->where('subject_type', 'crm_trial_booking')->where('subject_id', $booking->id)->first();
        if (! $event) {
            $this->at($ends->copy()->addMinutes(10));
            $event = $this->breaches->open('crm.trial_feedback', 'crm_trial_booking', $booking->id, $customer->assignedUser, $ends, $due, [
                'title' => "Phản hồi phụ huynh sau học thử: {$customer->name}",
                'description' => "Buổi học thử lớp {$booking->classModel?->name} kết thúc lúc {$ends->format('H:i d/m/Y')}. Liên hệ phụ huynh lấy phản hồi và ghi nhật ký trước {$due->format('H:i d/m/Y')}.",
                'branch_id' => $customer->branch_id,
            ]);

            return;
        }
        $runAt ??= $due->copy()->addMinutes(10);
        $this->at($runAt->min($this->realNow->copy()->subMinute()));
        $contactAt = $customer->histories()->counted()->whereIn('type', ['call', 'message', 'meet', 'result'])
            ->where('created_at', '>=', $ends)->min('created_at');
        $this->settle($event->fresh(), $contactAt ? Carbon::parse($contactAt) : null, "{$customer->code} {$customer->name}", [
            'Khách hàng' => "{$customer->code} — {$customer->name}",
            'Lớp học thử' => (string) $booking->classModel?->name,
            'Buổi học thử kết thúc lúc' => $ends->format('H:i d/m/Y'),
            'Liên hệ phản hồi đầu tiên' => $contactAt ? Carbon::parse($contactAt)->format('H:i d/m/Y') : 'Chưa có',
        ], route('crm.customers.show', $customer->id));
    }

    /** Theo dõi thu học phí tuần đầu: mở mốc + việc ngay sau khi chốt; quá hạn (chưa thu đủ) thì cảnh báo. */
    private function tuitionSla(CrmCustomer $customer): void
    {
        if (! $customer->converted_at) {
            return;
        }
        $customer->load(['assignedUser', 'convertedStudent.tuition']);
        $due = $customer->converted_at->copy()->addHours(Sla::value('crm.tuition_followup'));
        $this->at($customer->converted_at->copy()->addMinutes(10));
        $event = $this->breaches->open('crm.tuition_followup', 'crm_customer', $customer->id, $customer->assignedUser, $customer->converted_at, $due, [
            'title' => "Theo dõi thu học phí: {$customer->name}",
            'description' => "Khách {$customer->code} đã chốt lúc {$customer->converted_at->format('H:i d/m/Y')}. Theo dõi và thu đủ học phí trước {$due->format('H:i d/m/Y')}.",
            'branch_id' => $customer->branch_id,
        ]);
        if ($due->copy()->addMinutes(10)->gte($this->realNow)) {
            return; // còn hạn / sắp hết hạn.
        }
        $this->at($due->copy()->addMinutes(10));
        $tuition = $customer->convertedStudent?->tuition;
        $this->settle($event, null, "{$customer->code} {$customer->name}", [
            'Khách hàng' => "{$customer->code} — {$customer->name}",
            'Chốt lúc' => $customer->converted_at->format('H:i d/m/Y'),
            'Còn nợ học phí' => $tuition ? Money::format($tuition->debt_amount) : 'Chưa có hồ sơ học phí',
        ], route('crm.customers.show', $customer->id));
    }

    /** Như CrmSlaService::settle: xong đúng hạn → đóng mốc; quá hạn → biên bản / cảnh báo một lần, xong trễ thì đóng mốc. */
    private function settle(SlaEvent $event, ?Carbon $doneAt, string $label, array $facts, string $link): void
    {
        if ($event->resolved_at || $event->breached_at) {
            if ($doneAt && ! $event->resolved_at) {
                $this->breaches->resolve($event, $doneAt);
            }

            return;
        }
        if ($doneAt && $doneAt->lte($event->due_at)) {
            $this->breaches->resolve($event, $doneAt);

            return;
        }
        if (now()->gt($event->due_at)) {
            $this->breaches->breach($event, $label, $facts, $link, now());
            if ($doneAt) {
                $this->breaches->resolve($event, $doneAt);
            }
        }
    }

    /** Khách mới: liên hệ đúng hạn, quá hạn (gọi trễ / chưa gọi), liên hệ thất bại 3 lần liên tiếp. */
    private function slaContacts(): void
    {
        $onTime = $this->lead('sla_on_time');
        $this->note($onTime, $onTime->created_at->copy()->addHours(2), 'call', 'Đã gọi tư vấn lộ trình Movers, hẹn phụ huynh đưa bé test thứ 7.');
        $this->stage($onTime, $onTime->created_at->copy()->addHours(2)->addMinutes(5), 'consulting');

        $late = $this->lead('sla_late');
        $this->note($late, $late->created_at->copy()->addHours(30)->addMinutes(30), 'call', 'Gọi trễ (Học vụ nghỉ phép 1 ngày, chưa bàn giao). Phụ huynh vẫn quan tâm, hẹn tư vấn trực tiếp.');
        $this->stage($late, $late->created_at->copy()->addHours(30)->addMinutes(35), 'consulting');

        $overdue = $this->lead('sla_overdue');

        $failed = $this->lead('failed_calls');
        foreach ([2 => 'Không nghe máy.', 8 => 'Thuê bao tạm thời không liên lạc được.', 25 => 'Đổ chuông nhưng không bắt máy, đã nhắn Zalo.'] as $hours => $result) {
            $this->note($failed, $failed->created_at->copy()->addHours($hours), 'call', $result, CrmCustomerHistory::OUTCOME_FAILED);
        }

        foreach ([$onTime, $late, $overdue, $failed] as $customer) {
            $customer = $customer->fresh('assignedUser');
            $this->customerWindow($customer, 'crm.first_contact');
            $this->customerWindow($customer, 'crm.status_move');
        }
    }

    // ── Thùng rác + nhập Excel ────────────────────────────────────────────────

    private function trash(): void
    {
        $customer = $this->lead('deleted');
        $this->at($customer->created_at->copy()->addDay()->setTime(9, 30));
        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $customer->assigned_user_id,
            'type' => 'system',
            'content' => 'Xóa khách: nhập trùng với hồ sơ phụ huynh đã đăng ký cho anh của bé (gộp về một hồ sơ).',
        ]);
        $customer->delete();
    }

    private function import(): void
    {
        $this->at($this->ago(1, '10:15'));
        $owner = $this->owners['CG'];
        $path = tempnam(sys_get_temp_dir(), 'crm-import-');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Họ tên', 'Số điện thoại', 'Tên phụ huynh', 'SĐT phụ huynh', 'Email', 'Ngày sinh', 'Giới tính', 'Địa chỉ', 'Nguồn', 'Khóa học quan tâm', 'Ghi chú', 'Người phụ trách']);
        foreach (self::IMPORT_ROWS as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        try {
            $this->asUser($owner, CrmImportController::class, 'preview', [
                'file' => new UploadedFile($path, 'hoi-thao-phu-huynh-cau-giay.csv', 'text/csv', null, true),
                'branch_id' => $this->branches['CG'],
                'assigned_user_id' => $owner->id,
            ]);
            $this->asUser($owner, CrmImportController::class, 'store');
        } finally {
            @unlink($path);
        }
        foreach (['0868200117', '0868200118'] as $i => $phone) {
            $this->leads['import_'.$i] = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
        }
    }
}
