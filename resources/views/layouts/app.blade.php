{{--
    Layout ứng dụng (App Shell theo mockup crm-ui-mockup/app-shell-layout).
    Dùng: <x-app-layout title="Tiêu đề trang"> ... </x-app-layout>
      - Topbar chỉ gồm: tiêu đề trang (chữ thuần) · tìm kiếm chung · thông báo · tài khoản.
      - slot `header` (tuỳ chọn): khối tiêu đề + nút hành động của trang, hiển thị ở ĐẦU NỘI DUNG (không trên topbar).
      - thuộc tính `title`: <title> của tab trình duyệt + tiêu đề topbar.
        Không có `title` → lấy chữ của thẻ h1/h2 đầu tiên trong slot `header` → tên workspace hiện tại → "MEnglish".
      - thuộc tính `hide-errors`: tắt alert lỗi validate toàn cục (khi trang tự hiển thị danh sách lỗi).
--}}
@php
    $pageTitle = $attributes->get('title');
    $currentUser = Auth::user();
    $menu = app(\App\Support\Navigation\SidebarMenu::class);
    // Tiêu đề topbar (chữ thuần): title → h1/h2 đầu tiên của slot header (bỏ chữ icon) → tên workspace.
    $topbarTitle = $pageTitle;
    if (! $topbarTitle && isset($header)) {
        $headerHtml = preg_replace('/<span[^>]*material-symbols[^>]*>.*?<\/span>/su', '', (string) $header);
        if (preg_match('/<h[12][^>]*>(.*?)<\/h[12]>/su', $headerHtml, $m)) {
            $topbarTitle = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
    }
    if (! $topbarTitle && $currentUser) {
        $topbarTitle = $menu->workspaceFor($currentUser, request())['label'] ?? null;
    }
    // Ô tìm kiếm chung: tên màn hình + khách CRM / học viên / lớp (trang /search tự lọc theo quyền từng nhóm).
    $canGlobalSearch = $currentUser && Route::has('search');
    // Trang cấu hình / danh mục: bọc bằng menu con Cài đặt (URL cũ giữ nguyên).
    $settingsSections = $currentUser && $menu->isSettingsRoute(request()) ? $menu->settingsFor($currentUser, request()) : [];
    $canViewNotifications = (bool) $currentUser?->can('notification.view');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $pageTitle ? $pageTitle . ' · ' : '' }}{{ config('app.name', 'MEnglish') }}</title>

        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        {{-- Trạng thái thu gọn sidebar (≥1200px): áp trước khi vẽ để không nháy layout --}}
        <script>try { if (localStorage.getItem('sidebar_collapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}</script>

        {{-- Font & icon được tự host qua Vite (resources/css/app.css) --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{-- hx-headers: mọi request htmx (modal, làm mới danh sách) mang CSRF token --}}
    <body class="bg-background font-body-base text-body-base text-on-surface antialiased" hx-headers="{{ json_encode(['X-CSRF-TOKEN' => csrf_token()]) }}">
        <div class="flex min-h-screen flex-col" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
            @include('layouts.navigation')

            <div class="flex min-w-0 flex-1 flex-col transition-[padding] duration-200 md:pl-sidebar-collapsed desktop:pl-sidebar-width" data-sidebar-shell>
                {{-- Topbar --}}
                <header class="sticky top-0 z-30 flex h-header-height shrink-0 items-center justify-between gap-md border-b border-surface-container-highest bg-surface px-md lg:px-lg">
                    <div class="flex min-w-0 flex-1 items-center gap-md lg:gap-lg">
                        <button type="button" class="shrink-0 rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high md:hidden" @click="sidebarOpen = true" aria-label="Mở menu">
                            <span class="material-symbols-outlined">menu</span>
                        </button>

                        <div class="min-w-0 truncate font-h3 text-h3 text-on-surface" data-topbar-title>{{ $topbarTitle ?: 'MEnglish' }}</div>

                        @if ($canGlobalSearch)
                            <form method="GET" action="{{ route('search') }}" role="search" class="relative hidden w-[300px] shrink-0 lg:block">
                                <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                                <input type="search" name="q" value="{{ request()->routeIs('search') ? request('q') : '' }}" minlength="2"
                                       placeholder="Tìm màn hình, khách, học viên, lớp..." aria-label="Tìm kiếm màn hình, khách hàng, học viên, lớp học"
                                       class="w-full rounded-full border-none bg-surface-container-low py-2 pl-10 pr-md font-body-small text-body-small text-on-surface placeholder:text-on-surface-variant/50 focus:ring-2 focus:ring-primary-container/20">
                            </form>
                            <a href="{{ route('search') }}" class="shrink-0 rounded-lg p-2 text-on-surface-variant hover:bg-surface-container-high lg:hidden" aria-label="Tìm kiếm">
                                <span class="material-symbols-outlined">search</span>
                            </a>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-xs sm:gap-md">
                        {{-- Thông báo --}}
                        @php
                            $notifService = app(\App\Services\NotificationService::class);
                            $unreadNotifsCount = $notifService->getUnreadCount($currentUser);
                            $headerNotifs = $notifService->getUserNotifications($currentUser, 6);
                        @endphp
                        <x-dropdown align="right" width="notification">
                            <x-slot name="trigger">
                                <button type="button" class="relative rounded-lg p-2 text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary" title="Thông báo & Cảnh báo" aria-label="Thông báo">
                                    <span class="material-symbols-outlined">notifications</span>
                                    @if ($unreadNotifsCount > 0)
                                        <span class="absolute right-1 top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-surface bg-error px-1 font-code text-[10px] font-bold text-white">
                                            {{ $unreadNotifsCount > 9 ? '9+' : $unreadNotifsCount }}
                                        </span>
                                    @endif
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="flex items-center justify-between border-b border-surface-container bg-surface-container-low p-md">
                                    <div class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface">
                                        <span class="material-symbols-outlined text-[18px] text-error">notifications_active</span>
                                        <span>Cảnh báo quản trị</span>
                                        @if ($unreadNotifsCount > 0)
                                            <x-ui.badge color="error" :dot="false" pill>{{ $unreadNotifsCount }} chưa đọc</x-ui.badge>
                                        @endif
                                    </div>
                                    @if ($unreadNotifsCount > 0 && $canViewNotifications)
                                        <form action="{{ route('notifications.read-all') }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="font-caption text-caption font-semibold text-primary hover:underline">Đã đọc tất cả</button>
                                        </form>
                                    @endif
                                </div>

                                <div class="max-h-[380px] divide-y divide-surface-container overflow-y-auto">
                                    @forelse ($headerNotifs as $hn)
                                        <div class="flex items-start gap-sm p-md transition-colors hover:bg-surface-container-low {{ ! $hn->is_read ? 'bg-error-container/20' : '' }}">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $hn->badge_color }}">
                                                <span class="material-symbols-outlined text-[18px]">{{ $hn->icon }}</span>
                                            </div>
                                            <div class="min-w-0 flex-1 space-y-xs">
                                                <div class="flex items-center justify-between gap-sm">
                                                    <span class="truncate font-body-semibold text-body-small text-on-surface">{{ $hn->title }}</span>
                                                    @if (! $hn->is_read)
                                                        <span class="h-2 w-2 shrink-0 rounded-full bg-error"></span>
                                                    @endif
                                                </div>
                                                <p class="line-clamp-2 font-caption text-caption text-on-surface-variant">{{ $hn->message }}</p>
                                                <div class="flex items-center justify-between">
                                                    <span class="font-code text-[10px] text-on-surface-variant/70">{{ $hn->created_at->diffForHumans() }}</span>
                                                    @if ($hn->data && isset($hn->data['link']))
                                                        <a href="{{ $hn->data['link'] }}" class="inline-flex items-center gap-0.5 font-caption text-caption font-semibold text-secondary hover:underline">
                                                            <span>Xử lý ngay</span>
                                                            <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <x-ui.empty-state icon="notifications_paused" title="Không có thông báo" description="Chưa có thông báo hoặc cảnh báo nào." class="!py-lg" />
                                    @endforelse
                                </div>

                                @if ($canViewNotifications)
                                    <div class="border-t border-surface-container bg-surface-container-low p-sm text-center">
                                        <a href="{{ route('notifications.index') }}" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                                            <span>Xem tất cả cảnh báo &amp; Lead tồn đọng</span>
                                            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                                        </a>
                                    </div>
                                @endif
                            </x-slot>
                        </x-dropdown>

                        {{-- Tài khoản --}}
                        <x-dropdown align="right" width="56">
                            <x-slot name="trigger">
                                <button type="button" class="flex items-center gap-sm rounded-lg p-xs text-left transition-colors hover:bg-surface-container-low sm:border-l sm:border-surface-container-highest sm:pl-md" aria-label="Tài khoản">
                                    <span class="hidden text-right lg:block">
                                        <span class="block max-w-[160px] truncate font-body-medium text-body-medium leading-tight text-on-surface">{{ $currentUser?->name }}</span>
                                        <span class="block font-caption text-caption text-on-surface-variant">{{ $currentUser?->email }}</span>
                                    </span>
                                    <span class="rounded-full border-2 border-primary-container/20 p-0.5">
                                        <x-ui.avatar :name="$currentUser?->name ?? '?'" size="sm" />
                                    </span>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="border-b border-surface-container px-md py-sm">
                                    <div class="truncate font-body-semibold text-body-small text-on-surface">{{ $currentUser?->name }}</div>
                                    <div class="truncate font-caption text-caption text-on-surface-variant">{{ $currentUser?->email }}</div>
                                </div>
                                <a href="{{ route('profile.edit') }}" class="flex items-center gap-sm px-md py-sm font-body-medium text-body-medium text-on-surface hover:bg-surface-container-low">
                                    <span class="material-symbols-outlined text-[20px] text-on-surface-variant">account_circle</span> Hồ sơ cá nhân
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-sm px-md py-sm font-body-medium text-body-medium text-error hover:bg-error-container/40">
                                        <span class="material-symbols-outlined text-[20px]">logout</span> Đăng xuất
                                    </button>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </header>

                {{-- Nội dung trang --}}
                <main class="flex-1 p-md lg:p-lg">
                    {{-- Khối tiêu đề + nút hành động của trang (slot header) --}}
                    @isset($header)
                        <div class="mb-lg" data-page-header>{{ $header }}</div>
                    @endisset

                    {{-- Tab của workspace: trang tự đặt <x-ui.workspace-tabs> thì không chèn lại --}}
                    @unless (request()->attributes->get('workspace_tabs_rendered'))
                        <x-ui.workspace-tabs />
                    @endunless

                    @if ($errors->any() && ! $attributes->get('hide-errors'))
                        <x-ui.alert type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-lg" dismissible data-global-errors>
                            <ul class="list-inside list-disc space-y-0.5">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    @if ($settingsSections !== [])
                        <div class="flex flex-col gap-lg lg:flex-row lg:items-start">
                            <x-ui.settings-nav :sections="$settingsSections" />
                            <div class="min-w-0 flex-1">{{ $slot }}</div>
                        </div>
                    @else
                        {{ $slot }}
                    @endif
                </main>

                <footer class="mt-auto flex select-none flex-col items-center justify-between gap-xs border-t border-surface-container-highest bg-surface px-md py-md font-caption text-caption text-on-surface-variant sm:flex-row lg:px-lg">
                    <div class="flex items-center gap-xs">
                        <span class="font-semibold text-on-surface">MENGLISH</span>
                        <span aria-hidden="true">&bull;</span>
                        <span>Hệ thống quản trị giáo dục &amp; học vụ</span>
                    </div>
                    <div class="flex items-center gap-xs">
                        <span>Phát triển bởi</span>
                        <a href="https://vmst.vn" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-0.5 font-semibold text-primary hover:underline">
                            VMST Media <span class="material-symbols-outlined text-[13px] opacity-70">open_in_new</span>
                        </a>
                    </div>
                </footer>
            </div>

            {{-- Toast toàn cục: flash session + event "toast" --}}
            <x-ui.toast />

            {{-- Modal chung cho form/chi tiết tải bằng htmx: <x-ui.button :href="..." modal="md"> --}}
            <x-ui.remote-modal />

            @stack('scripts')
        </div>
    </body>
</html>
