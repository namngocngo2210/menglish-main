{{-- Nội dung chi tiết công việc + form đổi trạng thái — dùng chung modal xem nhanh và trang tasks/show.
     Biến: $task (creator, assignee, branch, classModel, confirmedBy), $allowed (trạng thái kế tiếp được phép), $asModal. --}}
@php
    $asModal = $asModal ?? false;
    $statusColors = [
        'new' => 'status-new', 'in_progress' => 'status-progress', 'pending_confirmation' => 'status-pending',
        'blocked' => 'status-blocked', 'completed' => 'status-done', 'overdue' => 'status-overdue', 'canceled' => 'status-canceled',
    ];
    $transitionLabels = [
        'in_progress' => $task->status === 'pending_confirmation' ? 'Trả về làm tiếp' : 'Đang thực hiện', 'blocked' => 'Bị chặn',
        'pending_confirmation' => 'Gửi chờ xác nhận', 'completed' => 'Xác nhận hoàn thành', 'canceled' => 'Hủy công việc',
    ];
    $frequencies = ['daily' => 'Hàng ngày', 'weekly' => 'Hàng tuần', 'monthly' => 'Hàng tháng'];
    $rows = array_filter([
        ['person', 'Người nhận', $task->assignee?->name ?? 'Chưa phân công'],
        ['assignment_ind', 'Người giao', $task->creator?->name ?? '—'],
        ['event', 'Hạn hoàn thành', ($task->due_date?->format('d/m/Y') ?? '—').($task->due_time ? ' · '.substr($task->due_time, 0, 5) : '')],
        ['repeat', 'Loại', $task->task_type_label.($task->frequency ? ' ('.($frequencies[$task->frequency] ?? $task->frequency).')' : '')],
        ['apartment', 'Chi nhánh', $task->branch?->name ?? '—'],
        $task->classModel ? ['school', 'Lớp', $task->classModel->name.($task->lesson_session ? ' · '.$task->lesson_session : '')] : null,
        $task->confirmedBy ? ['verified', 'Xác nhận bởi', $task->confirmedBy->name.($task->confirmed_at ? ' · '.$task->confirmed_at->format('H:i d/m/Y') : '')] : null,
    ]);
@endphp
<div class="space-y-lg" data-testid="task-detail">
    <div class="flex flex-wrap items-center gap-sm">
        <x-ui.badge :color="$statusColors[$task->status] ?? 'neutral'">{{ $task->status_label }}</x-ui.badge>
        @if ($task->time_slot_category && $task->lesson_session)
            <span class="rounded bg-surface-container-high px-sm font-caption text-caption text-on-surface-variant">{{ $task->time_slot_category_label }}</span>
        @endif
    </div>

    <dl class="grid grid-cols-1 gap-md sm:grid-cols-2">
        @foreach ($rows as [$icon, $label, $value])
            <div class="flex items-start gap-sm">
                <span class="material-symbols-outlined mt-0.5 text-[18px] text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>
                <div class="min-w-0">
                    <dt class="font-caption text-caption text-on-surface-variant">{{ $label }}</dt>
                    <dd class="break-words font-body-medium text-body-medium text-on-surface">{{ $value }}</dd>
                </div>
            </div>
        @endforeach
    </dl>

    @if ($task->description)
        <div class="rounded-lg bg-surface-container-low p-md">
            <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Mô tả</p>
            <p class="whitespace-pre-line font-body-base text-body-base text-on-surface">{{ $task->description }}</p>
        </div>
    @endif
    @foreach (['blocked_reason' => ['Lý do bị chặn', 'error'], 'rejection_reason' => ['Lý do hủy / trả về', 'warning'], 'completion_note' => ['Ghi chú / kết quả', 'info']] as $field => [$label, $type])
        @if ($task->{$field})
            <x-ui.alert :type="$type" :title="$label">{{ $task->{$field} }}</x-ui.alert>
        @endif
    @endforeach

    @if ($errors->has('status') || $errors->has('reason'))
        <x-ui.alert type="error">{{ $errors->first('status') ?: $errors->first('reason') }}</x-ui.alert>
    @endif

    @if ($allowed)
        <form id="{{ $asModal ? 'modal-' : '' }}task-status-form" method="POST" action="{{ route('tasks.status.update', $task->id) }}"
              class="space-y-md rounded-lg border border-outline-variant p-md" x-data="{ next: @js(old('status', $allowed[0])) }">
            @csrf
            <p class="font-body-medium text-body-medium font-semibold text-on-surface">Cập nhật trạng thái</p>
            <div class="flex flex-wrap gap-sm" role="radiogroup" aria-label="Trạng thái mới">
                @foreach ($allowed as $next)
                    <label class="inline-flex cursor-pointer items-center gap-xs rounded-lg border px-sm py-xs font-body-small text-body-small"
                           :class="next === @js($next) ? 'border-primary-container bg-primary-container/10 text-primary' : 'border-outline-variant text-on-surface-variant'">
                        <input type="radio" name="status" value="{{ $next }}" x-model="next" class="sr-only">{{ $transitionLabels[$next] ?? $next }}
                    </label>
                @endforeach
            </div>
            <x-ui.textarea name="reason" :id="($asModal ? 'modal-' : '').'task-status-reason'" rows="2" maxlength="1000"
                           x-bind:required="['blocked', 'canceled'].includes(next)"
                           label="Ghi chú lý do / kết quả" hint="Bắt buộc khi chuyển Bị chặn hoặc Hủy công việc." />
            @unless ($asModal)
                <div class="flex justify-end"><x-ui.button type="submit" icon="check">Cập nhật trạng thái</x-ui.button></div>
            @endunless
        </form>
    @endif
</div>
