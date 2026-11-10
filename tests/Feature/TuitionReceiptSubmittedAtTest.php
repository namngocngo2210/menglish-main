<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TuitionReceiptSubmittedAtTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private StudentTuition $tuition;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'CN A', 'code' => 'CNA', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole('academic_staff');
        $student = Student::create(['code' => 'HV-SA-1', 'name' => 'HV Gui Duyet', 'phone' => '0900000002', 'branch_id' => $branch->id, 'status' => 'studying']);
        $this->tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $branch->id, 'total_amount' => 5000000, 'final_amount' => 5000000,
            'paid_amount' => 0, 'debt_amount' => 5000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);
    }

    private function receipt(array $extra = []): TuitionReceipt
    {
        return TuitionReceipt::create(array_merge([
            'receipt_number' => 'PT-SA-'.++$this->seq,
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->tuition->student_id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-0001',
            'creator_id' => $this->staff->id,
            'status' => 'pending',
        ], $extra));
    }

    private function queue(): array
    {
        $rows = [];
        $this->actingAs($this->admin)->get(route('tuition.receipts.approve', ['status' => 'pending']))
            ->assertInertia(function (AssertableInertia $page) use (&$rows) {
                $rows = $page->toArray()['props']['receipts'] ?? [];
            });

        return $rows;
    }

    private function submitPayload(): array
    {
        return ['amount' => 1000000, 'payment_method' => 'cash', 'paper_invoice_number' => 'HDG-0001', 'submit_action' => 'submit'];
    }

    public function test_submit_sets_submitted_at_and_draft_does_not(): void
    {
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $this->submitPayload() + ['student_tuition_id' => $this->tuition->id])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(TuitionReceipt::firstOrFail()->submitted_at);

        TuitionReceipt::query()->delete();
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), ['submit_action' => 'draft'] + $this->submitPayload() + ['student_tuition_id' => $this->tuition->id])
            ->assertSessionHasNoErrors();
        $this->assertNull(TuitionReceipt::firstOrFail()->submitted_at);
    }

    public function test_resubmit_after_reject_updates_submitted_at(): void
    {
        $receipt = $this->receipt(['status' => 'rejected', 'rejection_reason' => 'Thiếu minh chứng', 'submitted_at' => now()->subDays(2)]);
        $old = $receipt->submitted_at;

        $this->actingAs($this->staff)->put(route('tuition.receipts.update', $receipt->id), $this->submitPayload())
            ->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame('pending', $receipt->status);
        $this->assertTrue($receipt->submitted_at->gt($old->copy()->addDay()));
    }

    public function test_queue_is_sorted_by_submitted_at_and_displays_it_not_created_at(): void
    {
        // A tạo trước nhưng gửi sau; B tạo sau nhưng gửi trước -> A đứng trước (mới gửi nhất lên đầu).
        $a = $this->receipt(['submitted_at' => '2026-11-05 10:00:00']);
        $b = $this->receipt(['submitted_at' => '2026-11-01 08:00:00']);
        $a->forceFill(['created_at' => '2026-10-01 07:00:00'])->saveQuietly();
        $b->forceFill(['created_at' => '2026-10-20 07:00:00'])->saveQuietly();
        $legacy = $this->receipt(['submitted_at' => null]);
        $legacy->forceFill(['created_at' => '2026-11-03 09:15:00'])->saveQuietly();

        $rows = $this->queue();
        $this->assertSame([$a->id, $legacy->id, $b->id], array_column($rows, 'id'));
        $byId = array_column($rows, 'created_at', 'id');
        $this->assertSame('10:00 05/11/2026', $byId[$a->id]); // submitted_at, không phải created_at 01/10
        $this->assertSame('09:15 03/11/2026', $byId[$legacy->id]); // fallback created_at khi NULL
    }
}
