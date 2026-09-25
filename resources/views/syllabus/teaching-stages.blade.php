<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="font-h1 text-h1 text-on-surface">Chặng đang dạy &amp; Order Test</h1>
            <p class="font-body-base text-on-surface-variant">Quản lý các chặng học và yêu cầu đề thi cho học viên.</p>
        </div>
    </x-slot>

    @php
        $user = auth()->user();
    @endphp
    {{-- Mockup 03_Cong_Giao_Vien/07: mỗi lớp đang mở chặng là một thẻ; order đề Big Test cho đúng chặng đang mở (Q4). --}}
    @if ($assignments->isEmpty())
        <div class="bg-surface-container-lowest rounded-xl border border-outline-variant p-xl shadow-sm flex flex-col items-center text-center gap-sm">
            <span class="material-symbols-outlined text-[48px] text-on-surface-variant">inventory_2</span>
            <p class="font-h3 text-h3 text-on-surface">Chưa được giao chặng nào</p>
            <p class="font-body-small text-body-small text-on-surface-variant">Hiện tại bạn chưa có chặng học nào đang mở. Vui lòng liên hệ Quản lý chuyên môn nếu có sai sót.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-lg">
            @foreach ($assignments as $as)
                @php
                    $class = $as->classModel;
                    $stageLabel = $as->stage?->label ?? $as->stage_name;
                    $order = ($orders[$as->class_id] ?? collect())->first(fn ($o) => $as->stage_id ? (int) $o->syllabus_stage_id === (int) $as->stage_id : $o->stage_name === $stageLabel);
                    $isMainTeacher = (int) $class?->teacher_id === (int) $user->id || (int) $class?->foreign_teacher_id === (int) $user->id;
                    $isAssistant = (int) $class?->assistant_id === (int) $user->id && ! $isMainTeacher;
                    $canOrder = $user->hasRole('admin') || $isMainTeacher;
                @endphp
                <article class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm p-lg flex flex-col gap-md">
                    <div class="flex items-start justify-between gap-sm">
                        <h3 class="font-h3 text-h3 text-on-surface">{{ $class?->name }}{{ $class?->code ? ' - '.$class->code : '' }}</h3>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[20px]">school</span></span>
                    </div>
                    <div class="space-y-xs font-body-small text-body-small">
                        <p class="flex items-center gap-xs font-semibold text-primary"><span class="material-symbols-outlined text-[18px]">flag</span>{{ $stageLabel }}</p>
                        <p class="flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">calendar_today</span>Bắt đầu: {{ ($as->opened_at ?? $as->created_at)?->format('d/m/Y') }}</p>
                        <p class="flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">{{ $isAssistant ? 'supervisor_account' : 'person' }}</span>{{ $isAssistant ? 'Trợ giảng (Chỉ xem)' : ($isMainTeacher ? 'Giáo viên chính' : 'Học thuật / Quản lý') }}</p>
                    </div>

                    <div class="mt-auto">
                        @if ($order && $order->status === 'pending')
                            <div class="mb-sm flex items-center gap-xs rounded-lg bg-amber-50 px-md py-sm font-body-small text-body-small font-medium text-amber-800"><span class="material-symbols-outlined text-[18px]">pending</span>Đã order - Chờ HT duyệt</div>
                            <x-ui.button variant="secondary" class="w-full" :href="route('teacher.order-test', $as->class_id)">Chi tiết</x-ui.button>
                        @elseif ($order && $order->status === 'approved')
                            <div class="mb-sm flex items-center gap-xs rounded-lg bg-tertiary/10 px-md py-sm font-body-small text-body-small font-medium text-tertiary"><span class="material-symbols-outlined text-[18px]">check_circle</span>Đã có đề</div>
                            <x-ui.button variant="secondary" class="w-full" :href="route('teacher.order-test', $as->class_id)">Chi tiết</x-ui.button>
                        @elseif ($canOrder)
                            @if ($order && $order->status === 'rejected')
                                <p class="mb-sm font-caption text-caption text-error">Order trước bị từ chối: {{ $order->rejection_reason }}</p>
                            @endif
                            <x-ui.button icon="assignment_add" class="w-full" :href="route('teacher.order-test', $as->class_id)">Order đề Big Test</x-ui.button>
                        @else
                            <p class="font-caption text-caption text-on-surface-variant">Chưa có order đề cho chặng này.</p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-app-layout>
