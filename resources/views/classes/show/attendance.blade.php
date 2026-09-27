{{-- Trang lớp · Điểm danh & báo cáo buổi: các buổi đã tới ngày (mới nhất trước), trạng thái điểm danh và báo cáo trực lớp. --}}
@php
    $today = today();
    $past = $sessions->filter(fn ($s) => $s->date->lte($today))->sortByDesc(fn ($s) => $s->date->format('Ymd').$s->start_time?->format('Hi'))->values();
    $missing = $past->filter(fn ($s) => ($attendanceStates[$s->id]['key'] ?? null) === 'missing')->count();
    $reportColors = ['pending_approval' => 'warning', 'approved' => 'success', 'rejected' => 'error'];
    $reportLabels = ['pending_approval' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Bị trả về'];
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat-card label="Buổi đã tới ngày" :value="$past->where('status', '!=', 'cancelled')->count()" icon="event_available" />
        <x-ui.stat-card label="Đã điểm danh" :value="$past->filter(fn ($s) => ($attendanceStates[$s->id]['key'] ?? null) === 'done')->count()" tone="success" icon="how_to_reg" />
        <x-ui.stat-card label="Chưa điểm danh" :value="$missing" :tone="$missing ? 'error' : 'default'" icon="pending_actions" />
        <x-ui.stat-card label="Báo cáo buổi đã nộp" :value="$classReports->count()" tone="secondary" icon="assignment_turned_in" />
    </div>

    <x-ui.data-table min-width="760px">
        <x-slot:header>
            <h2 class="text-base font-bold text-on-surface">Điểm danh & báo cáo từng buổi</h2>
            @can('work_task.view')
                <x-ui.button variant="secondary" size="sm" icon="post_add" :href="route('tasks.class-reports.create', ['class_id' => $class->id])">Nộp báo cáo buổi</x-ui.button>
            @endcan
        </x-slot:header>
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Buổi</th>
                    <th>Điểm danh</th>
                    <th>Báo cáo trực lớp</th>
                    <th class="text-right" aria-label="Thao tác"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($past as $s)
                    @php
                        $state = $attendanceStates[$s->id];
                        $report = $classReports->get($s->id);
                    @endphp
                    <tr>
                        <td>
                            <span class="font-code font-bold">{{ $s->date->format('d/m/Y') }}</span>
                            <span class="block font-code text-[11px] text-on-surface-variant">{{ $s->start_time?->format('H:i') }}–{{ $s->end_time?->format('H:i') }}</span>
                        </td>
                        <td>
                            <x-ui.badge :color="$state['color']">{{ $state['label'] }}</x-ui.badge>
                            @if ($s->attendances_count > 0)
                                <span class="ml-1 text-[11px] text-on-surface-variant">{{ $s->attendances_count }} HV</span>
                            @endif
                        </td>
                        <td>
                            @if ($report)
                                <x-ui.badge :color="$reportColors[$report->status] ?? 'neutral'">{{ $reportLabels[$report->status] ?? \App\Support\StatusLabel::for($report->status) }}</x-ui.badge>
                                <span class="ml-1 text-[11px] text-on-surface-variant">{{ $report->reporter?->name }}</span>
                            @elseif ($s->status !== 'cancelled')
                                <span class="text-[11px] text-on-surface-variant/70">Chưa nộp</span>
                            @else
                                <span class="text-[11px] text-on-surface-variant/70">—</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($canRecordAttendance && $s->status !== 'cancelled')
                                <x-ui.button variant="ghost" size="sm" icon="fact_check" :href="route('teacher.attendance', ['classId' => $class->id, 'session' => $s->id])">{{ $s->attendances_count > 0 ? 'Xem điểm danh' : 'Điểm danh' }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4"><x-ui.empty-state icon="event_busy" title="Chưa có buổi học nào tới ngày." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
</div>
