<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\StaffReport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Nhật ký Học vụ (mockup 02/05): ghi sự việc bằng mã KPI Học vụ + nội dung; chỉ Admin xác nhận "Lỗi nghiêm trọng".
 */
class AcademicJournalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    public function test_staff_logs_entry_with_kpi_code_and_content_only(): void
    {
        $staff = $this->makeUser('academic_staff', ['name' => 'Nguyễn Thị A']);

        $this->actingAs($staff)->get(route('reports.journal'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Journal')
                ->where('canConfirmSerious', false)
                ->where('kpiOptions.0.value', '1.1'));

        $this->actingAs($staff)->post(route('reports.journal.store'), ['kpi_code' => '', 'content' => ''])
            ->assertSessionHasErrors('content');
        $this->actingAs($staff)->post(route('reports.journal.store'), [
            'kpi_code' => '1.2', 'content' => "Học viên B thắc mắc điểm danh ngày 08/10.\nĐã cập nhật lại hệ thống.",
        ])->assertSessionHasNoErrors();

        $entry = StaffReport::where('type', 'journal')->sole();
        $this->assertSame('1.2', $entry->kpi_code);
        $this->assertSame('Học viên B thắc mắc điểm danh ngày 08/10.', $entry->title);
        $this->assertSame('normal', $entry->severity);
        $this->assertNull($entry->serious_confirmed_at);

        $this->actingAs($staff)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journals.data.0.kpi_code', '1.2')
                ->where('journals.data.0.serious', false)
                ->where('journals.data.0.user', 'Nguyễn Thị A')
                ->where('journals.data.0.user_role', 'Học vụ'));
    }

    public function test_only_admin_confirms_serious_error(): void
    {
        $staff = $this->makeUser('academic_staff');
        $manager = $this->makeUser('manager');
        $admin = $this->makeUser('admin', ['name' => 'Admin Liên']);
        $entry = StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => 'GV nghỉ gấp', 'content' => 'GV nghỉ gấp', 'severity' => 'normal', 'report_date' => today(), 'status' => 'open']);

        $this->actingAs($staff)->post(route('reports.journal.serious', $entry->id), ['serious' => 1])->assertForbidden();
        $this->actingAs($manager)->post(route('reports.journal.serious', $entry->id), ['serious' => 1])->assertForbidden();
        $this->assertNull($entry->fresh()->serious_confirmed_at);

        $this->actingAs($admin)->get(route('reports.journal'))->assertInertia(fn (AssertableInertia $page) => $page->where('canConfirmSerious', true));
        $this->actingAs($admin)->post(route('reports.journal.serious', $entry->id), ['serious' => 1])->assertRedirect();
        $this->assertSame($admin->id, $entry->fresh()->serious_confirmed_by);

        $this->actingAs($staff)->get(route('reports.journal'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journals.data.0.serious', true)
                ->where('journals.data.0.serious_by', 'Admin Liên'));

        $this->actingAs($admin)->post(route('reports.journal.serious', $entry->id), ['serious' => 0]);
        $this->assertNull($entry->fresh()->serious_confirmed_by);
        $this->assertNull($entry->fresh()->serious_confirmed_at);
    }
}
