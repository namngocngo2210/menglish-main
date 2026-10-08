<?php

namespace Tests\Feature;

use App\Models\AcademicObservation;
use App\Models\AdminNotification;
use App\Models\ClassChecklist;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\QaObservation;
use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\SupportTicket;
use App\Models\TeacherMeetingReport;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Kpi\KpiAutoCounter;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCoverageQualitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dữ liệu demo báo cáo & chất lượng: đủ trạng thái tốt / xấu của từng module, chạy lại không nhân bản. */
class DemoCoverageQualitySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_report_and_quality_case_and_is_idempotent(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoCoverageQualitySeeder::class);

        $user = fn (string $email) => User::where('email', $email)->firstOrFail();
        $lastMonth = now()->startOfMonth()->subMonthNoOverflow();
        $deadline = now()->startOfMonth()->setDay(KpiAutoCounter::MONTHLY_REPORT_DAY)->setTimeFromTimeString(KpiAutoCounter::MONTHLY_REPORT_DEADLINE);

        // Báo cáo ngày Học vụ: đúng hạn (trước 09:00 hôm sau) và 1 báo cáo trễ.
        $daily = StaffReport::where('user_id', $user('nva@menglish.edu.vn')->id)->where('type', 'daily')->get();
        $late = fn (StaffReport $r) => $r->created_at->gt($r->report_date->copy()->addDay()->setTime(9, 0));
        $this->assertSame(1, $daily->filter($late)->count());
        $this->assertGreaterThanOrEqual(4, $daily->reject($late)->count());
        $this->assertTrue(StaffReport::where('type', 'weekly')->whereNotNull('period_key')->exists(), 'Thiếu báo cáo tuần KPI.');
        // Báo cáo tuần Học thuật: 1 tuần nộp trễ (sau Chủ nhật của tuần đó).
        $weekly = StaffReport::where('user_id', $user('academiclead@menglish.edu.vn')->id)->where('type', 'weekly')->whereNull('period_key')->get();
        $this->assertCount(3, $weekly);
        $this->assertSame(1, $weekly->filter(fn (StaffReport $r) => $r->created_at->gt($r->report_date->copy()->endOfWeek()))->count());
        $this->assertTrue(StaffReport::where('type', 'monthly')->where('period_key', $lastMonth->format('Y-m'))->where('user_id', $user('academiclead@menglish.edu.vn')->id)->exists());
        $this->assertSame(1, StaffReport::where('type', 'quarterly')->count());

        // Báo cáo tháng GV: đúng hạn / trễ / không nộp (+ được nhắc).
        $monthly = fn (string $email) => StaffReport::where('user_id', $user($email)->id)->where('type', 'monthly')->where('period_key', $lastMonth->format('Y-m'))->first();
        $this->assertTrue($monthly('nguyenvanan@menglish.edu.vn')->created_at->lte($deadline));
        $this->assertTrue($monthly('gv.cohuu2@menglish.edu.vn')->created_at->gt($deadline));
        $this->assertNull($monthly('gv.cohuu1@menglish.edu.vn'));
        foreach (['gv.cohuu1@menglish.edu.vn', 'gv.cohuu2@menglish.edu.vn', 'ta.yen@menglish.edu.vn'] as $email) {
            $this->assertTrue(AdminNotification::where('user_id', $user($email)->id)->where('type', 'monthly_report_due')->where('data->month', $lastMonth->format('Y-m'))->exists(), "Thiếu nhắc {$email}.");
        }
        $this->assertFalse(AdminNotification::where('user_id', $user('nguyenvanan@menglish.edu.vn')->id)->where('type', 'monthly_report_due')->where('data->month', $lastMonth->format('Y-m'))->exists());

        // Nhật ký sự vụ: đủ mức độ / trạng thái, có follow-up, khẩn cấp còn mở.
        foreach (['normal', 'important', 'urgent'] as $severity) {
            $this->assertTrue(StaffReport::where('type', 'journal')->where('severity', $severity)->exists(), "Thiếu sự vụ {$severity}.");
        }
        foreach (['open', 'following', 'resolved'] as $status) {
            $this->assertTrue(StaffReport::where('type', 'journal')->where('status', $status)->exists(), "Thiếu sự vụ {$status}.");
        }
        $this->assertTrue(StaffReport::where('type', 'journal')->where('severity', 'urgent')->where('status', '!=', 'resolved')->exists());
        $this->assertGreaterThanOrEqual(5, StaffReportFollowup::count());

        // Dự giờ vận hành: đủ 4 xếp loại, trên buổi học thật của lớp và đúng GV của lớp.
        foreach (array_keys(QaObservation::RATINGS) as $rating) {
            $this->assertTrue(QaObservation::where('rating', $rating)->exists(), "Thiếu dự giờ {$rating}.");
        }
        foreach (QaObservation::with('classModel')->get() as $o) {
            $this->assertTrue(ClassSession::where('class_id', $o->class_id)->whereDate('date', $o->observed_on->toDateString())->exists());
            $this->assertContains($o->teacher_id, [$o->classModel->teacher_id, $o->classModel->foreign_teacher_id]);
        }

        // Dự giờ học thuật: 4 mức kết luận + lớp chưa dự giờ (chỉ tỉ lệ).
        foreach (['Xuất sắc', 'Tốt', 'Đạt', 'Cần cải thiện'] as $level) {
            $this->assertTrue(AcademicObservation::where('observed', true)->where('overall', 'like', "%{$level}%")->exists(), "Thiếu kết luận {$level}.");
        }
        $this->assertTrue(AcademicObservation::where('observed', false)->whereNotNull('attendance_rate')->whereNull('overall')->exists());

        // Checklist: Có / Không / N-A, có lớp thiếu sót.
        foreach (ClassChecklist::ANSWERS as $answer => $label) {
            $this->assertTrue(ClassChecklist::where('tuition_due', $answer)->orWhere('tuition_collected', $answer)->orWhere('feedback_on_time', $answer)->exists(), "Thiếu {$label}.");
        }
        $this->assertTrue(ClassChecklist::all()->contains(fn (ClassChecklist $c) => $c->gaps() !== []));
        $this->assertTrue(ClassChecklist::all()->contains(fn (ClassChecklist $c) => $c->gaps() === [] && $c->tuition_collected === ClassChecklist::YES));

        // Họp GV: đủ tình trạng.
        foreach (['handled', 'in_progress', 'sketchy', 'not_reported'] as $status) {
            $this->assertTrue(TeacherMeetingReport::where('status', $status)->exists(), "Thiếu họp GV {$status}.");
        }

        // Báo cáo trực lớp bị trả về.
        $this->assertTrue(ClassReport::where('status', ClassReport::STATUS_REJECTED)->whereNotNull('rejection_reason')->exists());

        // Ticket: đã đóng, mở lại, khẩn cấp quá 3 ngày chưa ai nhận.
        $this->assertTrue(SupportTicket::where('status', 'closed')->whereNotNull('resolved_at')->exists());
        $reopened = SupportTicket::where('title', '# Không mở được bài tập về nhà trên điện thoại')->firstOrFail();
        $this->assertSame('in_progress', $reopened->status);
        $this->assertTrue(TicketMessage::where('support_ticket_id', $reopened->id)->where('message', 'like', 'Đã mở lại ticket%')->exists());
        $this->assertTrue(SupportTicket::where('priority', 'urgent')->where('status', 'open')->whereNull('assignee_id')->where('created_at', '<=', now()->subDays(2))->exists());
        $this->assertSame(0, StaffReport::where('created_at', '>', now())->count() + SupportTicket::where('created_at', '>', now())->count());

        // Các màn chính hiển thị được.
        $admin = $user('admin@menglish.edu.vn');
        foreach (['reports.journal', 'reports.all', 'class-quality.operations', 'class-quality.academic', 'class-quality.checklist', 'class-quality.teacher-meetings', 'tickets.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
        $this->actingAs($user('nva@menglish.edu.vn'))->get(route('reports.my'))->assertOk();
        $this->actingAs($user('academiclead@menglish.edu.vn'))->get(route('reports.periodic.academic-monthly', ['month' => $lastMonth->format('Y-m')]))->assertOk();

        // Chạy lại: không nhân bản.
        $counts = fn () => [StaffReport::count(), StaffReportFollowup::count(), QaObservation::count(), AcademicObservation::count(), ClassChecklist::count(),
            TeacherMeetingReport::count(), ClassReport::count(), SupportTicket::count(), TicketMessage::count(), AdminNotification::where('type', 'monthly_report_due')->count()];
        $before = $counts();
        $this->seed(DemoCoverageQualitySeeder::class);
        $this->assertSame($before, $counts());
    }
}
