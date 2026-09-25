<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">how_to_reg</span>
                Cổng Giáo viên — Check-in & Lịch dạy hôm nay
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Xin chào {{ $teacher->name }} · {{ \Carbon\Carbon::parse($today)->format('d/m/Y') }}
                · <a href="{{ route('teacher.trial-guests') }}" class="font-semibold text-primary hover:underline">Khách học thử</a></p>
        </div>
    </x-slot>

    <div class="space-y-6">
        
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">
                {{ $errors->first() }}
            </div>
        @endif

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-4">
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
        </div>

        @if ($shifts->isEmpty())
            <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300">event_busy</span>
                <p class="mt-2 text-sm">Bạn chưa được phân công lớp nào đang hoạt động.</p>
            </div>
        @else
            <form method="POST" action="{{ route('teacher.checkin') }}" class="space-y-4">
                @csrf
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Danh sách ca dạy · chọn nhiều ca để check-in cùng lúc</h2>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                        Check-in các ca đã chọn
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($shifts as $shift)
                        @php $class = $shift['class']; @endphp
                        <div class="bg-white rounded-2xl p-5 border {{ $shift['checked_in'] ? 'border-emerald-300' : 'border-gray-200' }} shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <label class="flex items-start gap-3 cursor-pointer flex-1">
                                    @unless ($shift['checked_in'])
                                        <input type="checkbox" name="class_ids[]" value="{{ $class->id }}"
                                               class="mt-1 w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary">
                                    @else
                                        <span class="material-symbols-outlined text-emerald-500 text-[20px]">check_circle</span>
                                    @endunless
                                    <div>
                                        <div class="font-bold text-sm text-gray-900">{{ $class->name }}</div>
                                        <div class="text-[11px] text-gray-500 mt-0.5">
                                            {{ $class->code }} · {{ $class->branch?->name ?? 'Trung tâm' }} · {{ $shift['student_count'] }} HV
                                        </div>
                                        <div class="text-[11px] text-gray-500 mt-0.5 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[14px]">schedule</span>
                                            {{ $shift['scheduled_time'] ?? 'Chưa có lịch' }}
                                        </div>
                                    </div>
                                </label>
                                @if ($shift['checked_in'])
                                    <span class="shrink-0 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full">
                                        Đã check-in {{ $shift['checkin_time'] }}
                                    </span>
                                @endif
                            </div>

                            @if ($shift['checked_in'])
                                <div class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap gap-2">
                                    <a href="{{ route('teacher.attendance', $class->id) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold transition {{ $shift['attendance_done'] ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-orange-50 text-primary hover:bg-orange-100' }}">
                                        <span class="material-symbols-outlined text-[16px]">fact_check</span>
                                        {{ $shift['attendance_done'] ? 'Điểm danh ✓' : 'Điểm danh' }}
                                    </a>
                                    <a href="{{ route('teacher.homework', $class->id) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-blue-50 text-blue-700 hover:bg-blue-100 transition">
                                        <span class="material-symbols-outlined text-[16px]">assignment</span> Giao bài
                                    </a>
                                    <a href="{{ route('teacher.scores', $class->id) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-purple-50 text-purple-700 hover:bg-purple-100 transition">
                                        <span class="material-symbols-outlined text-[16px]">grading</span> Nhập điểm
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
