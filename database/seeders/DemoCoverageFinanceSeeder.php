<?php

namespace Database\Seeders;

use App\Http\Controllers\CrmController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TuitionController;
use App\Models\AcademicRecord;
use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\CrmStageService;
use App\Services\Tuition\Approvals\PaymentReportApprovalSource;
use App\Services\Tuition\PaymentReportService;
use App\Services\Tuition\TuitionSlaService;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dữ liệu demo Tài chính bổ sung (case đẹp lẫn xấu còn thiếu sau DemoPhase4Seeder), trên 5 khách học phí mới chốt qua CRM
 * (SĐT 03876…, tên tiền tố "# ", khóa FAM 2 chờ xếp lớp) để không đụng công nợ của khách demo khác. Mốc thời gian tương đối
 * với hôm nay (d = số ngày trước), mọi thao tác qua controller / service thật với đúng vai trò:
 * - Học viên báo đã đóng học phí (cổng học viên): P4 báo CK đợt 1 → Admin xác nhận → Kế toán lập phiếu CK, Admin duyệt;
 *   P3 báo đã CK nhưng chưa thấy tiền → bị từ chối; sáng nay P3 báo lại (chờ xác nhận). Học viên nhận kết quả ở hộp thư cổng.
 * - Hoàn phí (P1), chuyển nhượng (P2 → P4), khất nợ (P3), bảo lưu (P4): mỗi loại 1 hồ sơ bị Admin từ chối kèm lý do.
 * - Hủy hóa đơn: yêu cầu hủy HĐ đã duyệt của P2 bị Admin từ chối.
 * - Nộp tiền mặt về TK công ty: P1 nộp đúng hạn trong ngày; P2 bị nhắc lúc 19:15 (tuition:check-cash-deposits) rồi sáng
 *   hôm sau mới nộp (nộp trễ); P5 thu hôm qua, đã nhắc, vẫn chưa nộp (quá hạn).
 *
 * Không làm: kỳ lương "Đã chi trả" (xem báo cáo của seeder) và OperatingExpenseSeeder (ghi đè chi nhánh người dùng, kỳ lương
 * 09/2026, tạo học viên giả — không gọi).
 *
 * Gọi từ DemoCoverageSeeder (php artisan demo:luong). Idempotent: đã có khách 0387600001 thì bỏ qua. 1 transaction.
 */
class DemoCoverageFinanceSeeder extends Seeder
{
    use InvokesControllersAsUser;

    public const MARKER = '[demo-taichinh]';

    public const FIRST_PHONE = '0387600001';

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
    ];

    /** Khách học phí: khóa => [tên, phụ huynh, chi nhánh, chốt cách đây (ngày)]. Hạn đóng = ngày chốt + 7. */
    private const CUSTOMERS = [
        'P1' => ['# Phan Gia Huy', 'Phan Văn Dũng', 'CG', 30],     // đóng đủ tiền mặt, nộp đúng hạn → xin hoàn phí bị từ chối
        'P2' => ['# Đỗ Ngọc Hân', 'Đỗ Văn Thắng', 'BD', 21],       // đóng đủ tiền mặt, nộp trễ → hủy HĐ / chuyển nhượng bị từ chối
        'P3' => ['# Lê Minh Quân', 'Lê Văn Hòa', 'CG', 10],        // quá hạn: báo đóng bị từ chối, khất nợ bị từ chối, báo đóng lại
        'P4' => ['# Ngô Thanh Tâm', 'Ngô Thị Huyền', 'BD', 25],    // báo đóng được xác nhận → phiếu CK đã duyệt; bảo lưu bị từ chối
        'P5' => ['# Phí Thùy Linh', 'Phí Văn Nam', 'CG', 12],      // đóng 1 phần tiền mặt hôm qua, chưa nộp về TK công ty
    ];

    /** Ảnh minh chứng nhỏ (PNG) dạng tham chiếu an toàn, không tạo file trong public/uploads. */
    private const PROOF = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, Student> */
    private array $students = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> */
    private array $events = [];

    private Carbon $realNow;

    public function run(): void
    {
        $users = User::whereIn('email', self::STAFF)->get()->keyBy('email');
        $classes = ClassModel::whereIn('code', ['DEMO-CG-FAM2', 'DEMO-BD-FAM2'])->with('course')->get()->keyBy('code');
        if ($users->count() < count(self::STAFF) || $classes->count() < 2 || $classes->contains(fn (ClassModel $c) => ! $c->course)) {
            $this->command?->warn('DemoCoverageFinanceSeeder: chưa có tài khoản UserSeeder / lớp FAM 2 của DemoPhase1Seeder — bỏ qua.');

            return;
        }
        if (CrmCustomer::where('phone', self::FIRST_PHONE)->exists()) {
            $this->command?->info('DemoCoverageFinanceSeeder: đã có dữ liệu demo tài chính bổ sung — bỏ qua.');

            return;
        }

        $this->staff = collect(self::STAFF)->map(fn (string $email) => $users[$email])->all();
        $this->classes = ['CG' => $classes['DEMO-CG-FAM2'], 'BD' => $classes['DEMO-BD-FAM2']];

        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $this->realNow = now()->copy();

        try {
            DB::transaction(function () {
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
        foreach (self::CUSTOMERS as $key => [, , , $daysAgo]) {
            $this->event($this->at($daysAgo, 9, 30), fn () => $this->closeCustomer($key));
        }

        // P1: đóng đủ tiền mặt, Admin duyệt, nộp về TK công ty trước 19:00 → xin hoàn phí → Admin từ chối (ưu tiên chuyển nhượng).
        $this->event($this->at(29, 10), fn () => $this->receipt('P1', 'academic_cg', $this->fee('P1'), 'cash', note: 'Đóng đủ khóa tại quầy.'));
        $this->event($this->at(29, 14), fn () => $this->approveLast('P1'));
        $this->event($this->at(29, 17, 30), fn () => $this->confirmDeposit('P1'));
        $this->event($this->at(6, 9), fn () => $this->refundRequest('P1', 'accountant_cg', [
            'type' => TuitionRefundRequest::TYPE_REFUND, 'refund_amount' => 4000000, 'admin_fee' => 300000,
            'reason' => 'Gia đình chuyển nhà ra Hải Phòng, xin hoàn phần học phí chưa học.',
            'no_transfer_reason' => 'Phụ huynh muốn nhận lại tiền mặt, chưa tìm được người nhận chuyển nhượng.',
        ], 'Học viên chưa xếp lớp, có thể học lớp FAM 2 online hoặc chuyển nhượng buổi dư; đề nghị tư vấn chuyển nhượng trước khi hoàn tiền (A6).'));

        // P4: PH báo đã CK đợt 1 từ cổng học viên → Admin xác nhận → Kế toán lập phiếu CK kèm UNC, Admin duyệt.
        $this->event($this->at(8, 21), fn () => $this->paymentReport('P4', $this->fee('P4') / 2, 'Phụ huynh đã chuyển khoản đợt 1 (50%) qua Techcombank, nội dung '.$this->tuitionOf('P4')->transfer_memo.'.'));
        $this->event($this->at(7, 8, 30), fn () => $this->resolveReport('P4', null));
        $this->event($this->at(7, 9), fn () => $this->receipt('P4', 'accountant_bd', $this->fee('P4') / 2, 'transfer', 'FT26DEMOTC0401', 'Theo báo đóng của PH trên cổng học viên, đã đối chiếu sao kê.'));
        $this->event($this->at(7, 11), fn () => $this->approveLast('P4'));
        // P4: xin bảo lưu 5 tuần → Admin từ chối (lớp chưa khai giảng, tư vấn chuyển lớp tháng sau).
        $this->event($this->at(2, 10), fn () => $this->refundRequest('P4', 'accountant_bd', [
            'type' => TuitionRefundRequest::TYPE_DEFERRAL, 'defer_from' => $this->realNow->copy()->addDays(3)->toDateString(),
            'defer_to' => $this->realNow->copy()->addDays(40)->toDateString(), 'reason' => 'Học viên nằm viện mổ ruột thừa, nghỉ dưỡng hơn 1 tháng.',
        ], 'Học viên chưa vào lớp: chuyển sang lớp FAM 2 khai giảng tháng sau thay vì bảo lưu, giữ nguyên học phí đã đóng.', at: $this->at(1, 16)));

        // P3: PH báo đã CK đủ nhưng chưa thấy tiền về → từ chối; xin khất 18 ngày → từ chối; sáng nay báo lại đã CK 50%.
        $this->event($this->at(4, 20), fn () => $this->paymentReport('P3', $this->fee('P3'), 'Gia đình đã chuyển khoản đủ học phí khóa FAM 2 tối nay qua app ngân hàng.'));
        $this->event($this->at(3, 9, 30), fn () => $this->resolveReport('P3', 'Chưa thấy khoản tiền về tài khoản MB của trung tâm; nhờ phụ huynh gửi ảnh ủy nhiệm chi qua Zalo trung tâm'));
        $this->event($this->at(2, 9), fn () => $this->refundRequest('P3', 'accountant_cg', [
            'type' => TuitionRefundRequest::TYPE_EXTENSION, 'extended_due_date' => $this->realNow->copy()->addDays(18)->toDateString(),
            'reason' => 'Phụ huynh xin khất học phí thêm 3 tuần do chưa nhận lương.',
        ], 'Đã quá hạn và chưa đóng đợt nào; chỉ duyệt khất khi đóng tối thiểu 50% học phí.', at: $this->at(2, 14)));
        $this->event($this->at(0, 7, 30), fn () => $this->paymentReport('P3', $this->fee('P3') / 2, 'Đã chuyển khoản 50% học phí sáng nay, ảnh UNC đã gửi Zalo trung tâm.'));

        // P2: đóng đủ tiền mặt 15:00, Admin duyệt; 19:15 hệ thống nhắc chưa nộp; sáng hôm sau mới nộp (trễ).
        $this->event($this->at(2, 15), fn () => $this->receipt('P2', 'academic_bd', $this->fee('P2'), 'cash', note: 'Đóng đủ khóa tại quầy.'));
        $this->event($this->at(2, 16, 30), fn () => $this->approveLast('P2'));
        $this->event($this->at(2, 19, 15), fn () => app(TuitionSlaService::class)->notifyUndepositedCash(now()));
        $this->event($this->at(1, 8, 45), fn () => $this->confirmDeposit('P2'));
        // P2: yêu cầu hủy HĐ để xuất lại → Admin từ chối; chuyển nhượng 3 triệu sang bạn cùng lớp → Admin từ chối.
        $this->event($this->at(1, 10), fn () => $this->invoiceCancellation('P2', 'accountant_bd',
            'Phụ huynh muốn xuất lại hóa đơn đứng tên công ty để thanh toán phúc lợi.',
            'Hóa đơn đã xuất đúng người nộp; xuất hóa đơn GTGT cho công ty thì lập đề nghị xuất bổ sung, không hủy hóa đơn đã thu.'));
        $this->event($this->at(1, 11), fn () => $this->refundRequest('P2', 'accountant_bd', [
            'type' => TuitionRefundRequest::TYPE_TRANSFER, 'refund_amount' => 3000000,
            'target_student_id' => fn () => $this->students['P4']->id,
            'reason' => 'Chuyển 3.000.000đ số dư sang bạn cùng lớp # Ngô Thanh Tâm (hai gia đình quen biết).',
        ], 'Chuyển nhượng chỉ áp dụng giữa anh chị em ruột / người thân; hai học viên khác phụ huynh.', at: $this->at(1, 17, 30)));

        // P5: đóng 1 phần tiền mặt hôm qua, Admin duyệt; 19:15 hệ thống nhắc; đến nay vẫn chưa nộp về TK công ty.
        $this->event($this->at(1, 16), fn () => $this->receipt('P5', 'academic_cg', 5000000, 'cash', note: 'Đóng đợt 1, hẹn đóng nốt cuối tháng.'));
        $this->event($this->at(1, 17), fn () => $this->approveLast('P5'));
        $this->event($this->at(1, 19, 15), fn () => app(TuitionSlaService::class)->notifyUndepositedCash(now()));
    }

    // ── Khách → học viên + học phí ─────────────────────────────────────────────

    private function closeCustomer(string $key): void
    {
        [$name, $parent, $branch] = self::CUSTOMERS[$key];
        $n = array_search($key, array_keys(self::CUSTOMERS), true) + 1;
        $phone = sprintf('038760%04d', $n);
        $course = $this->classes[$branch]->course;

        $this->asUser($this->staff[$branch === 'CG' ? 'sales_cg' : 'sales_bd'], CrmController::class, 'storeCustomer', [
            'name' => $name, 'phone' => $phone, 'parent_name' => $parent, 'parent_phone' => sprintf('036760%04d', $n),
            'source' => 'Facebook Ads', 'branch_id' => $this->classes[$branch]->branch_id, 'course_interest' => $course->name,
            'deal_value' => $course->tuition_fee, 'notes' => 'Khách demo tài chính (học phí) '.self::MARKER,
        ]);
        $customer = CrmCustomer::where('phone_normalized', $phone)->firstOrFail();
        app(CrmStageService::class)->move($customer, 'consulting', $this->staff[$branch === 'CG' ? 'academic_cg' : 'academic_bd']);

        $this->asUser($this->staff[$branch === 'CG' ? 'manager_cg' : 'manager_bd'], CrmController::class, 'processClosingWizard', [
            'customer_id' => $customer->id, 'course_id' => $course->id, 'fee_paid_at_closing' => 0,
            'bill_notes' => 'Chốt khóa '.$course->code.' — xếp lớp sau. '.self::MARKER,
        ]);

        $this->students[$key] = Student::findOrFail($customer->refresh()->converted_student_id);
    }

    private function tuitionOf(string $key): StudentTuition
    {
        return StudentTuition::where('student_id', $this->students[$key]->id)->orderBy('id')->firstOrFail();
    }

    private function fee(string $key): float
    {
        return (float) $this->tuitionOf($key)->final_amount;
    }

    private function branchOf(string $key): string
    {
        return self::CUSTOMERS[$key][2];
    }

    // ── Phiếu thu, nộp tiền mặt, hủy hóa đơn ───────────────────────────────────

    private function receipt(string $key, string $creator, float $amount, string $method, ?string $code = null, string $note = ''): void
    {
        $tuition = $this->tuitionOf($key);
        $this->asUser($this->staff[$creator], TuitionController::class, 'storeReceipt', array_filter([
            'student_tuition_id' => $tuition->id, 'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => $method,
            'transaction_code' => $code, 'payer_name' => self::CUSTOMERS[$key][1], 'notes' => trim($note.' '.self::MARKER),
            // Chi nhánh có dải hóa đơn giấy: hệ thống cấp số và cần ảnh hóa đơn; không có thì nhập số hóa đơn giấy.
            'proof_image_preview' => self::PROOF,
            'paper_invoice_number' => $method === 'cash' ? 'HDG-TC-'.$tuition->id : null,
            'submit_action' => 'submit',
        ], fn ($v) => $v !== null));
    }

    private function lastReceipt(string $key, ?string $status = null): TuitionReceipt
    {
        return TuitionReceipt::where('student_tuition_id', $this->tuitionOf($key)->id)
            ->when($status, fn ($q) => $q->where('status', $status))->latest('id')->firstOrFail();
    }

    private function approveLast(string $key): void
    {
        $this->asUser($this->staff['admin'], TuitionController::class, 'approveReceiptAction', [], ['id' => $this->lastReceipt($key, TuitionReceipt::STATUS_PENDING)->id]);
    }

    /** Admin (quyền tuition.confirm_deposit) xác nhận tiền mặt của phiếu đã nộp về TK công ty. */
    private function confirmDeposit(string $key): void
    {
        $this->asUser($this->staff['admin'], TuitionController::class, 'confirmDeposit', [], ['id' => $this->lastReceipt($key, TuitionReceipt::STATUS_APPROVED)->id]);
    }

    private function invoiceCancellation(string $key, string $requester, string $reason, string $rejection): void
    {
        $receipt = $this->lastReceipt($key, TuitionReceipt::STATUS_APPROVED);
        $this->asUser($this->staff[$requester], TuitionController::class, 'storeInvoiceCancellation', [
            'invoice_number' => $receipt->invoice_number, 'amount' => (float) $receipt->amount, 'reason' => $reason,
        ]);
        Carbon::setTestNow(now()->addHours(5));
        $cancellation = InvoiceCancellation::where('tuition_receipt_id', $receipt->id)->where('status', 'pending')->firstOrFail();
        $this->asUser($this->staff['admin'], TuitionController::class, 'rejectInvoiceCancellation', ['rejection_reason' => $rejection], ['id' => $cancellation->id]);
    }

    // ── Hoàn / chuyển nhượng / khất nợ / bảo lưu (bị từ chối) ─────────────────

    /** Kế toán lập hồ sơ; Admin từ chối lúc $at (mặc định 25 giờ sau). Giá trị Closure trong $input được tính lúc chạy. */
    private function refundRequest(string $key, string $requester, array $input, string $rejection, ?Carbon $at = null): void
    {
        $input = array_map(fn ($v) => $v instanceof Closure ? $v() : $v, $input);
        $studentId = $this->students[$key]->id;
        $this->asUser($this->staff[$requester], TuitionController::class, 'storeRefundRequest', ['student_id' => $studentId] + $input);
        $refund = TuitionRefundRequest::where('student_id', $studentId)->where('status', 'pending')->latest('id')->firstOrFail();

        $rejectAt = $at ?? now()->addHours(25);
        $ceiling = $this->realNow->copy()->subMinutes(30);
        Carbon::setTestNow($rejectAt->lt($ceiling) ? $rejectAt : $ceiling);
        $this->asUser($this->staff['admin'], TuitionController::class, 'rejectRefundRequest', ['rejection_reason' => $rejection], ['id' => $refund->id]);
    }

    // ── Học viên báo đã đóng học phí ───────────────────────────────────────────

    private function paymentReport(string $key, float $amount, string $content): void
    {
        $student = $this->students[$key]->refresh();
        $portalUser = $student->user_id ? User::find($student->user_id) : null;
        if (! $portalUser) {
            throw new RuntimeException("DemoCoverageFinanceSeeder: học viên {$student->name} chưa có tài khoản cổng học viên.");
        }
        $this->asUser($portalUser, StudentPortalController::class, 'submitTuitionRequest', [
            'student_id' => $student->id, 'amount' => $amount, 'content' => $content,
        ]);
    }

    /** Xác nhận ($reason null) hoặc từ chối báo đóng gần nhất của học viên trong hộp "Cần duyệt" (chỉ Admin có đủ quyền). */
    private function resolveReport(string $key, ?string $reason): void
    {
        $report = AcademicRecord::where('screen_key', PaymentReportService::SCREEN_KEY)
            ->where('status', PaymentReportService::STATUS_PENDING)
            ->where('data->student_id', (string) $this->students[$key]->id)->latest('id')->firstOrFail();
        $source = app(PaymentReportApprovalSource::class);
        $result = $reason === null
            ? $source->approve($this->staff['admin'], $report->id)
            : $source->reject($this->staff['admin'], $report->id, $reason);
        if (! $result->ok) {
            throw new RuntimeException('DemoCoverageFinanceSeeder: xử lý báo đóng học phí lỗi: '.$result->message);
        }
    }

    // ── Tổng kết ───────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }
        $studentIds = collect($this->students)->pluck('id');
        $countBy = fn ($query, string $column) => $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k')
            ->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ');
        $receipts = TuitionReceipt::whereIn('student_id', $studentIds)->get();

        $this->command->table(['Tài chính (demo bổ sung)', 'Số dòng'], [
            ['Báo đóng học phí theo trạng thái', $countBy(AcademicRecord::where('screen_key', PaymentReportService::SCREEN_KEY)->whereIn('data->student_id', $studentIds->map(fn ($id) => (string) $id)), 'status')],
            ['Hồ sơ hoàn / chuyển / khất / bảo lưu', TuitionRefundRequest::whereIn('student_id', $studentIds)->get()
                ->map(fn (TuitionRefundRequest $r) => $r->type.'/'.$r->status)->countBy()->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ')],
            ['Yêu cầu hủy HĐ', $countBy(InvoiceCancellation::whereIn('student_id', $studentIds), 'status')],
            ['Phiếu thu theo trạng thái', $receipts->countBy('status')->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ')],
            ['Tiền mặt: nộp đúng hạn / trễ / chưa nộp', $receipts->filter(fn ($r) => $r->depositState() === 'on_time')->count().' / '
                .$receipts->filter(fn ($r) => $r->depositState() === 'late')->count().' / '
                .$receipts->filter(fn ($r) => $r->depositState() === 'pending')->count()],
        ]);
    }
}
