{{--
    Sidebar ứng dụng (theo mockup crm-ui-mockup/app-shell-layout).
    - Menu + quyền: App\Support\Navigation\SidebarMenu (ability đọc từ middleware can: của route).
    - Responsive: ≥1200px đầy đủ 240px (accordion) · 768–1199px thu gọn icon 72px (flyout khi hover)
      · <768px drawer (mở bằng nút menu trên topbar, biến `sidebarOpen` của layout).
--}}
@php
    $menuGroups = app(\App\Support\Navigation\SidebarMenu::class)->groupsFor(Auth::user(), request());
    $initialOpenGroups = collect($menuGroups)->mapWithKeys(fn ($g) => [$g['id'] => $g['is_active']])->all();
    $dashboardActive = request()->routeIs('dashboard');
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
    function sidebarNavigation(initialGroups) {
        const tabletQuery = window.matchMedia('(min-width: 768px) and (max-width: 1199.98px)');
        return {
            openGroups: Object.assign({}, initialGroups || {}),
            collapsed: tabletQuery.matches,
            init() {
                tabletQuery.addEventListener('change', (e) => { this.collapsed = e.matches; });
                try {
                    const saved = JSON.parse(localStorage.getItem('menglish_sidebar_open_groups') || '{}');
                    Object.keys(saved || {}).forEach((k) => { if (saved[k] === true) this.openGroups[k] = true; });
                } catch (e) {}
                Object.keys(initialGroups || {}).forEach((k) => { if (initialGroups[k]) this.openGroups[k] = true; });
                this.persist();

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
            toggle(id) {
                this.openGroups[id] = !this.openGroups[id];
                this.persist();
            },
            persist() {
                try { localStorage.setItem('menglish_sidebar_open_groups', JSON.stringify(this.openGroups)); } catch (e) {}
            },
            saveScroll() {
                try { sessionStorage.setItem('sidebar_scroll_top', this.$refs.navContainer?.scrollTop ?? 0); } catch (e) {}
            },
        };
    }
</script>

<aside
    class="fixed left-0 top-0 z-40 flex h-full w-sidebar-width flex-col bg-sidebar text-surface-variant shadow-level-3 transition-[transform,width] duration-200 max-md:-translate-x-full md:w-sidebar-collapsed desktop:w-sidebar-width md:shadow-none"
    :class="{ 'max-md:!translate-x-0': sidebarOpen }"
    x-data="sidebarNavigation(@js($initialOpenGroups))"
    aria-label="Menu chính"
    data-sidebar
>
    {{-- Brand --}}
    <div class="flex h-header-height shrink-0 items-center gap-sm px-md md:justify-center md:px-0 desktop:justify-start desktop:px-md">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-sm rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container" title="Về trang tổng quan">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white">
                <img src="{{ asset('images/menglish-logo.png') }}" alt="MEnglish" class="h-full w-full object-contain" width="40" height="40">
            </span>
            <span class="flex min-w-0 flex-col md:hidden desktop:flex">
                <span class="font-h2 text-h2 leading-none tracking-tight text-primary-container">MENGLISH</span>
                <span class="mt-1 text-[10px] font-semibold uppercase tracking-widest text-surface-variant/60">Hệ thống quản trị</span>
            </span>
        </a>
        <button type="button" class="ml-auto rounded-lg p-1 text-surface-variant/70 hover:bg-white/10 hover:text-white md:hidden" @click="sidebarOpen = false" aria-label="Đóng menu">
            <span class="material-symbols-outlined text-[20px]">close</span>
        </button>
    </div>

    {{-- Menu --}}
    <nav x-ref="navContainer" @scroll.passive.debounce.100ms="saveScroll()" class="custom-scrollbar flex-1 space-y-xs overflow-y-auto px-2 py-sm">
        <a href="{{ route('dashboard') }}"
           @if ($dashboardActive) aria-current="page" @endif
           title="Tổng quan"
           class="flex items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium transition-colors duration-150 active:scale-95 md:justify-center md:px-0 desktop:justify-start desktop:px-md {{ $dashboardActive ? 'bg-primary-container text-white shadow-lg shadow-primary-container/20' : 'hover:bg-white/10 hover:text-white' }}">
            <span class="material-symbols-outlined shrink-0 {{ $dashboardActive ? 'fill' : '' }}">dashboard</span>
            <span class="truncate md:hidden desktop:inline">Tổng quan</span>
        </a>

        @foreach ($menuGroups as $group)
            @php $gid = $group['id']; $groupActive = $group['is_active']; @endphp
            <div
                x-data="{
                    show: false, timer: null, top: 0, left: 0,
                    place() {
                        const r = this.$el.getBoundingClientRect();
                        this.left = r.right + 6; this.top = r.top;
                        this.$nextTick(() => {
                            const fly = this.$refs.flyout; if (!fly) return;
                            const max = window.innerHeight - fly.offsetHeight - 8;
                            this.top = Math.max(8, Math.min(r.top, max));
                        });
                    },
                    enter() { if (!collapsed) return; clearTimeout(this.timer); this.place(); this.show = true; },
                    leave() { this.timer = setTimeout(() => this.show = false, 150); },
                }"
                @mouseenter="enter()" @mouseleave="leave()"
            >
                <button type="button"
                        @click="collapsed ? (show = !show, place()) : toggle('{{ $gid }}')"
                        :aria-expanded="collapsed ? show : !!openGroups['{{ $gid }}']"
                        title="{{ $group['label'] }}"
                        class="group flex w-full items-center gap-md rounded-lg px-md py-sm text-left font-body-medium text-body-medium transition-colors duration-150 md:justify-center md:px-0 desktop:justify-start desktop:px-md {{ $groupActive ? 'text-white md:bg-primary-container md:shadow-lg md:shadow-primary-container/20 desktop:bg-transparent desktop:shadow-none' : 'hover:bg-white/10 hover:text-white' }}">
                    <span class="material-symbols-outlined shrink-0 {{ $groupActive ? 'text-primary-container md:text-white desktop:text-primary-container' : '' }}">{{ $group['icon'] }}</span>
                    <span class="flex-1 truncate md:hidden desktop:inline">{{ $group['label'] }}</span>
                    <span class="material-symbols-outlined text-[18px] text-surface-variant/50 transition-transform duration-200 md:hidden desktop:inline-block"
                          :class="{ 'rotate-90': openGroups['{{ $gid }}'] }">chevron_right</span>
                </button>

                {{-- Accordion (desktop + drawer) --}}
                <div x-show="!collapsed && openGroups['{{ $gid }}']" x-collapse @if (! $groupActive) x-cloak @endif>
                    <div class="ml-[27px] mt-xs space-y-0.5 border-l border-white/10 pl-sm">
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['url'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               data-menu-item
                               class="block truncate rounded-lg px-sm py-1.5 font-body-small text-body-small transition-colors duration-150 {{ $item['active'] ? 'bg-primary-container font-medium text-white shadow-lg shadow-primary-container/20' : 'text-surface-variant/80 hover:bg-white/10 hover:text-white' }}"
                               title="{{ $item['label'] }}">{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                </div>

                {{-- Flyout (tablet: sidebar thu gọn icon) --}}
                <template x-teleport="body">
                    <div x-ref="flyout" x-show="collapsed && show" x-cloak
                         @mouseenter="clearTimeout(timer)" @mouseleave="leave()"
                         x-transition.opacity.duration.150ms
                         class="fixed z-[60] max-h-[calc(100vh-16px)] w-64 overflow-y-auto rounded-xl border border-white/10 bg-sidebar py-sm shadow-level-3"
                         :style="`top:${top}px;left:${left}px`">
                        <div class="mb-xs border-b border-white/10 px-md pb-sm pt-xs font-label text-label uppercase text-white">{{ $group['label'] }}</div>
                        <div class="flex flex-col gap-0.5 px-sm">
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}"
                                   class="rounded-lg px-sm py-1.5 font-body-small text-body-small transition-colors {{ $item['active'] ? 'bg-primary-container font-medium text-white' : 'text-surface-variant/80 hover:bg-white/10 hover:text-white' }}">{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                </template>
            </div>
        @endforeach
    </nav>

    {{-- Vai trò + Đăng xuất --}}
    <div class="shrink-0 space-y-xs border-t border-white/10 px-2 py-sm">
        <div class="flex items-center gap-sm px-md py-xs md:justify-center md:px-0 desktop:justify-start desktop:px-md" title="Vai trò hiện tại: {{ $roleName }}">
            <span class="h-2 w-2 shrink-0 rounded-full bg-tertiary-container"></span>
            <span class="truncate font-caption text-caption text-surface-variant/70 md:hidden desktop:inline">Vai trò: <span class="font-semibold text-white">{{ $roleName }}</span></span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Đăng xuất"
                    class="flex w-full items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium text-red-400 transition-colors hover:bg-error/20 hover:text-red-300 md:justify-center md:px-0 desktop:justify-start desktop:px-md">
                <span class="material-symbols-outlined shrink-0">logout</span>
                <span class="md:hidden desktop:inline">Đăng xuất</span>
            </button>
        </form>
    </div>
</aside>

{{-- Backdrop cho drawer mobile --}}
<div class="fixed inset-0 z-30 bg-black/50 backdrop-blur-xs md:hidden" x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"></div>
