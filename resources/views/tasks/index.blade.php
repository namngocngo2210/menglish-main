{{-- Danh sách công việc (mockup phan-cong-cong-viec/danh_s_ch_c_ng_vi_c) — giao việc 2 chiều, đổi trạng thái theo luật.
     Giao việc mở modal 2xl (htmx, có lựa chọn "Giao cho: Trợ giảng"); bấm tiêu đề → modal xem nhanh (đẩy URL /tasks/{id}).
     Lưu xong server phát "tasks-changed" → #task-list tự tải lại (giữ tab, bộ lọc, trang hiện tại). --}}
@php
    $statuses = [
        'all' => 'Tất cả', 'overdue' => 'Quá hạn', 'blocked' => 'Bị chặn', 'pending_confirmation' => 'Chờ xác nhận',
        'in_progress' => 'Đang thực hiện', 'new' => 'Mới', 'completed' => 'Hoàn thành', 'canceled' => 'Đã hủy',
    ];
    $statusColors = [
        'new' => 'status-new', 'in_progress' => 'status-progress', 'pending_confirmation' => 'status-pending',
        'blocked' => 'status-blocked', 'completed' => 'status-done', 'overdue' => 'status-overdue', 'canceled' => 'status-canceled',
    ];
    $transitionLabels = [
        'in_progress' => ['Đang thực hiện', 'play_arrow'], 'blocked' => ['Bị chặn', 'block'],
        'pending_confirmation' => ['Gửi chờ xác nhận', 'outgoing_mail'], 'completed' => ['Xác nhận hoàn thành', 'check_circle'],
        'canceled' => ['Hủy công việc', 'cancel'],
    ];
    $canCreate = auth()->user()->can('work_task.create') || auth()->user()->can('work_task.request');
@endphp
<x-app-layout title="Danh sách công việc">
    <div x-data="{
            currentTask: null,
            newStatus: '',
            statusLabel: '',
            reasonRequired: false,
            openStatusModal(task, status, label) {
                this.currentTask = task; this.newStatus = status; this.statusLabel = label;
                this.reasonRequired = ['blocked', 'canceled'].includes(status);
                this.$dispatch('open-modal', 'task-status');
            }
         }">
        <x-ui.page-header title="Danh sách công việc" description="Quản lý, phân công và theo dõi tiến độ công việc — giao việc hai chiều.">
            <x-slot:actions>
                @can('work_task.approve')
                    <x-ui.button variant="secondary" icon="fact_check" :href="route('tasks.manual-approvals')">
                        Chờ xác nhận
                        @if ($counts['pending'] > 0)<span class="rounded-full bg-error px-1.5 font-code text-caption text-white">{{ $counts['pending'] }}</span>@endif
                    </x-ui.button>
                @endcan
                @if ($canCreate)
                    <x-ui.button icon="add" :href="route('tasks.create')" modal="2xl">
                        {{ auth()->user()->can('work_task.create') ? 'Giao việc' : 'Đề xuất việc cho Admin / Học vụ' }}
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('info'))
            <x-ui.alert type="info" class="mb-md" dismissible>{{ session('info') }}</x-ui.alert>
        @endif
        @if ($errors->has('status') || $errors->has('reason'))
            <x-ui.alert type="error" class="mb-md">{{ $errors->first('status') ?: $errors->first('reason') }}</x-ui.alert>
        @endif

        <div id="task-list" hx-get="{{ route('tasks.index', request()->query()) }}" hx-trigger="tasks-changed from:body" hx-select="#task-list" hx-swap="outerHTML" hx-disinherit="*">
        <div class="mb-md grid grid-cols-2 gap-md sm:grid-cols-4">
            <x-ui.stat-card label="Tất cả công việc" :value="$counts['all']" icon="assignment" />
            <x-ui.stat-card label="Việc của tôi" :value="$counts['mine']" icon="person" tone="primary" />
            <x-ui.stat-card label="Chờ xác nhận" :value="$counts['pending']" icon="pending_actions" tone="warning" />
            <x-ui.stat-card label="Quá hạn" :value="$counts['overdue']" icon="warning" tone="error" />
        </div>

        <x-ui.data-table min-width="880px">
            <x-slot:header>
                <div class="flex w-full flex-col gap-sm">
                    <div class="flex flex-col gap-sm md:flex-row md:items-center md:justify-between">
                        <x-ui.tabs class="border-0">
                            <x-ui.tab :href="route('tasks.index', array_merge(request()->except('page'), ['tab' => 'mine']))" :active="$tab === 'mine'" :count="$counts['mine']">Của tôi</x-ui.tab>
                            <x-ui.tab :href="route('tasks.index', array_merge(request()->except('page'), ['tab' => 'assigned']))" :active="$tab === 'assigned'" :count="$counts['assigned']">Tôi giao</x-ui.tab>
                            @if ($canViewAll)
                                <x-ui.tab :href="route('tasks.index', array_merge(request()->except('page'), ['tab' => 'all']))" :active="$tab === 'all'" :count="$counts['all']">Tất cả</x-ui.tab>
                            @endif
                        </x-ui.tabs>
                        <form method="GET" action="{{ route('tasks.index') }}" class="flex flex-wrap items-center gap-sm">
                            <input type="hidden" name="tab" value="{{ $tab }}">
                            <input type="hidden" name="status" value="{{ $status }}">
                            <x-ui.select name="task_type" :options="['one_time' => 'Phát sinh', 'recurring' => 'Lặp đi lặp lại']" :value="$taskType === 'all' ? null : $taskType"
                                         placeholder="Mọi loại" onchange="this.form.submit()" aria-label="Loại công việc" />
                            <x-ui.input name="q" :value="$search" icon="search" placeholder="Tìm công việc, nhân sự..." aria-label="Tìm kiếm" />
                        </form>
                    </div>
                    <div class="flex flex-wrap items-center gap-xs">
                        <span class="mr-xs font-body-small text-body-small text-on-surface-variant">Trạng thái:</span>
                        @foreach ($statuses as $stKey => $stLabel)
                            <a href="{{ route('tasks.index', array_merge(request()->except('page'), ['status' => $stKey])) }}"
                               class="rounded-full border px-sm py-[2px] font-body-small text-body-small transition-colors {{ $status === $stKey ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low' }}">{{ $stLabel }}</a>
                        @endforeach
                    </div>
                </div>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Loại</th>
                        <th>Người nhận</th>
                        <th>Hạn hoàn thành</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tasks as $task)
                        @php $allowed = \App\Http\Controllers\WorkTaskController::allowedTransitions($task, auth()->user()); @endphp
                        <tr class="{{ $task->status === 'overdue' ? 'bg-error-container/20' : '' }} {{ $task->status === 'canceled' ? 'opacity-60' : '' }}">
                            <td class="max-w-[360px]">
                                <a href="{{ route('tasks.show', $task->id) }}" hx-get="{{ route('tasks.show', $task->id) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" hx-push-url="true" data-modal-size="2xl"
                                   class="font-semibold text-on-surface hover:text-primary {{ $task->status === 'canceled' ? 'line-through' : '' }}">{{ $task->title }}</a>
                                @if ($task->description)
                                    <div class="line-clamp-1 font-caption text-caption text-on-surface-variant">{{ $task->description }}</div>
                                @endif
                                <div class="mt-xs flex flex-wrap gap-xs">
                                    @if ($task->classModel)
                                        <span class="inline-flex items-center gap-xs rounded bg-secondary-fixed/60 px-sm font-caption text-caption text-secondary">
                                            <span class="material-symbols-outlined text-[13px]" aria-hidden="true">school</span>{{ $task->classModel->name }}{{ $task->lesson_session ? ' · '.$task->lesson_session : '' }}
                                        </span>
                                    @endif
                                    @if ($task->time_slot_category && $task->assignee?->can('portal.assistant'))
                                        <span class="rounded bg-surface-container-high px-sm font-caption text-caption text-on-surface-variant">{{ $task->time_slot_category_label }}</span>
                                    @endif
                                </div>
                                @if ($task->status === 'blocked' && $task->blocked_reason)
                                    <div class="mt-xs flex items-center gap-xs font-caption text-caption text-status-blocked"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">block</span>Lý do: {{ $task->blocked_reason }}</div>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="$task->task_type === 'recurring' ? 'secondary' : 'info'" :dot="false">
                                    {{ $task->task_type_label }}@if ($task->frequency) ({{ ['daily' => 'Hàng ngày', 'weekly' => 'Hàng tuần', 'monthly' => 'Hàng tháng'][$task->frequency] ?? $task->frequency }})@endif
                                </x-ui.badge>
                            </td>
                            <td>
                                <div class="flex items-center gap-sm">
                                    <x-ui.avatar :name="$task->assignee?->name ?? '?'" size="sm" />
                                    <div class="min-w-0">
                                        <div class="truncate font-body-small text-body-small font-medium">{{ $task->assignee?->name ?? 'Chưa phân công' }}</div>
                                        @if ($tab !== 'mine' && $task->creator)
                                            <div class="truncate font-caption text-caption text-on-surface-variant">Giao bởi {{ $task->creator->name }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap font-code text-body-small {{ $task->status === 'overdue' ? 'font-semibold text-error' : '' }}">
                                {{ $task->due_date?->format('d/m/Y') ?? '—' }}
                                @if ($task->due_time)<span class="block text-caption text-on-surface-variant">{{ substr($task->due_time, 0, 5) }}</span>@endif
                            </td>
                            <td><x-ui.badge :color="$statusColors[$task->status] ?? 'neutral'">{{ $task->status_label }}</x-ui.badge></td>
                            <td class="text-right">
                                <div class="relative inline-block text-left" x-data="{ open: false }">
                                    <x-ui.button variant="ghost" icon="more_vert" aria-label="Thao tác" x-on:click="open = !open" x-on:click.outside="open = false" />
                                    <div x-show="open" x-cloak class="absolute right-0 z-20 mt-xs w-56 rounded-lg border border-outline-variant bg-surface-container-lowest py-xs text-left shadow-lg">
                                        @forelse ($allowed as $next)
                                            @php [$lbl, $ico] = $transitionLabels[$next] ?? [$next, 'arrow_forward']; if ($next === 'in_progress' && $task->status === 'pending_confirmation') { $lbl = 'Trả về làm tiếp'; } @endphp
                                            <button type="button" class="flex w-full items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low"
                                                    x-on:click="openStatusModal({{ \Illuminate\Support\Js::from(['id' => $task->id, 'title' => $task->title]) }}, @js($next), @js($lbl)); open = false">
                                                <span class="material-symbols-outlined text-[16px] text-on-surface-variant" aria-hidden="true">{{ $ico }}</span>{{ $lbl }}
                                            </button>
                                        @empty
                                            <p class="px-md py-xs font-caption text-caption text-on-surface-variant">Không có thao tác khả dụng</p>
                                        @endforelse
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="assignment_late" title="Không tìm thấy công việc nào phù hợp" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$tasks" unit="công việc" />
            </x-slot:footer>
        </x-ui.data-table>
        </div>

        {{-- Modal: Thay đổi trạng thái --}}
        <x-ui.modal name="task-status" title="Thay đổi trạng thái" max-width="md">
            <form id="task-status-change-form" :action="'{{ url('/tasks') }}/' + (currentTask ? currentTask.id : '') + '/status'" method="POST" class="space-y-md">
                @csrf
                <input type="hidden" name="status" :value="newStatus">
                <div>
                    <p class="font-caption text-caption text-on-surface-variant">Công việc</p>
                    <p class="font-body-medium text-body-medium font-semibold" x-text="currentTask ? currentTask.title : ''"></p>
                </div>
                <div>
                    <p class="font-caption text-caption text-on-surface-variant">Trạng thái mới</p>
                    <p class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-primary" x-text="statusLabel"></p>
                </div>
                <label class="block">
                    <span class="mb-xs block font-body-small text-body-small font-medium">
                        <span x-text="reasonRequired ? 'Ghi chú lý do' : 'Ghi chú / kết quả (tùy chọn)'"></span><span x-show="reasonRequired" class="text-error"> *</span>
                    </span>
                    <x-ui.textarea name="reason" rows="3" x-bind:required="reasonRequired" maxlength="1000" placeholder="Nhập lý do chi tiết khiến công việc bị chặn / kết quả..." />
                </label>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'task-status')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="task-status-change-form">Xác nhận</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
