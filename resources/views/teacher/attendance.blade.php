<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">fact_check</span>
                    Điểm danh lớp {{ $class->name }}
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ $class->code }}
                    @if ($session)
                        · Buổi ngày {{ $session->date->format('d/m/Y') }}, {{ $session->start_time?->format('H:i') }}-{{ $session->end_time?->format('H:i') }}
                        @if ($session->room) · Phòng {{ $session->room }} @endif
                        @if ($session->type === \App\Models\ClassSession::TYPE_MAKEUP) · Buổi học bù @endif
                        @if ($session->type === \App\Models\ClassSession::TYPE_SUPPORT) · Buổi phụ đạo @endif
                    @endif
                </p>
            </div>
            <a href="{{ route('teacher.home') }}" class="text-xs font-semibold text-gray-500 hover:text-primary flex items-center gap-1">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Về lịch dạy
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <!-- Chọn buổi -->
        @if ($recentSessions->isNotEmpty())
            <form method="GET" action="{{ route('teacher.attendance', $class->id) }}" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <label for="session-picker" class="text-xs font-bold text-gray-600 uppercase tracking-wider shrink-0">Buổi điểm danh</label>
                <select id="session-picker" name="session" onchange="this.form.submit()" class="flex-1 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                    @foreach ($recentSessions as $s)
                        <option value="{{ $s->id }}" @selected($session && $session->id === $s->id) @disabled($s->status === 'cancelled')>
                            {{ $s->date->format('d/m/Y') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}
                            @if ($s->type === \App\Models\ClassSession::TYPE_MAKEUP) · Học bù @elseif ($s->type === \App\Models\ClassSession::TYPE_SUPPORT) · Phụ đạo @endif
                            @if ($s->status === 'cancelled') · Đã hủy @elseif ($s->attendances_count > 0) · Đã điểm danh ({{ $s->attendances_count }}) @else · Chưa điểm danh @endif
                        </option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="px-3 py-2 rounded-lg bg-gray-100 text-xs font-bold">Chọn</button></noscript>
            </form>
        @endif

        @if (! $session)
            <x-ui.empty-state icon="event_busy" title="Lớp không có buổi học trong ngày này"
                description="Chọn một buổi ở danh sách phía trên để điểm danh bù, hoặc kiểm tra thời khóa biểu của lớp."
                class="bg-white rounded-2xl border border-gray-200 shadow-sm" />
        @elseif ($blockReason)
            <x-ui.alert type="warning">{{ $blockReason }}</x-ui.alert>
        @elseif ($students->isEmpty())
            <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300">group_off</span>
                <p class="mt-2 text-sm">Buổi học này chưa có học viên nào trong danh sách lớp.</p>
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

            <form method="POST" action="{{ route('teacher.attendance.store', $class->id) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="class_session_id" value="{{ $session->id }}">
                @php
                    $options = [
                        'present' => ['label' => 'Có mặt', 'class' => 'peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500'],
                        'late' => ['label' => 'Đi muộn', 'class' => 'peer-checked:bg-amber-500 peer-checked:text-white peer-checked:border-amber-500'],
                        'absent' => ['label' => 'Vắng', 'class' => 'peer-checked:bg-rose-500 peer-checked:text-white peer-checked:border-rose-500'],
                        'excused' => ['label' => 'Có phép', 'class' => 'peer-checked:bg-blue-500 peer-checked:text-white peer-checked:border-blue-500'],
                    ];
                @endphp

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
                    @foreach ($students as $student)
                        @php $record = $existing->get($student->id); $current = $record?->status ?? 'present'; @endphp
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-gray-900">{{ $student->name }}</div>
                                <div class="text-[11px] text-gray-400">
                                    {{ $student->code }}
                                    @if ((int) $student->current_class_id !== (int) $class->id) · Học viên liên kết lớp @endif
                                    @if ($record?->recorder && (int) $record->recorded_by !== (int) $record->user_id) · Lưu bởi {{ $record->recorder->name }} @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($options as $value => $opt)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="status[{{ $student->id }}]" value="{{ $value }}"
                                               class="sr-only peer" {{ $current === $value ? 'checked' : '' }}>
                                        <span class="inline-block px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 transition {{ $opt['class'] }}">
                                            {{ $opt['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <input type="text" name="note[{{ $student->id }}]" maxlength="500"
                                   value="{{ $record?->note }}"
                                   placeholder="Ghi chú..."
                                   class="w-full sm:w-48 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <p class="text-[11px] text-gray-500">Học viên vắng sẽ tự được đưa vào danh sách bổ trợ.</p>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        Lưu điểm danh
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
