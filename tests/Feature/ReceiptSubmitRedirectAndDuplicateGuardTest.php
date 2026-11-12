<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\InvoiceConfiguration;
use App\Models\MerchandiseItem;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\Approvals\AdminOnlyApprovals;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReceiptSubmitRedirectAndDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'CN A', 'code' => 'CNA', 'is_active' => true]);
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole('academic_staff');
        $student = Student::create(['code' => 'HV-SUBMIT-1', 'name' => 'HV Submit', 'phone' => '0900000003', 'branch_id' => $branch->id, 'status' => 'studying']);
        $this->tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $branch->id, 'total_amount' => 5000000, 'final_amount' => 5000000,
            'paid_amount' => 0, 'debt_amount' => 5000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);
        $this->actingAs($this->staff);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'student_tuition_id' => $this->tuition->id, 'amount' => 1000000, 'tuition_amount' => 1000000,
            'payment_method' => 'cash', 'paper_invoice_number' => 'HDG-SUBMIT-1', 'is_vat_invoice' => '0', 'submit_action' => 'submit',
        ], $extra);
    }

    private function submit(array $extra = [])
    {
        return $this->post(route('tuition.receipts.store'), $this->payload($extra));
    }

    public function test_staff_submit_redirects_to_accessible_history_with_success(): void
    {
        $this->assertFalse($this->staff->can(AdminOnlyApprovals::TUITION_VIEW_ABILITY));
        $response = $this->submit()->assertStatus(302)->assertRedirect(route('tuition.history'))
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($message) => str_contains($message, 'Đã gửi duyệt phiếu thu'));
        $receipt = TuitionReceipt::sole();
        $this->assertSame('pending', $receipt->status);
        $this->assertSame('1000000.00', $receipt->amount);
        $this->get($response->headers->get('Location'))->assertOk();
    }

    public function test_staff_draft_then_put_submit_redirects_to_accessible_history(): void
    {
        $this->submit(['submit_action' => 'draft'])->assertSessionHasNoErrors();
        $receipt = TuitionReceipt::sole();
        $this->assertSame('draft', $receipt->status);
        $response = $this->put(route('tuition.receipts.update', $receipt->id), $this->payload())
            ->assertStatus(302)->assertRedirect(route('tuition.history'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'Đã gửi duyệt phiếu thu'));
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('1000000.00', $receipt->fresh()->amount);
        $this->get($response->headers->get('Location'))->assertOk();
    }

    public function test_admin_and_manager_submit_redirect_to_accessible_approval_page(): void
    {
        foreach (['admin', 'manager'] as $index => $role) {
            $user = User::factory()->create(['branch_id' => $this->tuition->branch_id, 'is_active' => true]);
            $user->assignRole($role);
            $this->actingAs($user);
            $amount = 1000000 + $index * 100000;
            $response = $this->submit(['amount' => $amount, 'tuition_amount' => $amount])->assertStatus(302)->assertSessionHasNoErrors();
            $receipt = TuitionReceipt::latest('id')->firstOrFail();
            $response->assertRedirect(route('tuition.receipts.approve', ['selected_id' => $receipt->id]));
            $this->get($response->headers->get('Location'))->assertOk();

            $amount += 200000;
            $this->submit(['submit_action' => 'draft', 'amount' => $amount, 'tuition_amount' => $amount])->assertSessionHasNoErrors();
            $draft = TuitionReceipt::latest('id')->firstOrFail();
            $response = $this->put(route('tuition.receipts.update', $draft->id), $this->payload(['amount' => $amount, 'tuition_amount' => $amount]))
                ->assertStatus(302)->assertSessionHasNoErrors()
                ->assertRedirect(route('tuition.receipts.approve', ['selected_id' => $draft->id]));
            $this->get($response->headers->get('Location'))->assertOk();
        }
    }

    public function test_forbidden_inertia_get_preserves_modal_error_and_reloads_normal_page(): void
    {
        $url = route('tuition.receipts.approve');
        // Regression: the trait-constant access used to turn this 403 into a 500 on PHP 8.4.
        $this->assertNotSame(500, $this->get($url, ['X-Inertia' => 'true', 'X-Remote-Modal' => 'true'])->getStatusCode());
        $this->get($url, ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', $url);
        $this->get($url)->assertForbidden();
    }

    public function test_notification_failure_does_not_break_create_or_update_submit(): void
    {
        $event = 'eloquent.creating: '.AdminNotification::class;
        Event::listen($event, function () {
            throw new \RuntimeException('Notification storage unavailable');
        });
        try {
            $this->submit()->assertStatus(302)->assertRedirect(route('tuition.history'))->assertSessionHasNoErrors();
            $this->assertSame('pending', TuitionReceipt::sole()->status);
            $this->submit(['submit_action' => 'draft', 'amount' => 1100000, 'tuition_amount' => 1100000])->assertSessionHasNoErrors();
            $draft = TuitionReceipt::latest('id')->firstOrFail();
            $this->put(route('tuition.receipts.update', $draft->id), $this->payload(['amount' => 1100000, 'tuition_amount' => 1100000]))
                ->assertStatus(302)->assertRedirect(route('tuition.history'))->assertSessionHasNoErrors();
            $this->assertSame('pending', $draft->fresh()->status);
        } finally {
            Event::forget($event);
        }
    }

    public function test_identical_pending_submit_is_rejected_before_consuming_another_paper_number(): void
    {
        InvoiceConfiguration::create([
            'branch_id' => $this->tuition->branch_id, 'kind' => InvoiceConfiguration::KIND_PAPER,
            'template_code' => '01GTKT', 'series_code' => 'C26HDG', 'start_number' => 1, 'end_number' => 50,
            'current_number' => 1, 'provider' => 'paper', 'auto_issue' => false, 'is_active' => true,
        ]);
        $extra = ['expected_paper_invoice_number' => 'C26HDG-0000001', 'proof_image_preview' => '/uploads/tuition/receipts/test.jpg'];
        $this->submit($extra)->assertSessionHasNoErrors();
        $receipt = TuitionReceipt::sole();
        $receipt->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $next = InvoiceConfiguration::peekNextPaperNumber($this->tuition->branch_id);
        $this->submit($extra)->assertSessionHasErrors(['receipt' => 'Đã có phiếu thu '.$receipt->receipt_number
            .' cùng học viên, cùng khoản và số tiền '.Money::format(1000000).' đang chờ duyệt. Vui lòng kiểm tra trước khi lập thêm.'])
            ->assertSessionHasInput('amount', 1000000);
        $this->assertSame(1, TuitionReceipt::count());
        $this->assertSame($next, InvoiceConfiguration::peekNextPaperNumber($this->tuition->branch_id));
        $this->assertSame('1000000.00', $receipt->fresh()->amount);
    }

    public function test_different_amount_and_duplicate_drafts_are_allowed(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $this->submit(['amount' => 1100000, 'tuition_amount' => 1100000])->assertSessionHasNoErrors();
        $this->submit(['submit_action' => 'draft'])->assertSessionHasNoErrors();
        $this->submit(['submit_action' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame(4, TuitionReceipt::count());
        $this->assertSame(2, TuitionReceipt::where('status', 'draft')->count());
    }

    public function test_recent_approved_duplicate_is_rejected_but_older_than_24_hours_is_allowed(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $receipt = TuitionReceipt::sole();
        $receipt->forceFill(['status' => 'approved', 'created_at' => now()->subHours(23)])->saveQuietly();
        $this->submit()->assertSessionHasErrors(['receipt' => 'Đã có phiếu thu '.$receipt->receipt_number
            .' cùng học viên, cùng khoản và số tiền '.Money::format(1000000).' đã duyệt. Vui lòng kiểm tra trước khi lập thêm.']);
        $this->assertSame(1, TuitionReceipt::count());
        $receipt->forceFill(['created_at' => now()->subHours(25)])->saveQuietly();
        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(2, TuitionReceipt::count());
    }

    public function test_draft_submit_is_rejected_by_another_pending_receipt_and_keeps_draft_unchanged(): void
    {
        $this->submit(['submit_action' => 'draft'])->assertSessionHasNoErrors();
        $draft = TuitionReceipt::sole();
        $this->submit()->assertSessionHasNoErrors();
        $this->put(route('tuition.receipts.update', $draft->id), $this->payload())->assertSessionHasErrors('receipt');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('1000000.00', $draft->fresh()->amount);
        $this->assertSame(2, TuitionReceipt::count());
    }

    public function test_duplicate_put_does_not_allocate_a_paper_number_to_the_draft(): void
    {
        $this->submit(['submit_action' => 'draft'])->assertSessionHasNoErrors();
        $draft = TuitionReceipt::sole();
        $this->submit()->assertSessionHasNoErrors();
        InvoiceConfiguration::create([
            'branch_id' => $this->tuition->branch_id, 'kind' => InvoiceConfiguration::KIND_PAPER,
            'template_code' => '01GTKT', 'series_code' => 'C26HDG', 'start_number' => 1, 'end_number' => 50,
            'current_number' => 1, 'provider' => 'paper', 'auto_issue' => false, 'is_active' => true,
        ]);
        $next = InvoiceConfiguration::peekNextPaperNumber($this->tuition->branch_id);
        $this->put(route('tuition.receipts.update', $draft->id), $this->payload([
            'expected_paper_invoice_number' => $next, 'proof_image_preview' => '/uploads/tuition/receipts/test.jpg',
        ]))->assertSessionHasErrors('receipt');
        $this->assertSame($next, InvoiceConfiguration::peekNextPaperNumber($this->tuition->branch_id));
        $this->assertNull($draft->fresh()->invoice_number);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(2, TuitionReceipt::count());
    }

    public function test_collected_items_must_match_for_a_duplicate(): void
    {
        foreach (['A', 'B'] as $code) {
            $item = MerchandiseItem::create([
                'code' => 'SUBMIT-'.$code, 'name' => 'Học liệu '.$code, 'category' => 'book', 'unit' => 'Cuốn',
                'price' => 10000, 'stock_quantity' => 0, 'is_active' => true,
            ]);
            $payload = [
                'tuition_amount' => 990000, 'surcharge_amount' => 10000,
                'collected_items' => json_encode([['id' => $item->id, 'quantity' => 1]]),
            ];
            $this->submit($payload)->assertSessionHasNoErrors();
        }
        $this->assertSame(2, TuitionReceipt::count());
        $this->submit($payload)->assertSessionHasErrors('receipt');
        $this->assertSame(2, TuitionReceipt::count());
        $this->assertSame(['1000000.00', '1000000.00'], TuitionReceipt::orderBy('id')->pluck('amount')->all());
    }

    public function test_legacy_receipt_without_student_id_uses_its_tuition_student(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        TuitionReceipt::sole()->forceFill(['student_id' => null])->saveQuietly();
        $this->submit()->assertSessionHasErrors('receipt');
        $this->assertSame(1, TuitionReceipt::count());
    }

    public function test_pending_receipt_retry_keeps_existing_editability_error_without_self_duplicate(): void
    {
        $this->submit()->assertSessionHasNoErrors();
        $receipt = TuitionReceipt::sole();
        $this->put(route('tuition.receipts.update', $receipt->id), $this->payload())
            ->assertSessionHasErrors(['receipt' => 'Chỉ sửa được phiếu ở trạng thái Bản nháp hoặc Bị từ chối.']);
        $this->assertSame(1, TuitionReceipt::count());
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('1000000.00', $receipt->fresh()->amount);
    }

    public function test_student_only_receipts_use_null_safe_tuition_matching(): void
    {
        $extra = ['student_tuition_id' => null, 'student_id' => $this->tuition->student_id];
        $this->submit($extra)->assertSessionHasNoErrors();
        $this->submit($extra)->assertSessionHasErrors('receipt');
        $this->assertSame(1, TuitionReceipt::count());
        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame(2, TuitionReceipt::count());
    }
}
