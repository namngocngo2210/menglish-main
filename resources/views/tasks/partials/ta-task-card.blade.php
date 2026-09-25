{{-- Thẻ nhiệm vụ trong Portal trợ giảng. Biến: $task (WorkTask), $canComplete (bool), $isToday (bool) --}}
@php
    $statusColor = [
        'new' => 'status-new', 'in_progress' => 'status-progress', 'pending_confirmation' => 'status-pending',
        'blocked' => 'status-blocked', 'completed' => 'status-done', 'overdue' => 'status-overdue', 'rejected' => 'status-canceled',
    ][$task->status] ?? 'neutral';
    $payload = ['id' => $task->id, 'title' => $task->title, 'due_time' => $task->due_time];
@endphp
<article class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm {{ $task->status === 'completed' ? 'opacity-70' : '' }}" data-task-id="{{ $task->id }}">
    <div class="flex items-start justify-between gap-sm">
        <h3 class="font-body-medium text-body-medium font-semibold text-on-surface {{ $task->status === 'completed' ? 'line-through' : '' }}">{{ $task->title }}</h3>
        <x-ui.badge :color="$statusColor" pill>{{ $task->status_label }}</x-ui.badge>
    </div>
    @if ($task->classModel)
        <span class="inline-flex items-center gap-xs rounded bg-secondary-fixed/60 px-sm py-[2px] font-caption text-caption text-secondary">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">school</span>
            Trực lớp: {{ $task->classModel->code ?? $task->classModel->name }}{{ $task->lesson_session ? ' · '.$task->lesson_session : '' }}
        </span>
    @endif
    <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">event</span>
        Hạn: {{ $task->due_time ? substr($task->due_time, 0, 5) : '—' }}, {{ $isToday ? 'Hôm nay' : $task->due_date?->format('d/m/Y') }}
    </p>

    <div class="flex flex-col gap-sm">
        @if ($canComplete && in_array($task->status, ['new', 'in_progress', 'overdue', 'rejected'], true))
            <x-ui.button :variant="$task->status === 'overdue' ? 'danger' : 'primary'" icon="check_circle" class="w-full"
                         x-on:click="openCompleteModal({{ \Illuminate\Support\Js::from($payload) }})">
                {{ $task->status === 'overdue' ? 'Hoàn thành gấp' : 'Hoàn thành' }}
            </x-ui.button>
        @elseif ($task->status === 'pending_confirmation')
            <p class="flex items-center gap-xs font-body-small text-body-small text-orange-700">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">hourglass_top</span> Đang chờ người giao việc xác nhận
            </p>
        @elseif ($task->status === 'completed')
            <p class="flex items-center gap-xs font-body-small text-body-small text-tertiary">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">done_all</span>
                Đã hoàn thành{{ $task->completed_at ? ' lúc '.$task->completed_at->format('H:i') : '' }}
            </p>
        @endif
        @if ($task->classModel && $task->status !== 'completed')
            <x-ui.button variant="secondary" icon="assignment" class="w-full"
                         :href="route('tasks.class-reports.create', ['task_id' => $task->id, 'class_id' => $task->class_id])">Nộp báo cáo trực lớp</x-ui.button>
        @endif
    </div>
</article>
