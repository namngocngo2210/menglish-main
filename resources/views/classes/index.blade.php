{{-- Danh sách lớp: màn chính của menu Lớp học (gộp Sơ đồ khối + Danh sách lớp chi tiết). Bấm một lớp → Trang lớp. --}}
<x-app-layout>
    <x-ui.page-header title="Danh sách lớp" icon="co_present" description="Tìm lớp theo trạng thái, chương trình, cấp độ; bấm vào lớp để làm mọi việc của lớp đó." />

    @php
        $chipClass = fn (bool $active) => 'inline-flex items-center gap-xs rounded-full border px-sm py-1 font-body-small text-body-small font-semibold transition-colors '
            .($active ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface');
        $countClass = fn (bool $active) => 'rounded-full px-1.5 font-code text-[11px] leading-4 '.($active ? 'bg-white/25' : 'bg-surface-container-high');
        // Giữ các bộ lọc khác khi bấm chip; bấm lại chip đang chọn = bỏ chọn.
        $chipUrl = fn (string $key, ?string $value) => route('classes.index', array_filter(
            array_merge(request()->except(['page', $key]), [$key => request($key) === $value ? null : $value]),
            fn ($v) => filled($v)
        ));
        $openCount = collect($statusCounts)->except('cancelled')->sum();
        $tones = ['error' => 'error', 'warning' => 'warning', 'success' => 'success', 'primary' => 'primary', 'neutral' => 'neutral'];
    @endphp

    <div class="space-y-5">
        {{-- Việc cần chú ý --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <a href="{{ route('classes.index', ['status' => 'active']) }}" class="block">
                <x-ui.stat-card label="Đang học" :value="$stats['active']" tone="success" icon="play_circle" />
            </a>
            <a href="{{ route('classes.index', ['status' => 'pending_schedule']) }}" class="block">
                <x-ui.stat-card label="Chờ cấu hình lịch" :value="$stats['pending_schedule']" :tone="$stats['pending_schedule'] ? 'warning' : 'default'" icon="edit_calendar" />
            </a>
            <x-ui.stat-card label="Chưa đủ ngưỡng khai giảng" :value="$stats['short']" :tone="$stats['short'] ? 'warning' : 'default'" icon="group_add" />
            <a href="{{ route('tasks.classes-dashboard', ['attendance' => 'missing']) }}" class="block">
                <x-ui.stat-card label="Buổi hôm nay chưa điểm danh" :value="$stats['missing_attendance']" :tone="$stats['missing_attendance'] ? 'error' : 'default'" icon="fact_check" />
            </a>
        </div>

        <x-ui.filter-bar :action="route('classes.index')" placeholder="Tìm tên lớp hoặc mã lớp..." :reset-url="route('classes.index')" class="!mb-0">
            <x-slot:quick>
                <div class="space-y-sm">
                    <nav class="flex flex-wrap items-center gap-xs" aria-label="Lọc theo trạng thái lớp">
                        <a href="{{ route('classes.index', request()->except(['page', 'status'])) }}" class="{{ $chipClass(! $statusFilter) }}" @if (! $statusFilter) aria-current="page" @endif>
                            Đang mở <span class="{{ $countClass(! $statusFilter) }}">{{ $openCount }}</span>
                        </a>
                        @foreach (\App\Support\ClassLifecycle::STATUSES as $key => $meta)
                            @php $on = $statusFilter === $key; @endphp
                            <a href="{{ $chipUrl('status', $key) }}" class="{{ $chipClass($on) }}" @if ($on) aria-current="page" @endif>
                                {{ $meta['label'] }} <span class="{{ $countClass($on) }}">{{ (int) ($statusCounts[$key] ?? 0) }}</span>
                            </a>
                        @endforeach
                    </nav>
                    {{-- Sơ đồ khối: số lớp theo chương trình / cấp độ, bấm để lọc --}}
                    @if ($programCounts->isNotEmpty())
                        <nav class="flex flex-wrap items-center gap-xs" aria-label="Lọc theo chương trình">
                            <span class="mr-xs font-body-small text-body-small text-on-surface-variant">Chương trình</span>
                            @foreach ($programCounts as $program => $total)
                                @php $on = $programFilter === $program; @endphp
                                <a href="{{ $chipUrl('program', $program) }}" class="{{ $chipClass($on) }}" @if ($on) aria-current="page" @endif>
                                    {{ $program }} <span class="{{ $countClass($on) }}">{{ $total }}</span>
                                </a>
                            @endforeach
                        </nav>
                    @endif
                    @if ($levelCounts->isNotEmpty())
                        <nav class="flex flex-wrap items-center gap-xs" aria-label="Lọc theo cấp độ">
                            <span class="mr-xs font-body-small text-body-small text-on-surface-variant">Cấp độ</span>
                            @foreach ($levelCounts as $level => $total)
                                @php $on = $levelFilter === $level; @endphp
                                <a href="{{ $chipUrl('level', $level) }}" class="{{ $chipClass($on) }}" @if ($on) aria-current="page" @endif>
                                    {{ $level }} <span class="{{ $countClass($on) }}">{{ $total }}</span>
                                </a>
                            @endforeach
                        </nav>
                    @endif
                </div>
            </x-slot:quick>
            @foreach (['status' => $statusFilter, 'program' => $programFilter, 'level' => $levelFilter] as $name => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endif
            @endforeach
            <x-ui.select name="branch_id" label="Chi nhánh" placeholder="Tất cả chi nhánh" :value="$branchFilter" :options="$branches->pluck('name', 'id')" />
        </x-ui.filter-bar>

        <x-ui.data-table min-width="1080px">
            <table class="whitespace-nowrap text-xs">
                <thead>
                    <tr>
                        <th>Lớp</th>
                        <th>Trạng thái</th>
                        <th>Lịch học</th>
                        <th>GV / CM</th>
                        <th class="text-center">Sĩ số</th>
                        <th>Tiến độ</th>
                        <th class="text-center">Big Test</th>
                        <th>Việc tiếp theo</th>
                        <th class="text-right" aria-label="Thao tác"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($classes as $c)
                        @php
                            $seat = $rows[$c->id]['seat'];
                            $next = $rows[$c->id]['next'];
                            $status = \App\Support\ClassLifecycle::status($c->status);
                            $total = (int) $c->total_sessions_count;
                            $done = (int) $c->done_sessions_count;
                            $progress = $total > 0 ? (int) round($done * 100 / $total) : null;
                            $classBigTests = $bigTests->get($c->id, collect());
                            $showUrl = route('classes.show', $c->id);
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ $showUrl }}" class="font-code font-bold text-primary hover:underline">{{ $c->code }}</a>
                                <div class="max-w-[200px] truncate font-semibold text-on-surface">{{ $c->name }}</div>
                                <div class="text-[11px] text-on-surface-variant/80">{{ collect([$c->program, $c->level, $c->branch?->name])->filter()->implode(' · ') }}</div>
                            </td>
                            <td><x-ui.badge :color="$status['color']" :pill="true">{{ $status['label'] }}</x-ui.badge></td>
                            <td class="min-w-[170px] max-w-[220px] whitespace-normal text-on-surface-variant">{{ $c->schedule_text ?: 'Chưa xếp lịch' }}</td>
                            <td>
                                <div class="font-medium text-on-surface">{{ $c->teacher?->name ?? 'Chưa phân công' }}</div>
                                <div class="text-[11px] text-on-surface-variant">CM: {{ $c->assistant?->name ?? 'Chưa phân công' }}</div>
                            </td>
                            <td class="text-center" data-seats="{{ $c->id }}">
                                <span class="font-bold text-on-surface">{{ $seat['occupied'] }}</span><span class="text-on-surface-variant/70">/{{ $seat['capacity'] ?: '∞' }}</span>
                                <div class="text-[10px]">
                                    @if ($seat['left'] === null)
                                        <span class="text-on-surface-variant/70">Không giới hạn</span>
                                    @elseif ($seat['left'] === 0)
                                        <span class="font-bold text-error">Đã đủ</span>
                                    @else
                                        <span class="font-semibold text-tertiary">Còn {{ $seat['left'] }} chỗ</span>
                                    @endif
                                </div>
                                @if ($seat['needed'] > 0)
                                    <div class="text-[10px] font-semibold text-warning" title="Ngưỡng khai giảng {{ $seat['min'] }} học viên">Thiếu {{ $seat['needed'] }}/{{ $seat['min'] }} để KG</div>
                                @endif
                            </td>
                            <td>
                                @if ($progress === null)
                                    <span class="text-[11px] text-on-surface-variant/70">Chưa có buổi học</span>
                                @else
                                    <div class="mb-1 h-1.5 w-24 overflow-hidden rounded-full bg-surface-container">
                                        <div class="h-full rounded-full bg-secondary" style="width: {{ $progress }}%"></div>
                                    </div>
                                    <span class="block font-code text-[10px] text-on-surface-variant">{{ $done }}/{{ $total }} buổi</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($classBigTests->isEmpty())
                                    <span class="text-[11px] text-on-surface-variant/70">Chưa có</span>
                                @else
                                    <div class="flex items-center justify-center gap-1">
                                        @foreach ($classBigTests as $bt)
                                            @php $btDone = $bt->scheduled_at && $bt->scheduled_at->isPast(); @endphp
                                            <span class="flex h-5 w-5 items-center justify-center rounded text-[10px] font-bold {{ $btDone ? 'bg-tertiary/10 text-on-tertiary-container' : 'bg-surface-container text-on-surface-variant' }}"
                                                  title="{{ $bt->title }}{{ $bt->scheduled_at ? ' — '.$bt->scheduled_at->format('d/m/Y') : '' }}">{{ $loop->iteration }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if ($next)
                                    <a href="{{ route('classes.show', ['id' => $c->id, 'tab' => $next['tab']]) }}" class="hover:opacity-80">
                                        <x-ui.badge :color="$tones[$next['tone']] ?? 'neutral'" :dot="false">{{ $next['label'] }}</x-ui.badge>
                                    </a>
                                @else
                                    <span class="text-[11px] text-on-surface-variant/70">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <x-ui.button variant="secondary" size="sm" :href="$showUrl">Mở lớp</x-ui.button>
                                    @can('class.update')
                                        <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('classes.edit', $c->id)" aria-label="Sửa lớp {{ $c->code }}" />
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-ui.empty-state icon="search_off" title="Không tìm thấy lớp học nào thỏa mãn điều kiện tìm kiếm.">
                                    @can('class.create')
                                        <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
                                    @endcan
                                </x-ui.empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$classes" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
