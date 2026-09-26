{{-- Thẻ nhiệm vụ trong Portal trợ giảng (mockup nhi_m_v_h_m_nay_ta). Biến: $task (WorkTask), $canComplete (bool), $isToday (bool) --}}
@php
    $statusColor = [
        'new' => 'status-new', 'in_progress' => 'status-progress', 'pending_confirmation' => 'status-pending',
        'blocked' => 'status-blocked', 'completed' => 'status-done', 'overdue' => 'status-overdue', 'canceled' => 'status-canceled',
    ][$task->status] ?? 'neutral';
    $payload = ['id' => $task->id, 'title' => $task->title, 'due_time' => $task->due_time, 'due_label' => ($task->due_time ? substr($task->due_time, 0, 5) : '—').', '.($isToday ? 'Hôm nay' : $task->due_date?->format('d/m/Y'))];
    $report = $task->classReport;
    $lateHours = $task->status === 'overdue' ? max(1, $task->lateHours()) : 0;
@endphp
<article class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm {{ in_array($task->status, ['completed', 'canceled'], true) ? 'opacity-70' : '' }}" data-task-id="{{ $task->id }}">
    <div class="flex items-start justify-between gap-sm">
        <h3 class="font-body-medium text-body-medium font-semibold text-on-surface {{ in_array($task->status, ['completed', 'canceled'], true) ? 'line-through' : '' }}">{{ $task->title }}</h3>
        <x-ui.badge :color="$statusColor" pill class="uppercase">{{ $task->status_label }}</x-ui.badge>
    </div>
    @if ($task->classModel)
        <span class="inline-flex items-center gap-xs rounded bg-secondary-fixed/60 px-sm py-[2px] font-caption text-caption text-secondary">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">school</span>
            Trực lớp: {{ $task->classModel->code ?? $task->classModel->name }}{{ $task->lesson_session ? ' · '.$task->lesson_session : '' }}
        </span>
    @endif

    @if ($task->status === 'blocked' && $task->blocked_reason)
        <p class="flex items-center gap-xs font-caption text-caption text-status-blocked">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ $task->blocked_reason }}
        </p>
    @elseif ($task->status === 'overdue')
        <p class="flex items-center gap-xs font-caption text-caption font-semibold text-error">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>Trễ {{ $lateHours }} giờ
        </p>
    @elseif ($task->status === 'completed')
        <p class="flex items-center gap-xs font-caption text-caption text-tertiary">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">check</span>
            Hoàn thành{{ $task->completed_at ? ' lúc '.$task->completed_at->format('H:i') : '' }}
        </p>
    @elseif ($task->status !== 'canceled')
        <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">calendar_today</span>
            Hạn: {{ $payload['due_label'] }}
        </p>
    @endif
    @if ($task->rejection_reason && in_array($task->status, ['in_progress', 'overdue'], true))
        <p class="rounded bg-error-container/40 px-sm py-xs font-caption text-caption text-error">Bị trả về: {{ $task->rejection_reason }}</p>
    @endif

    <div class="flex flex-col gap-sm">
        @if ($canComplete && in_array($task->status, ['new', 'in_progress', 'overdue'], true))
            <x-ui.button :variant="$task->status === 'overdue' ? 'danger' : 'primary'" icon="check_circle" class="w-full"
                         x-on:click="openCompleteModal({{ \Illuminate\Support\Js::from($payload) }})">
                {{ $task->status === 'overdue' ? 'Hoàn thành gấp' : 'Hoàn thành' }}
            </x-ui.button>
        @elseif ($task->status === 'pending_confirmation')
            <p class="flex items-center gap-xs font-body-small text-body-small text-orange-700">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">hourglass_empty</span>
                @if ($report && $report->status === \App\Models\ClassReport::STATUS_PENDING)
                    Đang chờ {{ mb_strtolower($report->confirmerRoleLabel()) }} xác nhận báo cáo trực lớp
                @else
                    Đang chờ người giao việc xác nhận
                @endif
            </p>
        @endif
        @if ($task->classModel && in_array($task->status, ['new', 'in_progress', 'overdue', 'blocked'], true) && $canComplete)
            <x-ui.button variant="secondary" icon="assignment" class="w-full"
                         :href="route('tasks.class-reports.create', ['task_id' => $task->id, 'class_id' => $task->class_id])">Nộp báo cáo trực lớp</x-ui.button>
        @endif
    </div>
</article>
