<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('teacher.home') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">monitoring</span>
                        Báo cáo giảng dạy của tôi — T{{ $month }}/{{ $year }}
                    </h1>
                    <p class="text-xs text-gray-500">Giờ dạy &amp; trạng thái duyệt, điểm danh đã chấm, điểm đã nhập trong tháng</p>
                </div>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="month" name="period" value="{{ sprintf('%04d-%02d', $year, $month) }}"
                       onchange="this.form.submit()"
                       class="text-xs rounded-xl border border-gray-200 px-3 py-1.5 font-semibold" />
            </form>
        </div>
    </x-slot>

    <div class="space-y-4">
        <!-- Thẻ tổng quan -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs text-gray-500">Tổng giờ dạy tháng</span>
                <div class="text-2xl font-black text-gray-900 mt-1">{{ number_format($timesheets->sum('hours'), 1) }}h</div>
                <div class="text-[11px] text-gray-400 mt-0.5">{{ $timesheets->count() }} phiên chấm công</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs text-gray-500">Giờ đã đối soát</span>
                <div class="text-2xl font-black text-emerald-600 mt-1">{{ number_format($timesheets->where('status', 'valid')->sum('hours'), 1) }}h</div>
                <div class="text-[11px] text-gray-400 mt-0.5">{{ $timesheets->where('status', 'valid')->count() }} phiên hợp lệ</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs text-gray-500">Buổi đã điểm danh</span>
                <div class="text-2xl font-black text-primary mt-1">{{ $attendanceMarked }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">bản ghi điểm danh</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <span class="text-xs text-gray-500">Điểm mini test đã nhập</span>
                <div class="text-2xl font-black text-indigo-600 mt-1">{{ $scoresEntered }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">bản ghi điểm</div>
            </div>
        </div>

        <!-- Lớp đang phụ trách -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">school</span>
                    Lớp tôi phụ trách
                </h2>
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Lớp</th>
                        <th class="py-3 px-4">Khóa học</th>
                        <th class="py-3 px-4">Vai trò</th>
                        <th class="py-3 px-4">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($myClasses as $cls)
                        <tr>
                            <td class="py-3 px-4 font-bold text-gray-900">{{ $cls->name }} <span class="font-mono text-[10px] text-gray-400">{{ $cls->code }}</span></td>
                            <td class="py-3 px-4">{{ $cls->course?->name ?? $cls->program ?? '—' }}</td>
                            <td class="py-3 px-4">
                                @if ($cls->teacher_id === auth()->id())
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-primary/10 text-primary">GV chính</span>
                                @endif
                                @if ($cls->foreign_teacher_id === auth()->id())
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700">GVNN</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-gray-500">{{ $cls->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-8 text-gray-400 text-xs">Chưa được phân công lớp nào.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Chi tiết chấm công theo trạng thái -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">schedule</span>
                    Chi tiết chấm công tháng {{ sprintf('%02d/%d', $month, $year) }}
                </h2>
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Ngày dạy</th>
                        <th class="py-3 px-4">Lớp</th>
                        <th class="py-3 px-4">Loại</th>
                        <th class="py-3 px-4 text-right">Giờ</th>
                        <th class="py-3 px-4">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($timesheets->sortByDesc('teaching_date') as $ts)
                        <tr>
                            <td class="py-3 px-4 font-mono text-gray-600">{{ optional($ts->teaching_date)->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-3 px-4 font-semibold">{{ $ts->classModel?->name ?? '—' }}</td>
                            <td class="py-3 px-4">{{ $ts->type }}</td>
                            <td class="py-3 px-4 text-right font-mono font-bold">{{ number_format((float) $ts->hours, 1) }}h</td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $ts->status === 'valid' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($ts->status === 'invalid' ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200') }}">
                                    {{ $ts->status === 'valid' ? 'Đã duyệt' : ($ts->status === 'invalid' ? 'Bị từ chối' : 'Chờ duyệt') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-8 text-gray-400 text-xs">Chưa có phiên chấm công nào trong tháng.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
