<x-app-layout>
    <x-ui.page-header title="Danh sách lớp chi tiết Học thuật" icon="table_view" :back="route('classes.academic-overview')">
        <x-slot:actions>
            <x-ui.button icon="class" :href="route('classes.academic-detail')">Chi tiết lớp học</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    <div class="space-y-6">
        {{-- Header & Toolbar --}}
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-surface-container-lowest p-4 rounded-2xl border border-surface-container-highest shadow-sm">
            <div>
                <h2 class="text-base font-bold text-on-surface">Danh sách lớp học</h2>
            </div>

            {{-- Toolbar Filters --}}
            <form method="GET" action="{{ route('classes.academic-list') }}" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full md:w-auto">
                @if ($levelFilter)
                    <input type="hidden" name="level" value="{{ $levelFilter }}">
                @endif
                <div class="w-full sm:w-64">
                    <x-ui.input name="search" icon="search" :value="$search" class="text-xs" placeholder="Tìm kiếm lớp..." />
                </div>

                <div class="flex gap-2 w-full sm:w-auto">
                    <x-ui.select name="branch_id" onchange="this.form.submit()" class="text-xs" placeholder="Tất cả Chi nhánh"
                                 :value="$branchFilter" :options="$branches->pluck('name', 'id')" />

                    <x-ui.select name="program" onchange="this.form.submit()" class="text-xs" placeholder="Tất cả Chương trình"
                                 :value="$programFilter" :options="collect($programs)->mapWithKeys(fn ($p) => [$p => $p])" />
                </div>
            </form>
        </div>

        {{-- Data Table Card --}}
        <x-ui.data-table>
                <table class="text-xs whitespace-nowrap">
                    <thead>
                        <tr class="sticky top-0 z-10">
                            <th>Tên lớp</th>
                            <th>Lịch học</th>
                            <th>CM quản lý</th>
                            <th>Giáo viên</th>
                            <th>Ngày khai giảng</th>
                            <th>Dự kiến kết thúc</th>
                            <th class="text-center">SL HV<br/><span class="text-[9px] font-normal lowercase">(BĐ/HT)</span></th>
                            <th>Tình trạng lớp</th>
                            <th>Tiến độ</th>
                            <th class="text-center">Tình trạng sĩ số</th>
                            <th class="text-center">Big Test</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($classes as $c)
                            @php
                                $studentCount = $c->roster_count;
                                $capacity = $c->max_capacity;
                                $totalSessions = (int) $c->total_sessions_count;
                                $doneSessions = (int) $c->done_sessions_count;
                                $progress = $totalSessions > 0 ? (int) round($doneSessions * 100 / $totalSessions) : null;
                                $classBigTests = $bigTests->get($c->id, collect());
                                $statusMap = [
                                    'active' => ['Đang hoạt động', 'success'],
                                    'upcoming' => ['Sắp khai giảng', 'secondary'],
                                    'pending_schedule' => ['Chờ lịch', 'warning'],
                                    'completed' => ['Đã kết thúc', 'neutral'],
                                ];
                                [$statusLabel, $statusColor] = $statusMap[$c->status] ?? [\App\Support\StatusLabel::for($c->status), 'neutral'];
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('classes.academic-detail', ['id' => $c->id]) }}" class="font-bold text-secondary hover:text-primary transition font-mono">
                                        {{ $c->code }}
                                    </a>
                                    <div class="text-[10px] text-on-surface-variant font-normal truncate max-w-[140px]">{{ $c->name }}</div>
                                </td>
                                <td class="text-on-surface-variant font-medium">
                                    {{ $c->schedule_text ?: 'Chưa cập nhật' }}
                                </td>
                                <td class="text-on-surface">
                                    {{ $c->assistant?->name ?? 'Chưa phân công' }}
                                </td>
                                <td class="text-on-surface font-medium">
                                    {{ $c->teacher?->name ?? 'Chưa phân công' }}
                                </td>
                                <td class="text-on-surface-variant font-mono">
                                    {{ $c->start_date?->format('d/m/Y') ?? 'Chưa cập nhật' }}
                                </td>
                                <td class="text-on-surface-variant font-mono">
                                    {{ $c->end_date?->format('d/m/Y') ?? 'Chưa cập nhật' }}
                                </td>
                                <td class="text-center font-bold font-mono">
                                    {{ $studentCount }}/{{ $capacity ?? '—' }}
                                </td>
                                <td>
                                    <x-ui.badge :color="$statusColor" :pill="true">{{ $statusLabel }}</x-ui.badge>
                                </td>
                                <td>
                                    @if ($progress === null)
                                        <span class="text-[10px] text-on-surface-variant/70">Chưa có lịch học</span>
                                    @else
                                        <div class="w-24 h-1.5 bg-surface-container rounded-full overflow-hidden mb-1">
                                            <div class="h-full bg-secondary rounded-full" style="width: {{ $progress }}%"></div>
                                        </div>
                                        <span class="text-[10px] text-on-surface-variant font-mono block">{{ $progress }}% ({{ $doneSessions }}/{{ $totalSessions }} buổi)</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($capacity === null)
                                        <span class="text-[10px] text-on-surface-variant/70">—</span>
                                    @elseif ($studentCount >= $capacity)
                                        <x-ui.badge>Đủ</x-ui.badge>
                                    @else
                                        <x-ui.badge color="error">Còn {{ $capacity - $studentCount }} chỗ</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($classBigTests->isEmpty())
                                        <span class="text-[10px] text-on-surface-variant/70">Chưa có</span>
                                    @else
                                        <div class="flex items-center justify-center gap-1">
                                            @foreach ($classBigTests as $bt)
                                                @php $btDone = $bt->scheduled_at && $bt->scheduled_at->isPast(); @endphp
                                                <span class="w-5 h-5 rounded flex items-center justify-center text-[10px] font-bold {{ $btDone ? 'bg-tertiary/10 text-on-tertiary-container' : 'bg-surface-container text-on-surface-variant' }}"
                                                      title="{{ $bt->title }}{{ $bt->scheduled_at ? ' — ' . $bt->scheduled_at->format('d/m/Y') : '' }}">{{ $loop->iteration }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11"><x-ui.empty-state icon="search_off" title="Không tìm thấy lớp học nào thỏa mãn điều kiện tìm kiếm." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            <x-slot:footer><x-ui.pagination :paginator="$classes" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
