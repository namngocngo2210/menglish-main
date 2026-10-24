<?php

namespace Tests\Feature;

use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Services\Reports\AcademicPeriodMetrics;
use App\Services\Reports\DailyReportMetrics;
use App\Services\Reports\WeeklyReportMetrics;
use App\Support\ReportPeriod;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Báo cáo Học thuật (ngày / tuần / kỳ) ghi tên giáo trình của đề xuất sửa — giáo trình dùng cột `title`, không có `name`
 * (MariaDB báo lỗi cột không tồn tại; SQLite lặng lẽ trả chuỗi "name").
 */
class ReportProposalCurriculumTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_reports_show_proposal_curriculum_title(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole(Roles::ACADEMIC_LEAD);
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-RP', 'title' => 'Global Success 6', 'version' => 'v1']);
        SyllabusChangeProposal::create(['curriculum_id' => $curriculum->id, 'user_id' => $lead->id,
            'proposal_type' => 'Khác', 'new_content' => 'Mới', 'reason' => 'Sửa Unit 2', 'status' => 'pending']);

        $weekly = collect(WeeklyReportMetrics::academic($lead, ReportPeriod::weekKey(now()))['suggestions']['requests'])->pluck('content');
        $this->assertContains('Đề xuất sửa giáo trình · Global Success 6', $weekly->all());

        $daily = DailyReportMetrics::compute($lead, today()->toDateString(), DailyReportMetrics::AUDIENCE_ACADEMIC);
        $this->assertStringContainsString('Global Success 6 · Sửa Unit 2', json_encode($daily['sections']['syllabus'], JSON_UNESCAPED_UNICODE));

        $period = AcademicPeriodMetrics::compute($lead, now()->startOfMonth(), now()->endOfMonth(), 'Tháng trước');
        $this->assertStringContainsString('Global Success 6 · Sửa Unit 2', json_encode($period['sections']['syllabus'], JSON_UNESCAPED_UNICODE));
    }
}
