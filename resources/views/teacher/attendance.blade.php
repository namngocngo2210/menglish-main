{{-- Điểm danh theo buổi (mockup 03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien): tiêu đề buổi + khung giờ / phòng / sĩ số, cửa sổ ±24h,
     thống kê nhanh 4 trạng thái, quy tắc nghiệp vụ, danh sách lớp với trạng thái + ghi chú (bắt buộc khi nghỉ). --}}
@php
    $options = [
        'present' => ['label' => 'Đúng giờ', 'tone' => 'border-outline-variant'],
        'late' => ['label' => 'Muộn', 'tone' => 'border-amber-300 bg-amber-50'],
        'excused' => ['label' => 'Nghỉ có phép', 'tone' => 'border-blue-300 bg-blue-50'],
        'absent' => ['label' => 'Nghỉ không phép', 'tone' => 'border-rose-300 bg-rose-50'],
    ];
    $initialStatuses = $students->mapWithKeys(fn ($st) => [$st->id => old('status.'.$st->id, $existing->get($st->id)?->status ?? 'present')]);
    $roomLabel = $session?->room ? (str_starts_with(mb_strtolower($session->room), 'phòng') ? $session->room : 'Phòng '.$session->room) : 'Chưa có phòng';
@endphp
<x-app-layout title="Điểm danh — {{ $class->name }}">
    <div class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0"
         x-data="{ statuses: @js($initialStatuses), count(v) { return Object.values(this.statuses).filter(s => s === v).length; } }">

        {{-- Tiêu đề buổi học --}}
        <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
            <div class="flex flex-col justify-between gap-md lg:flex-row lg:items-center">
                <div class="min-w-0">
                    <a href="{{ route('teacher.home') }}" class="mb-xs inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant hover:text-primary">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_back</span> Về lịch dạy
                    </a>
                    <h1 class="font-h2 text-h2 text-on-surface">Điểm danh — {{ $class->name }}{{ $session ? ', '.$session->date->format('d/m/Y') : '' }}</h1>
                    @if ($session)
                        <div class="mt-sm flex flex-wrap items-center gap-x-md gap-y-xs font-body-small text-body-small text-on-surface-variant">
                            <span class="inline-flex items-center gap-xs rounded-md bg-surface-container-high px-sm py-[2px] font-medium text-on-surface">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>
                                Khung giờ: {{ $session->start_time?->format('H:i') }} – {{ $session->end_time?->format('H:i') }}
                            </span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">meeting_room</span>{{ $roomLabel }} · {{ $class->branch?->name ?? 'Chưa gán chi nhánh' }}</span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">groups</span>Sĩ số lớp: <strong class="text-on-surface">{{ $rosterSize }} học sinh</strong></span>
                            @if ($session->type === \App\Models\ClassSession::TYPE_MAKEUP)<x-ui.badge color="warning">Buổi học bù</x-ui.badge>@endif
                            @if ($session->type === \App\Models\ClassSession::TYPE_SUPPORT)<x-ui.badge color="secondary">Buổi phụ đạo</x-ui.badge>@endif
                        </div>
                    @endif
                </div>
                @if ($session && ! $blockReason)
                    <div class="flex items-center gap-sm rounded-lg border px-md py-sm {{ $window === 'closed' ? 'border-amber-300 bg-amber-50' : 'border-tertiary/30 bg-tertiary-fixed/20' }}" data-testid="attendance-window">
                        <span class="relative flex h-3 w-3">
                            @if ($window !== 'closed')<span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-tertiary-container opacity-60"></span>@endif
                            <span class="relative inline-flex h-3 w-3 rounded-full {{ $window === 'closed' ? 'bg-amber-500' : 'bg-tertiary-container' }}"></span>
                        </span>
                        <div>
                            <div class="font-body-medium text-body-medium text-on-surface">{{ $window === 'closed' ? 'Ngoài cửa sổ 24h — điểm danh bù' : 'Đang trong cửa sổ điểm danh' }}</div>
                            <div class="font-caption text-caption text-on-surface-variant">Quy định: Buổi học ±24 giờ{{ $window === 'closed' ? ' · Học vụ sẽ rà soát' : '' }}</div>
                        </div>
                    </div>
                @endif
            </div>

            @if ($session && ! $blockReason && $students->isNotEmpty())
                <div class="mt-md grid grid-cols-2 gap-sm border-t border-surface-container pt-md sm:grid-cols-4">
                    <div class="rounded-lg bg-tertiary-fixed/20 p-sm"><div class="font-caption text-caption text-on-surface-variant">Đúng giờ</div><div class="font-h3 text-h3 text-tertiary" x-text="count('present')">{{ $initialStatuses->filter(fn ($s) => $s === 'present')->count() }}</div></div>
                    <div class="rounded-lg bg-amber-50 p-sm"><div class="font-caption text-caption text-on-surface-variant">Đi muộn</div><div class="font-h3 text-h3 text-amber-600" x-text="count('late')">{{ $initialStatuses->filter(fn ($s) => $s === 'late')->count() }}</div></div>
                    <div class="rounded-lg bg-blue-50 p-sm"><div class="font-caption text-caption text-on-surface-variant">Nghỉ có phép</div><div class="font-h3 text-h3 text-blue-700" x-text="count('excused')">{{ $initialStatuses->filter(fn ($s) => $s === 'excused')->count() }}</div></div>
                    <div class="rounded-lg bg-rose-50 p-sm"><div class="font-caption text-caption text-on-surface-variant">Nghỉ không phép</div><div class="font-h3 text-h3 text-error" x-text="count('absent')">{{ $initialStatuses->filter(fn ($s) => $s === 'absent')->count() }}</div></div>
                </div>
            @endif
        </section>

        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first('note') ?: $errors->first() }}</x-ui.alert>
        @endif

        {{-- Chọn buổi --}}
        @if ($recentSessions->isNotEmpty())
            <form method="GET" action="{{ route('teacher.attendance', $class->id) }}" class="flex flex-col gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md sm:flex-row sm:items-center">
                <label for="session-picker" class="shrink-0 font-label-caps text-label-caps uppercase text-on-surface-variant">Buổi điểm danh</label>
                <select id="session-picker" name="session" onchange="this.form.submit()" class="flex-1 rounded-lg border border-outline-variant py-sm pl-md pr-xl font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/20">
                    @foreach ($recentSessions as $s)
                        <option value="{{ $s->id }}" @selected($session && $session->id === $s->id) @disabled($s->status === 'cancelled')>
                            {{ $s->date->format('d/m/Y') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}
                            @if ($s->type === \App\Models\ClassSession::TYPE_MAKEUP) · Học bù @elseif ($s->type === \App\Models\ClassSession::TYPE_SUPPORT) · Phụ đạo @endif
                            @if ($s->status === 'cancelled') · Đã hủy @elseif ($s->attendances_count > 0) · Đã điểm danh ({{ $s->attendances_count }}) @else · Chưa điểm danh @endif
                        </option>
                    @endforeach
                </select>
                <noscript><x-ui.button type="submit" size="sm" variant="secondary">Chọn</x-ui.button></noscript>
            </form>
        @endif

        @if (! $session)
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <x-ui.empty-state icon="event_busy" title="Lớp không có buổi học trong ngày này"
                    description="Chọn một buổi ở danh sách phía trên để điểm danh bù, hoặc kiểm tra thời khóa biểu của lớp." />
            </div>
        @elseif ($blockReason)
            <x-ui.alert type="warning">{{ $blockReason }}</x-ui.alert>
        @elseif ($students->isEmpty())
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <x-ui.empty-state icon="group_off" title="Chưa có học viên" description="Buổi học này chưa có học viên nào trong danh sách lớp." />
            </div>
        @else
            @if ($onBehalf)
                <x-ui.alert type="info" title="Điểm danh thay giáo viên">
                    Bạn đang điểm danh thay {{ $session->teacher?->name ?? $class->teacher?->name ?? 'giáo viên của lớp' }}. Hệ thống ghi nhận bạn là người lưu điểm danh.
                </x-ui.alert>
            @endif
            @if ($session->date->isBefore(today()))
                <x-ui.alert type="warning">Điểm danh bù cho buổi đã qua ngày {{ $session->date->format('d/m/Y') }}.</x-ui.alert>
            @endif

            <x-ui.alert type="info" title="Quy tắc nghiệp vụ điểm danh dành cho Giáo viên:">
                <ul class="list-disc space-y-xs pl-md">
                    <li>Người điểm danh được ghi nhận tự động theo tài khoản đang đăng nhập (GV chính/GVNN/Trợ giảng).</li>
                    <li>Khi chọn <strong>"Nghỉ có phép"</strong> hoặc <strong>"Nghỉ không phép"</strong>, ô <strong>Ghi chú là bắt buộc</strong> để lưu trữ lý do vắng học của học viên.</li>
                    <li>Trong cửa sổ ±24h, giáo viên có thể cập nhật lại nhiều lần; ngoài cửa sổ vẫn điểm danh bù được, Học vụ sẽ rà soát.</li>
                </ul>
            </x-ui.alert>

            <form method="POST" action="{{ route('teacher.attendance.store', $class->id) }}" class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                @csrf
                <input type="hidden" name="class_session_id" value="{{ $session->id }}">
                <div class="flex flex-col justify-between gap-sm border-b border-surface-container p-md sm:flex-row sm:items-center">
                    <div>
                        <h2 class="font-h3 text-h3 text-on-surface">Danh sách học sinh trong lớp (Roster)</h2>
                        <p class="font-caption text-caption text-on-surface-variant">Dữ liệu nguồn xếp lớp chính thức · Vui lòng kiểm tra và xác nhận đúng từng học sinh</p>
                    </div>
                    <span class="inline-flex items-center gap-xs rounded-full bg-tertiary-fixed/30 px-sm py-[2px] font-caption text-caption font-semibold text-tertiary">
                        <span class="h-2 w-2 rounded-full bg-tertiary"></span> {{ $students->count() }}/{{ $students->count() }} học sinh đã gán trạng thái
                    </span>
                </div>

                <div class="hidden grid-cols-[48px_1.2fr_1fr_1.4fr] gap-md bg-surface-container-low px-md py-sm font-label-caps text-label-caps uppercase text-on-surface-variant md:grid">
                    <span>STT</span><span>Học sinh</span><span>Trạng thái điểm danh <span class="text-error">*</span></span><span>Ghi chú</span>
                </div>
                <div class="divide-y divide-surface-container">
                    @foreach ($students as $student)
                        @php $record = $existing->get($student->id); $noteError = $errors->first('note.'.$student->id); @endphp
                        <div class="grid grid-cols-1 gap-sm px-md py-sm md:grid-cols-[48px_1.2fr_1fr_1.4fr] md:items-start md:gap-md"
                             :class="{ 'bg-amber-50/30': statuses[{{ $student->id }}] === 'late', 'bg-blue-50/30': statuses[{{ $student->id }}] === 'excused', 'bg-rose-50/30': statuses[{{ $student->id }}] === 'absent' }">
                            <span class="hidden font-code text-code text-on-surface-variant md:block">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <div class="min-w-0">
                                <div class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $student->name }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">
                                    <span class="font-code">{{ $student->code }}</span>
                                    @if ((int) $student->current_class_id !== (int) $class->id) · Học viên liên kết lớp @endif
                                    @if ($record?->recorder && (int) $record->recorded_by !== (int) $record->user_id) · Lưu bởi {{ $record->recorder->name }} @endif
                                </div>
                            </div>
                            <select name="status[{{ $student->id }}]" x-model="statuses[{{ $student->id }}]" aria-label="Trạng thái điểm danh {{ $student->name }}"
                                    class="w-full rounded-lg border py-sm pl-md pr-xl font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/20 {{ $options[$initialStatuses[$student->id]]['tone'] ?? 'border-outline-variant' }}">
                                @foreach ($options as $value => $opt)
                                    <option value="{{ $value }}" @selected($initialStatuses[$student->id] === $value)>{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                            <div>
                                <input type="text" name="note[{{ $student->id }}]" maxlength="500" value="{{ old('note.'.$student->id, $record?->note) }}"
                                       :placeholder="['absent', 'excused'].includes(statuses[{{ $student->id }}]) ? 'Nhập lý do nghỉ học... *' : 'Ghi chú thêm (tùy chọn)...'"
                                       :required="['absent', 'excused'].includes(statuses[{{ $student->id }}])"
                                       placeholder="Ghi chú thêm (tùy chọn)..." aria-label="Ghi chú {{ $student->name }}"
                                       class="w-full rounded-lg border px-md py-sm font-body-small text-body-small focus:border-primary-container focus:ring-primary-container/20 {{ $noteError ? 'border-error' : 'border-outline-variant' }}">
                                @if ($noteError)
                                    <p class="mt-xs font-caption text-caption text-error">{{ $noteError }}</p>
                                @else
                                    <p class="mt-xs font-caption text-caption text-error" x-show="['absent', 'excused'].includes(statuses[{{ $student->id }}])" x-cloak>* Cần ghi rõ lý do khi đánh dấu nghỉ</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col justify-between gap-sm border-t border-surface-container bg-surface-container-low p-md sm:flex-row sm:items-center">
                    <p class="font-caption text-caption text-on-surface-variant">Phiếu điểm danh sẽ được ghi đè (upsert) cập nhật trực tiếp cho buổi học này. Học viên vắng tự vào danh sách bổ trợ.</p>
                    <x-ui.button type="submit" icon="save" class="w-full sm:w-auto">Lưu điểm danh</x-ui.button>
                </div>
            </form>
        @endif
    </div>

    @include('teacher.partials.bottom-nav')
</x-app-layout>
