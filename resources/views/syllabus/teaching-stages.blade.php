<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="font-h1 text-h1 text-on-surface">Chặng đang dạy &amp; Order Test</h1>
                <p class="font-body-base text-on-surface-variant">Quản lý các chặng học, lịch dự kiến Big Test và yêu cầu đề thi cho học viên.</p>
            </div>
            <span class="inline-flex items-center gap-xs rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-medium text-body-small text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>Tháng {{ now()->month }}, {{ now()->year }}
            </span>
        </div>
    </x-slot>

    @php
        $user = auth()->user();
        $isAcademic = $user->can('syllabus.approve_adjustment');
    @endphp
    {{-- Mockup 03_Cong_Giao_Vien/07 (Chặng đang dạy & Order Test) + 10/11 (Lịch dự kiến Big Test): mỗi lớp đang mở chặng là một thẻ.
         GV chính đặt ngày dự kiến Big Test cuối chặng và order đề cho đúng chặng đang mở (Q4); trợ giảng chỉ xem. --}}
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
                    $plan = $plans[$as->id];
                    $order = $plan['order'];
                    $isMainTeacher = (int) $class?->teacher_id === (int) $user->id || (int) $class?->foreign_teacher_id === (int) $user->id;
                    $isAssistant = (int) $class?->assistant_id === (int) $user->id && ! $isMainTeacher;
                    $canOrder = $user->hasRole('admin') || $isMainTeacher;
                    $canSetDate = $isMainTeacher || $isAcademic;
                @endphp
                <article class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm p-lg flex flex-col gap-md">
                    <div class="flex items-start justify-between gap-sm">
                        <div class="min-w-0">
                            <span class="inline-flex rounded-md bg-primary-fixed/60 px-sm py-0.5 font-label text-label text-primary">{{ $as->stage?->label ?? $as->stage_name }}</span>
                            <h3 class="mt-xs font-h3 text-h3 text-on-surface">{{ $class?->name }}{{ $class?->code ? ' - '.$class->code : '' }}</h3>
                        </div>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[20px]">school</span></span>
                    </div>
                    <div class="space-y-xs font-body-small text-body-small">
                        <p class="flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">calendar_today</span>Bắt đầu: {{ ($as->opened_at ?? $as->created_at)?->format('d/m/Y') }}</p>
                        <p class="flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">{{ $isAssistant ? 'supervisor_account' : 'person' }}</span>{{ $isAssistant ? 'Trợ giảng (Chỉ xem)' : ($isMainTeacher ? 'Giáo viên chính' : 'Học thuật / Quản lý') }}</p>
                    </div>

                    {{-- Trạng thái đề --}}
                    @if ($plan['exam'] === 'approved')
                        <div class="flex items-center gap-xs rounded-lg bg-tertiary/10 px-md py-sm font-body-small text-body-small font-medium text-tertiary"><span class="material-symbols-outlined text-[18px]">check_circle</span>Đã có đề</div>
                    @elseif ($plan['exam'] === 'pending')
                        <div class="flex items-center gap-xs rounded-lg bg-amber-50 px-md py-sm font-body-small text-body-small font-medium text-amber-800"><span class="material-symbols-outlined text-[18px]">pending</span>Đã order - Chờ HT duyệt</div>
                    @endif

                    {{-- Lịch dự kiến Big Test --}}
                    <div class="rounded-lg border border-outline-variant bg-surface-container-low p-md space-y-sm">
                        <p class="font-body-small text-body-small text-on-surface">Ngày dự kiến Big Test:
                            <strong class="{{ $plan['date'] ? 'text-on-surface' : 'text-error' }}">{{ $plan['date']?->format('d/m/Y') ?? 'Chưa đặt lịch' }}</strong>
                            @if ($plan['bigTest'])<span class="font-caption text-caption text-on-surface-variant">(đợt thi {{ $plan['bigTest']->code }})</span>@endif
                        </p>
                        @if ($canSetDate && ! $plan['bigTest'])
                            <form method="POST" action="{{ route('syllabus.assignments.expected-date', $as->id) }}" class="flex items-end gap-sm">
                                @csrf
                                <label class="flex-1">
                                    <span class="mb-xs block font-label text-label text-on-surface-variant">{{ $as->expected_big_test_date ? 'Sửa ngày' : 'Chọn ngày' }}</span>
                                    <input type="date" name="expected_big_test_date" required min="{{ now()->toDateString() }}" value="{{ $as->expected_big_test_date?->toDateString() }}"
                                           class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small">
                                </label>
                                <x-ui.button type="submit" size="sm" :icon="$as->expected_big_test_date ? null : 'save'">{{ $as->expected_big_test_date ? 'Cập nhật' : 'Lưu' }}</x-ui.button>
                            </form>
                        @endif
                    </div>

                    <div class="mt-auto">
                        @if ($order && in_array($order->status, ['pending', 'approved'], true))
                            <x-ui.button variant="secondary" class="w-full" :href="route('teacher.order-test', $as->class_id)">Chi tiết</x-ui.button>
                        @elseif ($canOrder)
                            @if ($order && $order->status === 'rejected')
                                <p class="mb-sm font-caption text-caption text-error">Order trước bị từ chối: {{ $order->rejection_reason }}</p>
                            @endif
                            <x-ui.button icon="assignment_add" class="w-full" :href="route('teacher.order-test', $as->class_id)">Order đề Big Test</x-ui.button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-app-layout>
