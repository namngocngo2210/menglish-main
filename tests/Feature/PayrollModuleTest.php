<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_can_create_and_approve_payroll_period(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/payroll/periods', [
            'month' => 9,
            'year' => 2026,
        ]);

        $period = PayrollPeriod::where('month', 9)->where('year', 2026)->first();
        $this->assertNotNull($period);
        $response->assertRedirect(route('payroll.periods.show', $period->id));

        $approveResponse = $this->actingAs($user)->post("/payroll/periods/{$period->id}/approve");
        $period->refresh();
        $this->assertEquals('approved', $period->status);
    }

    public function test_can_record_manual_timesheet(): void
    {
        $user = User::factory()->create();
        $user->assignRole('manager');
        $class = ClassModel::create(['code' => 'CL-01', 'name' => 'Lớp Speaking B2']);

        $response = $this->actingAs($user)->post('/payroll/timesheets/manual', [
            'user_id' => $user->id,
            'class_id' => $class->id,
            'teaching_date' => '2026-08-20',
            'time_in' => '18:00',
            'time_out' => '20:30',
            'hourly_rate' => 250000,
            'type' => 'regular',
            'notes' => 'Giảng dạy buổi 1',
        ]);

        $response->assertRedirect(route('payroll.timesheets.teachers'));
        $this->assertDatabaseHas('teacher_timesheets', [
            'user_id' => $user->id,
            'class_id' => $class->id,
            'hours' => 2.5,
            'hourly_rate' => 250000,
        ]);
    }
}
