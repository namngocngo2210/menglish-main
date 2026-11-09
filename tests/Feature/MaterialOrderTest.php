<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\MaterialOrder;
use App\Models\User;
use App\Services\MaterialOrderService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Order học liệu: hạn xử lý theo loại, tạo trễ chỉ cảnh báo, job "Quá hạn" (idempotent), quyền xử lý theo loại,
 * giáo viên chỉ thấy order của mình, phạm vi chi nhánh.
 */
class MaterialOrderTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private Branch $cg;

    private Branch $hd;

    protected function setUp(): void
    {
        parent::setUp();
        // Thứ Tư 07/10/2026 09:00.
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->cg = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-MO', 'is_active' => true]);
        $this->hd = Branch::create(['name' => 'Hà Đông', 'code' => 'HD-MO', 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null, string $name = 'Nhân sự'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'branch_id' => ($branch ?? $this->cg)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function classFor(User $teacher): ClassModel
    {
        return ClassModel::create([
            'code' => 'MO-'.$teacher->id,
            'name' => 'Material order class',
            'branch_id' => $teacher->branch_id,
            'teacher_id' => $teacher->id,
            'status' => 'active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'category' => 'props',
            'branch_id' => $this->cg->id,
            'title' => 'Flashcard unit 5',
            'use_date' => '2026-10-12',
            ...$overrides,
        ];
    }

    private function order(User $teacher, array $overrides = []): MaterialOrder
    {
        $data = $this->payload($overrides);

        return app(MaterialOrderService::class)->create($teacher, $data);
    }

    public function test_due_at_per_category(): void
    {
        // Đạo cụ / In ấn: 15:00 ngày hôm trước ngày sử dụng.
        $this->assertSame('2026-10-11 15:00', MaterialOrder::computeDueAt('props', '2026-10-12')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-11 15:00', MaterialOrder::computeDueAt('printing', '2026-10-12')->format('Y-m-d H:i'));
        // Ngày sử dụng mùng 1 → hạn là chiều 30/09 (ngày hôm trước, sang tháng trước).
        $this->assertSame('2026-09-30 15:00', MaterialOrder::computeDueAt('props', '2026-10-01')->format('Y-m-d H:i'));
        // GVNN / học thuật: 23:59 ngày N (Cấu hình SLA, mặc định 5) của THÁNG chứa ngày sử dụng.
        $this->assertSame('2026-10-05 23:59', MaterialOrder::computeDueAt('foreign_teacher', '2026-10-20')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-05 23:59', MaterialOrder::computeDueAt('academic', '2026-11-30')->format('Y-m-d H:i'));

        \App\Models\SlaSetting::updateOrCreate(['rule_key' => 'material.monthly_deadline'], ['value' => 10]);
        \App\Services\Sla\Sla::forget();
        $this->assertSame('2026-10-10 23:59', MaterialOrder::computeDueAt('foreign_teacher', '2026-10-20')->format('Y-m-d H:i'));
        // N lớn hơn số ngày của tháng → ngày cuối tháng.
        \App\Models\SlaSetting::updateOrCreate(['rule_key' => 'material.monthly_deadline'], ['value' => 31]);
        \App\Services\Sla\Sla::forget();
        $this->assertSame('2026-02-28 23:59', MaterialOrder::computeDueAt('academic', '2026-02-10')->format('Y-m-d H:i'));
    }

    public function test_teacher_creates_order_with_code_and_notifies_branch_processors(): void
    {
        $teacher = $this->user('teacher');
        $cmSame = $this->user('academic_staff', $this->cg, 'CM Cầu Giấy');
        $cmOther = $this->user('academic_staff', $this->hd, 'CM Hà Đông');
        $lead = $this->user('academic_lead');

        $this->actingAs($teacher)->from(route('material-orders.index'))
            ->post(route('material-orders.store'), $this->payload(['class_id' => $this->classFor($teacher)->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('material-orders.index'))
            ->assertSessionMissing('warning');

        $order = MaterialOrder::firstOrFail();
        $this->assertSame('OH-2026-0001', $order->code);
        $this->assertSame($teacher->id, $order->requester_id);
        $this->assertSame('pending', $order->status);
        $this->assertFalse($order->created_late);
        $this->assertSame('2026-10-11 15:00', $order->due_at->format('Y-m-d H:i'));

        // Báo Học vụ của chi nhánh, không báo CM chi nhánh khác / Trưởng Học thuật (đạo cụ).
        $this->assertSame(1, AdminNotification::where('user_id', $cmSame->id)->where('type', 'material_order_new')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $cmOther->id)->count());
        $this->assertSame(0, AdminNotification::where('user_id', $lead->id)->count());
    }

    public function test_academic_order_notifies_academic_lead_not_cm(): void
    {
        $teacher = $this->user('teacher');
        $cm = $this->user('academic_staff');
        $lead = $this->user('academic_lead');

        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload(['category' => 'academic', 'use_date' => '2026-11-10']))->assertRedirect();

        $this->assertSame(1, AdminNotification::where('user_id', $lead->id)->where('type', 'material_order_new')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $cm->id)->count());
    }

    public function test_late_creation_is_allowed_with_warning_and_flag(): void
    {
        $teacher = $this->user('teacher');

        $class = $this->classFor($teacher);

        // Dùng ngày mai: hạn là 15:00 hôm nay, hiện 09:00 nên còn hạn; dùng hôm nay: hạn 15:00 hôm qua → trễ.
        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload(['class_id' => $class->id, 'use_date' => '2026-10-08']))
            ->assertSessionMissing('warning');
        $this->assertFalse(MaterialOrder::firstOrFail()->created_late);

        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload(['class_id' => $class->id, 'use_date' => '2026-10-07', 'title' => 'Gấp']))
            ->assertSessionHas('warning')
            ->assertSessionHasNoErrors();

        $late = MaterialOrder::where('title', 'Gấp')->firstOrFail();
        $this->assertTrue($late->created_late);
        $this->assertSame('pending', $late->status);

        // Badge "Tạo trễ" xuất hiện trong props danh sách.
        $this->actingAs($teacher)->get(route('material-orders.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('MaterialOrders/Index')
                ->where('orders.total', 2)
                ->where('orders.data.0.created_late', true));
    }

    public function test_props_and_printing_require_a_class(): void
    {
        $teacher = $this->user('teacher');

        foreach (['props', 'printing'] as $category) {
            foreach ([[], ['class_id' => null]] as $classData) {
                $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                    'category' => $category, ...$classData,
                ]))->assertSessionHasErrors(['class_id' => 'Vui lòng chọn lớp cho order Đạo cụ / In ấn.']);
            }
        }

        $this->assertSame(0, MaterialOrder::count());
    }

    public function test_foreign_teacher_and_academic_can_be_created_without_a_class(): void
    {
        $teacher = $this->user('teacher');

        foreach (['foreign_teacher', 'academic'] as $category) {
            $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                'category' => $category,
            ]))->assertRedirect()->assertSessionHasNoErrors();

            $this->assertNull(MaterialOrder::where('category', $category)->sole()->class_id);
        }
    }

    public function test_yesterday_is_rejected_for_every_category(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->classFor($teacher);

        foreach (array_keys(MaterialOrder::CATEGORIES) as $category) {
            $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                'category' => $category,
                'class_id' => $class->id,
                'use_date' => today()->subDay()->toDateString(),
            ]))->assertSessionHasErrors(['use_date' => 'Ngày sử dụng không được ở quá khứ.']);
        }

        $this->assertSame(0, MaterialOrder::count());
    }

    public function test_today_is_accepted_for_every_category(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->classFor($teacher);
        $this->travelTo(Carbon::parse('2026-10-08 00:30:00', config('app.timezone')));

        foreach (array_keys(MaterialOrder::CATEGORIES) as $category) {
            $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                'category' => $category,
                'class_id' => $class->id,
                'use_date' => '2026-10-08',
            ]))->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame('2026-10-08', MaterialOrder::where('category', $category)->sole()->use_date->toDateString());
        }
    }

    public function test_class_must_be_visible_to_the_teacher(): void
    {
        $teacher = $this->user('teacher');
        $otherClass = $this->classFor($this->user('teacher'));

        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
            'class_id' => $otherClass->id,
        ]))->assertSessionHasErrors(['class_id' => 'Lớp học không nằm trong phạm vi của bạn.']);

        $this->assertSame(0, MaterialOrder::count());
    }

    public function test_printing_stores_specification_and_exposes_it_on_show(): void
    {
        $teacher = $this->user('teacher');
        $specification = 'A4, 2 mặt, đen trắng, đóng quyển';

        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
            'category' => 'printing',
            'class_id' => $this->classFor($teacher)->id,
            'specification' => $specification,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $order = MaterialOrder::sole();
        $this->assertSame($specification, $order->specification);
        $this->actingAs($teacher)->get(route('material-orders.show', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('order.specification', $specification));
    }

    public function test_non_printing_orders_ignore_specification(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->classFor($teacher);

        foreach (['props', 'foreign_teacher', 'academic'] as $category) {
            $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                'category' => $category,
                'class_id' => $class->id,
                'specification' => 'A4, 2 mặt',
            ]))->assertRedirect()->assertSessionHasNoErrors();

            $this->assertNull(MaterialOrder::where('category', $category)->sole()->specification);
        }
    }

    public function test_printing_specification_is_optional(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->classFor($teacher);

        foreach ([[], ['specification' => null]] as $specificationData) {
            $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                'category' => 'printing',
                'class_id' => $class->id,
                ...$specificationData,
            ]))->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, MaterialOrder::whereNull('specification')->count());
    }

    public function test_specification_must_be_a_string_of_at_most_500_characters(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->classFor($teacher);

        foreach ([str_repeat('a', 501), ['A4']] as $specification) {
            $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
                'category' => 'printing',
                'class_id' => $class->id,
                'specification' => $specification,
            ]))->assertSessionHasErrors('specification');
        }

        $this->assertSame(0, MaterialOrder::count());
        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload([
            'category' => 'printing',
            'class_id' => $class->id,
            'specification' => str_repeat('a', 500),
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', 500), MaterialOrder::sole()->specification);
    }

    public function test_mark_overdue_switches_status_notifies_once_and_is_idempotent(): void
    {
        $teacher = $this->user('teacher');
        $cm = $this->user('academic_staff');
        $lead = $this->user('academic_lead');

        $props = $this->order($teacher, ['use_date' => '2026-10-12']);      // hạn 11/10 15:00
        $academic = $this->order($teacher, ['category' => 'academic', 'use_date' => '2026-10-20']); // hạn 05/10 23:59 (đã qua)
        $processing = $this->order($teacher, ['category' => 'printing', 'use_date' => '2026-10-12']);
        $processing->update(['status' => 'processing']);
        $done = $this->order($teacher, ['category' => 'foreign_teacher', 'use_date' => '2026-10-20']);
        $done->update(['status' => 'done']);
        AdminNotification::query()->delete();

        // Trước hạn: chưa đổi gì (chỉ order học thuật đã qua hạn).
        $this->artisan('material-orders:mark-overdue')->assertSuccessful();
        $this->assertSame('pending', $props->fresh()->status);
        $this->assertSame('overdue', $academic->fresh()->status);
        $this->assertSame(1, AdminNotification::where('user_id', $lead->id)->where('type', 'material_order_overdue')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $cm->id)->count());

        // Qua 15:00 ngày 11/10: đạo cụ (chờ) và in ấn (đang xử lý) → Quá hạn; order đã xong giữ nguyên.
        $this->travelTo(Carbon::parse('2026-10-11 15:01:00'));
        $this->artisan('material-orders:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', $props->fresh()->status);
        $this->assertSame('overdue', $processing->fresh()->status);
        $this->assertSame('done', $done->fresh()->status);
        $this->assertSame(2, AdminNotification::where('user_id', $cm->id)->where('type', 'material_order_overdue')->count());

        // Chạy lại: không đổi, không báo lặp.
        $count = AdminNotification::count();
        $this->artisan('material-orders:mark-overdue')->assertSuccessful();
        $this->assertSame($count, AdminNotification::count());
    }

    public function test_overdue_order_can_still_be_processed_and_is_recorded_late(): void
    {
        $teacher = $this->user('teacher');
        $cm = $this->user('academic_staff');
        $order = $this->order($teacher);
        $this->travelTo(Carbon::parse('2026-10-12 08:00:00'));
        $this->artisan('material-orders:mark-overdue');
        $this->assertSame('overdue', $order->fresh()->status);

        $this->actingAs($cm)->post(route('material-orders.claim', $order))->assertRedirect();
        $this->assertSame('processing', $order->fresh()->status);

        $this->actingAs($cm)->post(route('material-orders.complete', $order), ['processor_note' => 'Đã chuẩn bị'])->assertRedirect();
        $order->refresh();
        $this->assertSame('done', $order->status);
        $this->assertSame($cm->id, $order->processed_by);
        $this->assertNotNull($order->processed_at);
        $this->assertTrue($order->processed_late);
    }

    public function test_reject_requires_reason(): void
    {
        $cm = $this->user('academic_staff');
        $order = $this->order($this->user('teacher'));

        $this->actingAs($cm)->post(route('material-orders.reject', $order))->assertSessionHasErrors('reject_reason');
        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($cm)->post(route('material-orders.reject', $order), ['reject_reason' => 'Hết đạo cụ'])->assertRedirect();
        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertSame('Hết đạo cụ', $order->fresh()->reject_reason);
        $this->assertFalse($order->fresh()->processed_late);
    }

    public function test_processor_permissions_per_category(): void
    {
        $teacher = $this->user('teacher');
        $cm = $this->user('academic_staff');
        $lead = $this->user('academic_lead');
        $admin = $this->user('admin');

        $props = $this->order($teacher);
        $foreign = $this->order($teacher, ['category' => 'foreign_teacher', 'use_date' => '2026-10-20']);
        $academic = $this->order($teacher, ['category' => 'academic', 'use_date' => '2026-10-20']);

        // CM: đạo cụ / GVNN được, học thuật không. Trưởng Học thuật ngược lại. Giáo viên không xử lý được gì.
        $this->actingAs($cm)->post(route('material-orders.claim', $props))->assertRedirect();
        $this->actingAs($cm)->post(route('material-orders.claim', $foreign))->assertRedirect();
        $this->actingAs($cm)->post(route('material-orders.claim', $academic))->assertForbidden();

        $this->actingAs($lead)->post(route('material-orders.claim', $academic))->assertRedirect();
        $this->actingAs($lead)->post(route('material-orders.complete', $props))->assertForbidden();
        $this->actingAs($lead)->post(route('material-orders.complete', $foreign))->assertForbidden();

        $this->actingAs($teacher)->post(route('material-orders.complete', $props))->assertForbidden();
        $this->actingAs($teacher)->post(route('material-orders.reject', $academic), ['reject_reason' => 'x'])->assertForbidden();

        // Admin qua Gate::before xử lý được mọi loại.
        $this->actingAs($admin)->post(route('material-orders.complete', $academic))->assertRedirect();
        $this->assertSame('done', $academic->fresh()->status);
    }

    public function test_cm_cannot_process_other_branch_order(): void
    {
        $order = $this->order($this->user('teacher'));
        $cmOther = $this->user('academic_staff', $this->hd);

        $this->actingAs($cmOther)->post(route('material-orders.claim', $order))->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_show_exposes_actions_only_to_right_processor(): void
    {
        $order = $this->order($this->user('teacher'));
        $cm = $this->user('academic_staff');
        $lead = $this->user('academic_lead');

        $this->actingAs($cm)->get(route('material-orders.show', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('MaterialOrders/Show')
                ->where('actions.claim', true)->where('actions.complete', true)->where('actions.reject', true));

        $this->actingAs($lead)->get(route('material-orders.show', $order))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('actions.claim', false)->where('actions.complete', false)->where('actions.reject', false));
    }

    public function test_teacher_sees_only_own_orders(): void
    {
        $teacher = $this->user('teacher', null, 'GV A');
        $other = $this->user('teacher', null, 'GV B');
        $mine = $this->order($teacher, ['title' => 'Của A']);
        $theirs = $this->order($other, ['title' => 'Của B']);

        $this->actingAs($teacher)->get(route('material-orders.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 1)->where('orders.data.0.id', $mine->id));

        $this->actingAs($teacher)->get(route('material-orders.show', $mine))->assertOk();
        $this->actingAs($teacher)->get(route('material-orders.show', $theirs))->assertForbidden();
    }

    public function test_branch_scoping_for_cm_and_all_for_academic_lead(): void
    {
        $teacherCg = $this->user('teacher', $this->cg);
        $teacherHd = $this->user('teacher', $this->hd);
        $inCg = $this->order($teacherCg, ['branch_id' => $this->cg->id]);
        $inHd = $this->order($teacherHd, ['branch_id' => $this->hd->id]);

        $cm = $this->user('academic_staff', $this->cg);
        $this->actingAs($cm)->get(route('material-orders.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 1)->where('orders.data.0.id', $inCg->id));
        $this->actingAs($cm)->get(route('material-orders.show', $inHd))->assertForbidden();

        $lead = $this->user('academic_lead', $this->cg);
        $this->actingAs($lead)->get(route('material-orders.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 2));

        // Lọc theo loại / trạng thái.
        $this->actingAs($lead)->get(route('material-orders.index', ['category' => 'academic']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 0));
    }

    public function test_only_teachers_can_create_and_branch_is_restricted(): void
    {
        $cm = $this->user('academic_staff');
        $this->actingAs($cm)->get(route('material-orders.create'))->assertForbidden();
        $this->actingAs($cm)->post(route('material-orders.store'), $this->payload())->assertForbidden();

        $student = $this->user('student');
        $this->actingAs($student)->get(route('material-orders.index'))->assertForbidden();

        // Giáo viên chỉ chọn được chi nhánh của mình.
        $teacher = $this->user('teacher', $this->cg);
        $this->actingAs($teacher)->post(route('material-orders.store'), $this->payload(['class_id' => $this->classFor($teacher)->id, 'branch_id' => $this->hd->id]))->assertSessionHasErrors('branch_id');
        $this->actingAs($teacher)->get(route('material-orders.create'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('MaterialOrders/Create')->where('asModal', true)->has('branches', 1));
    }

    public function test_sidebar_entry_is_gated_by_permission(): void
    {
        $teacher = $this->user('teacher');
        $accountant = $this->user('accountant');

        // "Order học liệu" là tab của khu Giao việc → Danh sách đầu việc (cả GV lẫn Kế toán đều xem được danh sách).
        $url = route('material-orders.index');
        $this->assertStringContainsString('href="'.$url.'"', $this->actingAs($teacher)->get(route('tasks.index'))->assertOk()->getContent());
        $this->assertStringNotContainsString('href="'.$url.'"', $this->actingAs($accountant)->get(route('tasks.index'))->assertOk()->getContent());
    }

    public function test_deadline_state_badges(): void
    {
        $order = new MaterialOrder(['status' => 'pending', 'due_at' => now()->addDays(3)]);
        $this->assertSame('ok', $order->deadlineState());
        $order->due_at = now()->addHours(5);
        $this->assertSame('soon', $order->deadlineState());
        $order->due_at = now()->subHour();
        $this->assertSame('overdue', $order->deadlineState());
        $order->status = 'done';
        $this->assertNull($order->deadlineState());
    }
}
