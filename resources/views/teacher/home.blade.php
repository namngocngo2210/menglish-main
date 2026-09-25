<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">how_to_reg</span>
                Cổng Giáo viên — Lịch dạy & Check-in
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Xin chào {{ $teacher->name }} · {{ \Carbon\Carbon::parse($today)->format('d/m/Y') }}
                · <a href="{{ route('teacher.trial-guests') }}" class="font-semibold text-primary hover:underline">Khách học thử</a></p>
        </div>
    </x-slot>

    @php
        $typeLabels = [
            \App\Models\ClassSession::TYPE_REGULAR => null,
            \App\Models\ClassSession::TYPE_MAKEUP => 'Học bù',
            \App\Models\ClassSession::TYPE_SUPPORT => 'Phụ đạo',
        ];
    @endphp

    <div class="space-y-6">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Ca dạy hôm nay</div>
                <div class="mt-2 text-2xl font-black text-gray-900">{{ $stats['total'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Đã check-in</div>
                <div class="mt-2 text-2xl font-black text-emerald-600">{{ $stats['checked_in'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Đã điểm danh</div>
                <div class="mt-2 text-2xl font-black text-primary">{{ $stats['attendance_done'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Buổi chưa điểm danh</div>
                <div class="mt-2 text-2xl font-black {{ $stats['pending'] > 0 ? 'text-rose-600' : 'text-gray-900' }}">{{ $stats['pending'] }}</div>
            </div>
        </div>

        <!-- Hôm nay -->
        @if ($shifts->isEmpty())
            <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300">event_busy</span>
                <p class="mt-2 text-sm">Hôm nay bạn không có buổi dạy nào trên lịch.</p>
            </div>
        @else
            <form method="POST" action="{{ route('teacher.checkin') }}" class="space-y-4">
                @csrf
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Ca dạy hôm nay · chọn nhiều ca để check-in cùng lúc</h2>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                        Check-in các ca đã chọn
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($shifts as $shift)
                        @php $class = $shift['class']; $session = $shift['session']; @endphp
                        <div class="bg-white rounded-2xl p-5 border {{ $shift['checked_in'] ? 'border-emerald-300' : 'border-gray-200' }} shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <label class="flex items-start gap-3 cursor-pointer flex-1">
                                    @unless ($shift['checked_in'])
                                        <input type="checkbox" name="session_ids[]" value="{{ $session->id }}"
                                               class="mt-1 w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary-container">
                                    @else
                                        <span class="material-symbols-outlined text-emerald-500 text-[20px]">check_circle</span>
                                    @endunless
                                    <div>
                                        <div class="font-bold text-sm text-gray-900 flex items-center gap-2">
                                            {{ $class?->name }}
                                            @if ($typeLabels[$session->type] ?? null)
                                                <x-ui.badge color="info">{{ $typeLabels[$session->type] }}</x-ui.badge>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-gray-500 mt-0.5">
                                            {{ $class?->code }} · {{ $class?->branch?->name ?? 'Chưa gán chi nhánh' }} · {{ $shift['student_count'] }} HV
                                            @if ($session->type === \App\Models\ClassSession::TYPE_SUPPORT && $session->supportSession?->student)
                                                · {{ $session->supportSession->student->name }}
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-gray-500 mt-0.5 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[14px]">schedule</span>
                                            {{ $shift['scheduled_time'] }} @if ($session->room) · Phòng {{ $session->room }} @endif
                                        </div>
                                    </div>
                                </label>
                                @if ($shift['checked_in'])
                                    <span class="shrink-0 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full">
                                        Đã check-in {{ $shift['checkin_time'] }}
                                    </span>
                                @endif
                            </div>

                            <div class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap gap-2">
                                <a href="{{ route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id]) }}"
                                   class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold transition {{ $shift['attendance_done'] ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-orange-50 text-primary hover:bg-orange-100' }}">
                                    <span class="material-symbols-outlined text-[16px]">fact_check</span>
                                    {{ $shift['attendance_done'] ? 'Đã điểm danh' : 'Điểm danh' }}
                                </a>
                                @if ($session->type !== \App\Models\ClassSession::TYPE_SUPPORT)
                                    <a href="{{ route('teacher.homework', $class->id) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-blue-50 text-blue-700 hover:bg-blue-100 transition">
                                        <span class="material-symbols-outlined text-[16px]">assignment</span> Giao bài
                                    </a>
                                    <a href="{{ route('teacher.scores', $class->id) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-purple-50 text-purple-700 hover:bg-purple-100 transition">
                                        <span class="material-symbols-outlined text-[16px]">grading</span> Nhập điểm
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </form>
        @endif

        <!-- Buổi đã qua chưa điểm danh -->
        @if ($pendingSessions->isNotEmpty())
            <div class="bg-white rounded-2xl border border-rose-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-rose-100 bg-rose-50/60">
                    <h2 class="text-sm font-bold text-rose-800 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">pending_actions</span>
                        Buổi đã dạy chưa điểm danh (điểm danh bù)
                    </h2>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($pendingSessions as $s)
                        <div class="px-5 py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                            <div>
                                <span class="font-bold text-gray-900">{{ $s->classModel?->name }}</span>
                                <span class="text-gray-500">· {{ $s->date->format('d/m/Y') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}</span>
                                @if ($typeLabels[$s->type] ?? null)
                                    <x-ui.badge color="info">{{ $typeLabels[$s->type] }}</x-ui.badge>
                                @endif
                            </div>
                            <a href="{{ route('teacher.attendance', ['classId' => $s->class_id, 'session' => $s->id]) }}"
                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-orange-50 text-primary font-bold hover:bg-orange-100">
                                <span class="material-symbols-outlined text-[16px]">fact_check</span> Điểm danh bù
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Lịch tuần -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">calendar_view_week</span>
                    Lịch dạy tuần {{ $weekStart->format('d/m') }} – {{ $weekStart->copy()->addDays(6)->format('d/m/Y') }}
                </h2>
                <div class="flex items-center gap-2 text-xs font-semibold">
                    <a href="{{ route('teacher.home', ['week' => $weekStart->copy()->subWeek()->toDateString()]) }}" class="px-2.5 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50">← Tuần trước</a>
                    <a href="{{ route('teacher.home') }}" class="px-2.5 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50">Tuần này</a>
                    <a href="{{ route('teacher.home', ['week' => $weekStart->copy()->addWeek()->toDateString()]) }}" class="px-2.5 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50">Tuần sau →</a>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-7 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                @foreach ($weekDays as $day)
                    @php $isToday = $day['date']->isToday(); @endphp
                    <div class="p-3 min-h-[90px] {{ $isToday ? 'bg-orange-50/40' : '' }}">
                        <div class="text-[11px] font-bold uppercase tracking-wider {{ $isToday ? 'text-primary' : 'text-gray-500' }}">
                            {{ ['', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'][$day['date']->isoWeekday()] }} · {{ $day['date']->format('d/m') }}
                        </div>
                        <div class="mt-2 space-y-2">
                            @forelse ($day['sessions'] as $s)
                                @php
                                    $cancelled = $s->status === 'cancelled';
                                    $done = $attendanceDone->has($s->id);
                                    $canTake = ! $cancelled && ! $s->date->isFuture();
                                @endphp
                                <div class="rounded-lg border px-2 py-1.5 text-[11px] {{ $cancelled ? 'border-gray-200 bg-gray-50 text-gray-400 line-through' : ($done ? 'border-emerald-200 bg-emerald-50/60' : 'border-gray-200') }}">
                                    <div class="font-bold text-gray-900 {{ $cancelled ? 'text-gray-400' : '' }}">{{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}</div>
                                    <div class="truncate">{{ $s->classModel?->name }}</div>
                                    <div class="text-gray-500">
                                        {{ $s->room ? 'P. '.$s->room : '' }}
                                        @if ($typeLabels[$s->type] ?? null) · {{ $typeLabels[$s->type] }} @endif
                                        @if ($cancelled) · Đã hủy @endif
                                    </div>
                                    @if ($canTake)
                                        <a href="{{ route('teacher.attendance', ['classId' => $s->class_id, 'session' => $s->id]) }}" class="mt-1 inline-block font-bold {{ $done ? 'text-emerald-700' : 'text-primary' }} hover:underline">
                                            {{ $done ? 'Đã điểm danh' : 'Điểm danh' }}
                                        </a>
                                    @endif
                                </div>
                            @empty
                                <div class="text-[11px] text-gray-300">—</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
