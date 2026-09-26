{{-- Chọn đối tượng giao việc: "Nhân sự" (tasks.create — 1 việc, giao 2 chiều) | "Trợ giảng" (tasks.ta-assign — nhiều đầu việc
     theo ca, luật riêng: chỉ work_task.assign, TA trong phạm vi, giờ hạn theo buổi học, báo Admin khi gửi trễ).
     Trong modal các link được hx-boost → đổi nội dung modal tại chỗ. Biến: $current ('staff' | 'assistant'). --}}
<div class="mb-md inline-flex rounded-lg border border-outline-variant bg-surface-container-low p-[2px]" role="tablist" aria-label="Giao cho">
    <span class="self-center px-sm font-body-small text-body-small text-on-surface-variant">Giao cho:</span>
    @foreach (['staff' => ['Nhân sự', 'person', route('tasks.create')], 'assistant' => ['Trợ giảng (theo ca)', 'support_agent', route('tasks.ta-assign')]] as $mode => [$label, $icon, $url])
        <a href="{{ $url }}" role="tab" aria-selected="{{ $current === $mode ? 'true' : 'false' }}" data-assign-mode="{{ $mode }}"
           @class([
               'inline-flex items-center gap-xs rounded-md px-sm py-xs font-body-small text-body-small transition-colors',
               'bg-surface-container-lowest font-semibold text-primary shadow-sm' => $current === $mode,
               'text-on-surface-variant hover:text-primary' => $current !== $mode,
           ])>
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ $icon }}</span>{{ $label }}
        </a>
    @endforeach
</div>
