{{-- Nhận xét buổi học cho từng học sinh (mockup 03_Cong_Giao_Vien/05): theo từng BUỔI học, cột Monsters (Nhóm) / (Thưởng),
     Thực hành ngữ pháp, Tinh thần học tập, Kết quả, Nhận xét chi tiết; học sinh vắng bị khóa; "Lưu nháp" chưa hiện cho học viên. --}}
@php
    $cell = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 disabled:cursor-not-allowed disabled:bg-surface-container-low';
    $fields = [
        'monsters_group' => ['Monsters (Nhóm)', 'e.g. +5', 'w-[110px]'],
        'monsters_bonus' => ['Monsters (Thưởng)', 'e.g. +2', 'w-[110px]'],
        'grammar' => ['Thực hành ngữ pháp', 'Tốt / Khá / Cần cố gắng', 'w-[160px]'],
        'attitude' => ['Tinh thần học tập', 'Năng nổ, hăng hái', 'w-[160px]'],
        'result' => ['Kết quả', 'Đạt mục tiêu bài học', 'w-[160px]'],
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

            @if (! $session)
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <x-ui.empty-state icon="event_busy" title="Lớp không có buổi học trong ngày này" description="Chọn một buổi ở danh sách bên dưới để nhận xét." />
                </div>
            @elseif ($blockReason)
                <x-ui.alert type="warning">{{ $blockReason }}</x-ui.alert>
            @elseif ($students->isEmpty())
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <x-ui.empty-state icon="group_off" title="Chưa có học viên" description="Buổi học này chưa có học viên nào trong danh sách lớp." />
                </div>
            @else
                <x-ui.data-table min-width="1180px" class="shadow-sm">
                        <table>
                            <thead>
                                <tr>
                                    <th class="sticky left-0 z-10 w-[200px] border-r border-surface-container bg-surface-container-low">Học sinh</th>
                                    <th>Điểm danh</th>
                                    @foreach ($fields as [$label])<th>{{ $label }}</th>@endforeach
                                    <th class="min-w-[260px]">Nhận xét chi tiết</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($students as $student)
                                    @php
                                        $att = $attendance->get($student->id);
                                        $attStatus = $att?->status ?? 'none';
                                        $isAbsent = in_array($attStatus, ['absent', 'excused'], true);
                                        $remark = (array) ($existing->get($student->id) ?? $existing->get((string) $student->id) ?? []);
                                    @endphp
                                    <tr class="{{ $isAbsent ? 'bg-surface-container-low/60' : '' }}">
                                        <td class="sticky left-0 z-10 border-r border-surface-container {{ $isAbsent ? 'bg-surface-container-low' : 'bg-surface-container-lowest' }}">
                                            <div class="flex items-center gap-sm">
                                                <x-ui.avatar :name="$student->name" size="sm" />
                                                <span class="font-body-medium text-body-medium text-on-surface">{{ $student->name }}</span>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap">
                                            @if ($attStatus === 'present')
                                                <span class="inline-flex items-center gap-xs rounded bg-tertiary-fixed/40 px-sm py-[2px] font-caption text-caption font-semibold text-on-tertiary-fixed-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">check</span>Có mặt</span>
                                            @elseif ($attStatus === 'late')
                                                <span class="inline-flex items-center gap-xs rounded bg-warning-container px-sm py-[2px] font-caption text-caption font-semibold text-on-warning-container"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>Đi muộn</span>
                                            @elseif ($isAbsent)
                                                <span class="inline-flex items-center gap-xs rounded bg-error-container px-sm py-[2px] font-caption text-caption font-semibold text-on-error-container"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">close</span>Vắng mặt</span>
                                            @else
                                                <span class="inline-flex items-center gap-xs rounded bg-surface-container-high px-sm py-[2px] font-caption text-caption font-semibold text-on-surface-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">help</span>Chưa điểm danh</span>
                                            @endif
                                        </td>
                                        @foreach ($fields as $key => [$label, $placeholder, $width])
                                            <td>
                                                <input type="text" name="remarks[{{ $student->id }}][{{ $key }}]" value="{{ old('remarks.'.$student->id.'.'.$key, $remark[$key] ?? '') }}"
                                                       placeholder="{{ $isAbsent ? '-' : $placeholder }}" aria-label="{{ $label }} — {{ $student->name }}" @disabled($isAbsent)
                                                       class="{{ $cell }} {{ $width }}">
                                            </td>
                                        @endforeach
                                        <td>
                                            <textarea name="remarks[{{ $student->id }}][comment]" rows="2" aria-label="Nhận xét chi tiết — {{ $student->name }}" @disabled($isAbsent)
                                                      placeholder="{{ $isAbsent ? 'Học sinh vắng mặt...' : 'Nhận xét chi tiết về quá trình học tập trong buổi học này...' }}"
                                                      class="{{ $cell }}">{{ old('remarks.'.$student->id.'.comment', $remark['comment'] ?? '') }}</textarea>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    <x-slot:footer>
                        <div class="flex flex-col justify-end gap-sm p-md sm:flex-row">
                            <x-ui.button type="submit" name="action" value="draft" variant="secondary">Lưu nháp</x-ui.button>
                            <x-ui.button type="submit" name="action" value="final" icon="save">Lưu nhận xét</x-ui.button>
                        </div>
                    </x-slot:footer>
                </x-ui.data-table>
            @endif
        </form>

        {{-- Chọn buổi khác --}}
        @if ($recentSessions->isNotEmpty())
            <form method="GET" action="{{ route('teacher.remarks', $class->id) }}" class="flex flex-col gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md sm:flex-row sm:items-center">
                <label for="remark-session" class="shrink-0 font-label-caps text-label-caps uppercase text-on-surface-variant">Nhận xét buổi khác</label>
                <x-ui.select id="remark-session" name="session" onchange="this.form.submit()" class="flex-1">
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
