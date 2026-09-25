{{-- Thanh điều hướng dưới của Cổng Giáo viên trên điện thoại (mockup 03_Cong_Giao_Vien/16: Lịch dạy · Bảng công · Thông báo · Cá nhân).
     Trang dùng thanh này cần chừa khoảng trống cuối trang (pb-24 md:pb-0). --}}
@php
    $items = [
        ['route' => 'teacher.home', 'icon' => 'calendar_month', 'label' => 'Lịch dạy', 'active' => request()->routeIs('teacher.home', 'teacher.attendance', 'teacher.remarks', 'teacher.homework', 'teacher.scores')],
        ['route' => 'teacher.general-report', 'icon' => 'history_edu', 'label' => 'Bảng công', 'active' => request()->routeIs('teacher.general-report')],
    ];
    if (auth()->user()?->can('notification.view')) {
        $items[] = ['route' => 'notifications.index', 'icon' => 'notifications', 'label' => 'Thông báo', 'active' => request()->routeIs('notifications.*')];
    }
    $items[] = ['route' => 'profile.edit', 'icon' => 'account_circle', 'label' => 'Cá nhân', 'active' => request()->routeIs('profile.*')];
@endphp
<nav class="fixed inset-x-0 bottom-0 z-40 rounded-t-xl border-t border-outline-variant bg-surface-container-lowest shadow-level-3 md:hidden" aria-label="Điều hướng cổng giáo viên" data-testid="teacher-bottom-nav">
    <ul class="mx-auto grid max-w-md gap-xs px-sm py-xs" style="grid-template-columns: repeat({{ count($items) }}, minmax(0, 1fr))">
        @foreach ($items as $item)
            <li>
                <a href="{{ route($item['route']) }}" @if ($item['active']) aria-current="page" @endif
                   class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs {{ $item['active'] ? 'bg-primary-container text-white' : 'text-on-surface-variant active:bg-surface-variant' }}">
                    <span class="material-symbols-outlined text-[22px]" aria-hidden="true">{{ $item['icon'] }}</span>
                    <span class="text-[11px] font-semibold">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
