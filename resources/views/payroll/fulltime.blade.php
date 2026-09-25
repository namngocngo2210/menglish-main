<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.show', $period->id) }}" class="w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600">badge</span>
                        <span>Chi Tiết Bảng Lương Giáo Viên Full-Time</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full border font-bold {{ $period->status_badge }}">
                            {{ $period->status_label }}
                        </span>
                    </h1>
                    <p class="text-xs text-gray-500">Kỳ tính lương: {{ $period->title }} ({{ $period->code }})</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('payroll.periods.export', [$period->id, 'department' => 'fulltime']) }}" class="px-3.5 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">download</span>
                    <span>Xuất Excel</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        <!-- Navigation Sub-tabs -->
        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 text-xs">
            <a href="{{ route('payroll.periods.show', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">groups</span>
                <span>Toàn bộ / GV Part-time</span>
            </a>
            <a href="{{ route('payroll.periods.fulltime', $period->id) }}" class="px-4 py-2 font-bold rounded-xl bg-orange-600 text-white shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">work</span>
                <span>Giáo viên Full-time</span>
            </a>
            <a href="{{ route('payroll.periods.academic', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">school</span>
                <span>Khối Học Thuật</span>
            </a>
            <a href="{{ route('payroll.periods.operations', $period->id) }}" class="px-4 py-2 font-semibold rounded-xl bg-white text-gray-700 border border-gray-200 hover:bg-slate-50 transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">support_agent</span>
                <span>Khối Học Vụ &amp; Vận Hành</span>
            </a>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
            
            <!-- Left Column: Full-time Salary Records List (Col 8) -->
            <div class="xl:col-span-8 space-y-6">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-orange-600 text-base">work</span>
                            <span>Danh Sách Giáo Viên Cơ Hữu (Full-Time)</span>
                        </h3>
                        <span class="text-xs font-bold text-gray-500 font-mono">{{ $records->count() }} nhân sự</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                                    <th class="py-3 px-4">Giáo viên</th>
                                    <th class="py-3 px-4 text-right">Lương cứng</th>
                                    <th class="py-3 px-4 text-center">Định mức</th>
                                    <th class="py-3 px-4 text-center">Thực dạy</th>
                                    <th class="py-3 px-4 text-right">Thù lao dạy</th>
                                    <th class="py-3 px-4 text-right">Thưởng KPI</th>
                                    <th class="py-3 px-4 text-right">Phụ cấp</th>
                                    <th class="py-3 px-4 text-right">Hoa hồng</th>
                                    <th class="py-3 px-4 text-right">Giảm trừ</th>
                                    <th class="py-3 px-4 text-right font-black">Thực lĩnh</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                                @forelse ($records as $r)
                                    <tr class="hover:bg-orange-50/20 transition">
                                        <td class="py-3.5 px-4 font-bold text-gray-900">
                                            <div class="flex items-center gap-2">
                                                <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center font-bold text-xs">
                                                    {{ Str::substr($r->user?->name ?? 'G', 0, 1) }}
                                                </div>
                                                <div>
                                                    <a href="{{ route('payroll.records.show', $r->id) }}" class="text-xs font-bold text-gray-900 hover:text-orange-600 hover:underline" title="Xem phiếu lương">{{ $r->user?->name }}</a>
                                                    <p class="text-[10px] text-gray-400 font-mono">{{ $r->user?->email }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4 text-right font-mono font-semibold">{{ number_format($r->base_salary) }}đ</td>
                                        <td class="py-3.5 px-4 text-center font-mono font-bold">{{ $r->standard_hours ?: 60 }}h</td>
                                        <td class="py-3.5 px-4 text-center font-mono font-bold text-indigo-700">{{ $r->actual_hours }}h</td>
                                        <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">{{ number_format($r->teaching_salary) }}đ</td>
                                        <td class="py-3.5 px-4 text-right font-mono text-amber-600 font-semibold">{{ number_format($r->kpi_bonus) }}đ</td>
                                        <td class="py-3.5 px-4 text-right font-mono">{{ number_format($r->allowance) }}đ</td>
                                        <td class="py-3.5 px-4 text-right font-mono text-emerald-600 font-semibold">{{ number_format($r->commission_bonus) }}đ</td>
                                        <td class="py-3.5 px-4 text-right font-mono text-rose-600">-{{ number_format($r->total_deductions) }}đ</td>
                                        <td class="py-3.5 px-4 text-right font-mono font-black text-orange-600 text-sm">
                                            {{ number_format($r->net_salary) }}đ
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center py-8 text-gray-400 text-xs">Chưa có bản ghi lương giáo viên full-time trong kỳ này.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Detailed Compensation Formula Reference -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-3 text-xs">
                    <h4 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-orange-600 text-base">functions</span>
                        <span>Quy Chế Tính Lương GV Cơ Hữu MEnglish</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        {{-- Công thức đang áp dụng trong PayrollPeriod::calculatePayrollForPeriod(); công thức chính thức chờ BA chốt Q3. --}}
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">1. Lương cứng &amp; phụ cấp</span>
                            <p class="text-gray-500 text-[11px]">Lương cứng theo hồ sơ nhân sự; có lương cứng thì được phụ cấp và trừ BHXH theo cấu hình tham số lương.</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">2. Thù lao giờ dạy</span>
                            <p class="text-gray-500 text-[11px]">Số giờ chấm công hợp lệ × đơn giá hiệu lực tại ngày dạy (đơn giá riêng ca dạy → đơn giá GV → hồ sơ nhân sự).</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
                            <span class="font-bold text-gray-800">3. Thưởng KPI</span>
                            <p class="text-gray-500 text-[11px]">Đạt ngưỡng giờ dạy trong kỳ thì nhận thưởng KPI theo cấu hình. Thưởng tái tục chưa áp dụng (chờ BA chốt Q3).</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Summary Card (Col 4) -->
            <div class="xl:col-span-4 space-y-4">
                <div class="bg-gradient-to-br from-orange-600 to-amber-600 text-white rounded-2xl p-6 shadow-md space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-orange-100">Tổng chi GV Full-Time</span>
                        <span class="material-symbols-outlined text-2xl text-amber-200">account_balance</span>
                    </div>
                    <div>
                        <div class="text-3xl font-black font-mono tracking-tight">
                            {{ number_format($records->sum('net_salary')) }}đ
                        </div>
                        <p class="text-xs text-orange-100 mt-1">{{ $records->count() }} giáo viên cơ hữu trong kỳ</p>
                    </div>
                    <div class="pt-3 border-t border-white/20 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-orange-200 block text-[10px] uppercase font-bold">Tổng giờ thực dạy:</span>
                            <span class="font-bold font-mono text-sm">{{ $records->sum('actual_hours') }} giờ</span>
                        </div>
                        <div>
                            <span class="text-orange-200 block text-[10px] uppercase font-bold">Tổng KPI thưởng:</span>
                            <span class="font-bold font-mono text-sm">{{ number_format($records->sum('kpi_bonus')) }}đ</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</x-app-layout>
