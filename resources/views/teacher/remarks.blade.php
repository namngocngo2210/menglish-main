{{-- Nhận xét buổi học cho từng học sinh (mockup 03_Cong_Giao_Vien/05): theo từng BUỔI học, cột Monsters (Nhóm) / (Thưởng),
     Thực hành ngữ pháp, Tinh thần học tập, Kết quả, Nhận xét chi tiết; học sinh vắng bị khóa; "Lưu nháp" chưa hiện cho học viên. --}}
@php
    $cell = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small focus:border-primary-container focus:ring-2 focus:ring-primary-container/50 disabled:cursor-not-allowed disabled:bg-surface-container-low';
    $fields = [
        'monsters_group' => ['Monsters (Nhóm)', 'e.g. +5'],
        'monsters_bonus' => ['Monsters (Thưởng)', 'e.g. +2'],
        'grammar' => ['Thực hành ngữ pháp', 'Tốt / Khá / Cần cố gắng'],
        'attitude' => ['Tinh thần học tập', 'Năng nổ, hăng hái'],
        'result' => ['Kết quả', 'Đạt mục tiêu bài học'],
    ];
    $canSave = $session && ! $blockReason && $students->isNotEmpty();
@endphp
<x-app-layout title="Nhận xét buổi học — {{ $class->name }}">
    <div class="mx-auto max-w-7xl space-y-lg pb-24 md:pb-0">
        <form method="POST" action="{{ route('teacher.remarks.store', $class->id) }}" id="remarks-form" class="space-y-lg">
            @csrf
            @if ($session)<input type="hidden" name="class_session_id" value="{{ $session->id }}">@endif

            <x-ui.page-header title="Nhận xét buổi học cho từng học sinh" :back="route('teacher.home')" back-label="Về lịch dạy">
                <x-slot:meta>
                    <div class="flex flex-wrap items-center gap-sm">
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">class</span>Lớp {{ $class->name }}</span>
                        @if ($session)
                            <span class="h-1 w-1 rounded-full bg-outline-variant"></span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">event</span>{{ $sessionNo ? 'Buổi '.$sessionNo.': ' : '' }}{{ $session->date->format('d/m/Y') }} · {{ $session->start_time?->format('H:i') }}-{{ $session->end_time?->format('H:i') }}</span>
                            @if ($record?->status === 'draft')<x-ui.badge color="warning">Bản nháp</x-ui.badge>@elseif ($record)<x-ui.badge color="success">Đã lưu</x-ui.badge>@endif
                        @endif
                    </div>
                </x-slot:meta>
                @if ($canSave)
                    <x-slot:actions>
                        <x-ui.button type="submit" name="action" value="draft" variant="secondary" icon="save">Lưu nháp</x-ui.button>
                        <x-ui.button type="submit" name="action" value="final" icon="check_circle">Lưu nhận xét</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.page-header>

            @if ($errors->any())
                <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
            @endif

            @include('teacher.partials.trial-guests')

            @if (! $session)
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    @if ($recentSessions->isNotEmpty())
                        <x-ui.empty-state icon="event_busy" title="Lớp không có buổi học trong ngày này" description="Chọn buổi cần nhận xét ở ô “Nhận xét buổi khác” bên dưới." />
                    @else
                        <x-ui.empty-state icon="event_busy" title="Lớp chưa có buổi học nào" description="Lớp chưa được xếp thời khóa biểu. Liên hệ Học vụ để kiểm tra TKB của lớp." />
                    @endif
                </div>
            @elseif ($blockReason)
                <x-ui.alert type="warning">{{ $blockReason }}</x-ui.alert>
            @elseif ($students->isEmpty())
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <x-ui.empty-state icon="group_off" title="Chưa có học viên" description="Buổi học này chưa có học viên nào trong danh sách lớp." />
                </div>
            @else
                {{-- Màn nhỏ: mỗi học sinh một thẻ xếp dọc (như trang điểm danh); từ md trở lên: dạng bảng, cuộn ngang khi hẹp. --}}
                @php $grid = 'md:grid md:grid-cols-[200px_130px_110px_110px_160px_160px_160px_minmax(260px,1fr)] md:items-start md:gap-md'; @endphp
                {{-- Không đặt overflow-hidden ở màn nhỏ: sẽ làm hỏng thanh lưu sticky. --}}
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm md:overflow-hidden">
                    <div class="custom-scrollbar md:overflow-x-auto">
                        <div class="md:min-w-[1320px]">
                            <div class="hidden border-b border-outline-variant bg-surface-container-low px-md py-3 font-label text-label uppercase tracking-wider text-on-surface-variant {{ $grid }}">
                                <span>Học sinh</span><span>Điểm danh</span>
                                @foreach ($fields as [$label])<span>{{ $label }}</span>@endforeach
                                <span>Nhận xét chi tiết</span>
                            </div>
                            <div class="divide-y divide-surface-container">
                                @foreach ($students as $student)
                                    @php
                                        $att = $attendance->get($student->id);
                                        $attStatus = $att?->status ?? 'none';
                                        $isAbsent = in_array($attStatus, ['absent', 'excused'], true);
                                        $remark = (array) ($existing->get($student->id) ?? $existing->get((string) $student->id) ?? []);
                                    @endphp
                                    <div class="space-y-sm p-md md:space-y-0 md:px-md md:py-sm {{ $grid }} {{ $isAbsent ? 'bg-surface-container-low/60' : '' }}" data-testid="remark-row">
                                        <div class="flex items-center justify-between gap-sm md:contents">
                                            <div class="flex min-w-0 items-center gap-sm">
                                                <x-ui.avatar :name="$student->name" size="sm" />
                                                <span class="font-body-medium text-body-medium font-semibold text-on-surface md:font-normal">{{ $student->name }}</span>
                                            </div>
                                            <div class="shrink-0 whitespace-nowrap md:pt-xs">
                                                @if ($attStatus === 'present')
                                                    <span class="inline-flex items-center gap-xs rounded bg-tertiary-fixed/40 px-sm py-[2px] font-caption text-caption font-semibold text-on-tertiary-fixed-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">check</span>Có mặt</span>
                                                @elseif ($attStatus === 'late')
                                                    <span class="inline-flex items-center gap-xs rounded bg-warning-container px-sm py-[2px] font-caption text-caption font-semibold text-on-warning-container"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>Đi muộn</span>
                                                @elseif ($isAbsent)
                                                    <span class="inline-flex items-center gap-xs rounded bg-error-container px-sm py-[2px] font-caption text-caption font-semibold text-on-error-container"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">close</span>Vắng mặt</span>
                                                @else
                                                    <span class="inline-flex items-center gap-xs rounded bg-surface-container-high px-sm py-[2px] font-caption text-caption font-semibold text-on-surface-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">help</span>Chưa điểm danh</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-sm md:contents">
                                            @foreach ($fields as $key => [$label, $placeholder])
                                                <label class="block {{ $loop->index >= 2 ? 'col-span-2' : '' }}">
                                                    <span class="mb-[2px] block font-caption text-caption text-on-surface-variant md:hidden">{{ $label }}</span>
                                                    <input type="text" name="remarks[{{ $student->id }}][{{ $key }}]" value="{{ old('remarks.'.$student->id.'.'.$key, $remark[$key] ?? '') }}"
                                                           placeholder="{{ $isAbsent ? '-' : $placeholder }}" aria-label="{{ $label }} — {{ $student->name }}" @disabled($isAbsent)
                                                           class="{{ $cell }}">
                                                </label>
                                            @endforeach
                                        </div>
                                        <label class="block">
                                            <span class="mb-[2px] block font-caption text-caption text-on-surface-variant md:hidden">Nhận xét chi tiết</span>
                                            <textarea name="remarks[{{ $student->id }}][comment]" rows="2" aria-label="Nhận xét chi tiết — {{ $student->name }}" @disabled($isAbsent)
                                                      placeholder="{{ $isAbsent ? 'Học sinh vắng mặt...' : 'Nhận xét chi tiết về quá trình học tập trong buổi học này...' }}"
                                                      class="{{ $cell }}">{{ old('remarks.'.$student->id.'.comment', $remark['comment'] ?? '') }}</textarea>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    {{-- Điện thoại: thanh lưu bám ngay trên thanh điều hướng dưới để không phải cuộn hết danh sách. --}}
                    <div class="sticky bottom-[72px] z-20 flex flex-row justify-end gap-sm border-t border-outline-variant bg-surface-container-lowest p-md shadow-level-3 md:static md:shadow-none">
                        <x-ui.button type="submit" name="action" value="draft" variant="secondary" class="flex-1 md:flex-none">Lưu nháp</x-ui.button>
                        <x-ui.button type="submit" name="action" value="final" icon="save" class="flex-1 md:flex-none">Lưu nhận xét</x-ui.button>
                    </div>
                </div>
            @endif
        </form>

        {{-- Chọn buổi khác --}}
        @if ($recentSessions->isNotEmpty())
            <form method="GET" action="{{ route('teacher.remarks', $class->id) }}" class="flex flex-col gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md sm:flex-row sm:items-center">
                <label for="remark-session" class="shrink-0 font-label-caps text-label-caps uppercase text-on-surface-variant">Nhận xét buổi khác</label>
                <x-ui.select id="remark-session" name="session" onchange="this.form.submit()" class="flex-1">
                    @unless ($session)
                        {{-- Chưa chọn buổi: ô chọn để trống thay vì hiện buổi đầu danh sách mà trang không mở. --}}
                        <option value="" selected disabled>— Chọn buổi —</option>
                    @endunless
                    @foreach ($recentSessions as $s)
                        <option value="{{ $s->id }}" @selected($session && $session->id === $s->id) @disabled($s->status === 'cancelled')>
                            {{ $s->date->format('d/m/Y') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}{{ $s->type === \App\Models\ClassSession::TYPE_MAKEUP ? ' · Học bù' : '' }}{{ $s->status === 'cancelled' ? ' · Đã hủy' : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
                <noscript><x-ui.button type="submit" size="sm" variant="secondary">Chọn</x-ui.button></noscript>
            </form>
        @endif
    </div>

    @include('teacher.partials.bottom-nav')
</x-app-layout>
