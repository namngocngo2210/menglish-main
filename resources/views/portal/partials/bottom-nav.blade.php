@props([
    'activeTab' => 'home',
    'student' => null
])

@php
    $studentId = $student?->id ?? null;
@endphp

<nav class="fixed bottom-0 left-0 right-0 max-w-[430px] mx-auto w-full flex justify-around items-center py-2 bg-surface-container-lowest dark:bg-inverse-surface border-t border-surface-container-highest dark:border-inverse-surface shadow-lg z-50 rounded-t-2xl">
    {{-- Tab 1: Trang chủ --}}
    <a href="{{ route('portal.student.home', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-3 transition-transform duration-150 active:scale-90 {{ $activeTab === 'home' ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ $activeTab === 'home' ? 1 : 0 }};">home</span>
        <span class="text-[11px] tracking-wide">Trang chủ</span>
    </a>

    {{-- Tab 2: Học tập --}}
    <a href="{{ route('portal.student.homework', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-3 transition-transform duration-150 active:scale-90 {{ in_array($activeTab, ['learning', 'homework', 'pronunciation']) ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ in_array($activeTab, ['learning', 'homework', 'pronunciation']) ? 1 : 0 }};">menu_book</span>
        <span class="text-[11px] tracking-wide">Học tập</span>
    </a>

    {{-- Tab 3: Khảo sát --}}
    <a href="{{ route('portal.student.survey', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-3 transition-transform duration-150 active:scale-90 {{ in_array($activeTab, ['survey', 'feedback']) ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ in_array($activeTab, ['survey', 'feedback']) ? 1 : 0 }};">assignment</span>
        <span class="text-[11px] tracking-wide">Khảo sát</span>
    </a>

    {{-- Tab 4: Thông báo --}}
    <a href="{{ route('portal.student.notifications', ['studentId' => $studentId]) }}"
       class="flex flex-col items-center justify-center py-1 px-3 transition-transform duration-150 active:scale-90 {{ $activeTab === 'notifications' ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface' }}">
        <div class="relative">
            <span class="material-symbols-outlined text-[24px] mb-0.5" style="font-variation-settings: 'FILL' {{ $activeTab === 'notifications' ? 1 : 0 }};">notifications</span>
            <span class="absolute -top-1 -right-1 bg-error text-white text-[10px] font-bold w-4 h-4 rounded-full flex items-center justify-center shadow-xs">3</span>
        </div>
        <span class="text-[11px] tracking-wide">Thông báo</span>
    </a>
</nav>
