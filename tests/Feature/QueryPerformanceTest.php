<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Models\TeacherHourlyRate;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rà soát hiệu năng truy vấn: dữ liệu đọc lặp trong một request chỉ truy vấn 1 lần (và vẫn cập nhật khi dữ liệu đổi),
 * trang danh sách không tăng số truy vấn theo số dòng.
 */
class QueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở P', 'code' => 'CSP', 'is_active' => true]);
    }

    public function test_user_branch_ids_are_loaded_once(): void
    {
        $user = $this->userWithRole('manager');

        $queries = $this->countQueries(function () use ($user) {
            $user->branchIds();
            $user->branchIds();
            $user->branchIds();
        }, 'user_branches');

        $this->assertSame(1, $queries);
        $this->assertSame([$this->branch->id], $user->branchIds());
    }

    public function test_system_settings_are_read_with_one_query_and_refresh_after_save(): void
    {
        SystemSetting::set('perf_a', 'A');
        SystemSetting::set('perf_b', 'B');

        $queries = $this->countQueries(function () {
            $this->assertSame('A', SystemSetting::get('perf_a'));
            $this->assertSame('B', SystemSetting::get('perf_b'));
            $this->assertSame('mặc định', SystemSetting::get('perf_missing', 'mặc định'));
        }, 'system_settings');
        $this->assertSame(1, $queries);

        SystemSetting::set('perf_a', 'A2');
        $this->assertSame('A2', SystemSetting::get('perf_a'));
    }

    public function test_teacher_rate_versions_are_loaded_once_and_refresh_after_save(): void
    {
        $teacher = $this->userWithRole('teacher');
        TeacherHourlyRate::create(['user_id' => $teacher->id, 'hourly_rate' => 200000, 'effective_from' => '2026-08-01']);

        $queries = $this->countQueries(function () use ($teacher) {
            $this->assertNull(TeacherHourlyRate::rateFor($teacher->id, '2026-07-31'));
            $this->assertSame(200000.0, TeacherHourlyRate::rateFor($teacher->id, '2026-08-01'));
            $this->assertSame(200000.0, TeacherHourlyRate::rateFor($teacher->id, '2026-09-15'));
        }, 'teacher_hourly_rates');
        $this->assertSame(1, $queries);

        TeacherHourlyRate::create(['user_id' => $teacher->id, 'hourly_rate' => 250000, 'effective_from' => '2026-09-01']);
        $this->assertSame(200000.0, TeacherHourlyRate::rateFor($teacher->id, '2026-08-31'));
        $this->assertSame(250000.0, TeacherHourlyRate::rateFor($teacher->id, '2026-09-15'));
    }

    public function test_locked_payroll_periods_are_loaded_once_and_refresh_after_status_change(): void
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-08', 'title' => 'Bảng lương Tháng 8/2026', 'month' => 8, 'year' => 2026,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft',
        ]);

        $queries = $this->countQueries(function () {
            $this->assertFalse(PayrollPeriod::isLockedFor('2026-08-10'));
            $this->assertFalse(PayrollPeriod::isLockedFor('2026-08-20'));
        }, 'payroll_periods');
        $this->assertSame(1, $queries);

        $period->update(['status' => 'approved']);
        $this->assertTrue(PayrollPeriod::isLockedFor('2026-08-10'));
        $this->assertTrue(PayrollPeriod::isLockedFor('2026-08-31'));
        $this->assertFalse(PayrollPeriod::isLockedFor('2026-09-01'));
    }

    public function test_ticket_form_query_count_does_not_grow_with_staff(): void
    {
        $admin = $this->userWithRole('admin');
        foreach (range(1, 2) as $i) {
            $this->userWithRole('manager');
        }
        $this->actingAs($admin)->get(route('tickets.create'))->assertOk(); // làm nóng cache quyền / badge duyệt
        $few = $this->countQueries(fn () => $this->actingAs($admin)->get(route('tickets.create'))->assertOk());

        foreach (range(1, 10) as $i) {
            $this->userWithRole('manager');
        }
        $many = $this->countQueries(fn () => $this->actingAs($admin)->get(route('tickets.create'))->assertOk());

        $this->assertSame($few, $many);
    }

    public function test_branch_list_query_count_does_not_grow_with_branches(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->get(route('branches.index'))->assertOk(); // làm nóng cache quyền / badge duyệt
        $few = $this->countQueries(fn () => $this->actingAs($admin)->get(route('branches.index'))->assertOk());

        foreach (range(1, 5) as $i) {
            Branch::create(['name' => "Cơ sở thêm {$i}", 'code' => "CST{$i}", 'is_active' => true]);
        }
        $many = $this->countQueries(fn () => $this->actingAs($admin)->get(route('branches.index'))->assertOk());

        $this->assertSame($few, $many);
    }

    private function countQueries(callable $callback, ?string $table = null): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count, $table) {
            if ($table === null || str_contains($query->sql, "\"{$table}\"") || str_contains($query->sql, "`{$table}`")) {
                $count++;
            }
        });
        $callback();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
