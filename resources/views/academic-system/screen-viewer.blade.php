<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('dashboard') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <div class="flex items-center gap-2 text-[11px] text-gray-400 font-medium">
                        <span>Hệ thống 58 Màn hình</span>
                        <span class="material-symbols-outlined text-[12px]">chevron_right</span>
                        <span class="text-gray-600">{{ $safeCategory }}</span>
                    </div>
                    <h1 class="text-base font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">web</span>
                        <span>{{ str_replace('_', ' ', preg_replace('/^\d+_/', '', $safeScreen)) }}</span>
                    </h1>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ $rawUrl }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition" title="Mở trang gốc không có menu">
                    <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                    <span>Toàn màn hình (Không Menu)</span>
                </a>
                <a href="{{ route('syllabus.documents') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[16px]">menu_book</span>
                    <span>Về Giáo trình Flow 2</span>
                </a>
            </div>
        </div>
    </x-slot>

    <!-- Embedded Screen within Admin Layout (Keeps Sidebar Menu Permanent) -->
    <div class="w-full h-[calc(100vh-140px)] min-h-[700px] bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col">
        <iframe 
            src="{{ $rawUrl }}" 
            class="w-full flex-1 border-0" 
            loading="lazy" 
            title="{{ $screenKey }}"
        ></iframe>
    </div>
</x-app-layout>
