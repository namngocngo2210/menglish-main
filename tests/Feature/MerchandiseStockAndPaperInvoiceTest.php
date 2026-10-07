<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceConfiguration;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStock;
use App\Models\MerchandiseStockMovement;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Merchandise\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Mục 7: tồn kho sách theo chi nhánh (xuất khi phiếu thu được duyệt, hoàn khi hủy hóa đơn).
 * Mục 8: dải hóa đơn giấy cho thu tiền mặt (hệ thống cấp số theo chi nhánh, hủy số ghi sai, phiếu mới nhận số kế tiếp).
 */
class MerchandiseStockAndPaperInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $staff;

    private User $accountant;

    private User $admin;

    private Student $student;

    private StudentTuition $tuition;

    private MerchandiseItem $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'CN Hà Đông', 'code' => 'HD', 'is_active' => true]);
        $this->staff = $this->makeUser('academic_staff');
        $this->accountant = $this->makeUser('accountant');
        // Chỉ Admin duyệt phiếu thu / hủy hóa đơn (06/10/2026); Kế toán chỉ lập yêu cầu.
        $this->admin = $this->makeUser('admin');

        $this->student = Student::create([
            'code' => 'HV-KHO-001', 'name' => 'Lê Văn Kho', 'phone' => '0900000111',
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
        $this->tuition = StudentTuition::create([
            'student_id' => $this->student->id, 'branch_id' => $this->branch->id,
            'total_amount' => 5000000, 'final_amount' => 5000000, 'paid_amount' => 0, 'debt_amount' => 5000000,
            'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);
        $this->book = MerchandiseItem::create([
            'code' => 'BOOK-KID-1', 'name' => 'Sách Kids Box 1', 'category' => 'book', 'unit' => 'Cuốn',
            'price' => 150000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function makeUser(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['branch_id' => ($branch ?? $this->branch)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function paperRange(?Branch $branch = null, int $start = 1, int $end = 50): InvoiceConfiguration
    {
        return InvoiceConfiguration::create([
            'branch_id' => ($branch ?? $this->branch)->id, 'kind' => InvoiceConfiguration::KIND_PAPER,
            'template_code' => '01GTKT', 'series_code' => 'C26HDG', 'start_number' => $start, 'end_number' => $end,
            'current_number' => $start, 'provider' => 'paper', 'auto_issue' => false, 'is_active' => true,
        ]);
    }

    private function cashPayload(array $extra = []): array
    {
        return array_merge([
            'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000,
            'tuition_amount' => 1000000,
            'payment_method' => 'cash',
            'submit_action' => 'submit',
        ], $extra);
    }

    private function stockAt(MerchandiseItem $item, Branch $branch): int
    {
        return app(StockService::class)->quantities([$item->id], $branch->id)[$item->id];
    }

    // ───────────────────────── Mục 8: dải hóa đơn giấy ─────────────────────────

    public function test_cash_receipt_gets_next_paper_number_of_branch_and_keeps_it_on_approval(): void
    {
        $this->paperRange();

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload([
            'expected_paper_invoice_number' => 'C26HDG-0000001',
            'proof_image' => UploadedFile::fake()->image('hoa-don.jpg'),
        ]))->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::latest('id')->firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertSame('C26HDG-0000001', $receipt->invoice_number);
        $this->assertSame('C26HDG-0000001', $receipt->paper_invoice_number);
        $this->assertTrue($receipt->hasIssuedPaperInvoice());

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();
        $receipt->refresh();
        $this->assertSame('approved', $receipt->status);
        // Không lấy thêm số HĐĐT khi duyệt: số hóa đơn vẫn là số giấy đã cấp.
        $this->assertSame('C26HDG-0000001', $receipt->invoice_number);
        $this->assertFalse(InvoiceConfiguration::where('kind', 'electronic')->exists());

        $this->assertSame('C26HDG-0000002', InvoiceConfiguration::peekNextPaperNumber($this->branch->id));
    }

    public function test_cash_receipt_in_paper_branch_requires_photo_and_ignores_typed_number(): void
    {
        $this->paperRange();

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload(['paper_invoice_number' => 'HDG-TU-GHI']))
            ->assertSessionHasErrors('proof_image');
        $this->assertSame(0, TuitionReceipt::count());
        $this->assertSame('C26HDG-0000001', InvoiceConfiguration::peekNextPaperNumber($this->branch->id), 'Lỗi validate không được tiêu thụ số');
    }

    public function test_number_changed_since_form_opened_is_refused_without_consuming(): void
    {
        $range = $this->paperRange();
        TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(), 'invoice_number' => 'C26HDG-0000001', 'paper_invoice_number' => 'C26HDG-0000001',
            'student_tuition_id' => $this->tuition->id, 'student_id' => $this->student->id, 'amount' => 500000, 'tuition_amount' => 500000,
            'payment_method' => 'cash', 'payment_date' => now(), 'creator_id' => $this->staff->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload([
            'expected_paper_invoice_number' => 'C26HDG-0000001',
            'proof_image' => UploadedFile::fake()->image('hoa-don.jpg'),
        ]))->assertSessionHasErrors('paper_invoice_number');

        $this->assertSame(1, TuitionReceipt::count());
        $this->assertSame('C26HDG-0000002', InvoiceConfiguration::peekNextPaperNumber($this->branch->id));
        $this->assertLessThanOrEqual(2, $range->fresh()->current_number);
    }

    public function test_draft_reserves_number_and_method_cannot_leave_cash(): void
    {
        $this->paperRange();

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload(['submit_action' => 'draft']))->assertSessionHasNoErrors();
        $draft = TuitionReceipt::latest('id')->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('C26HDG-0000001', $draft->invoice_number);

        // Đổi sang chuyển khoản bị chặn vì số hóa đơn giấy đã cấp.
        $this->actingAs($this->staff)->put(route('tuition.receipts.update', $draft->id), [
            'amount' => 1000000, 'tuition_amount' => 1000000, 'payment_method' => 'transfer', 'submit_action' => 'draft',
        ])->assertSessionHasErrors('payment_method');

        // Tải ảnh rồi gửi duyệt: giữ nguyên số.
        $this->actingAs($this->staff)->put(route('tuition.receipts.update', $draft->id), [
            'amount' => 1000000, 'tuition_amount' => 1000000, 'payment_method' => 'cash', 'submit_action' => 'submit',
            'proof_image' => UploadedFile::fake()->image('hoa-don.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('pending', $draft->fresh()->status);
        $this->assertSame('C26HDG-0000001', $draft->fresh()->invoice_number);
    }

    public function test_wrong_number_on_paper_is_cancelled_and_next_receipt_gets_next_number(): void
    {
        $this->paperRange();
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload([
            'expected_paper_invoice_number' => 'C26HDG-0000001',
            'proof_image' => UploadedFile::fake()->image('hoa-don.jpg'),
        ]))->assertSessionHasNoErrors();
        $receipt = TuitionReceipt::latest('id')->firstOrFail();

        // Học vụ (người ghi hóa đơn) tạo yêu cầu hủy số ghi sai ngay khi phiếu còn chờ duyệt.
        $this->actingAs($this->staff)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26HDG-0000001', 'amount' => 1000000, 'reason' => 'Ghi nhầm số 0000011 trên hóa đơn giấy',
        ])->assertSessionHasNoErrors();
        $cancellation = InvoiceCancellation::firstOrFail();

        // Phiếu đang có yêu cầu hủy thì không duyệt được.
        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasErrors('receipt');

        $this->actingAs($this->admin)->post(route('tuition.invoices.cancellations.approve', $cancellation->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $receipt->fresh()->status);
        $this->assertSame('C26HDG-0000001', $receipt->fresh()->invoice_number, 'Số đã hủy giữ nguyên, không cấp lại');
        $this->assertEquals(5000000, (float) $this->tuition->fresh()->debt_amount);

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload([
            'expected_paper_invoice_number' => 'C26HDG-0000002',
            'proof_image' => UploadedFile::fake()->image('hoa-don-moi.jpg'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('C26HDG-0000002', TuitionReceipt::latest('id')->value('invoice_number'));
    }

    public function test_branch_without_paper_range_keeps_manual_paper_number(): void
    {
        $this->paperRange($this->otherBranch);

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload())
            ->assertSessionHasErrors('paper_invoice_number');
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload(['paper_invoice_number' => 'HDG-0042']))
            ->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::latest('id')->firstOrFail();
        $this->assertNull($receipt->invoice_number);
        $this->assertSame('HDG-0042', $receipt->paper_invoice_number);
    }

    public function test_closing_wizard_cash_uses_paper_number_and_photo(): void
    {
        $this->paperRange();
        $course = Course::create(['code' => 'KIDS-1', 'name' => 'Kids 1', 'tuition_fee' => 3000000, 'duration_months' => 3, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'KIDS-CG-1', 'name' => 'Kids CG 1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
        $customer = CrmCustomer::create(['code' => 'KH-KHO-1', 'name' => 'Phạm Thu Kho', 'phone' => '0911222333', 'stage' => 'result_sent', 'branch_id' => $this->branch->id]);

        $payload = [
            'customer_id' => $customer->id, 'class_id' => $class->id,
            'fee_items' => json_encode([['id' => $this->book->id]]),
            'paid_amount' => 3150000, 'payment_method' => 'cash',
            'expected_paper_invoice_number' => 'C26HDG-0000001',
        ];
        $this->actingAs($this->staff)->post(route('crm.closing-wizard.store'), $payload)->assertSessionHasErrors('paper_invoice_photo');

        $this->actingAs($this->staff)->post(route('crm.closing-wizard.store'), $payload + [
            'paper_invoice_photo' => UploadedFile::fake()->image('hoa-don.jpg'),
        ])->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::latest('id')->firstOrFail();
        $this->assertSame('C26HDG-0000001', $receipt->invoice_number);
        $this->assertNotNull($receipt->proof_image);
        // Chốt khách không còn trừ kho ngay: chờ phiếu thu được duyệt.
        $this->assertSame(0, $this->stockAt($this->book, $this->branch));

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();
        $this->assertSame(-1, $this->stockAt($this->book, $this->branch), 'Hết hàng vẫn duyệt được, kho âm để chi nhánh nhập bù');

        // Phiếu thu thứ 2 của cùng hợp đồng không trừ sách hợp đồng lần nữa.
        $tuition = StudentTuition::where('student_id', $receipt->student_id)->firstOrFail();
        $second = TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $tuition->id, 'student_id' => $receipt->student_id,
            'amount' => 1000, 'tuition_amount' => 1000, 'payment_method' => 'transfer', 'payment_date' => now(), 'creator_id' => $this->staff->id, 'status' => 'approved',
        ]);
        $this->assertSame(-1, $this->stockAt($this->book, $this->branch));
        $this->assertSame(0, MerchandiseStockMovement::where('tuition_receipt_id', $second->id)->count());
    }

    public function test_paper_range_must_belong_to_a_branch(): void
    {
        $this->actingAs($this->admin)->post(route('tuition.config.ranges.store'), [
            'kind' => 'paper', 'template_code' => '01', 'series_code' => 'C26HDG', 'start_number' => 1, 'end_number' => 50,
        ])->assertSessionHasErrors('branch_id');

        $this->actingAs($this->admin)->post(route('tuition.config.ranges.store'), [
            'kind' => 'paper', 'branch_id' => $this->branch->id, 'template_code' => '01', 'series_code' => 'C26HDG', 'start_number' => 1, 'end_number' => 50,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(InvoiceConfiguration::branchUsesPaperRange($this->branch->id));
        $this->assertFalse(InvoiceConfiguration::branchUsesPaperRange($this->otherBranch->id));
    }

    // ───────────────────────── Mục 7: tồn kho theo chi nhánh ─────────────────────────

    public function test_surcharge_items_leave_branch_stock_on_approval_and_return_on_cancellation(): void
    {
        app(StockService::class)->adjust($this->book->id, $this->branch->id, 10, MerchandiseStockMovement::TYPE_IMPORT);

        // Phụ thu riêng, không kèm học phí: 2 cuốn sách.
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_id' => $this->student->id,
            'amount' => 300000, 'tuition_amount' => 0, 'surcharge_amount' => 300000,
            'collected_items' => json_encode([['id' => $this->book->id, 'quantity' => 2]]),
            'payment_method' => 'transfer', 'transaction_code' => 'FT-KHO-01',
            'proof_image' => UploadedFile::fake()->image('unc.jpg'),
            'submit_action' => 'submit',
        ])->assertSessionHasNoErrors();
        $receipt = TuitionReceipt::latest('id')->firstOrFail();
        $this->assertSame('Sách Kids Box 1 x2', $receipt->surcharge_reason);
        $this->assertSame(10, $this->stockAt($this->book, $this->branch), 'Chưa duyệt thì chưa trừ kho');

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();
        $this->assertSame(8, $this->stockAt($this->book, $this->branch));
        $this->assertSame(0, $this->stockAt($this->book, $this->otherBranch));
        $this->assertDatabaseHas('merchandise_stock_movements', [
            'tuition_receipt_id' => $receipt->id, 'type' => 'sale', 'source' => 'surcharge', 'quantity_change' => -2, 'balance_after' => 8,
        ]);

        $receipt->refresh();
        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => $receipt->invoice_number, 'amount' => 300000, 'reason' => 'Phụ huynh trả lại sách',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('tuition.invoices.cancellations.approve', InvoiceCancellation::firstOrFail()->id))->assertSessionHasNoErrors();

        $this->assertSame(10, $this->stockAt($this->book, $this->branch));
        $this->assertDatabaseHas('merchandise_stock_movements', ['tuition_receipt_id' => $receipt->id, 'type' => 'return', 'quantity_change' => 2]);
    }

    public function test_surcharge_must_cover_selected_items_and_extra_needs_reason(): void
    {
        $base = [
            'student_id' => $this->student->id, 'tuition_amount' => 0,
            'collected_items' => json_encode([['id' => $this->book->id, 'quantity' => 1]]),
            'payment_method' => 'transfer', 'submit_action' => 'draft',
        ];

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['amount' => 100000, 'surcharge_amount' => 100000])
            ->assertSessionHasErrors('surcharge_amount');
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['amount' => 200000, 'surcharge_amount' => 200000])
            ->assertSessionHasErrors('surcharge_reason');
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['amount' => 200000, 'surcharge_amount' => 200000, 'surcharge_reason' => 'Phí in tài liệu'])
            ->assertSessionHasNoErrors();
    }

    public function test_stock_page_import_count_and_branch_scope(): void
    {
        $this->actingAs($this->staff)->get(route('merchandise.stock.index'))
            ->assertOk()
            ->assertSee('Tồn kho theo chi nhánh')
            ->assertSee('Sách Kids Box 1');

        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'import', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertSame(20, $this->stockAt($this->book, $this->branch));

        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'count', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 17, 'note' => 'Kiểm kê cuối tháng',
        ])->assertSessionHasNoErrors();
        $this->assertSame(17, $this->stockAt($this->book, $this->branch));
        $this->assertDatabaseHas('merchandise_stock_movements', ['type' => 'count', 'quantity_change' => -3, 'balance_after' => 17]);

        // Học vụ chỉ thao tác kho chi nhánh của mình.
        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'import', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->otherBranch->id, 'quantity' => 5,
        ])->assertForbidden();

        $this->actingAs($this->staff)->get(route('merchandise.stock.history', ['item' => $this->book->id, 'branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertSee('Kiểm kê cuối tháng');

        // Giáo viên không có quyền xem kho.
        $this->actingAs($this->makeUser('teacher'))->get(route('merchandise.stock.index'))->assertForbidden();
    }

    public function test_legacy_unallocated_stock_is_moved_to_branch_on_import(): void
    {
        $this->book->update(['stock_quantity' => 12]);

        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'import', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 20, 'from_legacy' => 1,
        ])->assertSessionHasErrors('quantity');

        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'import', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 12, 'from_legacy' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $this->book->fresh()->stock_quantity);
        $this->assertSame(12, $this->stockAt($this->book, $this->branch));
    }

    public function test_new_merchandise_item_initial_stock_goes_to_chosen_branch(): void
    {
        $this->actingAs($this->admin)->post(route('merchandise.store'), [
            'code' => 'UNI-S', 'name' => 'Áo đồng phục S', 'category' => 'uniform', 'unit' => 'Chiếc', 'price' => 200000,
            'stock_quantity' => 15, 'stock_branch_id' => $this->otherBranch->id, 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $item = MerchandiseItem::where('code', 'UNI-S')->firstOrFail();
        $this->assertSame(0, (int) $item->stock_quantity);
        $this->assertSame(15, (int) MerchandiseStock::where('merchandise_item_id', $item->id)->where('branch_id', $this->otherBranch->id)->value('quantity'));
    }

    public function test_out_of_stock_sale_assigns_one_restock_task_to_admin_and_closes_it_after_import(): void
    {
        app(StockService::class)->adjust($this->book->id, $this->branch->id, 1, MerchandiseStockMovement::TYPE_IMPORT);
        $approveSurcharge = function (int $quantity) {
            $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
                'student_id' => $this->student->id, 'amount' => 150000 * $quantity, 'tuition_amount' => 0,
                'surcharge_amount' => 150000 * $quantity, 'collected_items' => json_encode([['id' => $this->book->id, 'quantity' => $quantity]]),
                'payment_method' => 'transfer', 'transaction_code' => 'FT-BU-'.$quantity,
                'proof_image' => UploadedFile::fake()->image('unc.jpg'), 'submit_action' => 'submit',
            ])->assertSessionHasNoErrors();
            $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', TuitionReceipt::latest('id')->firstOrFail()->id))
                ->assertSessionHasNoErrors();
        };

        $approveSurcharge(3);
        $this->assertSame(-2, $this->stockAt($this->book, $this->branch), 'Hết sách vẫn thu và duyệt được, kho âm');
        $task = WorkTask::where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK)->sole();
        $this->assertSame($this->admin->id, $task->assignee_id);
        $this->assertSame($this->branch->id, $task->branch_id);
        $this->assertSame($this->book->id, $task->merchandise_item_id);
        $this->assertSame('new', $task->status);
        $this->assertStringContainsString('âm 2', $task->description);
        $this->assertTrue(AdminNotification::where('user_id', $this->admin->id)->where('data->task_id', $task->id)->exists());

        // Xuất tiếp cùng chi nhánh + sách: không tạo việc trùng, chỉ cập nhật số cần nhập bù.
        $approveSurcharge(1);
        $this->assertSame(1, WorkTask::where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK)->count());
        $this->assertStringContainsString('âm 3', $task->fresh()->description);

        // Nhập chưa đủ (tồn vẫn ≤ 0) → việc còn mở; nhập đủ → việc tự hoàn thành.
        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'import', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 3,
        ])->assertSessionHasNoErrors();
        $this->assertSame('new', $task->fresh()->status);
        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'import', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 10,
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);

        // Hết lần sau → việc mới.
        $this->actingAs($this->staff)->post(route('merchandise.stock.store'), [
            'type' => 'count', 'merchandise_item_id' => $this->book->id, 'branch_id' => $this->branch->id, 'quantity' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, WorkTask::where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK)->count());
        $this->assertSame(1, WorkTask::where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK)->whereIn('status', WorkTask::OPEN_STATUSES)->count());
    }

    public function test_stock_left_after_sale_does_not_create_restock_task(): void
    {
        app(StockService::class)->adjust($this->book->id, $this->branch->id, 5, MerchandiseStockMovement::TYPE_IMPORT);
        app(StockService::class)->adjust($this->book->id, $this->branch->id, -2, MerchandiseStockMovement::TYPE_SALE);

        $this->assertSame(0, WorkTask::where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK)->count());
    }

    public function test_cash_receipt_form_in_paper_branch_shows_content_to_write_on_paper_invoice(): void
    {
        $this->paperRange();
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->cashPayload(['submit_action' => 'draft']))->assertSessionHasNoErrors();
        $draft = TuitionReceipt::latest('id')->firstOrFail();

        $this->actingAs($this->staff)->get(route('tuition.receipts.edit', $draft->id))
            ->assertOk()
            ->assertSee('data-testid="paper-invoice-content"', false)
            ->assertSee('ghi <strong>đúng nội dung thu</strong>', false)
            ->assertSee('C26HDG-0000001')
            ->assertSee('Học phí 24 buổi Lê Văn Kho');
    }
}
