<?php

namespace Database\Seeders;

use App\Http\Controllers\CrmController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\SepayWebhookController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SystemConfigController;
use App\Http\Controllers\TuitionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionOverrideController;
use App\Http\Controllers\WorkTaskController;
use App\Models\BankAccount;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\CrmCustomer;
use App\Models\DebtReminderRule;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceConfiguration;
use App\Models\OperatingExpense;
use App\Models\SepayTransaction;
use App\Models\StudentTuition;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\TuitionContactLog;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\WorkTask;
use App\Services\CrmStageService;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Dữ liệu demo Phase 4 (học phí → hóa đơn → công nợ, chuyển khoản SePay, hoàn / chuyển nhượng / bảo lưu / khất nợ,
 * quá hạn + nhắc nợ, thu chi; nền tảng: giao việc 2 chiều, trợ giảng 3 ca, báo cáo trực lớp, ticket, nhật ký,
 * phân quyền cá nhân theo phạm vi, bắt đổi mật khẩu, hợp đồng sắp hết hạn), dựng trên tài khoản UserSeeder và
 * lớp / khóa học của DemoPhase1Seeder.
 *
 * Mọi thao tác đi qua controller thật (TuitionController, SepayWebhookController, SystemConfigController,
 * FinanceController, CrmController, WorkTaskController, SupportTicketController, UserController,
 * UserPermissionOverrideController) với đúng vai trò, và chạy với "đồng hồ" đặt đúng thời điểm trong quá khứ (khách chốt
 * N ngày trước → hạn đóng N−7 ngày trước) để nhóm quá hạn ≥ 7 ngày / 1–6 ngày, doanh thu tháng trước / tháng này hình
 * thành như thật.
 *
 * Khách học phí demo: SĐT 0388…, tên tiền tố "# ", chốt vào danh sách Chờ xếp lớp (khóa FAM 2) hoặc đã đóng phí khi chốt.
 *
 * Chạy cùng điều kiện với DemoPhase1–3Seeder (DatabaseSeeder). Idempotent: đã có khách demo 0388000001 thì chỉ in số
 * liệu. Toàn bộ trong 1 transaction.
 */
class DemoPhase4Seeder extends Seeder
{
    use InvokesControllersAsUser;

    public const MARKER = '[demo-p4]';

    public const FIRST_PHONE = '0388000001';

    private const STAFF = [
        'admin' => 'admin@menglish.edu.vn',
        'manager_cg' => 'manager@menglish.edu.vn',
        'manager_bd' => 'manager.bd@menglish.edu.vn',
        'accountant_cg' => 'ketoan2@menglish.edu.vn',
        'accountant_bd' => 'ttb@menglish.edu.vn',
        'academic_cg' => 'nva@menglish.edu.vn',
        'academic_bd' => 'giaovu2@menglish.edu.vn',
        'sales_cg' => 'tranmaia@menglish.edu.vn',
        'sales_bd' => 'hoangthinh@menglish.edu.vn',
        'teacher_cg' => 'nguyenvanan@menglish.edu.vn',
        'teacher_ft_cg' => 'gv.cohuu1@menglish.edu.vn',
        'teacher_bd' => 'gv.cohuu2@menglish.edu.vn',
        'assistant_cg' => 'ta.tuan@menglish.edu.vn',
        'assistant_bd' => 'ta.yen@menglish.edu.vn',
        'student_cg' => 'hocvien1@menglish.edu.vn',
    ];

    /**
     * Khách học phí: [tên, phụ huynh, chi nhánh, số ngày trước hôm nay lúc chốt, đóng phí khi chốt?].
     * Hạn đóng = ngày chốt + 7 (CrmController::processClosingWizard).
     */
    private const CUSTOMERS = [
        'A' => ['# Đinh Khánh Linh', 'Đinh Văn Hải', 'CG', 20, false],   // 2 đợt: phiếu tay (trả về → sửa → duyệt) + SePay → nợ 0
        'B' => ['# Hồ Minh Châu', 'Hồ Thị Lan', 'CG', 25, false],        // quá hạn ≥ 7 ngày, đã liên hệ + báo Admin, PH báo đã CK (phiếu chờ duyệt)
        'C' => ['# Quách Thu Trang', 'Quách Văn Long', 'BD', 12, false], // quá hạn 1–6 ngày, đóng 1 phần; phiếu tay CK + SePay trùng mã
        'D' => ['# Lý Hoàng Nam', 'Lý Thị Hoa', 'BD', 15, false],        // khất nợ (dời hạn, tạm dừng nhắc nợ)
        'E' => ['# Tăng Bảo Ngọc', 'Tăng Văn Phú', 'CG', 40, true],      // đã đóng đủ → chuyển nhượng sang F, hồ sơ hoàn phí quá hạn xử lý
        'F' => ['# Tăng Bảo Anh', 'Tăng Văn Phú', 'CG', 35, false],      // nhận chuyển nhượng từ E
        'G' => ['# Mai Quốc Việt', 'Mai Thu Hà', 'BD', 30, false],       // đóng 1 phần → bảo lưu
        'H' => ['# Trịnh Đức Anh', 'Trịnh Văn Tùng', 'CG', 5, false],    // sắp đến hạn: phiếu nháp + phiếu bị trả về
        'J' => ['# Viên Thảo Nhi', 'Viên Văn Quang', 'BD', 14, false],   // đóng đủ → hủy hóa đơn → công nợ khôi phục (quá hạn ≥ 7)
    ];

    /** Ảnh minh chứng nhỏ (PNG) dạng tham chiếu an toàn, không tạo file trong public/uploads. */
    private const PROOF = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const BANKS = [
        'CG' => ['MB', 'MB Bank (Quân đội)', '0901 2233 4455', 'Tài khoản thu học phí Cầu Giấy'],
        'BD' => ['TCB', 'Techcombank', '1903 6677 8899', 'Tài khoản thu học phí Ba Đình'],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, int> */
    private array $branches = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, CrmCustomer> */
    private array $customers = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> */
    private array $events = [];

    private Carbon $realNow;

    private string $sepaySecret = '';

    /** Kết quả các bước "phải bị từ chối / bỏ qua" (in ra cuối seed). */
    private array $rejections = [];

    public function run(): void
    {
        if (! User::where('email', self::STAFF['accountant_cg'])->exists() || ! ClassModel::where('code', 'DEMO-CG-FAM2')->exists()) {
            $this->command?->warn('DemoPhase4Seeder: chưa có tài khoản UserSeeder / lớp DemoPhase1Seeder — bỏ qua.');

            return;
        }
        if (CrmCustomer::where('phone', self::FIRST_PHONE)->exists()) {
            $this->printSummary();

            return;
        }

        $this->staff = collect(self::STAFF)->map(fn (string $email) => User::where('email', $email)->firstOrFail())->all();
        foreach (['CG', 'BD'] as $code) {
            $this->classes[$code.'-FAM1'] = ClassModel::where('code', "DEMO-{$code}-FAM1")->firstOrFail();
            $this->classes[$code.'-FAM2'] = ClassModel::where('code', "DEMO-{$code}-FAM2")->firstOrFail();
            $this->branches[$code] = (int) $this->classes[$code.'-FAM1']->branch_id;
        }

        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $webhookFlag = config('services.sepay.webhook_enabled');
        $this->realNow = now()->copy();

        try {
            DB::transaction(function () {
                $this->planEvents();
                $this->runEvents();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
            config(['services.sepay.webhook_enabled' => $webhookFlag]);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }

        $this->printSummary();
    }

    // ── Lịch sự kiện ────────────────────────────────────────────────────────────

    private function at(int $daysAgo, int $hour, int $minute = 0): Carbon
    {
        return $this->realNow->copy()->subDays($daysAgo)->setTime($hour, $minute);
    }

    private function event(Carbon $at, Closure $callback): void
    {
        $this->events[] = [$at->copy(), count($this->events), $callback];
    }

    /** Chạy theo thời gian; mốc rơi vào sau "bây giờ − 30 phút" được kéo về mốc đó (giữ thứ tự khai báo). */
    private function runEvents(): void
    {
        $ceiling = $this->realNow->copy()->subMinutes(30);
        $events = array_map(fn (array $e) => [$e[0]->lt($ceiling) ? $e[0] : $ceiling->copy(), $e[1], $e[2]], $this->events);
        usort($events, fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
        foreach ($events as [$at, , $callback]) {
            Carbon::setTestNow($at);
            $callback();
        }
    }

    private function planEvents(): void
    {
        // Cấu hình: tài khoản NH từng chi nhánh, dải số HĐ từng chi nhánh, SePay, mốc nhắc nợ.
        $this->event($this->at(45, 8), fn () => $this->seedFinanceConfig());

        // Khách chốt (Sale nhập → Học vụ chuyển Đang tư vấn → Quản lý Chốt & Xếp lớp sau, khóa FAM 2).
        foreach (self::CUSTOMERS as $key => [, , , $daysAgo]) {
            $this->event($this->at($daysAgo, 9, 30), fn () => $this->closeCustomer($key));
        }

        // A: đợt 1 — Học vụ lập nháp → gửi duyệt (CK kèm minh chứng) → Kế toán trả về → sửa mã GD, gửi lại → Kế toán duyệt.
        $this->event($this->at(19, 10), fn () => $this->receipt('A', 'academic_cg', 4000000, 'transfer', null, draft: true, note: 'PH hẹn chuyển khoản đợt 1.'));
        $this->event($this->at(19, 11), fn () => $this->updateLastReceipt('A', 'academic_cg', ['submit_action' => 'submit', 'transaction_code' => 'FT26DEMO0001A']));
        $this->event($this->at(19, 15), fn () => $this->rejectLast('A', 'accountant_cg', 'Mã giao dịch không khớp sao kê (FT26DEMO0001A). Vui lòng kiểm tra lại ủy nhiệm chi.'));
        $this->event($this->at(18, 9), fn () => $this->updateLastReceipt('A', 'academic_cg', ['submit_action' => 'submit', 'transaction_code' => 'FT26DEMO0001', 'notes' => 'Đã sửa mã giao dịch theo sao kê.']));
        $this->event($this->at(18, 14), fn () => $this->approveLast('A', 'accountant_cg'));
        // A: đợt 2 — PH chuyển khoản đúng nội dung QR → SePay tự gạch nợ phần còn lại, xuất HĐ; webhook gửi lại bị bỏ qua.
        $this->event($this->at(3, 20, 15), fn () => $this->sepay('SEPAY-DEMO-P4-0001', 'CG', $this->debtOf('A'), $this->tuitionOf('A')->transfer_memo.' CK hoc phi dot 2'));
        $this->event($this->at(3, 20, 17), fn () => $this->sepay('SEPAY-DEMO-P4-0001', 'CG', $this->debtOf('A'), $this->tuitionOf('A')->transfer_memo.' CK hoc phi dot 2', expectDuplicate: true));

        // B: quá hạn ≥ 7 ngày — nhắc nợ, đã liên hệ (2 lần), báo cáo Admin; hôm nay PH báo đã CK → phiếu chờ duyệt.
        $this->event($this->at(10, 9), fn () => $this->overdueAction('B', 'academic_cg', 'sendOverdueReminder'));
        $this->event($this->at(10, 9, 30), fn () => $this->overdueAction('B', 'academic_cg', 'markContacted', ['note' => 'Gọi PH lần 1: hẹn đóng cuối tuần.']));
        $this->event($this->at(3, 16), fn () => $this->overdueAction('B', 'academic_cg', 'markContacted', ['note' => 'Gọi PH lần 2: chưa nghe máy, đã nhắn Zalo.']));
        $this->event($this->at(2, 10), fn () => $this->overdueAction('B', 'manager_cg', 'reportOverdueToAdmin', ['note' => 'Quá hạn trên 2 tuần, đề nghị Admin gọi trực tiếp.']));
        $this->event($this->at(0, 8), fn () => $this->receipt('B', 'academic_cg', 9500000, 'transfer', 'FT26DEMO0002', note: 'PH gửi ảnh chuyển khoản qua Zalo sáng nay.'));

        // C: đóng 1 phần tiền mặt (Học vụ lập, Kế toán duyệt) → quá hạn 1–6 ngày với phần còn lại; đợt CK tay + SePay cùng mã.
        $this->event($this->at(11, 10), fn () => $this->receipt('C', 'academic_bd', 3000000, 'cash', null, note: 'Đóng đợt 1 tại quầy.'));
        $this->event($this->at(11, 16), fn () => $this->approveLast('C', 'accountant_bd'));
        $this->event($this->at(2, 10), fn () => $this->receipt('C', 'academic_bd', 2000000, 'transfer', 'FT26DEMO0003', note: 'PH chuyển khoản đợt 2.'));
        // Webhook cùng mã giao dịch đến sau phiếu tay chờ duyệt → không tạo phiếu lần 2 (duplicate_manual); Kế toán vẫn duyệt được phiếu tay.
        $this->event($this->at(2, 10, 5), fn () => $this->sepay('FT26DEMO0003', 'BD', 2000000, $this->tuitionOf('C')->transfer_memo.' CK dot 2', expectStatus: 'duplicate_manual'));
        $this->event($this->at(2, 15), fn () => $this->approveLast('C', 'accountant_bd'));
        $this->event($this->at(1, 9), fn () => $this->overdueAction('C', 'academic_bd', 'markContacted', ['note' => 'PH hẹn đóng nốt trong tuần.']));
        $this->event($this->at(0, 7), fn () => $this->invoiceCancellation('C', 'accountant_bd', 'Xuất sai tên người nộp trên hóa đơn đợt 1, cần xuất lại.', approveBy: null));

        // D: khất nợ — Kế toán lập, Quản lý duyệt: hạn mới +10 ngày, tạm dừng nhắc nợ.
        $this->event($this->at(6, 9), fn () => $this->refundRequest('D', 'accountant_bd', ['type' => 'extension', 'extended_due_date' => $this->realNow->copy()->addDays(10)->toDateString(),
            'reason' => 'Gia đình xin khất đến kỳ lương tháng sau.'], approveBy: 'manager_bd'));

        // E: đóng đủ khi chốt (Kế toán duyệt) → chuyển nhượng 3.000.000 sang em F (Admin duyệt) → hồ sơ hoàn phí chờ duyệt quá 1 tuần.
        $this->event($this->at(40, 15), fn () => $this->approveLast('E', 'accountant_cg'));
        $this->event($this->at(20, 9), fn () => $this->refundRequest('E', 'accountant_cg', ['type' => 'transfer', 'refund_amount' => 3000000,
            'target_student_id' => $this->customers['F']->converted_student_id, 'reason' => 'Chuyển số buổi dư của chị sang em (cùng phụ huynh).'], approveBy: 'admin'));
        $this->event($this->at(10, 9), fn () => $this->refundRequest('E', 'accountant_cg', ['type' => 'refund', 'refund_amount' => 2000000,
            'reason' => 'Gia đình chuyển công tác, xin hoàn phần học phí còn lại.', 'proof_image_preview' => self::PROOF], approveBy: null));

        // G: đóng 1 phần → bảo lưu 30 ngày (học viên sang "Bảo lưu", đóng băng công nợ).
        $this->event($this->at(28, 10), fn () => $this->receipt('G', 'academic_bd', 4750000, 'cash', null, note: 'Đóng 50% học phí.'));
        $this->event($this->at(28, 15), fn () => $this->approveLast('G', 'accountant_bd'));
        $this->event($this->at(3, 9), fn () => $this->refundRequest('G', 'accountant_bd', ['type' => 'deferral', 'defer_from' => $this->realNow->copy()->subDay()->toDateString(),
            'defer_to' => $this->realNow->copy()->addDays(30)->toDateString(), 'reason' => 'Học viên đi du học hè 1 tháng.'], approveBy: 'manager_bd'));

        // H: sắp đến hạn — 1 phiếu nháp, 1 phiếu CK bị trả về (chưa sửa).
        $this->event($this->at(1, 10), fn () => $this->receipt('H', 'academic_cg', 2000000, 'cash', null, draft: true, note: 'Nháp: PH hẹn đóng tiền mặt.'));
        $this->event($this->at(1, 11), fn () => $this->receipt('H', 'academic_cg', 3000000, 'transfer', 'FT26DEMO0004', note: 'PH chuyển khoản đặt chỗ.'));
        $this->event($this->at(1, 15), fn () => $this->rejectLast('H', 'accountant_cg', 'Ảnh minh chứng mờ, không đọc được số tiền.'));

        // J: đóng đủ → hủy hóa đơn (Kế toán yêu cầu, Quản lý duyệt) → công nợ khôi phục.
        $this->event($this->at(13, 10), fn () => $this->receipt('J', 'academic_bd', 9500000, 'cash', null, note: 'Đóng đủ khóa.'));
        $this->event($this->at(13, 15), fn () => $this->approveLast('J', 'accountant_bd'));
        $this->event($this->at(9, 10), fn () => $this->invoiceCancellation('J', 'accountant_bd', 'Phụ huynh đổi ý chưa đóng, tiền mặt trả lại tại quầy — hủy hóa đơn.', approveBy: 'manager_bd'));

        // SePay: tiền vào tài khoản lạ (không gạch nợ) + giao dịch không nhận ra học viên (chờ đối soát tay).
        $this->event($this->at(1, 21), fn () => $this->sepay('SEPAY-DEMO-P4-0009', null, 1500000, 'HV CK HOC PHI', expectStatus: 'rejected_account'));
        $this->event($this->at(0, 6), fn () => $this->sepay('SEPAY-DEMO-P4-0010', 'CG', 800000, 'CHUYEN TIEN MUA SACH', expectStatus: 'unmatched'));

        // Khoản chi vận hành tháng trước + tháng này.
        $this->event($this->at(0, 7, 30), fn () => $this->seedExpenses());

        // Nền tảng: giao việc 2 chiều, trợ giảng 3 ca, báo cáo trực lớp, ticket, tài khoản & phân quyền.
        $this->event($this->at(2, 9), fn () => $this->seedWorkTasks());
        $this->event($this->at(0, 7), fn () => $this->seedTaShiftsAndClassReports());
        $this->event($this->at(1, 14), fn () => $this->seedTickets());
        $this->event($this->at(4, 9), fn () => $this->seedAccounts());
    }

    // ── Cấu hình ───────────────────────────────────────────────────────────────

    private function seedFinanceConfig(): void
    {
        $accountant = $this->staff['accountant_cg'];
        foreach (self::BANKS as $code => [$bankCode, $bankName, $number, $location]) {
            $this->asUser($accountant, SystemConfigController::class, 'storeBankAccount', [
                'bank_code' => $bankCode, 'bank_name' => $bankName, 'account_number' => $number,
                'account_holder' => 'CONG TY CO PHAN GIAO DUC MENGLISH', 'branch_location' => $location.' '.self::MARKER,
                'branch_id' => $this->branches[$code],
            ]);
            $this->asUser($accountant, TuitionController::class, 'storeInvoiceRange', [
                'branch_id' => $this->branches[$code], 'template_code' => '1/001', 'series_code' => 'C26M'.$code,
                'start_number' => 1, 'end_number' => 500, 'provider' => 'vnpt',
            ]);
        }

        // Mốc nhắc nợ quá hạn 7 ngày (mẫu tin chỉ dùng biến hệ thống) + mốc quá hạn bắt buộc liên hệ = 7 ngày.
        $this->asUser($accountant, SystemConfigController::class, 'storeDebtReminder', [
            'title' => 'Mốc 4: Cảnh báo quá hạn 7 ngày (T+7)', 'timing' => 'after', 'days' => 7,
            'template_content' => 'Kính gửi Quý phụ huynh, học phí của học viên {ten_hoc_vien} lớp {lop_hoc} còn {so_tien} đã quá hạn từ {han_dong}. Trung tâm sẽ liên hệ trực tiếp để hỗ trợ.',
            'channels' => ['portal', 'email'], 'is_enabled' => 1,
        ]);
        $this->asUser($accountant, SystemConfigController::class, 'updateDebtReminderSettings', ['must_contact_days' => 7]);

        // SePay (Admin): xác thực HMAC-SHA256 với khóa sinh ngẫu nhiên mỗi lần seed (không có khóa mặc định đoán được).
        $this->sepaySecret = Str::random(40);
        $this->asUser($this->staff['admin'], SystemConfigController::class, 'updateSepayConfig', [
            'webhook_name' => 'SePay — demo Phase 4 (sandbox)', 'webhook_url' => route('sepay.webhook.api'),
            'transaction_type' => 'in', 'data_format' => 'json', 'auth_method' => 'hmac_sha256',
            'secret_key' => $this->sepaySecret, 'is_active' => 1, 'auto_retry' => 1,
        ]);
    }

    // ── Khách → học viên + học phí ─────────────────────────────────────────────

    private function closeCustomer(string $key): void
    {
        [$name, $parent, $branch, , $paid] = self::CUSTOMERS[$key];
        $n = array_search($key, array_keys(self::CUSTOMERS), true) + 1;
        $phone = sprintf('0388%06d', $n);
        $sale = $this->staff[$branch === 'CG' ? 'sales_cg' : 'sales_bd'];
        $course = $this->classes[$branch.'-FAM2']->course;

        $this->asUser($sale, CrmController::class, 'storeCustomer', [
            'name' => $name, 'phone' => $phone, 'parent_name' => $parent, 'parent_phone' => sprintf('0368%06d', $n),
            'source' => 'Bạn bè giới thiệu', 'branch_id' => $this->branches[$branch], 'course_interest' => $course->name,
            'deal_value' => $course->tuition_fee, 'notes' => 'Khách demo Phase 4 (học phí) '.self::MARKER,
        ]);
        $customer = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
        app(CrmStageService::class)->move($customer, 'consulting', $this->staff[$branch === 'CG' ? 'academic_cg' : 'academic_bd']);

        $this->asUser($this->staff[$branch === 'CG' ? 'manager_cg' : 'manager_bd'], CrmController::class, 'processClosingWizard', array_filter([
            'customer_id' => $customer->id, 'course_id' => $course->id,
            'fee_paid_at_closing' => $paid ? 1 : 0,
            'paid_amount' => $paid ? (float) $course->tuition_fee : null,
            'payment_method' => $paid ? 'cash' : null,
            'bill_notes' => 'Chốt demo Phase 4 — xếp lớp sau (khóa '.$course->code.').',
        ], fn ($v) => $v !== null));

        $this->customers[$key] = $customer->refresh();
    }

    private function tuitionOf(string $key): StudentTuition
    {
        return StudentTuition::where('student_id', $this->customers[$key]->converted_student_id)->orderBy('id')->firstOrFail();
    }

    private function debtOf(string $key): float
    {
        $tuition = $this->tuitionOf($key);
        $tuition->recalculateDebt();

        return (float) $tuition->debt_amount;
    }

    private function lastReceipt(string $key): TuitionReceipt
    {
        return TuitionReceipt::where('student_tuition_id', $this->tuitionOf($key)->id)->latest('id')->firstOrFail();
    }

    // ── Phiếu thu ──────────────────────────────────────────────────────────────

    private function receipt(string $key, string $creator, float $amount, string $method, ?string $code, bool $draft = false, string $note = ''): void
    {
        $tuition = $this->tuitionOf($key);
        $this->asUser($this->staff[$creator], TuitionController::class, 'storeReceipt', array_filter([
            'student_tuition_id' => $tuition->id, 'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => $method,
            'transaction_code' => $code, 'payer_name' => self::CUSTOMERS[$key][1], 'notes' => trim($note.' '.self::MARKER),
            'proof_image_preview' => $method === 'cash' ? null : self::PROOF,
            'submit_action' => $draft ? 'draft' : 'submit',
        ], fn ($v) => $v !== null));
    }

    private function updateLastReceipt(string $key, string $creator, array $changes): void
    {
        $receipt = $this->lastReceipt($key);
        $this->asUser($this->staff[$creator], TuitionController::class, 'updateReceipt', $changes + [
            'amount' => (float) $receipt->amount, 'tuition_amount' => (float) $receipt->amount, 'payment_method' => $receipt->payment_method,
            'transaction_code' => $receipt->transaction_code, 'notes' => $receipt->notes,
        ], ['id' => $receipt->id]);
    }

    private function rejectLast(string $key, string $approver, string $reason): void
    {
        $this->asUser($this->staff[$approver], TuitionController::class, 'rejectReceiptAction', ['rejection_reason' => $reason], ['id' => $this->lastReceipt($key)->id]);
    }

    private function approveLast(string $key, string $approver): void
    {
        $receipt = TuitionReceipt::where('student_tuition_id', $this->tuitionOf($key)->id)->where('status', TuitionReceipt::STATUS_PENDING)->latest('id')->firstOrFail();
        $this->asUser($this->staff[$approver], TuitionController::class, 'approveReceiptAction', [], ['id' => $receipt->id]);
    }

    private function invoiceCancellation(string $key, string $requester, string $reason, ?string $approveBy): void
    {
        $receipt = TuitionReceipt::where('student_tuition_id', $this->tuitionOf($key)->id)->where('status', TuitionReceipt::STATUS_APPROVED)
            ->whereNotNull('invoice_number')->orderBy('id')->firstOrFail();
        $this->asUser($this->staff[$requester], TuitionController::class, 'storeInvoiceCancellation', [
            'invoice_number' => $receipt->invoice_number, 'amount' => (float) $receipt->amount, 'reason' => $reason,
        ]);
        if ($approveBy) {
            Carbon::setTestNow(now()->addHours(3));
            $cancellation = InvoiceCancellation::where('tuition_receipt_id', $receipt->id)->where('status', 'pending')->firstOrFail();
            $this->asUser($this->staff[$approveBy], TuitionController::class, 'approveInvoiceCancellation', [], ['id' => $cancellation->id]);
        }
    }

    private function refundRequest(string $key, string $requester, array $input, ?string $approveBy): void
    {
        $studentId = $this->customers[$key]->converted_student_id;
        $this->asUser($this->staff[$requester], TuitionController::class, 'storeRefundRequest', ['student_id' => $studentId] + $input);
        if ($approveBy) {
            Carbon::setTestNow(now()->addHours(2));
            $refund = TuitionRefundRequest::where('student_id', $studentId)->where('status', 'pending')->latest('id')->firstOrFail();
            $this->asUser($this->staff[$approveBy], TuitionController::class, 'approveRefundRequest', ['clawback_commission' => 0], ['id' => $refund->id]);
        }
    }

    private function overdueAction(string $key, string $user, string $method, array $input = []): void
    {
        $this->asUser($this->staff[$user], TuitionController::class, $method, $input, ['id' => $this->tuitionOf($key)->id]);
    }

    // ── SePay ──────────────────────────────────────────────────────────────────

    /**
     * Gửi webhook SePay thật (ký HMAC-SHA256) vào SepayWebhookController. $branch = null → tài khoản lạ.
     */
    private function sepay(string $id, ?string $branch, float $amount, string $content, bool $expectDuplicate = false, ?string $expectStatus = null): void
    {
        config(['services.sepay.webhook_enabled' => true]);
        $payload = json_encode([
            'id' => $id, 'gateway' => $branch ? self::BANKS[$branch][0] : 'VPB',
            'transactionDate' => now()->format('Y-m-d H:i:s'),
            'accountNumber' => $branch ? str_replace(' ', '', self::BANKS[$branch][2]) : '0000111122223333',
            'transferType' => 'in', 'transferAmount' => $amount, 'content' => $content, 'referenceCode' => 'REF-'.$id,
        ], JSON_UNESCAPED_UNICODE);
        $request = Request::create(route('sepay.webhook.api'), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SEPAY_SIGNATURE' => hash_hmac('sha256', $payload, $this->sepaySecret),
        ], $payload);
        app()->instance('request', $request);

        $before = SepayTransaction::count();
        $response = app(SepayWebhookController::class)->handleWebhook($request);
        $body = $response->getData(true);
        if ($expectDuplicate) {
            if (SepayTransaction::count() !== $before || ! str_contains((string) ($body['message'] ?? ''), 'Duplicate')) {
                throw new RuntimeException('DemoPhase4Seeder: webhook SePay gửi lại không bị bỏ qua.');
            }
            $this->rejections[] = ["Webhook SePay {$id} gửi lại lần 2", $body['message']];

            return;
        }
        $tx = SepayTransaction::where('sepay_id', $id)->firstOrFail();
        if ($expectStatus === null && $tx->status !== 'matched') {
            throw new RuntimeException("DemoPhase4Seeder: SePay {$id} không gạch nợ ({$tx->status}): {$tx->response_message}");
        }
        if ($expectStatus !== null) {
            if ($tx->status !== $expectStatus) {
                throw new RuntimeException("DemoPhase4Seeder: SePay {$id} mong đợi {$expectStatus}, nhận {$tx->status}: {$tx->response_message}");
            }
            $this->rejections[] = ["Webhook SePay {$id} ({$tx->status})", (string) $tx->response_message];
        }
    }

    // ── Thu chi ────────────────────────────────────────────────────────────────

    private function seedExpenses(): void
    {
        $thisMonth = $this->realNow->copy()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonthNoOverflow();
        $day = fn (Carbon $month, int $d) => $month->copy()->addDays($d - 1)->min($this->realNow)->toDateString();
        $rows = [
            ['accountant_cg', 'CG', [$lastMonth, 5], 'Tiền thuê mặt bằng Cầu Giấy', 25000000, 'chuyen_khoan', 'mat_bang_tien_ich'],
            ['accountant_cg', 'CG', [$lastMonth, 12], 'In ấn giáo trình FAM 1', 3200000, 'tien_mat', 'giao_trinh_van_hanh'],
            ['accountant_cg', 'CG', [$thisMonth, 5], 'Tiền thuê mặt bằng Cầu Giấy', 25000000, 'chuyen_khoan', 'mat_bang_tien_ich'],
            ['accountant_cg', 'CG', [$thisMonth, 8], 'Internet cáp quang + wifi', 1200000, 'chuyen_khoan', 'mat_bang_tien_ich'],
            ['accountant_cg', 'CG', [$thisMonth, 10], 'Văn phòng phẩm, bút lông bảng', 650000, 'tien_mat', 'giao_trinh_van_hanh'],
            ['accountant_bd', 'BD', [$lastMonth, 5], 'Tiền thuê mặt bằng Ba Đình', 18000000, 'chuyen_khoan', 'mat_bang_tien_ich'],
            ['accountant_bd', 'BD', [$thisMonth, 5], 'Tiền thuê mặt bằng Ba Đình', 18000000, 'chuyen_khoan', 'mat_bang_tien_ich'],
            ['accountant_bd', 'BD', [$thisMonth, 9], 'Tiền điện tháng trước', 2400000, 'chuyen_khoan', 'mat_bang_tien_ich'],
            ['accountant_bd', 'BD', [$thisMonth, 11], 'Sửa điều hòa phòng 2', 900000, 'tien_mat', 'khac'],
        ];
        foreach ($rows as [$user, $branch, [$month, $d], $title, $amount, $method, $category]) {
            $this->asUser($this->staff[$user], FinanceController::class, 'storeExpense', [
                'expense_date' => $day($month, $d), 'title' => $title, 'amount' => $amount, 'payment_method' => $method,
                'branch_id' => $this->branches[$branch], 'category' => $category, 'notes' => 'Chứng từ lưu tại kế toán chi nhánh '.self::MARKER,
            ]);
        }
    }

    // ── Giao việc, trợ giảng, trực lớp ─────────────────────────────────────────

    private function seedWorkTasks(): void
    {
        $due = $this->realNow->copy()->addDays(3)->toDateString();
        // Chiều xuôi: Admin → GV.
        $this->asUser($this->staff['admin'], WorkTaskController::class, 'store', [
            'taskTitle' => 'Chuẩn bị giáo án buổi học mở cho phụ huynh '.self::MARKER, 'taskDescription' => 'Buổi học mở cuối tháng, lớp FAM 1.',
            'assignee' => $this->staff['teacher_cg']->id, 'dueDate' => $due, 'taskType' => 'one_time', 'branch_id' => $this->branches['CG'],
            'class_id' => $this->classes['CG-FAM1']->id,
        ]);
        // Chiều ngược: GV → Học vụ (work_task.request), Học vụ làm xong gửi chờ xác nhận, GV (người giao) duyệt.
        $this->asUser($this->staff['teacher_cg'], WorkTaskController::class, 'store', [
            'taskTitle' => 'Đề nghị in thêm 5 bộ giáo trình FAM 1 '.self::MARKER, 'taskDescription' => 'Lớp có 2 học viên mới chưa có sách.',
            'assignee' => $this->staff['academic_cg']->id, 'dueDate' => $due, 'taskType' => 'one_time', 'branch_id' => $this->branches['CG'],
        ]);
        $request = WorkTask::where('title', 'like', 'Đề nghị in thêm%')->latest('id')->firstOrFail();
        Carbon::setTestNow(now()->addHours(3));
        $this->asUser($this->staff['academic_cg'], WorkTaskController::class, 'updateStatus', ['status' => 'in_progress'], ['id' => $request->id]);
        Carbon::setTestNow(now()->addHours(2));
        $this->asUser($this->staff['academic_cg'], WorkTaskController::class, 'updateStatus', ['status' => 'pending_confirmation', 'note' => 'Đã in và để ở quầy lễ tân.'], ['id' => $request->id]);
        Carbon::setTestNow(now()->addHours(1));
        $this->asUser($this->staff['teacher_cg'], WorkTaskController::class, 'approveTask', [], ['id' => $request->id]);
        // TA → Quản lý (chiều ngược, còn mở).
        $this->asUser($this->staff['assistant_bd'], WorkTaskController::class, 'store', [
            'taskTitle' => 'Báo hỏng loa phòng 2, nhờ thay trước buổi tới '.self::MARKER,
            'assignee' => $this->staff['manager_bd']->id, 'dueDate' => $due, 'taskType' => 'one_time', 'branch_id' => $this->branches['BD'],
        ]);
    }

    private function seedTaShiftsAndClassReports(): void
    {
        $today = $this->realNow->toDateString();
        $class = $this->classes['BD-FAM1'];
        $this->asUser($this->staff['academic_bd'], WorkTaskController::class, 'taAssignStore', [
            'assistant_id' => $this->staff['assistant_bd']->id, 'assign_date' => $today, 'branch_id' => $this->branches['BD'],
            'tasks' => [
                ['category' => 'before', 'content' => 'Mở phòng, bật máy chiếu, chuẩn bị bảng tên '.self::MARKER, 'attach_class' => '1', 'class_id' => $class->id],
                ['category' => 'during', 'content' => 'Hỗ trợ điểm danh + kèm nhóm học viên yếu '.self::MARKER, 'attach_class' => '1', 'class_id' => $class->id],
                ['category' => 'after', 'content' => 'Nộp báo cáo trực lớp + dọn phòng '.self::MARKER, 'attach_class' => '1', 'class_id' => $class->id],
            ],
        ]);
        $shifts = WorkTask::where('assignee_id', $this->staff['assistant_bd']->id)->whereDate('due_date', $today)
            ->where('title', 'like', '%'.self::MARKER)->get()->keyBy('time_slot_category');
        // Ca trước giờ học: có ảnh → hoàn thành ngay; ca trong giờ: không ảnh → chờ xác nhận.
        $this->asUser($this->staff['assistant_bd'], WorkTaskController::class, 'completeTask', ['note' => 'Đã mở phòng 17:15.', 'proof_image_url' => 'https://menglish.edu.vn/demo/phong-hoc.jpg'], ['id' => $shifts['before']->id]);
        $this->asUser($this->staff['assistant_bd'], WorkTaskController::class, 'completeTask', ['note' => 'Kèm 3 bạn đọc chậm.'], ['id' => $shifts['during']->id]);

        // Báo cáo trực lớp: có ảnh bảng → tự hoàn thành; không ảnh → chờ GV chính của lớp xác nhận.
        $this->asUser($this->staff['assistant_cg'], WorkTaskController::class, 'storeClassReport', [
            'class_id' => $this->classes['CG-FAM1']->id, 'session_name' => 'Buổi trực '.$this->realNow->format('d/m').' '.self::MARKER,
            'hom_nay_hoc_gi' => 'Unit 5: Animals — từ vựng + mẫu câu "It has…"', 'nhat_ky_day' => 'Lớp đi đủ, 2 bạn cần luyện phát âm.',
            'board_image_url' => 'https://menglish.edu.vn/demo/anh-bang-fam1.jpg',
        ]);
        $this->asUser($this->staff['assistant_bd'], WorkTaskController::class, 'storeClassReport', [
            'class_id' => $class->id, 'session_name' => 'Buổi trực '.$this->realNow->format('d/m').' '.self::MARKER,
            'hom_nay_hoc_gi' => 'Unit 4: My family — ôn tập', 'nhat_ky_day' => 'Quên chụp ảnh bảng.', 'task_id' => $shifts['after']->id,
        ]);
    }

    // ── Ticket ─────────────────────────────────────────────────────────────────

    private function seedTickets(): void
    {
        $manager = $this->staff['manager_cg'];
        $this->asUser($this->staff['teacher_cg'], SupportTicketController::class, 'store', [
            'title' => 'Máy chiếu phòng 203 không nhận HDMI '.self::MARKER, 'category' => 'technical_issue', 'priority' => 'high',
            'description' => 'Buổi FAM 1 tối nay cần chiếu video, máy chiếu báo "No signal".',
        ]);
        $this->asUser($this->staff['student_cg'], SupportTicketController::class, 'store', [
            'title' => 'Xin hóa đơn điện tử học phí đợt 1 '.self::MARKER, 'category' => 'tuition', 'priority' => 'medium',
            'description' => 'Phụ huynh cần hóa đơn điện tử để nộp công ty.',
        ]);
        $tickets = SupportTicket::where('title', 'like', '%'.self::MARKER)->orderBy('id')->get();
        foreach ($tickets as $i => $ticket) {
            Carbon::setTestNow(now()->addMinutes(20));
            $this->asUser($this->staff['admin'], SupportTicketController::class, 'assign', ['assignee_id' => $manager->id], ['id' => $ticket->id]);
            $this->asUser($manager, SupportTicketController::class, 'storeMessage', [
                'message' => $i === 0 ? 'Nội bộ: đã gọi IT, chi phí thay cáp 350.000đ — chưa báo GV.' : 'Nội bộ: kiểm tra phiếu thu đã duyệt trước khi gửi HĐ, KHÔNG gửi số tài khoản cá nhân.',
                'is_internal_note' => 1,
            ], ['id' => $ticket->id]);
            $this->asUser($manager, SupportTicketController::class, 'storeMessage', [
                'message' => $i === 0 ? 'Trung tâm đã cử kỹ thuật, 17:00 sẽ xong.' : 'Hóa đơn điện tử đã được gửi vào email phụ huynh.',
            ], ['id' => $ticket->id]);
        }
        $this->asUser($manager, SupportTicketController::class, 'updateStatus', ['status' => 'resolved'], ['id' => $tickets->last()->id]);
    }

    // ── Tài khoản, phân quyền, nhật ký ─────────────────────────────────────────

    private function seedAccounts(): void
    {
        $admin = $this->staff['admin'];

        // Tài khoản mới → bắt đổi mật khẩu ở lần đăng nhập đầu.
        $this->asUser($admin, UserController::class, 'store', [
            'name' => 'Kế toán Thử Việc', 'email' => 'ketoan.moi@menglish.edu.vn', 'phone' => '0911000444',
            'branch_id' => $this->branches['BD'], 'role' => 'accountant', 'password' => (string) config('access.seed_password', 'Password123!'),
            'contract_type' => 'Thử việc', 'contract_start_date' => $this->realNow->toDateString(),
            'contract_end_date' => $this->realNow->copy()->addMonths(2)->toDateString(),
        ]);

        // Sửa hồ sơ GV full-time: hợp đồng sắp hết hạn (+20 ngày) → nhật ký trước / sau + cảnh báo HĐ.
        $teacher = $this->staff['teacher_ft_cg'];
        $this->asUserOnRoute($admin, UserController::class, 'update', 'users/{user}', ['user' => $teacher], [
            'name' => $teacher->name, 'email' => $teacher->email, 'branch_id' => $teacher->branch_id,
            'role' => $teacher->getRoleNames()->first(), 'phone' => '0912000131',
            'contract_type' => 'Toàn thời gian', 'contract_start_date' => $this->realNow->copy()->subYear()->toDateString(),
            'contract_end_date' => $this->realNow->copy()->addDays(20)->toDateString(),
        ]);
        Artisan::call('hr:notify-expiring-contracts');

        // Phân quyền cá nhân: Sale CG xem lớp chi nhánh CG; Sale BD xem + sửa đúng lớp DEMO-BD-FAM1.
        $this->asUser($admin, UserPermissionOverrideController::class, 'update', [
            'overrides' => ['class' => ['view' => 'allow']],
            'scope' => ['class' => ['type' => UserPermissionOverride::SCOPE_BRANCH, 'ids' => [$this->branches['CG']]]],
        ], ['user' => $this->staff['sales_cg']]);
        $this->asUser($admin, UserPermissionOverrideController::class, 'update', [
            'overrides' => ['class' => ['view' => 'allow', 'update' => 'allow']],
            'scope' => ['class' => ['type' => UserPermissionOverride::SCOPE_CLASS, 'ids' => [$this->classes['BD-FAM1']->id]]],
        ], ['user' => $this->staff['sales_bd']]);
    }

    /**
     * Như asUser cho action nhận FormRequest cần route có tham số (UserRequest dùng $this->route('user') để biết đang
     * sửa bản ghi nào — thiếu route thì mật khẩu thành bắt buộc như khi tạo mới).
     */
    private function asUserOnRoute(User $user, string $controller, string $method, string $uri, array $routeParams, array $input): mixed
    {
        $request = Request::create('/'.$uri, 'PUT', $input);
        $route = (new Route(['PUT'], $uri, []))->bind($request);
        foreach ($routeParams as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        Auth::setUser($user);

        $formRequestClass = (new \ReflectionMethod($controller, $method))->getParameters()[0]->getType()->getName();
        /** @var \Illuminate\Foundation\Http\FormRequest $form */
        $form = $formRequestClass::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        return app($controller)->{$method}($form, ...array_values($routeParams));
    }

    // ── Tổng kết ───────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }
        $countBy = fn ($query, string $column) => $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k')
            ->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ');
        $demoTuitions = StudentTuition::whereHas('student', fn ($q) => $q->whereIn('id', CrmCustomer::where('phone', 'like', '0388%')->pluck('converted_student_id')))->get();
        $seriousDays = 7;
        $overdue = $demoTuitions->filter(fn (StudentTuition $t) => (float) $t->debt_amount > 0 && ! $t->remindersPausedOn() && ($t->daysOverdue() ?? 0) >= 1);
        $rows = [
            ['bank_accounts theo chi nhánh', BankAccount::with('branch')->get()->map(fn ($b) => ($b->branch?->code ?? 'chung').' '.$b->bank_code)->implode(', ')],
            ['invoice_configurations (dải số)', InvoiceConfiguration::with('branch')->get()->map(fn ($c) => $c->series_code.' ('.($c->branch?->code ?? 'mặc định').')')->implode(', ')],
            ['student_tuitions demo P4 theo trạng thái', $countBy(StudentTuition::whereIn('id', $demoTuitions->pluck('id')), 'status')],
            ['tuition_receipts theo trạng thái (toàn DB)', $countBy(TuitionReceipt::query(), 'status')],
            ['HĐ theo dải chi nhánh (C26MCG / C26MBD)', TuitionReceipt::where('invoice_number', 'like', 'C26MCG%')->count().' / '.TuitionReceipt::where('invoice_number', 'like', 'C26MBD%')->count()],
            ['sepay_transactions theo trạng thái', $countBy(SepayTransaction::query(), 'status')],
            ['invoice_cancellations theo trạng thái', $countBy(InvoiceCancellation::query(), 'status')],
            ['tuition_refund_requests theo loại/trạng thái', TuitionRefundRequest::get()->groupBy(fn ($r) => $r->type.'/'.$r->status)->map->count()->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ')],
            ['quá hạn demo P4 (≥7 / 1–6 ngày / tạm dừng)', $overdue->filter(fn ($t) => $t->daysOverdue() >= $seriousDays)->count().' / '
                .$overdue->filter(fn ($t) => $t->daysOverdue() < $seriousDays)->count().' / '.$demoTuitions->filter->remindersPausedOn()->count()],
            ['tuition_contact_logs theo hành động', $countBy(TuitionContactLog::query(), 'action')],
            ['debt_reminder_rules (mốc)', DebtReminderRule::orderBy('id')->pluck('milestone_key')->implode(', ')],
            ['operating_expenses (demo P4)', OperatingExpense::where('notes', 'like', '%'.self::MARKER.'%')->count().' ('.number_format((float) OperatingExpense::where('notes', 'like', '%'.self::MARKER.'%')->sum('amount'), 0, ',', '.').'đ)'],
            ['work_tasks demo P4 theo trạng thái', $countBy(WorkTask::where('title', 'like', '%'.self::MARKER.'%'), 'status')],
            ['class_reports theo trạng thái', $countBy(ClassReport::query(), 'status')],
            ['support_tickets / ghi chú nội bộ', SupportTicket::count().' / '.TicketMessage::where('is_internal_note', true)->count()],
            ['activity_log có trước/sau (updated)', Activity::where('event', 'updated')->count()],
            ['user_permission_overrides theo phạm vi', $countBy(UserPermissionOverride::query(), 'scope_type')],
            ['users bắt đổi mật khẩu (nhân sự)', User::where('must_change_password', true)->whereDoesntHave('roles', fn ($q) => $q->where('name', 'student'))->pluck('email')->implode(', ')],
            ['users HĐ hết hạn ≤ 30 ngày', User::whereNotNull('contract_end_date')->whereDate('contract_end_date', '<=', now()->addDays(30)->toDateString())->pluck('email')->implode(', ')],
        ];
        $this->command->table(['Phase 4', 'Số dòng'], $rows);
        foreach ($this->rejections as [$what, $why]) {
            $this->command->line("Bị chặn / bỏ qua đúng quy tắc — {$what}: {$why}");
        }
    }
}
