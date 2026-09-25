<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CandidateCv;
use App\Models\JobPosting;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecruitmentAndDashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'teacher']);
        Role::create(['name' => 'assistant']);
    }

    public function test_can_access_academic_reports_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get(route('academic.dashboards.reports'));
        $response->assertStatus(200);
        $response->assertSee('Dashboard Báo cáo Đào tạo');
    }

    public function test_can_access_academic_incidents_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get(route('academic.dashboards.incidents'));
        $response->assertStatus(200);
        $response->assertSee('Dashboard Nhật ký Sự vụ');
    }

    public function test_can_access_recruitment_index_and_create_job(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get(route('recruitment.index'));
        $response->assertStatus(200);
        $response->assertSee('Quản lý Tuyển dụng');

        $storeResponse = $this->actingAs($user)->post(route('recruitment.jobs.store'), [
            'title' => 'Giáo viên IELTS 7.5+',
            'department' => 'Học thuật & Đào tạo',
            'employment_type' => 'Full-time',
            'salary_range' => '15 - 22 triệu',
            'description' => 'Giảng dạy các lớp IELTS cấp tốc',
        ]);

        $storeResponse->assertRedirect(route('recruitment.index', ['tab' => 'jobs']));
        $this->assertDatabaseHas('job_postings', [
            'title' => 'Giáo viên IELTS 7.5+',
            'employment_type' => 'Full-time',
        ]);
    }

    public function test_public_candidate_can_submit_cv_via_portal(): void
    {
        Storage::fake('public');

        $job = JobPosting::create([
            'title' => 'Trợ giảng Tiếng Anh',
            'department' => 'Học vụ & Vận hành',
            'employment_type' => 'Part-time',
            'description' => 'Hỗ trợ lớp học',
            'is_active' => true,
        ]);

        $portalResponse = $this->get(route('portal.recruitment'));
        $portalResponse->assertStatus(200);
        $portalResponse->assertSee('Trợ giảng Tiếng Anh');

        $cvFile = UploadedFile::fake()->create('cv.pdf', 500, 'application/pdf');

        $submitResponse = $this->post(route('portal.recruitment.submit'), [
            'job_posting_id' => $job->id,
            'full_name' => 'Nguyễn Thị Thu',
            'email' => 'thu.nguyen@example.com',
            'phone' => '0912345678',
            'applying_position' => 'Trợ giảng Tiếng Anh',
            'cv_file' => $cvFile,
            'cover_letter' => 'Em có IELTS 6.5 và mong muốn làm trợ giảng',
        ]);

        $submitResponse->assertSessionHas('success');
        $this->assertDatabaseHas('candidate_cvs', [
            'full_name' => 'Nguyễn Thị Thu',
            'email' => 'thu.nguyen@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_can_update_payroll_record_foreign_teacher_deduction(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09',
            'title' => 'Bảng lương Tháng 9/2026',
            'month' => 9,
            'year' => 2026,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'draft',
        ]);

        $record = PayrollRecord::create([
            'payroll_period_id' => $period->id,
            'user_id' => $user->id,
            'base_salary' => 10000000,
            'teaching_salary' => 5000000,
            'net_salary' => 15000000,
        ]);

        $response = $this->actingAs($user)->post(route('payroll.records.update', $record->id), [
            'foreign_teacher_sessions_count' => 3,
            'foreign_teacher_deduction_rate' => 50000,
            'notes' => '3 buổi có GVNN cùng dạy',
        ]);

        $response->assertSessionHas('status');
        $record->refresh();

        $this->assertEquals(3, $record->foreign_teacher_sessions_count);
        $this->assertEquals(150000, $record->foreign_teacher_deduction);
        $this->assertEquals(14850000, $record->net_salary);
    }
}
