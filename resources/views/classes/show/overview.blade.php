{{-- Trang lớp · Tổng quan: thông tin chung + sĩ số + buổi kế tiếp + học thuật + sự vụ (gộp Hồ sơ lớp & Chi tiết học thuật). --}}
@php
    $card = 'rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm';
    $label = 'mb-1 block text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70';
    $tabUrl = fn (string $t) => route('classes.show', ['id' => $class->id, 'tab' => $t]);
@endphp

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <section class="{{ $card }} lg:col-span-2">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">info</span>Thông tin chung
            </h2>
            @if ($canManage)
                <a href="{{ route('classes.edit', $class->id) }}" class="text-xs font-semibold text-primary hover:underline">Sửa thông tin lớp</a>
            @endif
        </div>
        <dl class="grid grid-cols-1 gap-5 text-xs sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'Chi nhánh' => $class->branch?->name ?? 'Chưa cập nhật',
                'CM quản lý' => $class->assistant?->name ?? 'Chưa phân công',
                'Chương trình' => $class->program ?? $class->course?->name ?? 'Chưa cập nhật',
                'Cấp độ' => $class->level ?? 'Chưa cập nhật',
                'Phòng học' => $class->room ?? 'Chưa cập nhật',
                'Giáo viên chính' => $class->teacher?->name ?? 'Chưa phân công',
                'Lịch học' => $class->schedule_text ?? 'Chưa cập nhật',
                'Khai giảng → Kết thúc' => ($class->start_date?->format('d/m/Y') ?? '—').' → '.($class->end_date?->format('d/m/Y') ?? '—'),
            ] as $name => $value)
                <div>
                    <dt class="{{ $label }}">{{ $name }}</dt>
                    <dd class="font-bold text-on-surface">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="{{ $card }}">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">group</span>Sĩ số
            </h2>
            <a href="{{ $tabUrl('students') }}" class="text-xs font-semibold text-primary hover:underline">Xem học viên</a>
        </div>
        <p class="text-xl font-bold text-primary" data-seats="{{ $class->id }}">{{ $seat['occupied'] }} / {{ $seat['capacity'] ?: '∞' }} <span class="text-xs font-semibold text-on-surface-variant">học viên</span></p>
        <p class="mt-1 text-[11px] {{ $seat['left'] === 0 ? 'font-bold text-error' : 'text-on-surface-variant' }}">
            {{ $seat['left'] === null ? 'Không giới hạn sĩ số' : ($seat['left'] === 0 ? 'Đã đủ sĩ số' : 'Còn '.$seat['left'].' chỗ') }}
            · Ngưỡng khai giảng {{ $seat['min'] }}
        </p>
        @if ($seat['needed'] > 0)
            <p class="mt-1 text-[11px] font-semibold text-warning">Cần thêm {{ $seat['needed'] }} học viên để khai giảng</p>
        @endif
    </section>

    <section class="{{ $card }}">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">event</span>Buổi kế tiếp
            </h2>
            <a href="{{ $tabUrl('schedule') }}" class="text-xs font-semibold text-primary hover:underline">Lịch & buổi học</a>
        </div>
        @if ($nextSession)
            <p class="text-sm font-bold text-on-surface">{{ $nextSession->date->format('d/m/Y') }} · {{ $nextSession->start_time?->format('H:i') }}–{{ $nextSession->end_time?->format('H:i') }}</p>
            <p class="mt-1 text-xs text-on-surface-variant">{{ $nextSession->room ?: $class->room ?: 'Chưa có phòng' }} · {{ $nextSession->teacher?->name ?? $class->teacher?->name ?? 'Chưa phân công GV' }}</p>
        @else
            <p class="text-xs text-on-surface-variant">{{ $class->status === 'pending_schedule' ? 'Lớp chưa có lịch học.' : 'Không còn buổi học nào sắp tới.' }}</p>
        @endif
        <p class="mt-3 text-xs text-on-surface-variant">Đã học <span class="font-code font-bold text-on-surface">{{ $sessionProgress['done'] }}/{{ $sessionProgress['total'] }}</span> buổi</p>
    </section>

    <section class="{{ $card }}">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">school</span>Học thuật
            </h2>
            <a href="{{ $tabUrl('academic') }}" class="text-xs font-semibold text-primary hover:underline">Chi tiết</a>
        </div>
        <dl class="space-y-3 text-xs">
            <div>
                <dt class="{{ $label }}">Chặng hiện tại</dt>
                <dd class="font-bold text-on-surface">{{ $currentStage?->stage_name ?? 'Chưa giao chặng' }}</dd>
            </div>
            <div>
                <dt class="{{ $label }}">Big Test sắp tới</dt>
                <dd class="font-bold text-on-surface">{{ $nextBigTest ? $nextBigTest->title.' · '.$nextBigTest->scheduled_at->format('d/m/Y') : 'Chưa có' }}</dd>
            </div>
        </dl>
    </section>

    <section class="{{ $card }}">
        <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
            <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">report</span>Sự vụ
            </h2>
            <a href="{{ $tabUrl('incidents') }}" class="text-xs font-semibold text-primary hover:underline">Xem sự vụ</a>
        </div>
        <p class="text-xl font-bold {{ $openIncidents ? 'text-warning' : 'text-on-surface' }}">{{ $openIncidents }}</p>
        <p class="text-xs text-on-surface-variant">sự vụ đang mở của lớp</p>
    </section>
</div>
