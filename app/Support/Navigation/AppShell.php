<?php

namespace App\Support\Navigation;

use App\Helpers\AclHelper;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Portal\PortalNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Dữ liệu khung ứng dụng (sidebar, topbar, tab workspace, menu Cài đặt) gửi cho layout Vue (AppLayout.vue)
 * qua shared prop `shell` của Inertia. Cùng nguồn với SidebarMenu nên menu, tab và quyền không lệch nhau.
 */
final class AppShell
{
    public function __construct(
        private readonly SidebarMenu $menu,
        private readonly NotificationService $notifications,
        private readonly ApprovalInboxService $approvals,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(?User $user, Request $request): ?array
    {
        if (! $user) {
            return null;
        }

        $portalStudent = $user->isPortalStudentOnly();
        $primaryRole = $user->getRoleNames()->first();
        $settingsUrl = $this->menu->settingsUrlFor($user, $request) ? route('settings.index') : null;
        $isSettingsRoute = $this->menu->isSettingsRoute($request);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'login' => $user->loginIdentifier(),
                'role' => $primaryRole ? AclHelper::shortRoleLabel($primaryRole) : 'Người dùng',
                'portal_student' => $portalStudent,
            ],
            'sidebar' => [
                'dashboard' => $portalStudent ? null : ['url' => route('dashboard'), 'active' => $request->routeIs('dashboard')],
                'groups' => $this->sidebarGroups($user, $request),
                'settings' => $settingsUrl ? ['url' => $settingsUrl, 'active' => $isSettingsRoute || $request->routeIs('settings.*')] : null,
            ],
            'title' => $this->fallbackTitle($user, $request),
            'search' => ! $portalStudent && Route::has('search')
                ? ['url' => route('search'), 'query' => $request->routeIs('search') ? (string) $request->query('q', '') : '']
                : null,
            'notifications' => $this->notificationMenu($user, $portalStudent),
            'workspace' => $this->workspace($user, $request),
            'settings' => $isSettingsRoute ? $this->menu->settingsFor($user, $request) : [],
            'logout_url' => route('logout'),
            'profile_url' => route('profile.edit'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sidebarGroups(User $user, Request $request): array
    {
        // Badge mục "Cần duyệt" = tổng chờ duyệt (cache 60s); null khi user không duyệt được nguồn nào.
        $approvalBadge = $this->approvals->badge($user);

        return array_map(fn (array $group) => [
            'id' => $group['id'],
            'section' => $group['section'],
            'label' => $group['label'],
            'icon' => $group['icon'],
            'url' => $group['url'],
            'active' => $group['is_active'],
            // Mục mở modal thay vì chuyển trang (vd. "Tạo đầu việc"); mở thẳng URL vẫn ra trang đầy đủ.
            'modal' => $group['modal'] ?? null,
            'badge' => $group['id'] === 'approvals' ? $approvalBadge : null,
        ], $this->menu->groupsFor($user, $request));
    }

    /** Tiêu đề topbar khi trang không tự đặt: tab đang mở → workspace → "MEnglish". */
    private function fallbackTitle(User $user, Request $request): string
    {
        $workspace = $this->menu->workspaceFor($user, $request);
        $tab = collect($workspace['items'] ?? [])->firstWhere('active', true);

        return $tab['label'] ?? $workspace['label'] ?? 'MEnglish';
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationMenu(User $user, bool $portalStudent): array
    {
        if ($portalStudent) {
            return [
                'portal' => true,
                'unread' => PortalNotifications::unreadCountForUser($user),
                'url' => route('portal.student.notifications'),
            ];
        }

        $canView = $user->can('notification.view');

        return [
            'portal' => false,
            'unread' => $this->notifications->getUnreadCount($user),
            'items' => $this->notifications->getUserNotifications($user, 6)->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'icon' => $n->icon,
                'badge_color' => $n->badge_color,
                'is_read' => (bool) $n->is_read,
                'ago' => $n->created_at?->diffForHumans(),
                'link' => is_array($n->data) ? ($n->data['link'] ?? null) : null,
            ])->values()->all(),
            'index_url' => $canView ? route('notifications.index') : null,
            'read_all_url' => $canView ? route('notifications.read-all') : null,
        ];
    }

    /**
     * Tab + nút hành động + lọc nhanh (chip) của workspace chứa route hiện tại.
     *
     * @return array<string, mixed>|null
     */
    private function workspace(User $user, Request $request): ?array
    {
        $ws = $this->menu->workspaceFor($user, $request);
        if (! $ws) {
            return null;
        }

        $items = collect($ws['items']);
        $chips = $items->where('as', 'chip')->values();
        // Tab cha đang mở khi chính nó hoặc 1 chip của nó đang mở.
        $tabs = $items->whereNull('as')->map(fn (array $tab) => [
            'label' => $tab['label'],
            'url' => $tab['url'],
            'route' => $tab['route'],
            'active' => $tab['active'] || $chips->contains(fn (array $chip) => $chip['chip_of'] === $tab['route'] && $chip['active']),
        ])->values();

        $menus = $items->where('as', 'menu')->concat(collect($ws['actions'])->whereNotNull('menu'))
            ->groupBy('menu')
            ->map(fn ($entries, $label) => [
                'label' => $label,
                'items' => $entries->map(fn (array $entry) => [
                    'label' => $entry['label'],
                    'url' => $entry['url'],
                    'icon' => $entry['icon'] ?? 'chevron_right',
                    'active' => ! empty($entry['active']),
                ])->values()->all(),
            ])->values();

        $buttons = collect($ws['actions'])->whereNull('menu')
            ->reject(fn (array $action) => ! empty($action['hide_on']) && $request->routeIs(...$action['hide_on']))
            ->map(fn (array $action) => [
                'label' => $action['label'],
                'url' => $action['url'],
                'icon' => $action['icon'] ?? null,
                'variant' => $action['variant'] ?? 'primary',
                'modal' => $action['modal'] ?? null,
            ])->values();

        // Chip lọc nhanh của tab đang mở; "Tất cả" = tab cha, sáng khi không chip nào đang mở.
        $activeTab = $items->whereNull('as')->first(fn (array $tab) => $tab['active']
            || $chips->contains(fn (array $chip) => $chip['chip_of'] === $tab['route'] && $chip['active']));
        $tabChips = $activeTab ? $chips->where('chip_of', $activeTab['route'])->values() : collect();

        return [
            'id' => $ws['id'],
            'label' => $ws['label'],
            'tabs' => $tabs->all(),
            'menus' => $menus->all(),
            'buttons' => $buttons->all(),
            'chips' => $tabChips->isEmpty() ? null : [
                'all' => ['url' => route($activeTab['route']), 'active' => ! $tabChips->contains('active', true)],
                'items' => $tabChips->map(fn (array $chip) => [
                    'label' => $chip['label'],
                    'url' => $chip['url'],
                    'active' => $chip['active'],
                    'tone' => $chip['tone'] ?? null,
                    'count' => $chip['count'] ?? null,
                    'hide_empty' => ! empty($chip['hide_empty']),
                ])->all(),
            ],
        ];
    }
}
