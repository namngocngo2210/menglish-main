{{-- Trang lớp · Học thuật: chương trình, chặng giáo trình, tiến độ và lịch Big Test (trước là "Chi tiết lớp học thuật"). --}}
@php
    $card = 'rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm';
    $label = 'mb-1 block text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70';
@endphp

<div class="space-y-5">
    <section class="{{ $card }}">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">school</span>Chương trình &amp; Tiến độ
            </h2>
            @can('syllabus.manage')
                <a href="{{ route('syllabus.assignments') }}" class="text-xs font-semibold text-primary hover:underline">Giao chặng</a>
            @endcan
        </div>
        <dl class="grid grid-cols-1 gap-5 text-xs sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="{{ $label }}">Tên chương trình</dt>
                <dd class="font-bold text-on-surface">{{ $class->program ?? $class->course?->name ?? 'Chưa cập nhật' }}</dd>
            </div>
            <div>
                <dt class="{{ $label }}">Chặng hiện tại</dt>
                <dd>
                    @if ($currentStage)
                        <x-ui.badge color="primary" :pill="true">{{ $currentStage->stage_name }}</x-ui.badge>
                    @else
                        <span class="font-bold text-on-surface-variant/70">Chưa giao chặng</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="{{ $label }}">Ngày mở chặng</dt>
                <dd class="font-mono font-bold text-on-surface">{{ $currentStage?->created_at?->format('d/m/Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="{{ $label }}">Buổi đã học</dt>
                <dd>
                    @if ($sessionProgress['total'] > 0)
                        <span class="font-mono text-sm font-bold text-primary">{{ $sessionProgress['done'] }} / {{ $sessionProgress['total'] }} buổi</span>
                    @else
                        <span class="font-bold text-on-surface-variant/70">Chưa có lịch học</span>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="{{ $card }}">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">event_available</span>Lịch Big Test
            </h2>
            @can('syllabus.manage')
                <a href="{{ route('syllabus.big-tests.results') }}" class="text-xs font-semibold text-primary hover:underline">Bảng điểm & kết quả</a>
            @endcan
        </div>
        @if ($bigTests->isEmpty())
            <x-ui.empty-state icon="event_busy" title="Lớp chưa có đợt Big Test nào." />
        @else
            <ul class="space-y-2">
                @foreach ($bigTests as $bt)
                    @php $btPast = $bt->scheduled_at && $bt->scheduled_at->isPast(); @endphp
                    <li class="flex flex-col justify-between gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low/70 p-3 sm:flex-row sm:items-center">
                        <div>
                            <span class="block text-xs font-bold text-on-surface">{{ $bt->title }}</span>
                            <span class="font-mono text-[11px] text-on-surface-variant">{{ $bt->scheduled_at?->format('d/m/Y H:i') ?? 'Chưa xếp lịch' }}{{ $bt->room ? ' • '.$bt->room : '' }}</span>
                        </div>
                        <x-ui.badge :color="$btPast ? 'success' : 'primary'">{{ $btPast ? 'Đã diễn ra' : 'Sắp diễn ra' }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
