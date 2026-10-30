<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Sla\Sla;
use App\Services\Sla\SlaBreachService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** TK-0019: GVNN quá hạn SLA vẫn báo và đánh dấu sổ / việc, nhưng không tự lập biên bản. */
class ForeignTeacherSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_gvnn_qua_han_sla_co_bat_phat_chi_nhan_thong_bao_mot_lan_va_van_dong_duoc_moc(): void
    {
        $now = Carbon::parse('2026-10-20 12:00:00');
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole(Roles::FOREIGN_TEACHER);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $service = app(SlaBreachService::class);

        // Bảo vệ chung cho cả SLA giáo viên và SLA nghiệp vụ có tự giao việc.
        foreach (['gv.remarks_late', 'care.first_month'] as $index => $rule) {
            $this->assertTrue(Sla::penalizes($rule));
            $event = $service->open($rule, 'gvnn_test', $index + 1, $teacher, $now->copy()->subDays(2), $now->copy()->subDay(), [
                'title' => 'Theo dõi SLA GVNN', 'description' => 'Xử lý công việc đúng hạn',
            ]);
            AdminNotification::query()->delete();

            $this->assertNull($service->breach($event, 'Buổi học GVNN', ['Lớp' => 'Lớp GVNN'], '/classes', $now));
            $event->refresh();
            $this->assertTrue($event->breached_at->equalTo($now));
            $this->assertNull($event->penalty_id);
            $this->assertSame(0, Penalty::withoutViewerScope()->count());
            foreach ([$teacher, $admin] as $recipient) {
                $notice = AdminNotification::where('user_id', $recipient->id)->sole();
                $this->assertSame('sla_breach', $notice->type);
                $this->assertSame('/classes', $notice->data['link']);
                $this->assertSame($rule, $notice->data['sla_rule']);
            }
            if ($event->work_task_id) {
                $task = WorkTask::findOrFail($event->work_task_id);
                $this->assertSame('overdue', $task->status);
                $this->assertTrue($task->sla_breached_at->equalTo($now));
            }

            // Tải lại từ sổ để mô phỏng lần chạy scheduler kế tiếp.
            $this->assertNull($service->breach(SlaEvent::findOrFail($event->id), 'Buổi học GVNN', [], '/classes', $now));
            $this->assertSame(2, AdminNotification::count());
            $service->resolve($event, $now->copy()->addHour());
            $this->assertNotNull($event->fresh()->resolved_at);
            if ($event->work_task_id) {
                $this->assertSame('completed', WorkTask::findOrFail($event->work_task_id)->status);
            }
        }
    }

    public function test_gvnn_kiem_vai_tro_khac_van_khong_bi_tu_dong_lap_bien_ban_sla(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole([Roles::FOREIGN_TEACHER, Roles::ACADEMIC_STAFF]);
        $service = app(SlaBreachService::class);
        $event = $service->open('crm.first_contact', 'gvnn_test', 1, $owner, now()->subDays(2), now()->subDay());

        $this->assertNull($service->breach($event, 'Khách cần liên hệ', [], '/crm'));

        $this->assertNotNull($event->fresh()->breached_at);
        $this->assertSame(0, Penalty::withoutViewerScope()->count());
        $this->assertSame('sla_breach', AdminNotification::where('user_id', $owner->id)->sole()->type);
    }
}
