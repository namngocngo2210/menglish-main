<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-cyan-600">payments</span>
                    Danh sách Bảng lương theo kỳ (Epic 7)
                </h1>
                <p class="text-xs text-gray-500">Quản lý các kỳ tính lương, chốt công giáo viên và phê duyệt chi trả</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('payroll.timesheets.manual') }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">edit_calendar</span>
                    <span>Chấm công</span>
                </a>
                <button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-primary hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_chart</span>
                    <span>Tạo kỳ lương mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach ($periods as $period)
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm hover:shadow-md hover:border-primary/40 transition flex flex-col justify-between space-y-4">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold font-mono px-2 py-0.5 rounded bg-gray-100 text-gray-700">{{ $period['id'] }}</span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border {{ $period['status_badge'] }}">{{ $period['status'] }}</span>
                        </div>
                        <h3 class="font-extrabold text-base text-gray-900">{{ $period['title'] }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $period['period'] }}</p>

                        <div class="mt-4 pt-3 border-t border-gray-100 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-gray-400 block text-[11px]">Giáo viên &amp; Nhân sự:</span>
                                <span class="font-bold text-gray-900">{{ $period['total_teachers'] }} người</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block text-[11px]">Tổng giờ dạy:</span>
                                <span class="font-bold text-gray-900 font-mono">{{ $period['total_hours'] }}</span>
                            </div>
                        </div>

                        <div class="mt-3 p-3 bg-orange-50/40 rounded-xl border border-orange-200">
                            <span class="text-[11px] font-medium text-gray-600">Tổng quỹ lương kỳ:</span>
                            <div class="text-lg font-black text-primary font-mono">{{ $period['total_amount'] }}</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <a href="{{ route('payroll.periods.show', $period['id']) }}" class="flex-1 text-center py-2 bg-primary hover:bg-primary-hover text-white rounded-xl text-xs font-bold shadow-sm transition">
                            Chi tiết bảng lương
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
