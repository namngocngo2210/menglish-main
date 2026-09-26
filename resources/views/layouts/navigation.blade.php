{{--
    Sidebar ứng dụng (theo mockup crm-ui-mockup/app-shell-layout).
    - Menu + quyền: App\Support\Navigation\SidebarMenu (ability đọc từ middleware can: của route).
    - 1 mục = 1 workspace (link tới tab đầu tiên user được xem); màn hình con là tab trong trang (x-ui.workspace-tabs).
      Cấu hình / danh mục gom vào mục "Cài đặt" cuối sidebar.
    - Responsive: ≥1200px đầy đủ 240px (có nút thu gọn → chỉ icon 72px, lưu localStorage, class
      `sidebar-collapsed` trên <html>) · 768–1199px luôn thu gọn icon 72px · <768px drawer
      (mở bằng nút menu trên topbar, biến `sidebarOpen` của layout).
    - Khi thu gọn: rê chuột / focus vào mục hiện tooltip tên mục (phần tử [data-tooltip]).
--}}
@php
    $sidebarMenu = app(\App\Support\Navigation\SidebarMenu::class);
    $menuGroups = $sidebarMenu->groupsFor(Auth::user(), request());
    $settingsUrl = $sidebarMenu->settingsUrlFor(Auth::user(), request()) ? route('settings.index') : null;
    $settingsActive = $settingsUrl && ($sidebarMenu->isSettingsRoute(request()) || request()->routeIs('settings.*'));
    $dashboardActive = request()->routeIs('dashboard');
    // "Việc cần duyệt": chỉ hiện khi duyệt được ít nhất 1 nguồn; badge = tổng chờ duyệt (cache 60s, 1 lần đọc cache).
    $approvalBadge = Auth::user() ? app(\App\Support\Approvals\ApprovalInboxService::class)->badge(Auth::user()) : null;
    $roleLabels = [
        'admin' => 'Quản trị hệ thống', 'manager' => 'Quản lý', 'accountant' => 'Kế toán',
        'academic_staff' => 'Nhân viên học vụ', 'academic_lead' => 'Trưởng học vụ', 'sales_consultant' => 'Tư vấn tuyển sinh',
        'teacher' => 'Giáo viên', 'teacher_fulltime' => 'Giáo viên full-time', 'teacher_parttime' => 'Giáo viên part-time',
        'assistant' => 'Trợ giảng', 'student' => 'Học viên',
    ];
    $primaryRole = Auth::user()?->getRoleNames()->first();
    $roleName = $roleLabels[$primaryRole] ?? ($primaryRole ? \Illuminate\Support\Str::headline($primaryRole) : 'Người dùng');
@endphp

<script>
    function sidebarNavigation() {
        return {
            collapsed: document.documentElement.classList.contains('sidebar-collapsed'),
            tip: { show: false, text: '', top: 0, left: 0 },
            init() {
                // Giữ vị trí cuộn của menu giữa các lần chuyển trang; lần đầu cuộn tới mục đang mở.
                this.$nextTick(() => {
                    const nav = this.$refs.navContainer;
                    if (!nav) return;
                    let saved = null;
                    try { saved = sessionStorage.getItem('sidebar_scroll_top'); } catch (e) {}
                    if (saved !== null) {
                        nav.scrollTop = parseInt(saved, 10);
                    } else {
                        nav.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
                    }
                });
            },
            saveScroll() {
                try { sessionStorage.setItem('sidebar_scroll_top', this.$refs.navContainer?.scrollTop ?? 0); } catch (e) {}
            },
            toggleCollapsed() {
                this.collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
                this.tip.show = false;
                try { localStorage.setItem('sidebar_collapsed', this.collapsed ? '1' : '0'); } catch (e) {}
            },
            // Tooltip tên mục: chỉ khi sidebar đang ở dạng icon (thu gọn thủ công hoặc màn 768–1199px).
            showTip(event) {
                const item = event.target.closest?.('[data-tooltip]');
                if (!item || this.$root.offsetWidth > 100) { this.tip.show = false; return; }
                const rect = item.getBoundingClientRect();
                this.tip = { show: true, text: item.dataset.tooltip, top: rect.top + rect.height / 2, left: rect.right + 12 };
            },
        };
    }
</script>

<aside
    class="fixed left-0 top-0 z-40 flex h-full w-sidebar-width flex-col bg-sidebar text-surface-variant shadow-level-3 transition-[transform,width] duration-200 max-md:-translate-x-full md:w-sidebar-collapsed desktop:w-sidebar-width md:shadow-none"
    :class="{ 'max-md:!translate-x-0': sidebarOpen }"
    x-data="sidebarNavigation()"
    @mouseover="showTip($event)" @focusin="showTip($event)" @mouseleave="tip.show = false" @focusout="tip.show = false"
    aria-label="Menu chính"
    data-sidebar
>
    {{-- Brand --}}
    <div class="flex h-header-height shrink-0 items-center gap-sm px-md md:justify-center md:px-0 desktop:justify-start desktop:px-md" data-sidebar-center>
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-sm rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container" title="Về trang tổng quan">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface-container-lowest">
                <img src="{{ asset('images/menglish-logo.png') }}" alt="MEnglish" class="h-full w-full object-contain" width="40" height="40">
            </span>
            <span class="flex min-w-0 flex-col md:hidden desktop:flex" data-sidebar-text>
                <span class="font-h2 text-h2 leading-none tracking-tight text-primary-container">MENGLISH</span>
                <span class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-surface-variant/60">Hệ thống quản trị</span>
            </span>
        </a>
        <button type="button" class="ml-auto rounded-lg p-1 text-surface-variant/70 hover:bg-white/10 hover:text-white md:hidden" @click="sidebarOpen = false" aria-label="Đóng menu">
            <span class="material-symbols-outlined text-[20px]">close</span>
        </button>
    </div>

    {{-- Menu --}}
    <nav x-ref="navContainer" @scroll.passive.debounce.100ms="saveScroll()" @scroll.passive="tip.show = false" class="sidebar-scrollbar flex-1 space-y-xs overflow-y-auto px-2 py-sm">
        @include('layouts.partials.sidebar-link', ['url' => route('dashboard'), 'label' => 'Tổng quan', 'icon' => 'dashboard', 'active' => $dashboardActive, 'id' => 'dashboard'])
        @if ($approvalBadge !== null)
            @include('layouts.partials.sidebar-link', ['url' => route('approvals.index'), 'label' => 'Việc cần duyệt', 'icon' => 'fact_check', 'active' => request()->routeIs('approvals.*'), 'id' => 'approvals', 'badge' => $approvalBadge])
        @endif

        @foreach ($menuGroups as $group)
            @if ($loop->first || $group['section'] !== $menuGroups[$loop->index - 1]['section'])
                {{-- Tiêu đề khu (sidebar thu gọn: chỉ còn đường kẻ) --}}
                <div class="px-md pb-1 pt-md font-caption text-[10px] font-semibold uppercase tracking-widest text-surface-variant/50 md:hidden desktop:block" data-menu-section data-sidebar-text>{{ $group['section'] }}</div>
                <div class="mx-auto my-sm hidden h-px w-8 bg-white/10 md:block desktop:hidden" aria-hidden="true" data-sidebar-divider></div>
            @endif
            @include('layouts.partials.sidebar-link', ['url' => $group['url'], 'label' => $group['label'], 'icon' => $group['icon'], 'active' => $group['is_active'], 'id' => $group['id']])
        @endforeach

        @if ($settingsUrl)
            <div class="mx-md my-sm h-px bg-white/10" aria-hidden="true"></div>
            @include('layouts.partials.sidebar-link', ['url' => $settingsUrl, 'label' => 'Cài đặt', 'icon' => 'settings', 'active' => $settingsActive, 'id' => 'settings'])
        @endif
    </nav>

    {{-- Vai trò + Đăng xuất --}}
    <div class="shrink-0 space-y-xs border-t border-white/10 px-2 py-sm">
        <div class="flex items-center gap-sm px-md py-xs md:justify-center md:px-0 desktop:justify-start desktop:px-md" data-sidebar-center data-tooltip="Vai trò: {{ $roleName }}">
            <span class="h-2 w-2 shrink-0 rounded-full bg-tertiary-container"></span>
            <span class="truncate font-caption text-caption text-surface-variant/70 md:hidden desktop:inline" data-sidebar-text>Vai trò: <span class="font-semibold text-white">{{ $roleName }}</span></span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" data-tooltip="Đăng xuất" aria-label="Đăng xuất" data-sidebar-center
                    class="flex w-full items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium text-error-container/80 transition-colors hover:bg-error/20 hover:text-error-container md:justify-center md:px-0 desktop:justify-start desktop:px-md">
                <span class="material-symbols-outlined shrink-0" aria-hidden="true">logout</span>
                <span class="md:hidden desktop:inline" data-sidebar-text>Đăng xuất</span>
            </button>
        </form>
        {{-- Thu gọn / mở rộng sidebar (chỉ màn ≥1200px; 768–1199px luôn dạng icon) --}}
        <button type="button" @click="toggleCollapsed()" data-sidebar-center data-sidebar-toggle
                :data-tooltip="collapsed ? 'Mở rộng menu' : null" :aria-expanded="(!collapsed).toString()" aria-label="Thu gọn / mở rộng menu"
                class="hidden w-full items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium text-surface-variant/70 transition-colors hover:bg-white/10 hover:text-white desktop:flex">
            <span class="material-symbols-outlined shrink-0" aria-hidden="true" x-text="collapsed ? 'left_panel_open' : 'left_panel_close'">left_panel_close</span>
            <span class="truncate" data-sidebar-text>Thu gọn menu</span>
        </button>
    </div>

    {{-- Tooltip tên mục khi sidebar dạng icon (position fixed để không bị cắt bởi vùng cuộn) --}}
    <div x-show="tip.show" x-cloak role="tooltip"
         class="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-md bg-inverse-surface px-2.5 py-1.5 text-xs font-semibold text-white shadow-lg ring-1 ring-white/10"
         :style="`top: ${tip.top}px; left: ${tip.left}px`" x-text="tip.text"></div>
</aside>

{{-- Backdrop cho drawer mobile --}}
<div class="fixed inset-0 z-30 bg-black/50 backdrop-blur-xs md:hidden" x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"></div>
