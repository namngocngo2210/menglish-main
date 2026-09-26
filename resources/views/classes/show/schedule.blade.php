{{-- Trang lớp · Lịch & buổi học: lịch cố định + toàn bộ buổi học của lớp; cấu hình lịch mở màn TKB lọc sẵn lớp này. --}}
@php
    $today = today();
    $upcoming = $sessions->filter(fn ($s) => $s->date->gte($today) && $s->status !== 'cancelled')->count();
@endphp

<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-base font-bold text-on-surface">Lịch học</h2>
            <p class="text-xs text-on-surface-variant">{{ $class->schedule_text ?: 'Lớp chưa có lịch học cố định.' }}
                · {{ $sessions->where('status', '!=', 'cancelled')->count() }} buổi, còn {{ $upcoming }} buổi sắp tới</p>
        </div>
        @if ($canManage)
            @can('work_task.view')
                <x-ui.button :variant="$class->status === 'pending_schedule' ? 'primary' : 'secondary'" icon="edit_calendar"
                             :href="route('tasks.schedule-config', ['class_id' => $class->id])">{{ $class->scheduleConfig ? 'Sửa lịch' : 'Cấu hình lịch' }}</x-ui.button>
            @endcan
        @endif
    </div>

    <x-ui.data-table min-width="760px">
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Ngày</th>
                    <th>Giờ</th>
                    <th>Phòng</th>
                    <th>Giáo viên</th>
                    <th>Trợ giảng</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $s)
                    @php $isToday = $s->date->isSameDay($today); @endphp
                    <tr class="{{ $isToday ? 'bg-primary-container/5' : '' }} {{ $s->status === 'cancelled' ? 'text-on-surface-variant/60' : '' }}">
                        <td class="font-code font-bold">{{ $s->date->format('d/m/Y') }}@if ($isToday) <span class="ml-1 text-[10px] font-semibold text-primary">Hôm nay</span>@endif</td>
                        <td class="font-code">{{ $s->start_time?->format('H:i') }}–{{ $s->end_time?->format('H:i') }}</td>
                        <td>{{ $s->room ?: ($class->room ?: '—') }}</td>
                        <td>{{ collect([$s->teacher?->name, $s->foreignTeacher?->name])->filter()->implode(' + ') ?: '—' }}</td>
                        <td>{{ $s->assistant?->name ?? '—' }}</td>
                        <td>
                            @if ($s->status === 'cancelled')
                                <x-ui.badge color="neutral">{{ $s->holiday ? 'Nghỉ lễ' : 'Đã hủy' }}</x-ui.badge>
                            @elseif ($s->date->lt($today))
                                <x-ui.badge color="success">Đã diễn ra</x-ui.badge>
                            @else
                                <x-ui.badge color="info">Sắp diễn ra</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"><x-ui.empty-state icon="event_busy" title="Lớp chưa có buổi học nào. Cấu hình lịch để sinh buổi học." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
</div>
