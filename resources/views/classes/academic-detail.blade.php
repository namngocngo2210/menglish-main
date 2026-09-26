<x-app-layout>
    <x-ui.page-header title="Chi tiết lớp học Học thuật" icon="class" :back="route('classes.academic-list')">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="restart_alt" :href="route('classes.trial-booking')">Bắt đầu lại</x-ui.button>
            <x-ui.button icon="add" :href="route('classes.create')">Tạo thêm lớp mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    <div class="max-w-[1200px] mx-auto space-y-6">
        {{-- Class Header & Switcher --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest shadow-sm">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-on-surface tracking-tight">
                        Chi tiết lớp {{ $class?->code ?? '' }}
                    </h2>
                    @if ($class)
                        <x-ui.badge color="success" :pill="true">{{ \App\Support\StatusLabel::for($class->status) }}</x-ui.badge>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs text-on-surface-variant font-semibold">Chuyển lớp:</span>
                <x-ui.select aria-label="Chuyển lớp" class="text-xs font-bold cursor-pointer"
                             onchange="window.location.href = '{{ route('classes.academic-detail') }}/' + this.value">
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($class && $class->id === $c->id) ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->code }})
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>

        {{-- Grid Layout for Top Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- 1. Thông tin chung --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-2 mb-5 border-b border-surface-container-highest pb-3">
                    <span class="material-symbols-outlined text-primary text-[22px]">info</span>
                    <h3 class="text-base font-bold text-on-surface">Thông tin chung</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6 text-xs">
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Lịch học</span>
                        <span class="font-bold text-on-surface">{{ $class?->schedule_text ?? 'Chưa xếp lịch' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">CM quản lý</span>
                        <span class="font-bold text-on-surface">{{ $class?->assistant?->name ?? 'Chưa phân công' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Giáo viên</span>
                        <span class="font-bold text-on-surface">{{ $class?->teacher?->name ?? 'Chưa phân công' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Sĩ số</span>
                        <span class="font-bold text-primary font-mono">{{ $class?->roster_count ?? 0 }}/{{ $class?->max_capacity ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Ngày khai giảng</span>
                        <span class="font-bold text-on-surface font-mono">{{ $class?->start_date ? $class->start_date->format('d/m/Y') : 'Chưa xác định' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Dự kiến kết thúc</span>
                        <span class="font-bold text-on-surface font-mono">{{ $class?->end_date ? $class->end_date->format('d/m/Y') : 'Chưa xác định' }}</span>
                    </div>
                </div>
            </div>

            {{-- 2. Chương trình & Tiến độ --}}
            <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-2 mb-5 border-b border-surface-container-highest pb-3">
                    <span class="material-symbols-outlined text-primary text-[22px]">school</span>
                    <h3 class="text-base font-bold text-on-surface">Chương trình &amp; Tiến độ</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6 text-xs">
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Tên chương trình</span>
                        <span class="font-bold text-on-surface">{{ $class?->program ?? $class?->course?->name ?? 'Chưa cập nhật' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Chặng hiện tại</span>
                        @if ($currentStage)
                            <x-ui.badge color="primary" :pill="true">{{ $currentStage->stage_name }}</x-ui.badge>
                        @else
                            <span class="font-bold text-on-surface-variant/70">Chưa giao chặng</span>
                        @endif
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Ngày mở chặng</span>
                        <span class="font-bold text-on-surface font-mono">{{ $currentStage?->created_at?->format('d/m/Y') ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Buổi đã học</span>
                        @if ($sessionProgress && $sessionProgress['total'] > 0)
                            <span class="font-bold text-on-surface font-mono text-sm text-primary">{{ $sessionProgress['done'] }} / {{ $sessionProgress['total'] }} buổi</span>
                        @else
                            <span class="font-bold text-on-surface-variant/70">Chưa có lịch học</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. Lịch Big Test --}}
        <div class="bg-surface-container-lowest rounded-2xl p-6 border border-surface-container-highest shadow-sm">
            <div class="flex items-center gap-2 mb-6 border-b border-surface-container-highest pb-3">
                <span class="material-symbols-outlined text-primary text-[22px]">event_available</span>
                <h3 class="text-base font-bold text-on-surface">Lịch Big Test</h3>
            </div>

            @if ($bigTests->isEmpty())
                <x-ui.empty-state icon="event_busy" title="Lớp chưa có đợt Big Test nào." />
            @else
                <div class="relative pl-4 space-y-6 before:absolute before:left-[21px] before:top-3 before:bottom-3 before:w-0.5 before:bg-surface-container-high">
                    @foreach ($bigTests as $bt)
                        @php($btPast = $bt->scheduled_at && $bt->scheduled_at->isPast())
                        <div class="relative flex items-start gap-4 group">
                            <div class="w-7 h-7 rounded-full {{ $btPast ? 'bg-tertiary/10' : 'bg-primary-container/10' }} flex items-center justify-center shrink-0 z-10 shadow-2xs">
                                <div class="w-3 h-3 rounded-full {{ $btPast ? 'bg-tertiary' : 'bg-primary-container' }}"></div>
                            </div>
                            <div class="flex-1 flex flex-col sm:flex-row sm:items-center justify-between p-3.5 bg-surface-container-low/70 hover:bg-surface-container-low rounded-xl border border-surface-container-highest/80 transition">
                                <div>
                                    <span class="text-xs font-bold text-on-surface block">{{ $bt->title }}</span>
                                    <span class="text-[11px] text-on-surface-variant font-mono">{{ $bt->scheduled_at?->format('d/m/Y H:i') ?? 'Chưa xếp lịch' }}{{ $bt->room ? ' • '.$bt->room : '' }}</span>
                                </div>
                                <div class="mt-2 sm:mt-0">
                                    <x-ui.badge :color="$btPast ? 'success' : 'primary'">{{ $btPast ? 'Đã diễn ra' : 'Sắp diễn ra' }}</x-ui.badge>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
