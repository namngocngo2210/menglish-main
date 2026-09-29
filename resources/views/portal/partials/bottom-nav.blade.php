@props([
    'activeTab' => 'home',
    'student' => null
])

@php
    $studentId = $student?->id ?? null;
    $unreadCount = \App\Support\Portal\PortalNotifications::unreadCount($student);
@endphp

<nav aria-label="Điều hướng cổng học viên" class="md:hidden fixed bottom-0 left-0 right-0 max-w-[430px] mx-auto w-full flex justify-around items-center py-2 bg-surface-container-lowest dark:bg-inverse-surface border-t border-surface-container-highest dark:border-inverse-surface shadow-lg z-50 rounded-t-2xl">
    {{-- Tab 1: Trang chủ --}}
    <a href="{{ route('portal.student.home', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-2 transition-transform duration-150 active:scale-90 {{ $activeTab === 'home' ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ $activeTab === 'home' ? 1 : 0 }};">home</span>
        <span class="text-xs tracking-wide">Trang chủ</span>
    </a>

    {{-- Tab 2: Học tập --}}
    <a href="{{ route('portal.student.homework', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-2 transition-transform duration-150 active:scale-90 {{ in_array($activeTab, ['learning', 'homework']) ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ in_array($activeTab, ['learning', 'homework']) ? 1 : 0 }};">menu_book</span>
        <span class="text-xs tracking-wide">Học tập</span>
    </a>

    {{-- Tab 3: Phát âm (trước chỉ vào được qua dải tab trên đầu, bị ẩn trên điện thoại) --}}
    <a href="{{ route('portal.student.pronunciation', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-2 transition-transform duration-150 active:scale-90 {{ $activeTab === 'pronunciation' ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ $activeTab === 'pronunciation' ? 1 : 0 }};">mic</span>
        <span class="text-xs tracking-wide">Phát âm</span>
    </a>

    {{-- Tab 4: Khảo sát --}}
    <a href="{{ route('portal.student.survey', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-2 transition-transform duration-150 active:scale-90 {{ in_array($activeTab, ['survey', 'feedback']) ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ in_array($activeTab, ['survey', 'feedback']) ? 1 : 0 }};">assignment</span>
        <span class="text-xs tracking-wide">Khảo sát</span>
    </a>

    {{-- Tab 5: Thông báo --}}
    <a href="{{ route('portal.student.notifications', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-2 transition-transform duration-150 active:scale-90 {{ $activeTab === 'notifications' ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <div class="relative">
            <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ $activeTab === 'notifications' ? 1 : 0 }};">notifications</span>
            @if ($unreadCount > 0)
                <span class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-error px-1 text-xs font-bold text-white shadow-xs" aria-label="{{ $unreadCount }} thông báo chưa đọc">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
            @endif
        </div>
        <span class="text-xs tracking-wide">Thông báo</span>
    </a>
</nav>
