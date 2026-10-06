<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Ưu đãi khi thu học phí (03/10/2026): dùng lại ưu đãi có sẵn / mặc định, ca đặc biệt tạo ưu đãi riêng kèm lý do.
 */
class PromotionReuseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    /** Người duyệt phiếu thu: chỉ Admin (06/10/2026). */
    private User $admin;

    private Course $course;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở ưu đãi', 'code' => 'UD', 'is_active' => true]);
        $this->academic = $this->makeUser('academic_staff');
        $this->admin = $this->makeUser(Roles::ADMIN);
        $this->course = Course::create(['code' => 'MOV1', 'name' => 'Movers 1', 'tuition_fee' => 10000000, 'total_lessons' => 48, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'MOV1-A', 'name' => 'Movers 1 A', 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 10, 'status' => 'active', 'tuition_fee' => 10000000,
        ]);
    }

    public function test_catalog_page_lists_reusable_and_special_promotions_separately(): void
    {
        $this->promotion(['name' => 'Ưu đãi khai giảng', 'is_default' => true]);
        $this->promotion(['name' => 'Giảm riêng bé An', 'is_special' => true, 'reason' => 'Anh chị em ruột', 'usage_limit' => 1]);

        $this->actingAs($this->academic)->get(route('crm.promotions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Promotions/Index')
                ->where('counts.catalog', 1)
                ->where('counts.special', 1)
                ->has('promotions.data', 1)
                ->where('promotions.data.0.name', 'Ưu đãi khai giảng')
                ->where('promotions.data.0.is_default', true));

        $this->actingAs($this->academic)->get(route('crm.promotions.index', ['tab' => 'special']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('promotions.data.0.reason', 'Anh chị em ruột'));
    }

    public function test_teacher_cannot_open_promotion_catalog(): void
    {
        $this->actingAs($this->makeUser('teacher'))->get(route('crm.promotions.index'))->assertForbidden();
    }

    public function test_update_and_toggle_promotion(): void
    {
        $promotion = $this->promotion(['name' => 'Cũ']);

        $this->actingAs($this->academic)->put(route('crm.promotions.update', $promotion), [
            'name' => 'Ưu đãi học viên cũ',
            'type' => 'fixed',
            'value' => 300000,
            'max_discount_amount' => 999,
            'is_default' => '1',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $promotion->refresh();
        $this->assertSame('Ưu đãi học viên cũ', $promotion->name);
        $this->assertTrue($promotion->is_default);
        // Giảm cố định không có mức trần.
        $this->assertNull($promotion->max_discount_amount);

        $this->actingAs($this->academic)->post(route('crm.promotions.toggle', $promotion))->assertRedirect();
        $this->assertFalse($promotion->fresh()->is_active);
    }

    public function test_special_promotion_requires_reason_and_is_single_use(): void
    {
        $this->actingAs($this->academic)->postJson(route('crm.promotions.store'), [
            'name' => 'Giảm thêm',
            'type' => 'fixed',
            'value' => 500000,
            'is_special' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($this->academic)->postJson(route('crm.promotions.store'), [
            'name' => 'Giảm thêm',
            'type' => 'fixed',
            'value' => 500000,
            'is_special' => true,
            'is_default' => true,
            'usage_limit' => 50,
            'reason' => 'Hai con cùng học',
        ])->assertOk()->assertJsonPath('promotion.is_special', true);

        $promotion = Promotion::where('name', 'Giảm thêm')->firstOrFail();
        $this->assertTrue($promotion->is_special);
        $this->assertFalse($promotion->is_default);
        $this->assertSame(1, $promotion->usage_limit);
        $this->assertSame($this->academic->id, $promotion->created_by);
        $this->assertSame('Hai con cùng học', $promotion->reason);
    }

    public function test_closing_wizard_offers_catalog_promotions_and_default_flag(): void
    {
        $default = $this->promotion(['name' => 'Mặc định khóa', 'is_default' => true, 'course_id' => $this->course->id]);
        $this->promotion(['name' => 'Riêng khách khác', 'is_special' => true, 'reason' => 'x', 'usage_limit' => 1]);
        $this->promotion(['name' => 'Đã ngừng', 'is_active' => false]);
        $lead = $this->lead();

        $this->actingAs($this->academic)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/ClosingWizard')
                ->has('promotions', 1)
                ->where('promotions.0.id', $default->id)
                ->where('promotions.0.is_default', true));
    }

    public function test_special_promotion_can_only_be_used_by_its_creator(): void
    {
        $other = $this->makeUser('academic_staff');
        $special = $this->promotion(['name' => 'Riêng', 'is_special' => true, 'reason' => 'Ca đặc biệt', 'usage_limit' => 1, 'created_by' => $other->id]);
        $lead = $this->lead();
        $bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id, 'is_default_vietqr' => true, 'is_active' => true,
        ]);

        $this->actingAs($this->academic)->post(route('crm.closing-wizard.store'), [
            'customer_id' => $lead->id,
            'class_id' => $this->classModel->id,
            'promotion_id' => $special->id,
            'fee_paid_at_closing' => 0,
            'paid_amount' => 0,
            'bank_account_id' => $bank->id,
        ])->assertSessionHasErrors('promotion_id');

        $this->assertSame(0, StudentTuition::count());
    }

    public function test_receipt_with_catalog_promotion_recomputes_discount_and_counts_usage_on_approval(): void
    {
        $tuition = $this->tuition(4000000);
        $promotion = $this->promotion(['name' => 'Giảm 10%', 'type' => 'percent', 'value' => 10, 'max_discount_amount' => 300000]);

        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $tuition->id,
            'promotion_id' => $promotion->id,
            // Số giảm gửi lên bị bỏ qua: server tính lại theo ưu đãi (10% của 4 triệu, trần 300.000).
            'discount_amount' => 999999,
            'tuition_amount' => 3700000,
            'amount' => 3700000,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-UD-1',
            'submit_action' => 'submit',
        ])->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::firstOrFail();
        $this->assertSame($promotion->id, $receipt->promotion_id);
        $this->assertEquals(300000, (float) $receipt->discount_amount);
        $this->assertSame(0, $promotion->fresh()->used_count);

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();

        $this->assertSame(1, $promotion->fresh()->used_count);
        $this->assertEquals(0, (float) $tuition->fresh()->debt_amount);
    }

    public function test_manual_receipt_discount_requires_reason(): void
    {
        $tuition = $this->tuition(4000000);
        $payload = [
            'student_tuition_id' => $tuition->id,
            'discount_amount' => 200000,
            'tuition_amount' => 3800000,
            'amount' => 3800000,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-UD-2',
            'submit_action' => 'submit',
        ];

        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), $payload)->assertSessionHasErrors('discount_reason');
        $this->assertSame(0, TuitionReceipt::count());

        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [...$payload, 'discount_reason' => 'Bù buổi nghỉ do trung tâm'])
            ->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::firstOrFail();
        $this->assertNull($receipt->promotion_id);
        $this->assertSame('Bù buổi nghỉ do trung tâm', $receipt->discount_reason);
    }

    public function test_receipt_rejects_promotion_for_another_branch(): void
    {
        $tuition = $this->tuition(4000000);
        $otherBranch = Branch::create(['name' => 'Cơ sở khác', 'code' => 'KHAC', 'is_active' => true]);
        $promotion = $this->promotion(['name' => 'Chỉ cơ sở khác', 'branch_id' => $otherBranch->id]);

        $this->actingAs($this->academic)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $tuition->id,
            'promotion_id' => $promotion->id,
            'tuition_amount' => 3500000,
            'amount' => 3500000,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-UD-3',
            'submit_action' => 'submit',
        ])->assertSessionHasErrors('promotion_id');
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function promotion(array $attributes): Promotion
    {
        return Promotion::create([
            'code' => 'UD'.strtoupper(substr(md5((string) microtime(true).random_int(0, 9999)), 0, 6)),
            'type' => 'fixed',
            'value' => 500000,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function lead(): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Khách ưu đãi',
            'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->academic->id,
            'stage' => 'consulting',
        ]);
    }

    private function tuition(float $amount): StudentTuition
    {
        $student = Student::create([
            'code' => 'HV-UD-'.random_int(1000, 9999),
            'name' => 'Học viên ưu đãi',
            'phone' => '0900000009',
            'branch_id' => $this->branch->id,
            'current_class_id' => $this->classModel->id,
            'status' => 'studying',
        ]);

        return StudentTuition::create([
            'student_id' => $student->id,
            'class_id' => $this->classModel->id,
            'branch_id' => $this->branch->id,
            'total_amount' => $amount,
            'final_amount' => $amount,
            'paid_amount' => 0,
            'debt_amount' => $amount,
            'due_date' => now()->addDays(10),
            'status' => 'unpaid',
        ]);
    }
}
