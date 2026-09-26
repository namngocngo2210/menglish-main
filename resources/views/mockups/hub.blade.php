<x-app-layout>
    <x-ui.page-header title="MEnglish UI Mockup Navigator & Design Hub" icon="auto_stories" description="Tổng hợp toàn bộ 65 màn hình mockup giao diện và tính năng Laravel tương ứng">
        <x-slot:badges>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                100% Laravel Integrated
            </span>
        </x-slot:badges>
        <x-slot:actions>
            <x-ui.button icon="dashboard_customize" :href="route('academic-system.index')">Gallery 58 Màn Mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6" x-data="{
        search: '',
        activeTab: 'all',
        previewModalOpen: false,
        previewSrc: '',
        previewTitle: '',
        previewRoute: '',
        openPreview(src, title, route) {
            this.previewSrc = '{{ asset('') }}' + src;
            this.previewTitle = title;
            this.previewRoute = route;
            this.previewModalOpen = true;
        }
    }">
        {{-- Search & Filter bar --}}
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
            {{-- Search input --}}
            <div class="relative w-full md:w-96">
                <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xl">search</span>
                <input
                    type="text"
                    x-model="search"
                    placeholder="Tìm nhanh trong 65 màn hình (vd: pipeline, giao việc, trợ giảng...)"
                    class="w-full pl-10 pr-4 py-2 text-xs rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition"
                />
            </div>

            {{-- Quick module filters --}}
            <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0">
                <button
                    @click="activeTab = 'all'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'all' ? 'bg-navy text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Tất cả (71)
                </button>
                <button
                    @click="activeTab = 'classes'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'classes' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Tuyển sinh &amp; Lớp (6)
                </button>
                <button
                    @click="activeTab = 'tasks'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'tasks' ? 'bg-orange-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Phân công &amp; TA (9)
                </button>
                <button
                    @click="activeTab = 'crm'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'crm' ? 'bg-primary-container text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    CRM (10)
                </button>
                <button
                    @click="activeTab = 'tuition'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'tuition' ? 'bg-amber-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Học phí (8)
                </button>
                <button
                    @click="activeTab = 'payroll'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'payroll' ? 'bg-cyan-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Lương &amp; CC (12)
                </button>
                <button
                    @click="activeTab = 'syllabus'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'syllabus' ? 'bg-purple-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Syllabus (8)
                </button>
                <button
                    @click="activeTab = 'tests'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    :class="activeTab === 'tests' ? 'bg-teal-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                >
                    Đề Test (5)
                </button>
            </div>
        </div>

        {{-- Modules Grid --}}
        <div class="space-y-8">
            @foreach ($modules as $module)
                <div
                    class="space-y-3"
                    x-show="(activeTab === 'all' || activeTab === '{{ $module['id'] }}' || (activeTab === 'tests' && ('{{ $module['id'] }}' === 'placement-tests' || '{{ $module['id'] }}' === 'levels')))"
                >
                    <div class="flex items-center justify-between border-b border-gray-200 pb-2">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-xl">{{ $module['icon'] }}</span>
                            <h2 class="text-base font-bold text-gray-900">{{ $module['name'] }}</h2>
                        </div>
                        <span class="text-xs font-semibold bg-gray-100 text-gray-600 px-2.5 py-0.5 rounded-full">{{ $module['badge'] }}</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        @foreach ($module['screens'] as $screen)
                            <div
                                class="bg-white rounded-xl border border-gray-200 p-4 hover:shadow-md hover:border-primary-container/50 transition-all flex flex-col justify-between group"
                                x-show="!search || '{{ strtolower($screen['name'] . ' ' . $screen['type']) }}'.includes(search.toLowerCase())"
                            >
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-mono text-xs font-bold px-2 py-0.5 rounded bg-orange-50 text-primary border border-orange-200">
                                            #{{ $screen['num'] }}
                                        </span>
                                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded
                                            @if($screen['type'] === 'kanban') bg-emerald-50 text-emerald-700
                                            @elseif($screen['type'] === 'table') bg-blue-50 text-blue-700
                                            @elseif($screen['type'] === 'form') bg-amber-50 text-amber-700
                                            @elseif($screen['type'] === 'wizard') bg-cyan-50 text-cyan-700
                                            @elseif($screen['type'] === 'report') bg-purple-50 text-purple-700
                                            @elseif($screen['type'] === 'detail') bg-indigo-50 text-indigo-700
                                            @elseif($screen['type'] === 'modal') bg-rose-50 text-rose-700
                                            @else bg-gray-100 text-gray-700 @endif
                                        ">
                                            {{ $screen['type'] }}
                                        </span>
                                    </div>

                                    <h3 class="font-bold text-sm text-gray-900 group-hover:text-primary transition line-clamp-1 mb-1">
                                        {{ $screen['name'] }}
                                    </h3>
                                    <p class="text-[11px] text-gray-400 font-mono truncate mb-4">
                                        {{ $screen['src'] }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-2 pt-3 border-t border-gray-100">
                                    <a
                                        href="{{ isset($screen['params']) ? route($screen['route'], $screen['params']) : route($screen['route']) }}"
                                        class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary-container hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition"
                                    >
                                        <span class="material-symbols-outlined text-[15px]">play_circle</span>
                                        <span>Mở màn hình</span>
                                    </a>

                                    <button
                                        @click="openPreview('{{ str_starts_with($screen['src'], 'roundcuoi-kieulien') ? $screen['src'] : 'ui-full-tinh-nang-menglish/' . $screen['src'] }}', '{{ $screen['name'] }}', '{{ isset($screen['params']) ? route($screen['route'], $screen['params']) : route($screen['route']) }}')"
                                        title="Xem Preview HTML gốc"
                                        class="p-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-900 transition"
                                    >
                                        <span class="material-symbols-outlined text-[18px]">preview</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Interactive Preview Modal --}}
        <div
            x-show="previewModalOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-black/70 backdrop-blur-sm"
            @keydown.escape.window="previewModalOpen = false"
        >
            <div
                class="bg-white rounded-2xl shadow-2xl border border-gray-200 w-full max-w-6xl h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200"
                @click.outside="previewModalOpen = false"
            >
                {{-- Modal Header --}}
                <div class="h-14 px-6 bg-navy text-white flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-primary text-xl">devices</span>
                        <div>
                            <h3 class="text-sm font-bold text-white" x-text="previewTitle"></h3>
                            <span class="text-[10px] text-white/50 font-mono" x-text="previewSrc"></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <a
                            :href="previewRoute"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary-container hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition"
                        >
                            <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                            <span>Mở Live Controller</span>
                        </a>
                        <button
                            @click="previewModalOpen = false"
                            class="p-1.5 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition"
                        >
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                </div>

                {{-- Iframe Container --}}
                <div class="flex-1 bg-gray-100 p-2 overflow-hidden">
                    <iframe :src="previewSrc" class="w-full h-full rounded-xl border border-gray-200 bg-white shadow-inner" frameborder="0"></iframe>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
