@props([
    'activeStep' => 1,
])

@php
    $steps = [
        [
            'num' => 1,
            'title' => 'Đặt lịch học thử',
            'role' => 'Admin / Tuyển sinh',
            'role_badge' => 'bg-orange-50 text-orange-700 border-orange-200',
            'icon' => 'event_available',
            'url' => route('classes.trial-booking'),
            'type' => 'internal',
            'hint' => 'Popup chọn lớp & buổi',
        ],
        [
            'num' => 2,
            'title' => 'Tạo lớp mới',
            'role' => 'Admin / Học vụ',
            'role_badge' => 'bg-blue-50 text-blue-700 border-blue-200',
            'icon' => 'group_add',
            'url' => route('classes.create'),
            'type' => 'internal',
            'hint' => 'Thông tin lớp, lịch & nhân sự',
        ],
        [
            'num' => 3,
            'title' => 'Hồ sơ lớp học',
            'role' => 'Admin / Học vụ',
            'role_badge' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'icon' => 'school',
            'url' => route('classes.profile'),
            'type' => 'internal',
            'hint' => 'Thông tin chung & DS học sinh',
        ],
        [
            'num' => 4,
            'title' => 'Sơ đồ khối lớp',
            'role' => 'Học thuật',
            'role_badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'icon' => 'dashboard',
            'url' => route('classes.academic-overview'),
            'type' => 'internal',
            'hint' => 'Tổng quan số lớp theo khối',
        ],
        [
            'num' => 5,
            'title' => 'Danh sách lớp chi tiết',
            'role' => 'Học vụ / Học thuật',
            'role_badge' => 'bg-purple-50 text-purple-700 border-purple-200',
            'icon' => 'table_view',
            'url' => route('classes.academic-list'),
            'type' => 'internal',
            'hint' => 'Tiến độ, Big Test, Dự giờ',
        ],
        [
            'num' => 6,
            'title' => 'Chi tiết lớp học thuật',
            'role' => 'Học thuật',
            'role_badge' => 'bg-amber-50 text-amber-700 border-amber-200',
            'icon' => 'class',
            'url' => route('classes.academic-detail'),
            'type' => 'internal',
            'hint' => 'Timeline buổi học & Big Test',
        ],
    ];

    $currentStepData = $steps[$activeStep - 1] ?? $steps[0];
@endphp

<div class="bg-surface-container-lowest border border-gray-200 rounded-2xl shadow-sm mb-6 overflow-hidden">
    <!-- Top Flow Status Bar -->
    <div class="px-5 py-3.5 bg-gradient-to-r from-orange-500/10 via-primary-container/5 to-transparent border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-xl bg-primary-container text-white flex items-center justify-center font-bold text-sm shadow-sm">
                <span class="material-symbols-outlined text-[18px]">meeting_room</span>
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-primary">Quy trình 1</span>
                    <span class="text-xs text-gray-400">•</span>
                    <h2 class="text-sm font-bold text-gray-900 tracking-tight">Tuyển sinh, Khai giảng &amp; Quản lý Lớp học</h2>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-orange-100 text-primary border border-orange-200">6 bước chuẩn BA</span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500 hidden sm:inline">Bước hiện tại:</span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-primary-container text-white shadow-2xs">
                <span>#{{ $currentStepData['num'] }}</span>
                <span>{{ $currentStepData['title'] }}</span>
            </span>
            <span class="text-xs px-2 py-0.5 rounded-md border font-semibold {{ $currentStepData['role_badge'] }}">
                {{ $currentStepData['role'] }}
            </span>
        </div>
    </div>

    <!-- 6-Step Ribbon Navigation -->
    <div class="overflow-x-auto custom-scrollbar bg-white p-2">
        <div class="flex items-center gap-2 min-w-max">
            @foreach($steps as $s)
                @php
                    $isCurrent = $s['num'] === $activeStep;
                    $isPassed = $s['num'] < $activeStep;
                @endphp
                <a href="{{ $s['url'] }}"
                   class="group flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-left transition-all relative {{ $isCurrent ? 'bg-primary-container/10 border-2 border-primary-container shadow-2xs' : ($isPassed ? 'bg-gray-50/70 hover:bg-gray-100/70 border border-gray-200 text-gray-700' : 'bg-white hover:bg-gray-50 border border-gray-100 text-gray-500') }}">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center font-bold text-xs shrink-0 transition-colors {{ $isCurrent ? 'bg-primary-container text-white shadow-xs' : ($isPassed ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-600 group-hover:bg-gray-300') }}">
                        @if($isPassed)
                            <span class="material-symbols-outlined text-[15px]">check</span>
                        @else
                            {{ $s['num'] }}
                        @endif
                    </div>
                    <div>
                        <div class="text-xs font-bold leading-tight flex items-center gap-1.5 {{ $isCurrent ? 'text-primary' : 'text-gray-900 group-hover:text-primary' }}">
                            <span>{{ $s['title'] }}</span>
                        </div>
                        <div class="text-[10px] text-gray-400 font-medium truncate max-w-[130px]">
                            {{ $s['hint'] ?? $s['role'] }}
                        </div>
                    </div>
                </a>

                @if(!$loop->last)
                    <div class="text-gray-300 shrink-0 select-none">
                        <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
