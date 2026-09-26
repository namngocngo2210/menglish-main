<x-app-layout>
    <x-ui.page-header :title="'Báo cáo giảng dạy của tôi — T' . $month . '/' . $year" icon="monitoring" :back="route('teacher.home')">
        <x-slot:actions>
            <form method="GET" class="flex items-center gap-2">
                <x-ui.input type="month" name="period" :value="sprintf('%04d-%02d', $year, $month)"
                            onchange="this.form.submit()" aria-label="Tháng báo cáo" />
            </form>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-4">
        {{-- Thẻ tổng quan --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-ui.stat-card label="Tổng giờ dạy tháng" :value="number_format($timesheets->sum('hours'), 1).'h'" :hint="$timesheets->count().' phiên chấm công'" />
            <x-ui.stat-card label="Giờ đã đối soát" :value="number_format($timesheets->where('status', 'valid')->sum('hours'), 1).'h'" tone="success" :hint="$timesheets->where('status', 'valid')->count().' phiên hợp lệ'" />
            <x-ui.stat-card label="Buổi đã điểm danh" :value="$attendanceMarked" tone="primary" hint="bản ghi điểm danh" />
            <x-ui.stat-card label="Điểm mini test đã nhập" :value="$scoresEntered" tone="secondary" hint="bản ghi điểm" />
        </div>

        {{-- Lớp đang phụ trách --}}
        <x-ui.data-table class="shadow-sm">
            <x-slot:header>
                <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">school</span>
                    Lớp tôi phụ trách
                </h2>
            </x-slot:header>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Lớp</th>
                        <th>Khóa học</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($myClasses as $cls)
                        <tr>
                            <td class="font-bold">{{ $cls->name }} <span class="font-mono text-[10px] text-on-surface-variant/70">{{ $cls->code }}</span></td>
                            <td>{{ $cls->course?->name ?? $cls->program ?? '—' }}</td>
                            <td>
                                @if ($cls->teacher_id === auth()->id())
                                    <x-ui.badge color="primary" :pill="true">GV chính</x-ui.badge>
                                @endif
                                @if ($cls->foreign_teacher_id === auth()->id())
                                    <x-ui.badge color="secondary" :pill="true">GVNN</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-on-surface-variant">{{ $cls->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state icon="school" title="Chưa được phân công lớp nào." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        {{-- Chi tiết chấm công theo trạng thái --}}
        <x-ui.data-table class="shadow-sm">
            <x-slot:header>
                <h2 class="text-sm font-bold text-on-surface uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">schedule</span>
                    Chi tiết chấm công tháng {{ sprintf('%02d/%d', $month, $year) }}
                </h2>
            </x-slot:header>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Ngày dạy</th>
                        <th>Lớp</th>
                        <th>Loại</th>
                        <th class="text-right">Giờ</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($timesheets->sortByDesc('teaching_date') as $ts)
                        <tr>
                            <td class="font-mono text-on-surface-variant">{{ optional($ts->teaching_date)->format('d/m/Y') ?? '—' }}</td>
                            <td class="font-semibold">{{ $ts->classModel?->name ?? '—' }}</td>
                            <td>{{ $ts->type }}</td>
                            <td class="text-right font-mono font-bold">{{ number_format((float) $ts->hours, 1) }}h</td>
                            <td>
                                <x-ui.badge :color="$ts->status === 'valid' ? 'success' : ($ts->status === 'invalid' ? 'error' : 'warning')" :pill="true">
                                    {{ $ts->status === 'valid' ? 'Đã duyệt' : ($ts->status === 'invalid' ? 'Bị từ chối' : 'Chờ duyệt') }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="schedule" title="Chưa có phiên chấm công nào trong tháng." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    @include('teacher.partials.bottom-nav')
</x-app-layout>
