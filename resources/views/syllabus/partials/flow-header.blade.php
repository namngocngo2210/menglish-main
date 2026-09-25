@props([
    'activeStep' => 1,
])

@php
    $steps = [
        [
            'num' => 1,
            'title' => 'Quản lý kho tài liệu',
            'role' => 'Admin / Học thuật',
            'role_badge' => 'bg-blue-50 text-blue-700 border-blue-200',
            'icon' => 'folder_open',
            'url' => route('syllabus.documents'),
            'type' => 'internal',
        ],
        [
            'num' => 2,
            'title' => 'Soạn syllabus chặng',
            'role' => 'Học thuật',
            'role_badge' => 'bg-amber-50 text-amber-700 border-amber-200',
            'icon' => 'edit_document',
            'url' => route('syllabus.builder'),
            'type' => 'internal',
        ],
        [
            'num' => 3,
            'title' => 'Giao chặng cho giáo viên',
            'role' => 'Admin',
            'role_badge' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'icon' => 'assignment_ind',
            'url' => route('syllabus.assignments'),
            'type' => 'internal',
        ],
        [
            'num' => 4,
            'title' => 'Xem tài liệu giảng dạy',
            'role' => 'Giáo viên',
            'role_badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'icon' => 'menu_book',
            'url' => route('syllabus.teacher-view'),
            'type' => 'internal',
            'hint' => 'Cổng Giáo viên (PDF Reader)',
        ],
        [
            'num' => 5,
            'title' => 'Đề xuất sửa giáo trình',
            'role' => 'Giáo viên',
            'role_badge' => 'bg-orange-50 text-orange-700 border-orange-200',
            'icon' => 'edit_attributes',
            'url' => route('syllabus.teacher-propose'),
            'type' => 'internal',
            'hint' => 'Cổng Giáo viên',
        ],
        [
            'num' => 6,
            'title' => 'Duyệt đề xuất sửa giáo trình',
            'role' => 'Admin',
            'role_badge' => 'bg-orange-50 text-orange-700 border-orange-200',
            'icon' => 'checklist_rtl',
            'url' => route('syllabus.versions'),
            'type' => 'internal',
        ],
        [
            'num' => 7,
            'title' => 'Xin điều chỉnh tiến độ',
            'role' => 'Giáo viên',
            'role_badge' => 'bg-amber-50 text-amber-700 border-amber-200',
            'icon' => 'speed',
            'url' => route('syllabus.teacher-adjust'),
            'type' => 'internal',
            'hint' => 'Cổng Giáo viên',
        ],
        [
            'num' => 8,
            'title' => 'Duyệt điều chỉnh tiến độ',
            'role' => 'Admin',
            'role_badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'icon' => 'rule',
            'url' => route('syllabus.adjustment-requests'),
            'type' => 'internal',
        ],
    ];

    $currentStepData = $steps[$activeStep - 1] ?? $steps[0];
@endphp

<div class="bg-surface-container-lowest border border-gray-200 rounded-2xl shadow-sm mb-6 overflow-hidden">
    <!-- Top Flow Status Bar -->
    <div class="px-5 py-3.5 bg-gradient-to-r from-amber-500/10 via-orange-500/5 to-transparent border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-xl bg-primary text-white flex items-center justify-center font-bold text-sm shadow-sm">
                <span class="material-symbols-outlined text-[18px]">menu_book</span>
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-primary">Flow 2</span>
                    <span class="text-xs text-gray-400">•</span>
                    <h2 class="text-sm font-bold text-gray-900 tracking-tight">Quản lý Giáo trình &amp; Phân bổ Syllabus</h2>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">8 bước chuẩn</span>
                </div>
                <p class="text-[11px] text-gray-500 hidden sm:block">Quy trình biên soạn khung syllabus, giao chặng học cho giáo viên và xử lý đề xuất chỉnh sửa giáo trình</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500 font-medium hidden md:inline">Đang xem:</span>
            <span class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary font-bold text-xs flex items-center gap-1.5 border border-primary/20">
                <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
                <span>#{{ $currentStepData['num'] }} {{ $currentStepData['title'] }}</span>
            </span>

        </div>
    </div>

    <!-- 8 Steps Horizontal Scrollable Ribbon -->
    <div class="p-3 bg-gray-50/50 overflow-x-auto custom-scrollbar">
        <div class="flex items-center gap-2 min-w-max">
            @foreach ($steps as $step)
                @php
                    $isActive = ($step['num'] === (int)$activeStep);
                @endphp
                <a href="{{ $step['url'] }}" class="flex items-center gap-2.5 px-3 py-2 rounded-xl border text-xs transition-all {{ $isActive ? 'bg-white border-primary shadow-sm ring-2 ring-primary/10 text-gray-900 font-bold' : 'bg-white/80 border-gray-200 hover:border-gray-300 text-gray-600 hover:bg-white hover:text-gray-900' }}">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center font-bold text-[11px] shrink-0 {{ $isActive ? 'bg-primary text-white shadow-sm' : 'bg-gray-100 text-gray-600' }}">
                        {{ $step['num'] }}
                    </div>
                    <div class="flex flex-col text-left">
                        <span class="truncate max-w-[140px] leading-tight">{{ $step['title'] }}</span>
                        <div class="flex items-center gap-1 mt-0.5">
                            <span class="text-[9px] px-1.5 py-0.2 rounded border font-medium {{ $step['role_badge'] }}">{{ $step['role'] }}</span>
                            @if(isset($step['hint']))
                                <span class="text-[9px] text-gray-400">· {{ $step['hint'] }}</span>
                            @endif
                        </div>
                    </div>
                    @if($step['type'] === 'portal')
                        <span class="material-symbols-outlined text-[14px] text-gray-400 ml-0.5">arrow_outward</span>
                    @endif
                </a>

                @if(!$loop->last)
                    <span class="material-symbols-outlined text-gray-300 text-[14px] shrink-0">arrow_forward</span>
                @endif
            @endforeach
        </div>
    </div>
</div>
