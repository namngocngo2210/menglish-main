<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900">
        <div class="min-h-screen bg-gray-100 flex flex-col" x-data="{ sidebarOpen: false }">
            @include('layouts.navigation')

            <div class="flex-1 flex flex-col min-w-0 lg:pl-[280px]">
                <!-- Top bar -->
                <header class="min-h-[64px] py-2 bg-white border-b border-gray-200 flex items-center justify-between px-4 sm:px-6 sticky top-0 z-30 gap-4">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <button class="lg:hidden text-gray-500 hover:text-gray-700 p-1.5 rounded-lg border border-gray-200 bg-gray-50 shrink-0" @click="sidebarOpen = true">
                            <span class="material-symbols-outlined text-2xl">menu</span>
                        </button>
                        <div class="min-w-0 flex-1">
                            @isset($header)
                                <div class="min-w-0">{{ $header }}</div>
                            @else
                                <div class="flex items-center gap-2 text-sm text-gray-500 font-medium">
                                    <a href="{{ route('dashboard') }}" class="text-gray-900 font-bold hover:text-primary transition-colors flex items-center gap-1.5">
                                        <span>MEnglish Admin Portal</span>
                                    </a>
                                </div>
                            @endisset
                        </div>
                    </div>

                    <!-- Right header items -->
                    <div class="flex items-center gap-3 shrink-0">

                        <!-- Notification Bell with Scoped Alerts -->
                        @php
                            $notifService = app(\App\Services\NotificationService::class);
                            $currentUser = Auth::user();
                            $unreadNotifsCount = $notifService->getUnreadCount($currentUser);
                            $headerNotifs = $notifService->getUserNotifications($currentUser, 6);
                        @endphp
                        <x-dropdown align="right" width="notification">
                            <x-slot name="trigger">
                                <button class="relative p-2 rounded-xl text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition focus:outline-none" title="Thông báo & Cảnh báo">
                                    <span class="material-symbols-outlined text-2xl">notifications</span>
                                    @if ($unreadNotifsCount > 0)
                                        <span class="absolute top-1 right-1 min-w-[18px] h-[18px] px-1 bg-rose-600 text-white font-mono font-bold text-[10px] rounded-full flex items-center justify-center animate-pulse border-2 border-white shadow-sm">
                                            {{ $unreadNotifsCount > 9 ? '9+' : $unreadNotifsCount }}
                                        </span>
                                    @endif
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="p-3.5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                                    <div class="font-bold text-xs text-gray-900 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-rose-600 text-base">notifications_active</span>
                                        <span>Cảnh Báo Quản Trị</span>
                                        @if ($unreadNotifsCount > 0)
                                            <span class="px-2 py-0.5 rounded-full bg-rose-100 text-rose-800 font-bold text-[10px]">{{ $unreadNotifsCount }} chưa đọc</span>
                                        @endif
                                    </div>
                                    @if ($unreadNotifsCount > 0)
                                        <form action="{{ route('notifications.read-all') }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-[11px] font-semibold text-primary hover:underline">Đã đọc tất cả</button>
                                        </form>
                                    @endif
                                </div>

                                <div class="max-h-[380px] overflow-y-auto divide-y divide-gray-100">
                                    @forelse ($headerNotifs as $hn)
                                        <div class="p-3.5 hover:bg-gray-50 transition flex items-start gap-3 {{ !$hn->is_read ? 'bg-rose-50/20' : '' }}">
                                            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 {{ $hn->badge_color }}">
                                                <span class="material-symbols-outlined text-lg">{{ $hn->icon }}</span>
                                            </div>
                                            <div class="flex-1 min-w-0 space-y-1">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="font-bold text-xs text-gray-900 truncate">{{ $hn->title }}</span>
                                                    @if (!$hn->is_read)
                                                        <span class="w-2 h-2 rounded-full bg-rose-500 shrink-0"></span>
                                                    @endif
                                                </div>
                                                <p class="text-[11px] text-gray-600 leading-snug line-clamp-2">{{ $hn->message }}</p>
                                                <div class="flex items-center justify-between pt-1">
                                                    <span class="text-[10px] text-gray-400 font-mono">{{ $hn->created_at->diffForHumans() }}</span>
                                                    @if ($hn->data && isset($hn->data['link']))
                                                        <a href="{{ $hn->data['link'] }}" class="text-[11px] font-bold text-indigo-600 hover:text-indigo-800 hover:underline inline-flex items-center gap-0.5">
                                                            <span>Xử lý ngay</span>
                                                            <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="p-8 text-center text-xs text-gray-400 space-y-1">
                                            <span class="material-symbols-outlined text-3xl text-gray-300 block mx-auto">notifications_paused</span>
                                            <div>Không có thông báo hoặc cảnh báo nào.</div>
                                        </div>
                                    @endforelse
                                </div>

                                <div class="p-3 border-t border-gray-100 bg-gray-50 text-center">
                                    <a href="{{ route('notifications.index') }}" class="text-xs font-bold text-primary hover:underline inline-flex items-center gap-1">
                                        <span>Xem tất cả cảnh báo &amp; Lead tồn đọng</span>
                                        <span class="material-symbols-outlined text-sm">chevron_right</span>
                                    </a>
                                </div>
                            </x-slot>
                        </x-dropdown>

                        <!-- User Profile Dropdown -->
                        <x-dropdown align="right" width="56">
                            <x-slot name="trigger">
                                <button class="inline-flex items-center gap-1.5 p-1 text-sm font-medium text-gray-700 hover:text-gray-900 rounded-lg">
                                    <span class="w-8 h-8 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold">{{ Str::substr(Auth::user()?->name ?? 'A', 0, 1) }}</span>
                                    <span class="hidden md:inline font-semibold">{{ Auth::user()?->name ?? 'Admin User' }}</span>
                                    <span class="material-symbols-outlined text-sm text-gray-400">expand_more</span>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="px-4 py-2 border-b border-gray-100">
                                    <div class="text-xs font-bold text-gray-900">{{ Auth::user()?->name ?? 'Admin User' }}</div>
                                    <div class="text-[11px] text-gray-500">{{ Auth::user()?->email ?? 'admin@menglish.edu.vn' }}</div>
                                </div>
                                <x-dropdown-link :href="route('profile.edit')">
                                    <div class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">account_circle</span> {{ __('Hồ sơ cá nhân') }}</div>
                                </x-dropdown-link>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                        <div class="flex items-center gap-2 text-rose-600"><span class="material-symbols-outlined text-[18px]">logout</span> {{ __('Đăng xuất') }}</div>
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </header>

                @if (session('status'))
                    <div class="m-4 sm:m-6 mb-0 p-4 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-center gap-3">
                        <span class="material-symbols-outlined text-emerald-600">check_circle</span>
                        <span class="text-sm font-medium">{{ session('status') }}</span>
                    </div>
                @endif

                <!-- Page Content -->
                <main class="flex-1 p-4 sm:p-6 lg:p-8">
                    {{ $slot }}
                </main>

                <!-- Footer -->
                <footer class="mt-auto py-4 px-4 sm:px-6 lg:px-8 border-t border-gray-200/80 bg-white/70 backdrop-blur-xs text-xs text-gray-500 flex flex-col sm:flex-row items-center justify-between gap-2 select-none">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-gray-700">MENGLISH Admin</span>
                        <span class="text-gray-300">&bull;</span>
                        <span>Hệ thống Quản trị Giáo dục &amp; Học vụ</span>
                    </div>
                    <div class="flex items-center gap-1.5 text-gray-500">
                        <span>Phát triển bởi</span>
                        <a 
                            href="https://vmst.vn" 
                            target="_blank" 
                            rel="noopener noreferrer" 
                            class="font-bold text-primary-container hover:text-primary hover:underline transition inline-flex items-center gap-0.5"
                            title="Truy cập website VMST Media"
                        >
                            <span>VMST Media</span>
                            <span class="material-symbols-outlined text-[13px] opacity-70">open_in_new</span>
                        </a>
                    </div>
                </footer>
            </div>

            <!-- Global Floating Toast Notification System -->
            <div x-data="{
                toasts: [],
                add(message, type = 'success') {
                    const id = Date.now();
                    this.toasts.push({ id, message, type });
                    setTimeout(() => this.remove(id), 4500);
                },
                remove(id) {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                }
            }"
            x-init="
                @if (session('success') || session('status'))
                    add('{{ session('success') ?? session('status') }}', 'success');
                @endif
                @if (session('error'))
                    add('{{ session('error') }}', 'error');
                @endif
                @if (session('warning'))
                    add('{{ session('warning') }}', 'warning');
                @endif
                window.addEventListener('toast', e => add(e.detail.message, e.detail.type || 'success'));
            "
            class="fixed top-5 right-5 z-50 flex flex-col gap-2.5 max-w-sm w-full pointer-events-none">
                <template x-for="toast in toasts" :key="toast.id">
                    <div class="pointer-events-auto flex items-center justify-between p-4 rounded-2xl shadow-xl border text-xs font-semibold transform transition-all duration-300 translate-y-0"
                         :class="{
                             'bg-emerald-600 text-white border-emerald-500 shadow-emerald-600/20': toast.type === 'success',
                             'bg-rose-600 text-white border-rose-500 shadow-rose-600/20': toast.type === 'error',
                             'bg-amber-500 text-white border-amber-400 shadow-amber-500/20': toast.type === 'warning',
                             'bg-blue-600 text-white border-blue-500 shadow-blue-600/20': toast.type === 'info'
                         }"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-x-8"
                         x-transition:enter-end="opacity-100 translate-x-0"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-x-0"
                         x-transition:leave-end="opacity-0 translate-x-8">
                        <div class="flex items-center gap-2.5">
                            <span class="material-symbols-outlined text-lg"
                                  x-text="toast.type === 'success' ? 'check_circle' : (toast.type === 'error' ? 'error' : (toast.type === 'warning' ? 'warning' : 'info'))"></span>
                            <span x-text="toast.message" class="leading-snug"></span>
                        </div>
                        <button type="button" @click="remove(toast.id)" class="ml-3 text-white/80 hover:text-white p-0.5 rounded-lg">
                            <span class="material-symbols-outlined text-sm">close</span>
                        </button>
                    </div>
                </template>
            </div>

            @stack('scripts')
        </div>
    </body>
</html>
