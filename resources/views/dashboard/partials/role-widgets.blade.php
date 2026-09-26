{{-- Dashboard theo vai trò (BPMN 22) — dữ liệu thật từ DashboardController. --}}
<section class="space-y-md" data-role-dashboard="{{ $roleDashboard['type'] }}">
    <div class="flex flex-wrap items-end justify-between gap-sm">
        <div>
            <h2 class="font-h2 text-h2 text-on-surface">{{ $roleDashboard['title'] }}</h2>
            <p class="font-body-small text-body-small text-on-surface-variant">Phạm vi: {{ $roleDashboard['scope'] }} · cập nhật {{ now()->format('H:i d/m/Y') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 {{ count($roleDashboard['stats']) >= 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-3' }}">
        @foreach ($roleDashboard['stats'] as $stat)
            <x-ui.stat-card :label="$stat['label']" :value="$stat['value']" :icon="$stat['icon']" :tone="$stat['tone']" :hint="$stat['hint']" />
        @endforeach
    </div>

    @if (! empty($roleDashboard['queues']))
        <div class="rounded-xl border border-surface-variant bg-surface-container-lowest">
            <div class="border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Hàng chờ cần xử lý</h3>
            </div>
            <div class="grid grid-cols-1 divide-y divide-surface-variant/60 sm:grid-cols-2 sm:divide-y-0 {{ count($roleDashboard['queues']) >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }}" data-role-queues>
                @foreach ($roleDashboard['queues'] as $queue)
                    <a href="{{ $queue['href'] ?? '#' }}" class="flex items-center gap-sm px-md py-sm hover:bg-surface-container-low">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $queue['value'] > 0 ? 'bg-warning-container text-warning' : 'bg-surface-container-low text-on-surface-variant' }}">
                            <span class="material-symbols-outlined" aria-hidden="true">{{ $queue['icon'] }}</span>
                        </span>
                        <span class="min-w-0">
                            <span class="block font-h3 text-h3 {{ $queue['value'] > 0 ? 'text-warning' : 'text-on-surface' }}">{{ number_format($queue['value']) }}</span>
                            <span class="block truncate font-body-small text-body-small text-on-surface-variant">{{ $queue['label'] }}</span>
                            @if (! empty($queue['hint']))
                                <span class="block truncate font-caption text-caption text-on-surface-variant">{{ $queue['hint'] }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if (in_array($roleDashboard['type'], ['admin', 'manager'], true))
        <div class="rounded-xl border border-surface-variant bg-surface-container-lowest">
            <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Việc quá hạn cần xử lý</h3>
                <a href="{{ route('tasks.index', ['tab' => 'all', 'status' => 'overdue']) }}" class="font-body-small text-body-small text-primary hover:underline">Xem danh sách</a>
            </div>
            @forelse ($roleDashboard['overdueTasks'] as $task)
                <div class="flex items-center justify-between gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0">
                    <div class="min-w-0">
                        <p class="truncate font-body-medium text-body-medium text-on-surface">{{ $task->title }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ $task->assignee?->name ?? 'Chưa phân công' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-error-container px-sm py-xs font-caption text-caption text-error">Hạn {{ $task->due_date?->format('d/m/Y') ?? '—' }}</span>
                </div>
            @empty
                <x-ui.empty-state icon="task_alt" title="Không có việc quá hạn" />
            @endforelse
        </div>
    @endif

    @if ($roleDashboard['type'] === 'academic')
        <div class="grid grid-cols-1 gap-md lg:grid-cols-2">
            <div class="rounded-xl border border-surface-variant bg-surface-container-lowest">
                <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Đề xuất chờ duyệt</h3>
                    <a href="{{ route('syllabus.adjustment-requests') }}" class="font-body-small text-body-small text-primary hover:underline">Duyệt đề xuất</a>
                </div>
                @forelse ($roleDashboard['pendingAdjustments'] as $req)
                    <div class="border-b border-surface-variant/60 px-md py-sm last:border-0">
                        <p class="font-body-medium text-body-medium text-on-surface">{{ $req->classModel?->name ?? 'Lớp' }} — {{ $req->teacher?->name ?? 'Giáo viên' }}</p>
                        <p class="truncate font-caption text-caption text-on-surface-variant">{{ $req->reason }}</p>
                    </div>
                @empty
                    <x-ui.empty-state icon="inbox" title="Không có đề xuất chờ duyệt" />
                @endforelse
            </div>
            <div class="rounded-xl border border-surface-variant bg-surface-container-lowest">
                <div class="flex items-center justify-between border-b border-surface-variant px-md py-sm">
                    <h3 class="font-h3 text-h3 text-on-surface">Big Test sắp tới</h3>
                    <a href="{{ route('syllabus.big-tests.schedules') }}" class="font-body-small text-body-small text-primary hover:underline">Lịch Big Test</a>
                </div>
                @forelse ($roleDashboard['upcomingBigTests'] as $test)
                    <div class="flex items-center justify-between gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0">
                        <div class="min-w-0">
                            <p class="truncate font-body-medium text-body-medium text-on-surface">{{ $test->title ?? $test->code }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ $test->classModel?->name ?? '—' }}</p>
                        </div>
                        <span class="shrink-0 font-caption text-caption text-on-surface-variant">{{ $test->scheduled_at?->format('H:i d/m') }}</span>
                    </div>
                @empty
                    <x-ui.empty-state icon="event_available" title="Không có Big Test trong 14 ngày tới" />
                @endforelse
            </div>
        </div>
    @endif
</section>
