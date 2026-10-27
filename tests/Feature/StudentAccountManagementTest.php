<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentAccountService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Màn "Tài khoản học viên" (docs/tai-khoan-hoc-vien.md): Học vụ tạo tài khoản / cấp lại mật khẩu tạm theo lô cho học viên
 * đang học trong phạm vi, tải file đăng nhập một lần.
 */
class StudentAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $academic;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $this->branch = Branch::create(['name' => 'CS A', 'code' => 'SA-A', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'CS B', 'code' => 'SA-B', 'is_active' => true]);
        $this->academic = $this->user('academic_staff', $this->branch);
        $course = Course::create(['name' => 'Starters SA', 'code' => 'SA-C', 'total_lessons' => 24, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp SA1', 'code' => 'SA1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'status' => 'active', 'max_capacity' => 12, 'start_date' => '2026-09-01',
        ]);
    }

    private function user(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function student(string $code, ?Branch $branch = null, string $status = 'studying'): Student
    {
        $student = Student::create([
            'code' => $code, 'name' => 'HV '.$code, 'phone' => '0911000'.random_int(100, 999),
            'branch_id' => ($branch ?? $this->branch)->id, 'status' => $status, 'current_class_id' => $this->class->id,
        ]);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);

        return $student;
    }

    public function test_list_is_scoped_to_branch_and_filters_by_account_state(): void
    {
        $withoutAccount = $this->student('SA0001');
        $withAccount = $this->student('SA0002');
        app(StudentAccountService::class)->create($withAccount);
        $this->student('SA0003', $this->otherBranch);
        $this->student('SA0004', status: 'dropped');

        $codes = fn (array $query = []) => fn (AssertableInertia $page) => $page->component('Students/Accounts')
            ->where('students.data', fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all() === $query['expect']);

        $this->actingAs($this->academic)->get(route('students.accounts.index'))->assertOk()
            ->assertInertia($codes(['expect' => ['SA0001', 'SA0002']]))
            // Tên đăng nhập hiển thị như bước chốt CRM (User::loginIdentifier — email tự sinh); mã học viên cũng đăng nhập được.
            ->assertInertia(fn (AssertableInertia $page) => $page->where('students.data', fn ($rows) => collect($rows)->firstWhere('code', 'SA0002')['login'] === $withAccount->fresh()->user->loginIdentifier()
                && collect($rows)->firstWhere('code', 'SA0001')['login'] === null));
        $this->actingAs($this->academic)->get(route('students.accounts.index', ['account' => 'none']))
            ->assertInertia($codes(['expect' => ['SA0001']]));
        $this->actingAs($this->academic)->get(route('students.accounts.index', ['status' => 'dropped']))
            ->assertInertia($codes(['expect' => ['SA0004']]));
        $this->assertNull($withoutAccount->fresh()->user_id);
    }

    public function test_batch_create_reports_partial_results_and_file_downloads_once(): void
    {
        Excel::fake();
        $fresh = $this->student('SA0011');
        $linked = $this->student('SA0012');
        app(StudentAccountService::class)->create($linked);

        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'create', 'student_ids' => [$fresh->id, $linked->id]])
            ->assertRedirect(route('students.accounts.index'))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Đã tạo tài khoản cho 1 học viên') && str_contains($m, 'Bỏ qua: SA0012: đã có tài khoản.'));
        $account = $fresh->fresh()->user;
        $this->assertSame('SA0011', $account->employee_code);

        $this->actingAs($this->academic)->get(route('students.accounts.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('download.count', 1));

        $this->actingAs($this->academic)->get(route('students.accounts.download'))->assertOk();
        Excel::assertDownloaded('tai-khoan-hoc-vien-20261023-100000.xlsx', function (ArrayExport $export) use ($account) {
            [$row] = $export->array();
            $this->assertSame(['Mã học viên', 'Họ tên', 'Lớp', 'Tên đăng nhập', 'Mật khẩu tạm'], $export->headings());
            $this->assertSame(['SA0011', 'HV SA0011', 'Lớp SA1', $account->loginIdentifier()], array_slice($row, 0, 4));

            return Hash::check($row[4], $account->password);
        });
        $this->assertTrue((bool) ClassEnrollment::where('student_id', $fresh->id)->value('account_sent'));

        // Tải lần hai: mật khẩu tạm đã bị xoá khỏi session.
        $this->actingAs($this->academic)->get(route('students.accounts.download'))
            ->assertRedirect(route('students.accounts.index'))->assertSessionHasErrors('download');
    }

    public function test_create_without_selection_covers_all_filtered_students_across_pages(): void
    {
        $a = $this->student('SA0021');
        $b = $this->student('SA0022');
        $linked = $this->student('SA0023');
        app(StudentAccountService::class)->create($linked);
        $outside = $this->student('SA0024', $this->otherBranch);
        $dropped = $this->student('SA0025', status: 'dropped');

        $this->actingAs($this->academic)->get(route('students.accounts.index', ['per_page' => 10, 'page' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('creatableTotal', 2)->where('students.total', 3));

        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'create', 'scope' => 'all', 'status' => 'studying'])
            ->assertRedirect(route('students.accounts.index', ['status' => 'studying']))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Đã tạo tài khoản cho 2 học viên'));
        $this->assertNotNull($a->fresh()->user_id);
        $this->assertNotNull($b->fresh()->user_id);
        $this->assertNull($outside->fresh()->user_id);
        $this->assertNull($dropped->fresh()->user_id);

        // Bộ lọc tìm kiếm được tôn trọng; "tất cả" chỉ áp dụng cho tạo tài khoản.
        $c = $this->student('SA0026');
        $d = $this->student('SA0027');
        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'create', 'scope' => 'all', 'search' => 'SA0026']);
        $this->assertNotNull($c->fresh()->user_id);
        $this->assertNull($d->fresh()->user_id);
        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'reset', 'scope' => 'all'])->assertStatus(422);
        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'create'])->assertSessionHasErrors('student_ids');
    }

    public function test_batch_reset_issues_new_temporary_passwords(): void
    {
        Excel::fake();
        $student = $this->student('SA0021');
        app(StudentAccountService::class)->create($student);
        $oldHash = $student->fresh()->user->password;

        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'reset', 'student_ids' => [$student->id]])
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Đã cấp lại mật khẩu tạm cho 1 học viên'));
        $this->assertNotSame($oldHash, $student->fresh()->user->password);
        $this->assertTrue((bool) $student->fresh()->user->must_change_password);
    }

    public function test_requires_permission_and_hides_students_outside_scope(): void
    {
        $sales = $this->user('sales_consultant', $this->branch);
        $this->actingAs($sales)->get(route('students.accounts.index'))->assertForbidden();
        $this->actingAs($sales)->post(route('students.accounts.store'), ['action' => 'create', 'student_ids' => [1]])->assertForbidden();

        $outside = $this->student('SA0031', $this->otherBranch);
        $this->actingAs($this->academic)->post(route('students.accounts.store'), ['action' => 'create', 'student_ids' => [$outside->id]])->assertNotFound();
        $this->assertNull($outside->fresh()->user_id);
    }
}
