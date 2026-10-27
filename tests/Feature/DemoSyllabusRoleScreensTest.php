<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\ReportPeriod;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Dữ liệu demo quy trình syllabus mở được trên màn của từng vai trò (docs/kho-syllabus.md §7–10). */
class DemoSyllabusRoleScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_opens_its_syllabus_screens_on_demo_data(): void
    {
        Http::fake();
        $this->seed(DatabaseSeeder::class);
        $user = fn (string $email) => User::where('email', $email)->firstOrFail();
        $class = fn (string $code) => ClassModel::where('code', $code)->value('id');
        $lead = $user('academiclead@menglish.edu.vn');
        $weekly = StaffReport::where('user_id', $lead->id)->where('kind', 'academic_weekly')->where('period_key', ReportPeriod::weekKey(now()))->value('id');
        $overdueTask = WorkTask::where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)->where('status', 'overdue')->value('id');

        $screens = [
            'academiclead@menglish.edu.vn' => [
                route('syllabus.curriculums.index'), route('syllabus.assignments'), route('syllabus.adjustment-requests'),
                route('classes.show', ['id' => $class('DEMO-BD-FAM1'), 'tab' => 'syllabus']), route('classes.show', ['id' => $class('DEMO-CG-FAM0'), 'tab' => 'syllabus']),
                route('tasks.index'), route('tasks.show', $overdueTask), route('reports.periodic.academic-weekly'), route('notifications.index'),
                route('syllabus.big-tests.distribution'),
            ],
            'admin@menglish.edu.vn' => [route('approvals.index'), route('syllabus.adjustment-requests'), route('reports.detail', $weekly), route('notifications.index')],
            'manager@menglish.edu.vn' => [route('reports.detail', $weekly), route('notifications.index'), route('classes.show', ['id' => $class('DEMO-CG-FAM1'), 'tab' => 'syllabus'])],
            'nva@menglish.edu.vn' => [
                route('syllabus.curriculums.index'), route('classes.show', ['id' => $class('DEMO-CG-FAM0'), 'tab' => 'syllabus']), route('material-orders.index'),
            ],
            'nguyenvanan@menglish.edu.vn' => [
                route('teacher.lesson-today', ['class' => $class('DEMO-CG-FAM0')]), route('teacher.lesson-today', ['class' => $class('DEMO-CG-FAM1')]),
                route('teacher.orders'), route('teacher.homework', $class('DEMO-CG-FAM1')), route('portal.teacher.submissions', ['classId' => $class('DEMO-CG-FAM1'), 'type' => 'basket']),
                route('syllabus.teacher-adjust'), route('syllabus.teacher-view'), route('notifications.index'),
            ],
            'gv.cohuu2@menglish.edu.vn' => [route('teacher.lesson-today', ['class' => $class('DEMO-BD-FAM0')]), route('teacher.orders'), route('notifications.index')],
            'ta.tuan@menglish.edu.vn' => [route('syllabus.teacher-view')],
            'hocvien1@menglish.edu.vn' => [route('portal.student.homework', Student::where('user_id', $user('hocvien1@menglish.edu.vn')->id)->value('id'))],
        ];

        foreach ($screens as $email => $urls) {
            foreach ($urls as $url) {
                $this->actingAs($user($email))->get($url)->assertOk();
            }
        }

        // Nội dung quy trình hiện đúng chỗ.
        $this->actingAs($lead)->get(route('reports.periodic.academic-weekly'))->assertSee('ưu tiên xử lý đầu tuần sau');
        $this->actingAs($user('nva@menglish.edu.vn'))->get(route('material-orders.index'))->assertSee('In ấn');
        $this->actingAs($user('gv.cohuu2@menglish.edu.vn'))->get(route('notifications.index'))->assertSee('XÁC NHẬN');
        $this->actingAs($lead)->get(route('notifications.index'))->assertSee('NHẮC LẠI');
    }
}
