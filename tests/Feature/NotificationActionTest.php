<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Thông báo có nút hành động (docs/kho-syllabus.md §6.1): chuông topbar và trang Thông báo trả về `action`. */
class NotificationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_and_index_expose_action(): void
    {
        $branch = Branch::create(['name' => 'Cơ sở TB', 'code' => 'TB', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->assignRole(Roles::ACADEMIC_LEAD);
        $action = ['label' => 'XÁC NHẬN', 'url' => 'https://example.com/confirm', 'method' => 'post'];
        AdminNotification::notifyUser($user->id, 'syllabus_workflow', 'Có nút', 'Nội dung', null, ['action' => $action]);
        AdminNotification::notifyUser($user->id, 'syllabus_workflow', 'Không nút', 'Nội dung');

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.notifications.items', fn ($items) => collect($items)->firstWhere('title', 'Có nút')['action'] === $action
                    && collect($items)->firstWhere('title', 'Không nút')['action'] === null)
                ->where('notifications.data', fn ($rows) => collect($rows)->firstWhere('title', 'Có nút')['action'] === $action)
                ->etc());
    }

    public function test_action_without_url_or_unknown_method_is_dropped(): void
    {
        $branch = Branch::create(['name' => 'Cơ sở TB', 'code' => 'TB', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $user->assignRole(Roles::ACADEMIC_LEAD);
        AdminNotification::notifyUser($user->id, 'syllabus_workflow', 'Sai', 'x', null, ['action' => ['label' => 'X', 'url' => 'javascript:alert(1)', 'method' => 'post']]);

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications.data.0.action', null)
                ->etc());
    }
}
