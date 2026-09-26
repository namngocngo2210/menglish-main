{{-- Cổng Giáo viên — Tổng quan hôm nay (mockup 03_Cong_Giao_Vien/01_app_shell): lịch dạy hôm nay + check-in nhiều ca, banner ca sắp
     bắt đầu, thẻ học sinh cần chú ý / lương tạm tính / chấm công / vi phạm, buổi cần điểm danh bù, lịch tuần. Điện thoại: thanh điều hướng dưới. --}}
@php
    $typeLabels = [
        \App\Models\ClassSession::TYPE_REGULAR => null,
        \App\Models\ClassSession::TYPE_MAKEUP => 'Học bù',
        \App\Models\ClassSession::TYPE_SUPPORT => 'Phụ đạo',
    ];
    $weekdayLong = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ Nhật'];
    $todayDate = \Illuminate\Support\Carbon::parse($today);
    $card = 'rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg';
@endphp
<x-app-layout title="Cổng Giáo viên">
    <div class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0">
        <x-ui.page-header title="Tổng quan hôm nay">
            <x-slot:meta>
                Xin chào <span class="font-semibold text-on-surface">{{ $teacher->name }}</span> · Cổng Giáo viên ·
                <a href="{{ route('teacher.trial-guests') }}" class="font-semibold text-primary hover:underline">Khách học thử</a>
            </x-slot:meta>
            <x-slot:actions>
                <x-ui.avatar :name="$teacher->name" />
            </x-slot:actions>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        {{-- ─── Lịch dạy hôm nay ─── --}}
        <section class="{{ $card }}">
            <div class="mb-md flex flex-col justify-between gap-md md:flex-row md:items-center">
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">Lịch dạy hôm nay — {{ $weekdayLong[$todayDate->isoWeekday()] }}, {{ $todayDate->format('d/m') }}</h2>
                    <p class="font-body-base text-body-base text-on-surface-variant">
                        @if ($stats['total'] > 0)
                            Bạn có {{ $stats['total'] }} ca dạy trong ngày hôm nay · đã check-in {{ $stats['checked_in'] }} · đã điểm danh {{ $stats['attendance_done'] }}.
                        @else
                            Hôm nay bạn không có buổi dạy nào trên lịch.
                        @endif
                    </p>
                </div>
                @if ($nextShift)
                    @php $ns = $nextShift['session']; @endphp
                    <div class="flex items-center gap-md rounded-lg border border-primary-container/20 bg-primary-container/10 p-md" data-testid="next-shift-banner">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">warning</span>
                        <div class="flex-1">
                            <p class="font-body-medium text-body-medium text-on-primary-container">Ca dạy lúc {{ $ns->start_time?->format('H:i') }} {{ now()->lt($ns->date->copy()->setTimeFromTimeString($ns->start_time?->format('H:i') ?? '00:00')) ? 'sắp bắt đầu!' : 'đang diễn ra!' }}</p>
                            <p class="font-caption text-caption text-on-primary-container/80">Vui lòng hoàn thành thủ tục điểm danh tại lớp học.</p>
                        </div>
                        <x-ui.button size="sm" :href="route('teacher.attendance', ['classId' => $ns->class_id, 'session' => $ns->id])">Điểm danh ngay</x-ui.button>
                    </div>
                @endif
            </div>

            @if ($shifts->isNotEmpty())
                <form method="POST" action="{{ route('teacher.checkin') }}" class="space-y-md">
                    @csrf
                    <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                        @foreach ($shifts as $shift)
                            @php $class = $shift['class']; $session = $shift['session']; @endphp
                            <div class="rounded-lg border {{ $shift['checked_in'] ? 'border-tertiary/40' : 'border-outline-variant' }} bg-surface-container-low p-md">
                                <div class="flex items-start gap-md">
                                    @unless ($shift['checked_in'])
                                        <input type="checkbox" name="session_ids[]" value="{{ $session->id }}" aria-label="Chọn ca {{ $class?->name }} để check-in"
                                               class="mt-sm h-5 w-5 rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
                                    @endunless
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-container/10 text-primary-container">
                                        <span class="material-symbols-outlined" aria-hidden="true">{{ $shift['checked_in'] ? 'check_circle' : 'schedule' }}</span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                                            {{ $class?->name }}
                                            @if ($typeLabels[$session->type] ?? null)<x-ui.badge color="info">{{ $typeLabels[$session->type] }}</x-ui.badge>@endif
                                        </p>
                                        <p class="font-body-small text-body-small text-on-surface-variant">
                                            {{ $shift['scheduled_time'] }} • {{ $session->room ? (str_starts_with(mb_strtolower($session->room), 'phòng') ? $session->room : 'Phòng '.$session->room) : 'Chưa có phòng' }}, {{ $class?->branch?->name ?? 'Chưa gán chi nhánh' }}
                                        </p>
                                        <p class="font-caption text-caption text-on-surface-variant">
                                            {{ $class?->code }} · {{ $shift['student_count'] }} HV
                                            @if ($session->type === \App\Models\ClassSession::TYPE_SUPPORT && $session->supportSession?->student) · {{ $session->supportSession->student->name }} @endif
                                            @if ($shift['checked_in']) · <span class="font-semibold text-tertiary">Đã check-in {{ $shift['checkin_time'] }}</span> @endif
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-md flex flex-wrap gap-xs border-t border-outline-variant/60 pt-sm">
                                    <x-ui.button size="sm" :variant="$shift['attendance_done'] ? 'secondary' : 'primary'" icon="fact_check"
                                        :href="route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id])">{{ $shift['attendance_done'] ? 'Đã điểm danh' : 'Điểm danh' }}</x-ui.button>
                                    @if ($session->type !== \App\Models\ClassSession::TYPE_SUPPORT)
                                        <x-ui.button size="sm" variant="secondary" icon="rate_review" :href="route('teacher.remarks', ['classId' => $class->id, 'session' => $session->id])">Nhận xét</x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" icon="assignment" :href="route('teacher.homework', ['classId' => $class->id, 'session' => $session->id])">Giao bài</x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" icon="grading" :href="route('teacher.scores', $class->id)">Nhập điểm</x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($shifts->contains('checked_in', false))
                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="how_to_reg" class="w-full md:w-auto">Check-in các ca đã chọn</x-ui.button>
                        </div>
                    @endif
                </form>
            @else
                <x-ui.empty-state icon="event_busy" title="Không có ca dạy hôm nay" description="Lịch dạy được sinh từ TKB của các lớp bạn phụ trách." />
            @endif
        </section>

        {{-- ─── Thẻ tổng quan ─── --}}
        <div class="grid grid-cols-1 gap-lg md:grid-cols-2">
            <div class="{{ $card }} flex flex-col" data-testid="widget-attention">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Học sinh cần chú ý</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">priority_high</span>
                </div>
                <div class="flex-1 space-y-sm">
                    @forelse ($widgets['attention'] as $score)
                        <div class="rounded border border-error-container/60 bg-error-container/20 p-sm">
                            <p class="font-body-medium text-body-medium text-on-surface">{{ $score->student?->name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">
                                {{ $score->classModel?->name }} • {{ $score->name }} • Điểm: <span class="font-bold text-error">{{ rtrim(rtrim(number_format((float) $score->score, 2, '.', ''), '0'), '.') }}/{{ rtrim(rtrim(number_format((float) $score->max_score, 2, '.', ''), '0'), '.') }}</span>
                            </p>
                        </div>
                    @empty
                        <p class="font-body-small text-body-small text-on-surface-variant">Không có học sinh nào dưới mục tiêu trong 30 ngày qua.</p>
                    @endforelse
                    <p class="font-caption text-caption italic text-on-surface-variant">* Danh sách học sinh có kết quả Mini Test dưới mục tiêu (dưới 7/10).</p>
                </div>
            </div>

            <div class="{{ $card }} flex flex-col" data-testid="widget-salary">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Lương tạm tính tháng {{ now()->month }}</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">payments</span>
                </div>
                <div class="flex flex-1 flex-col justify-center">
                    @if ($widgets['estimate'] !== null)
                        <p class="text-[28px] font-bold leading-9 text-primary">{{ number_format($widgets['estimate'], 0, ',', '.') }}đ</p>
                        <p class="font-caption text-caption text-on-surface-variant">Tính đến ngày {{ now()->format('d/m') }} · {{ rtrim(rtrim(number_format($widgets['hours'], 1, ',', '.'), '0'), ',') }} giờ dạy × đơn giá (chưa gồm phụ cấp, KPI, khấu trừ)</p>
                    @else
                        <p class="text-[28px] font-bold leading-9 text-primary">{{ rtrim(rtrim(number_format($widgets['hours'], 1, ',', '.'), '0'), ',') }} giờ</p>
                        <p class="font-caption text-caption text-on-surface-variant">Giờ dạy đã chấm công tính đến ngày {{ now()->format('d/m') }} — chưa có đơn giá riêng để tạm tính lương.</p>
                    @endif
                </div>
                <x-ui.button variant="secondary" class="mt-md w-full" :href="route('portal.my-salary')">Chi tiết</x-ui.button>
            </div>

            <div class="{{ $card }} flex flex-col" data-testid="widget-timesheet">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Báo cáo chấm công</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">assignment_turned_in</span>
                </div>
                <p class="flex-1 font-body-base text-body-base text-on-surface-variant">
                    Kỳ lương {{ now()->format('m/Y') }}: {{ $widgets['timesheets_total'] }} ca đã chấm công, {{ $widgets['timesheets_pending'] }} ca chờ Học vụ duyệt.
                    Vui lòng kiểm tra giờ dạy trước 23:59 ngày {{ now()->endOfMonth()->format('d/m') }}.
                    @if ($stats['pending'] > 0)<span class="font-semibold text-error">Còn {{ $stats['pending'] }} buổi chưa điểm danh.</span>@endif
                </p>
                <x-ui.button class="mt-md w-full" :href="route('teacher.general-report')">Xem bảng công</x-ui.button>
            </div>

            <div class="{{ $card }} flex flex-col" data-testid="widget-violations">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Vi phạm &amp; Khoản trừ</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">gavel</span>
                </div>
                <div class="flex flex-1 items-center gap-md">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border-4 {{ $widgets['violations']->isEmpty() ? 'border-tertiary text-tertiary' : 'border-error text-error' }}">
                        <span class="font-bold">{{ $widgets['violations']->count() }}</span>
                    </div>
                    <div>
                        @if ($widgets['violations']->isEmpty())
                            <p class="font-body-medium text-body-medium text-on-surface">Không có vi phạm trong tháng</p>
                        @else
                            @php $latest = $widgets['violations']->first(); @endphp
                            <p class="font-body-medium text-body-medium text-on-surface">{{ $widgets['violations']->count() }} lỗi vi phạm trong tháng</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ $latest->violation_type }} — {{ $latest->violation_date?->format('d/m') }} · {{ $latest->status_label }}</p>
                        @endif
                    </div>
                </div>
                <x-ui.button variant="secondary" class="mt-md w-full" :href="route('penalties.index')">Xem biên bản</x-ui.button>
            </div>
        </div>

        {{-- ─── Buổi đã qua chưa điểm danh ─── --}}
        @if ($pendingSessions->isNotEmpty())
            <section class="overflow-hidden rounded-xl border border-error/30 bg-surface-container-lowest shadow-sm">
                <div class="flex items-center gap-sm border-b border-error/20 bg-error-container/30 px-md py-sm">
                    <span class="material-symbols-outlined text-error" aria-hidden="true">pending_actions</span>
                    <h2 class="font-body-semibold text-body-semibold text-on-error-container">Buổi đã dạy chưa điểm danh (điểm danh bù)</h2>
                </div>
                <ul class="divide-y divide-surface-container">
                    @foreach ($pendingSessions as $s)
                        <li class="flex flex-col justify-between gap-sm px-md py-sm font-body-small text-body-small sm:flex-row sm:items-center">
                            <div>
                                <span class="font-semibold text-on-surface">{{ $s->classModel?->name }}</span>
                                <span class="text-on-surface-variant">· {{ $s->date->format('d/m/Y') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}</span>
                                @if ($typeLabels[$s->type] ?? null)<x-ui.badge color="info">{{ $typeLabels[$s->type] }}</x-ui.badge>@endif
                            </div>
                            <x-ui.button size="sm" variant="secondary" icon="fact_check" :href="route('teacher.attendance', ['classId' => $s->class_id, 'session' => $s->id])">Điểm danh bù</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ─── Lịch dạy tuần ─── --}}
        <section class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
            <div class="flex flex-col justify-between gap-sm border-b border-surface-container px-md py-sm sm:flex-row sm:items-center">
                <h2 class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">calendar_view_week</span>
                    Lịch dạy tuần {{ $weekStart->format('d/m') }} – {{ $weekStart->copy()->addDays(6)->format('d/m/Y') }}
                </h2>
                <div class="flex items-center gap-xs">
                    <x-ui.button size="sm" variant="secondary" icon="chevron_left" :href="route('teacher.home', ['week' => $weekStart->copy()->subWeek()->toDateString()])">Tuần trước</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" :href="route('teacher.home')">Tuần này</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" :href="route('teacher.home', ['week' => $weekStart->copy()->addWeek()->toDateString()])">Tuần sau <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span></x-ui.button>
                </div>
            </div>
            <div class="grid grid-cols-1 divide-y divide-surface-container md:grid-cols-7 md:divide-x md:divide-y-0">
                @foreach ($weekDays as $day)
                    @php $isToday = $day['date']->isToday(); @endphp
                    <div class="min-h-[90px] p-sm {{ $isToday ? 'bg-primary-container/5' : '' }}">
                        <div class="font-label-caps text-label-caps uppercase {{ $isToday ? 'text-primary' : 'text-on-surface-variant' }}">
                            {{ ['', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'][$day['date']->isoWeekday()] }} · {{ $day['date']->format('d/m') }}
                        </div>
                        <div class="mt-sm space-y-xs">
                            @forelse ($day['sessions'] as $s)
                                @php
                                    $cancelled = $s->status === 'cancelled';
                                    $done = $attendanceDone->has($s->id);
                                    $canTake = ! $cancelled && ! $s->date->isFuture();
                                @endphp
                                <div class="rounded-lg border px-sm py-xs text-[11px] {{ $cancelled ? 'border-outline-variant bg-surface-container-low text-on-surface-variant line-through' : ($done ? 'border-tertiary/30 bg-tertiary-fixed/20' : 'border-outline-variant') }}">
                                    <div class="font-semibold text-on-surface">{{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}</div>
                                    <div class="truncate">{{ $s->classModel?->name }}</div>
                                    <div class="text-on-surface-variant">
                                        {{ $s->room ? 'P. '.$s->room : '' }}
                                        @if ($typeLabels[$s->type] ?? null) · {{ $typeLabels[$s->type] }} @endif
                                        @if ($cancelled) · Đã hủy @endif
                                    </div>
                                    @if ($canTake)
                                        <a href="{{ route('teacher.attendance', ['classId' => $s->class_id, 'session' => $s->id]) }}" class="mt-xs inline-block font-semibold {{ $done ? 'text-tertiary' : 'text-primary' }} hover:underline">
                                            {{ $done ? 'Đã điểm danh' : 'Điểm danh' }}
                                        </a>
                                    @endif
                                </div>
                            @empty
                                <div class="text-[11px] text-outline-variant">—</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    @include('teacher.partials.bottom-nav')
</x-app-layout>
