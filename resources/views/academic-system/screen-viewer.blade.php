<x-app-layout>
    <x-ui.page-header :title="str_replace('_', ' ', preg_replace('/^\d+_/', '', $safeScreen))" icon="web" :back="route('dashboard')">
        <x-slot:breadcrumbs>
            <span>Hệ thống 58 Màn hình</span>
            <span class="material-symbols-outlined text-[12px]">chevron_right</span>
            <span>{{ $safeCategory }}</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <a href="{{ $rawUrl }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition" title="Mở trang gốc không có menu">
                <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                <span>Toàn màn hình (Không Menu)</span>
            </a>
            <a href="{{ route('syllabus.documents') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                <span class="material-symbols-outlined text-[16px]">menu_book</span>
                <span>Về Giáo trình Flow 2</span>
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Embedded Screen within Admin Layout (Keeps Sidebar Menu Permanent) --}}
    <div class="w-full h-[calc(100vh-140px)] min-h-[700px] bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col">
        <iframe 
            src="{{ $rawUrl }}" 
            class="w-full flex-1 border-0" 
            loading="lazy" 
            title="{{ $screenKey }}"
        ></iframe>
    </div>
</x-app-layout>
