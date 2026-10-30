<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Một nhân sự làm ở nhiều cơ sở: cơ sở chính (users.branch_id) + cơ sở làm việc thêm (user_branches), gán ở form
 * Thêm / Sửa nhân sự. Quản lý cơ sở thấy và quản lý được nhân sự làm thêm ở cơ sở mình.
 */
class StaffMultiBranchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $a;

    private Branch $b;

    private Branch $c;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->a = Branch::create(['name' => 'CN A', 'code' => 'MB-A', 'is_active' => true]);
        $this->b = Branch::create(['name' => 'CN B', 'code' => 'MB-B', 'is_active' => true]);
        $this->c = Branch::create(['name' => 'CN C', 'code' => 'MB-C', 'is_active' => true]);
        $this->admin = $this->user('admin', $this->a);
    }

    private function user(string $role, ?Branch $branch): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch?->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function form(User $user, array $extra, bool $present = true): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'role' => $user->getRoleNames()->first(),
        ] + ($present ? ['extra_branch_ids_present' => 1, 'extra_branch_ids' => $extra] : []);
    }

    /** @return list<int> */
    private function extraIds(User $user): array
    {
        return $user->branches()->orderBy('branches.id')->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_admin_creates_staff_with_extra_branches_and_primary_is_not_duplicated(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'GV Hai Cơ Sở',
            'email' => 'gv.multi@menglish.edu.vn',
            'branch_id' => $this->a->id,
            'role' => 'teacher',
            'password' => 'Password123!',
            'extra_branch_ids_present' => 1,
            'extra_branch_ids' => [$this->a->id, $this->b->id, $this->c->id],
        ])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $user = User::where('email', 'gv.multi@menglish.edu.vn')->sole();
        $this->assertSame($this->a->id, (int) $user->branch_id);
        $this->assertSame([$this->b->id, $this->c->id], $this->extraIds($user));
        $this->assertSame([$this->a->id, $this->b->id, $this->c->id], collect($user->branchIds())->sort()->values()->all());
    }

    public function test_update_syncs_extra_branches_only_when_form_sends_them(): void
    {
        $teacher = $this->user('teacher', $this->a);
        $teacher->branches()->sync([$this->b->id]);

        $this->actingAs($this->admin)->get(route('users.edit', $teacher))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('user.extra_branch_ids', [$this->b->id]));

        // Request không có trường cơ sở làm thêm (vd. client cũ) → giữ nguyên.
        $this->actingAs($this->admin)->put(route('users.update', $teacher), $this->form($teacher, [], false))->assertSessionHasNoErrors();
        $this->assertSame([$this->b->id], $this->extraIds($teacher));

        $this->actingAs($this->admin)->put(route('users.update', $teacher), $this->form($teacher, [$this->c->id]))->assertSessionHasNoErrors();
        $this->assertSame([$this->c->id], $this->extraIds($teacher));

        // Bỏ chọn hết → xoá cơ sở làm thêm.
        $this->actingAs($this->admin)->put(route('users.update', $teacher), $this->form($teacher, []))->assertSessionHasNoErrors();
        $this->assertSame([], $this->extraIds($teacher));
    }

    public function test_index_lists_extra_branches_and_branch_filter_includes_them(): void
    {
        $teacher = $this->user('teacher', $this->a);
        $teacher->branches()->sync([$this->b->id]);
        $onlyA = $this->user('teacher', $this->a);

        $this->actingAs($this->admin)->get(route('users.index', ['branch_id' => $this->b->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1)
                ->where('users.data.0.id', $teacher->id)
                ->where('users.data.0.branch_name', 'CN A')
                ->where('users.data.0.extra_branches', ['CN B']));

        $this->actingAs($this->admin)->get(route('users.index', ['branch_id' => $this->a->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('users.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$this->admin->id, $teacher->id, $onlyA->id])->sort()->values()->all()));
    }

    public function test_branch_manager_sees_and_manages_staff_working_extra_at_their_branch(): void
    {
        $managerB = $this->user('manager', $this->b);
        $teacher = $this->user('teacher', $this->a);
        $teacher->branches()->sync([$this->b->id]);
        $outsider = $this->user('teacher', $this->a);

        $this->actingAs($managerB)->get(route('users.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('users.data', fn ($rows) => collect($rows)->pluck('id')->contains($teacher->id)
                && ! collect($rows)->pluck('id')->contains($outsider->id)));

        $this->actingAs($managerB)->get(route('users.show', $teacher))->assertOk();
        $this->actingAs($managerB)->get(route('users.show', $outsider))->assertForbidden();
    }

    public function test_branch_scoped_editor_cannot_add_other_branches_and_keeps_those_out_of_scope(): void
    {
        // Học vụ: sửa được tài khoản giáo viên, phạm vi "Chi nhánh".
        $staffB = $this->user('academic_staff', $this->b);
        $teacher = $this->user('teacher_parttime', $this->b);
        $teacher->branches()->sync([$this->c->id]);

        // Thêm cơ sở ngoài phạm vi → lỗi.
        $this->actingAs($staffB)->put(route('users.update', $teacher), $this->form($teacher, [$this->a->id]))
            ->assertSessionHasErrors('extra_branch_ids');
        $this->assertSame([$this->c->id], $this->extraIds($teacher));

        // Form của người phạm vi CN B không hiện CN C: gửi rỗng vẫn giữ CN C; thêm CN B (trùng cơ sở chính) bị bỏ qua.
        $this->actingAs($staffB)->put(route('users.update', $teacher), $this->form($teacher, [$this->b->id]))->assertSessionHasNoErrors();
        $this->assertSame([$this->c->id], $this->extraIds($teacher));
    }

    public function test_teachers_and_assistants_do_not_require_primary_branch_but_other_staff_do(): void
    {
        $form = ['name' => 'Trợ Giảng', 'email' => 'tg@gmail.com', 'branch_id' => '', 'password' => 'Password123!'];

        $this->actingAs($this->admin)->post(route('users.store'), ['role' => 'sales_consultant'] + $form)
            ->assertSessionHasErrors('branch_id');

        foreach (['teacher_fulltime', 'teacher_parttime', 'foreign_teacher'] as $i => $role) {
            $this->actingAs($this->admin)->post(route('users.store'), ['role' => $role, 'email' => "gv{$i}@gmail.com"] + $form)
                ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
            $this->assertNull(User::where('email', "gv{$i}@gmail.com")->sole()->branch_id);
        }

        $this->actingAs($this->admin)->get(route('users.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branchOptionalRoles', ['teacher_fulltime', 'teacher_parttime', 'foreign_teacher', 'assistant']));

        $this->actingAs($this->admin)->post(route('users.store'), ['role' => 'assistant'] + $form)
            ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $assistant = User::where('email', 'tg@gmail.com')->sole();
        $this->assertNull($assistant->branch_id);

        // Trợ giảng nhập từ file không có cơ sở: sửa hồ sơ không bị bắt chọn cơ sở.
        $this->actingAs($this->admin)->put(route('users.update', $assistant), ['phone' => '0912345678'] + $this->form($assistant, []))
            ->assertSessionHasNoErrors();
        $this->assertNull($assistant->fresh()->branch_id);
        $this->assertSame('0912345678', $assistant->fresh()->phone);
    }

    public function test_branch_scoped_editor_still_needs_branch_for_assistant(): void
    {
        $staffB = $this->user('academic_staff', $this->b);

        $this->actingAs($staffB)->post(route('users.store'), [
            'name' => 'Trợ Giảng', 'email' => 'tg@gmail.com', 'branch_id' => '', 'role' => 'assistant', 'password' => 'Password123!',
        ])->assertSessionHasErrors('branch_id');
        $this->assertFalse(User::where('email', 'tg@gmail.com')->exists());
    }

    public function test_branch_scoped_editor_edits_staff_whose_primary_branch_is_elsewhere(): void
    {
        $staffB = $this->user('academic_staff', $this->b);
        $teacher = $this->user('teacher_parttime', $this->a);
        $teacher->branches()->sync([$this->b->id]);

        // Ô cơ sở chính có sẵn CN A (cơ sở hiện tại của nhân sự) dù ngoài phạm vi người sửa.
        $this->actingAs($staffB)->get(route('users.edit', $teacher))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branches', fn ($options) => collect($options)->pluck('value')->map(fn ($v) => (int) $v)->sort()->values()->all()
                === [$this->a->id, $this->b->id]));

        $this->actingAs($staffB)->put(route('users.update', $teacher), ['phone' => '0912345678'] + $this->form($teacher, [$this->b->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->a->id, (int) $teacher->fresh()->branch_id);
        $this->assertSame('0912345678', $teacher->fresh()->phone);
        $this->assertSame([$this->b->id], $this->extraIds($teacher));

        // Chuyển cơ sở chính sang chi nhánh ngoài phạm vi vẫn bị chặn.
        $this->actingAs($staffB)->put(route('users.update', $teacher), ['branch_id' => $this->c->id] + $this->form($teacher, [$this->b->id]))
            ->assertSessionHasErrors('branch_id');
    }
}
