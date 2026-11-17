<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CourseLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** TK-2026-0129 — TD R-04: trình độ chưa gắn Giáo trình không được chọn làm Cấp độ của lớp. */
class ClassLevelRequiresSyllabusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->branch = Branch::create(['name' => 'Chi nhánh Test', 'code' => 'TST']);
        $this->makeLevelWithSyllabus('WITH-SYL');
        CourseLevel::create(['code' => 'NO-SYL', 'name' => 'Không giáo trình', 'target' => 'IELTS', 'is_active' => true]);
    }

    private function payload(string $level, array $extra = []): array
    {
        return $extra + [
            'ten_lop' => 'Lớp test', 'ma_lop' => 'LT-1', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => 'IELTS', 'cap_do' => $level, 'si_so_toi_da' => 10,
            'room_id' => $this->makeRoom($this->branch->id)->id,
        ];
    }

    public function test_create_form_lists_only_levels_with_syllabus(): void
    {
        $this->actingAs($this->admin)->get(route('classes.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('levels', fn ($levels) => collect($levels)->pluck('value')->all() === ['WITH-SYL']));
    }

    public function test_store_rejects_level_without_syllabus_and_accepts_with_syllabus(): void
    {
        $this->actingAs($this->admin)->post(route('classes.store'), $this->payload('NO-SYL'))
            ->assertSessionHasErrors('cap_do');
        $this->assertSame(0, ClassModel::where('code', 'LT-1')->count());

        $this->actingAs($this->admin)->post(route('classes.store'), $this->payload('WITH-SYL'))
            ->assertSessionHasNoErrors();
        $this->assertSame('WITH-SYL', ClassModel::where('code', 'LT-1')->value('level'));
    }

    public function test_update_rejects_switching_to_level_without_syllabus_but_keeps_existing_legacy_value(): void
    {
        $class = ClassModel::create(['name' => 'Lớp cũ', 'code' => 'OLD-1', 'branch_id' => $this->branch->id, 'program' => 'IELTS',
            'level' => 'NO-SYL', 'max_capacity' => 10, 'status' => 'active']);
        $payload = fn (string $level) => $this->payload($level, ['ten_lop' => 'Lớp cũ', 'ma_lop' => 'OLD-1']);

        // Giữ nguyên cấp độ cũ (chưa có giáo trình) → vẫn lưu được, form edit vẫn hiển thị giá trị đó.
        $this->actingAs($this->admin)->put(route('classes.update', $class->id), $payload('NO-SYL'))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('classes.edit', $class->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('levels', fn ($levels) => collect($levels)->pluck('value')->all() === ['NO-SYL', 'WITH-SYL']));

        // Lớp có cấp độ hợp lệ không được đổi sang cấp độ chưa có giáo trình.
        $class->update(['level' => 'WITH-SYL']);
        $this->actingAs($this->admin)->put(route('classes.update', $class->id), $payload('NO-SYL'))->assertSessionHasErrors('cap_do');
        $this->assertSame('WITH-SYL', $class->fresh()->level);
    }
}
