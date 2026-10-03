<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Quản lý phòng học: CRUD phòng theo chi nhánh, danh mục loại phòng, tra cứu phòng trống, gắn phòng cho lớp / TKB. */
class RoomManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $academic;

    private RoomType $type;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG-ROOM', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'CN Hà Đông', 'code' => 'HD-ROOM', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->academic = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->academic->assignRole('academic_staff');
        $this->type = RoomType::create(['name' => 'Phòng tiêu chuẩn', 'is_active' => true]);
        $this->course = Course::create(['name' => 'IELTS ROOM', 'code' => 'IELTS-ROOM', 'total_lessons' => 24, 'is_active' => true]);
    }

    private function room(Branch $branch, string $name, ?int $capacity = 16): Room
    {
        return Room::create(['branch_id' => $branch->id, 'room_type_id' => $this->type->id, 'name' => $name, 'capacity' => $capacity]);
    }

    private function makeClass(string $code, array $attributes = []): ClassModel
    {
        return ClassModel::create($attributes + [
            'name' => "Lớp {$code}", 'code' => $code, 'course_id' => $this->course->id, 'program' => $this->course->name,
            'level' => 'B1', 'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 12,
            'start_date' => now()->subWeek()->toDateString(),
        ]);
    }

    private function withSlot(ClassModel $class, string $day, string $start = '18:00', string $end = '19:30'): ClassModel
    {
        ClassScheduleConfig::create(['class_id' => $class->id, 'slot1_day' => $day, 'slot1_start' => $start, 'slot1_end' => $end]);

        return $class;
    }

    private function weekdayName(CarbonInterface $date): string
    {
        return ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][$date->dayOfWeek];
    }

    public function test_admin_creates_room_with_unique_name_per_branch_and_valid_capacity(): void
    {
        $payload = ['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'name' => 'Phòng 202', 'capacity' => 15, 'description' => 'Có máy chiếu'];

        $this->actingAs($this->admin)->post(route('rooms.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('rooms', ['branch_id' => $this->branch->id, 'name' => 'Phòng 202', 'capacity' => 15]);

        $this->actingAs($this->admin)->post(route('rooms.store'), $payload)
            ->assertSessionHasErrors(['name' => 'Tên phòng đã tồn tại trong chi nhánh này']);
        $this->actingAs($this->admin)->post(route('rooms.store'), ['branch_id' => $this->otherBranch->id] + $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('rooms.store'), ['name' => 'Phòng 303', 'capacity' => 0] + $payload)
            ->assertSessionHasErrors(['capacity' => 'Sức chứa phải là số nguyên lớn hơn 0']);

        $inactive = RoomType::create(['name' => 'Phòng cũ', 'is_active' => false]);
        $this->actingAs($this->admin)->post(route('rooms.store'), ['name' => 'Phòng 404', 'room_type_id' => $inactive->id] + $payload)
            ->assertSessionHasErrors('room_type_id');

        $this->actingAs($this->admin)->get(route('rooms.index'))->assertOk()->assertSee('Phòng 202')->assertSee('CN Hà Đông');
    }

    public function test_academic_staff_is_limited_to_own_branch_and_blocked_without_branch(): void
    {
        $this->room($this->branch, 'Phòng mình');
        $other = $this->room($this->otherBranch, 'Phòng chi nhánh khác');

        $this->actingAs($this->academic)->get(route('rooms.index'))->assertOk()
            ->assertSee('Phòng mình')->assertDontSee('Phòng chi nhánh khác')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Rooms/Index')->where('lockedBranch.id', $this->branch->id));

        $this->actingAs($this->academic)->post(route('rooms.store'), [
            'branch_id' => $this->otherBranch->id, 'room_type_id' => $this->type->id, 'name' => 'Phòng lấn sân',
        ])->assertSessionHasErrors('branch_id');
        $this->actingAs($this->academic)->put(route('rooms.update', $other->id), ['name' => 'Đổi tên', 'room_type_id' => $this->type->id])->assertForbidden();
        $this->actingAs($this->academic)->delete(route('rooms.destroy', $other->id))->assertForbidden();

        $noBranch = User::factory()->create(['is_active' => true, 'branch_id' => null]);
        $noBranch->assignRole('academic_staff');
        $this->actingAs($noBranch)->get(route('rooms.index'))->assertOk()
            ->assertSee('Tài khoản chưa được gán chi nhánh, vui lòng liên hệ Admin')->assertDontSee('Phòng mình');
        $this->actingAs($noBranch)->get(route('rooms.create'))->assertForbidden();
    }

    public function test_renaming_room_updates_class_and_upcoming_sessions(): void
    {
        $room = $this->room($this->branch, 'P.101');
        $class = $this->makeClass('RM-REN', ['room_id' => $room->id, 'room' => 'P.101']);
        $past = ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => now()->subDays(2)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P.101', 'status' => 'completed']);
        $future = ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => now()->addDays(2)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P.101', 'status' => 'scheduled']);

        $this->actingAs($this->academic)->put(route('rooms.update', $room->id), [
            'name' => 'Phòng 101', 'room_type_id' => $this->type->id, 'capacity' => 20, 'branch_id' => $this->otherBranch->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->branch->id, $room->fresh()->branch_id, 'Sửa phòng không đổi được chi nhánh.');
        $this->assertSame('Phòng 101', $class->fresh()->room);
        $this->assertSame('Phòng 101', $future->fresh()->room);
        $this->assertSame('P.101', $past->fresh()->room);
    }

    public function test_delete_room_blocked_while_studying_and_detaches_pending_classes(): void
    {
        $busy = $this->room($this->branch, 'Phòng bận');
        $this->makeClass('RM-STUDY', ['room_id' => $busy->id, 'room' => 'Phòng bận']);
        $this->actingAs($this->admin)->delete(route('rooms.destroy', $busy->id))->assertSessionHas('error');
        $this->assertNotSoftDeleted($busy);

        $pendingRoom = $this->room($this->branch, 'Phòng dự kiến');
        $pending = $this->makeClass('RM-PEND', ['room_id' => $pendingRoom->id, 'room' => 'Phòng dự kiến', 'status' => 'upcoming', 'start_date' => now()->addWeek()->toDateString()]);
        $this->actingAs($this->admin)->delete(route('rooms.destroy', $pendingRoom->id))->assertSessionHas('success');
        $this->assertSoftDeleted($pendingRoom);
        $this->assertNull($pending->fresh()->room_id);
        $this->assertNull($pending->fresh()->room);

        $free = $this->room($this->branch, 'Phòng trống');
        $this->actingAs($this->academic)->delete(route('rooms.destroy', $free->id))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('rooms.destroy', $free->id))->assertSessionHas('success');
        $this->assertSoftDeleted($free);
    }

    public function test_room_types_toggle_and_cannot_delete_type_in_use(): void
    {
        $this->actingAs($this->admin)->post(route('rooms.types.store'), ['name' => 'Phòng Lab'])->assertSessionHasNoErrors();
        $lab = RoomType::where('name', 'Phòng Lab')->firstOrFail();
        $this->actingAs($this->admin)->post(route('rooms.types.store'), ['name' => 'Phòng Lab'])->assertSessionHasErrors('name');

        $this->room($this->branch, 'Phòng 101');
        $this->actingAs($this->admin)->delete(route('rooms.types.destroy', $this->type->id))
            ->assertSessionHas('error', 'Loại phòng đang được sử dụng — chỉ có thể Ngừng dùng');
        $this->assertNotSoftDeleted($this->type);

        $this->actingAs($this->admin)->post(route('rooms.types.toggle', $this->type->id))->assertSessionHas('success');
        $this->assertFalse($this->type->fresh()->is_active);

        $this->actingAs($this->admin)->delete(route('rooms.types.destroy', $lab->id))->assertSessionHas('success');
        $this->assertSoftDeleted($lab);

        $this->actingAs($this->academic)->post(route('rooms.types.store'), ['name' => 'Không được'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('rooms.types.index'))->assertOk()->assertSee('Ngừng dùng');
    }

    public function test_availability_lookup_excludes_rooms_with_overlapping_sessions(): void
    {
        $date = now()->addDays(3);
        $busy = $this->room($this->branch, 'Phòng 101');
        $this->room($this->branch, 'Phòng 102');
        $class = $this->makeClass('RM-AV', ['room_id' => $busy->id, 'room' => 'Phòng 101']);
        ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => $date->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'Phòng 101', 'status' => 'scheduled']);

        $this->actingAs($this->academic)->get(route('rooms.availability', ['date' => $date->toDateString(), 'start' => '19:00', 'end' => '20:00']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Rooms/Availability')
                ->where('result.total', 2)
                ->has('result.rooms', 1)
                ->where('result.rooms.0.name', 'Phòng 102'));

        $this->actingAs($this->academic)->get(route('rooms.availability', ['date' => $date->toDateString(), 'start' => '19:30', 'end' => '21:00']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('result.rooms', 2));

        $this->actingAs($this->academic)->get(route('rooms.availability', ['date' => $date->toDateString(), 'start' => '20:00', 'end' => '19:00']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filterErrors.end', 'Giờ kết thúc phải sau giờ bắt đầu')->where('result', null));
    }

    public function test_class_create_takes_room_from_selected_branch_only(): void
    {
        $own = $this->room($this->branch, 'Phòng 101');
        $foreign = $this->room($this->otherBranch, 'Phòng HĐ');
        $payload = [
            'ten_lop' => 'IELTS K40', 'ma_lop' => 'RM-K40', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => $this->course->name, 'cap_do' => 'B1', 'si_so_toi_da' => 12,
        ];

        $this->actingAs($this->admin)->get(route('classes.create'))->assertOk()->assertSee('phòng học dự kiến')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.name', 'Phòng 101')->where('rooms.0.branch_id', $this->branch->id));

        $this->actingAs($this->admin)->post(route('classes.store'), $payload + ['room_id' => $foreign->id])
            ->assertSessionHasErrors(['room_id' => 'Phòng học không thuộc chi nhánh đã chọn, vui lòng chọn lại.']);
        $this->actingAs($this->admin)->post(route('classes.store'), ['ten_lop' => ''] + $payload)
            ->assertSessionHasErrors(['ten_lop' => 'Chưa nhập Tên lớp.']);

        $this->actingAs($this->admin)->post(route('classes.store'), $payload + ['room_id' => $own->id])->assertSessionHasNoErrors();
        $class = ClassModel::where('code', 'RM-K40')->sole();
        $this->assertSame($own->id, $class->room_id);
        $this->assertSame('Phòng 101', $class->room);
    }

    public function test_schedule_config_blocks_studying_conflict_and_asks_to_transfer_pending_class(): void
    {
        $day = now()->addDays(2);
        $dayName = $this->weekdayName($day);
        $room = $this->room($this->branch, 'Phòng 101');
        $class = $this->makeClass('RM-TKB', ['status' => 'pending_schedule', 'start_date' => null]);
        $payload = [
            'class_id' => $class->id, 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(13)->toDateString(),
            'slot1_day' => $dayName, 'slot1_start' => '18:00', 'slot1_end' => '19:30', 'slot2_day' => '', 'room_id' => $room->id,
        ];

        $studying = $this->withSlot($this->makeClass('RM-STUDY', ['room_id' => $room->id, 'room' => 'Phòng 101']), $dayName, '19:00', '20:30');
        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), $payload)->assertSessionHasErrors('room_id');
        $this->assertNull($class->fresh()->room_id);

        $studying->update(['status' => 'upcoming', 'start_date' => now()->addMonth()->toDateString()]);
        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), $payload)->assertSessionHasErrors('room_transfer');
        $this->assertSame($room->id, $studying->fresh()->room_id);

        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), $payload + ['transfer_room' => 1])->assertSessionHasNoErrors();
        $this->assertNull($studying->fresh()->room_id);
        $this->assertSame($room->id, $class->fresh()->room_id);
        $this->assertSame('Phòng 101', $class->fresh()->room);
        $this->assertSame(['Phòng 101'], $class->sessions()->pluck('room')->unique()->values()->all());
    }

    public function test_ending_class_releases_room(): void
    {
        $room = $this->room($this->branch, 'Phòng 101');
        $class = $this->makeClass('RM-END', ['room_id' => $room->id, 'room' => 'Phòng 101']);

        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), ['toggle_class_id' => $class->id])
            ->assertSessionHas('success', "Đã kết thúc lớp {$class->name}. Phòng 101 đã được giải phóng khỏi lớp này.");
        $this->assertNull($class->fresh()->room_id);

        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), ['toggle_class_id' => $class->id])
            ->assertSessionHas('warning');
        $this->assertSame('active', $class->fresh()->status);
    }

    public function test_migration_links_legacy_room_codes_to_branch_rooms(): void
    {
        $class = $this->makeClass('RM-LEG', ['room' => 'P101']);
        $other = $this->makeClass('RM-LEG2', ['room' => 'Phòng tầng 3', 'branch_id' => $this->otherBranch->id]);
        $session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P101', 'status' => 'scheduled']);

        $migration = require database_path('migrations/2026_10_21_090000_create_rooms_tables.php');
        (fn () => $this->linkExistingRooms())->call($migration);

        $room = Room::where('branch_id', $this->branch->id)->where('name', 'Phòng 101')->sole();
        $this->assertSame(20, $room->capacity);
        $this->assertSame($room->id, $class->fresh()->room_id);
        $this->assertSame('Phòng 101', $class->fresh()->room);
        $this->assertSame('Phòng 101', $session->fresh()->room);
        $this->assertSame('Phòng tầng 3', Room::find($other->fresh()->room_id)?->name);
    }
}
