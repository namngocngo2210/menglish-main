<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayRescheduleService;
use App\Services\ScheduleExtensionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rà soát đợt 7 (Lịch): giãn tiến độ không chồng buổi bù và giữ GVNN, nghỉ lễ hủy lịch học thử,
 * xếp lại TKB không xóa buổi có hẹn học thử, xóa lớp không bị chặn bởi buổi quá khứ.
 */
class ReviewRoundScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở T', 'code' => 'CST', 'is_active' => true]);
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->manager->assignRole('manager');
        $teacher = User::factory()->create(['is_active' => true]);
        $foreign = User::factory()->create(['is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'T-1', 'name' => 'Lớp T', 'branch_id' => $this->branch->id, 'status' => 'active',
            'teacher_id' => $teacher->id, 'foreign_teacher_id' => $foreign->id,
        ]);
    }

    public function test_extension_goes_after_makeup_and_keeps_foreign_teacher(): void
    {
        $base = today()->addWeek()->startOfWeek();
        $this->makeSession($base, ClassSession::TYPE_REGULAR);
        $makeup = $this->makeSession($base->copy()->addWeek(), ClassSession::TYPE_MAKEUP);

        $created = app(ScheduleExtensionService::class)->extend($this->class, 1, 'Giãn');

        $this->assertTrue($created[0]->date->gt($makeup->date));
        $this->assertSame($this->class->foreign_teacher_id, $created[0]->foreign_teacher_id);
    }

    public function test_holiday_cancels_trial_booking_and_replace_keeps_trial_session(): void
    {
        $day = today()->addDays(3);
        $session = $this->makeSession($day, ClassSession::TYPE_REGULAR);
        $booking = $this->booking($session);

        $this->assertFalse(ClassSession::whereKey($session->id)->replaceable()->exists());

        $holiday = Holiday::create(['code' => 'H-T', 'name' => 'Nghỉ T', 'start_date' => $day->toDateString(), 'end_date' => $day->toDateString(), 'is_system_wide' => true]);
        app(HolidayRescheduleService::class)->apply($holiday);

        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_class_with_only_stale_past_sessions_can_be_deleted(): void
    {
        $this->makeSession(today()->subDays(10), ClassSession::TYPE_REGULAR);

        $this->actingAs($this->manager)->delete(route('classes.destroy', $this->class->id))->assertRedirect(route('classes.index'));
        $this->assertSoftDeleted('classes', ['id' => $this->class->id]);
    }

    private function makeSession($date, string $type): ClassSession
    {
        return ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date->toDateString(),
            'type' => $type, 'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $this->class->teacher_id, 'status' => 'scheduled',
        ]);
    }

    private function booking(ClassSession $session): CrmTrialBooking
    {
        $lead = CrmCustomer::create([
            'code' => CrmCustomer::generateCode(), 'name' => 'Khách thử', 'phone' => '0912345678', 'phone_normalized' => '0912345678',
            'branch_id' => $this->branch->id, 'source' => 'Facebook', 'stage' => 'trial',
        ]);

        return CrmTrialBooking::create([
            'customer_id' => $lead->id, 'class_id' => $this->class->id, 'class_session_id' => $session->id, 'status' => 'scheduled',
        ]);
    }
}
