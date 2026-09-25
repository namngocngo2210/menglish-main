<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600">wallet</span>
                    <span>Lương Của Tôi (Teacher &amp; Staff Portal)</span>
                </h1>
                <p class="text-xs text-gray-500">Tra cứu chi tiết giờ dạy, các khoản thưởng KPI và phiếu lương cá nhân hàng tháng</p>
            </div>
            <div class="flex items-center gap-2">
                @if ($records->isNotEmpty())
                    <form method="GET" action="{{ route('portal.my-salary') }}">
                        <select name="period_id" onchange="this.form.submit()" class="text-xs rounded-xl border border-gray-300 py-2 pl-3 pr-8 font-semibold text-gray-700">
                            @foreach ($records as $option)
                                <option value="{{ $option->payroll_period_id }}" @selected($record && $option->payroll_period_id === $record->payroll_period_id)>{{ $option->period->title }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <button type="button" onclick="window.print();" class="px-3.5 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold shadow-2xs transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span>
                    <span>Tải Phiếu Lương PDF</span>
                </button>
            </div>
        </div>
    </x-slot>

    @php
        $netSalary = $record ? $record->net_salary : 0;
        $incomeTotal = $record ? $record->gross_income : 0;
        $deductionTotal = $record ? $record->total_deductions : 0;
        $hoursTaught = $record ? $record->actual_hours : 0;
    @endphp

    <div class="space-y-6">

        <!-- 3 Bento Summary Cards (Matching Mockup) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            
            <!-- Card 1: Tổng thu nhập -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex flex-col justify-between space-y-4">
                <div class="flex justify-between items-start">
                    <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Tổng thu nhập</span>
                    <div class="w-9 h-9 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl">payments</span>
                    </div>
                </div>
                <div>
                    <div class="text-2xl font-black text-gray-900 font-mono tracking-tight">{{ number_format($incomeTotal) }} ₫</div>
                    <div class="text-[11px] text-emerald-600 font-semibold flex items-center gap-0.5 mt-1">
                        <span class="material-symbols-outlined text-[14px]">trending_up</span>
                        <span>Đã bao gồm thù lao {{ $hoursTaught }}h dạy &amp; KPI</span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Tổng khoản trừ -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex flex-col justify-between space-y-4">
                <div class="flex justify-between items-start">
                    <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Tổng khoản trừ</span>
                    <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl">money_off</span>
                    </div>
                </div>
                <div>
                    <div class="text-2xl font-black text-rose-600 font-mono tracking-tight">{{ number_format($deductionTotal) }} ₫</div>
                    <div class="text-[11px] text-gray-400 flex items-center gap-0.5 mt-1">
                        <span class="material-symbols-outlined text-[14px]">info</span>
                        <span>BHXH, Thuế TNCN &amp; Phạt (nếu có)</span>
                    </div>
                </div>
            </div>

            <!-- Card 3: Thực nhận (Orange Gradient Box) -->
            <div class="rounded-2xl p-6 bg-gradient-to-br from-orange-600 via-orange-500 to-amber-600 text-white shadow-lg relative overflow-hidden flex flex-col justify-between space-y-4">
                <div class="absolute -right-6 -bottom-6 w-28 h-28 bg-white/10 rounded-full blur-xl pointer-events-none"></div>
                <div class="flex justify-between items-start relative z-10">
                    <span class="text-xs font-black text-orange-100 uppercase tracking-wider">Thực nhận chuyển khoản</span>
                    <div class="w-9 h-9 rounded-xl bg-white/20 text-white flex items-center justify-center">
                        <span class="material-symbols-outlined text-xl">account_balance</span>
                    </div>
                </div>
                <div class="relative z-10">
                    <div class="text-3xl font-black font-mono tracking-tight">{{ number_format($netSalary) }} ₫</div>
                    <div class="text-xs text-orange-100 mt-1 font-medium">
                        {{ $record?->period?->status === 'paid' ? 'Đã chi trả qua tài khoản Ngân hàng' : 'Dự kiến chi trả qua tài khoản Ngân hàng' }}
                    </div>
                </div>
            </div>

        </div>

        <!-- Detailed Payslip Breakdown Card -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-gray-100 gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-orange-100 text-orange-600 flex items-center justify-center font-black text-xl shadow-xs">
                        {{ Str::substr(Auth::user()?->name ?? 'G', 0, 1) }}
                    </div>
                    <div>
                        <h2 class="text-base font-black text-gray-900">{{ Auth::user()?->name ?? 'Giáo viên MEnglish' }}</h2>
                        <p class="text-xs text-gray-500 font-mono">{{ Auth::user()?->email }} · {{ Auth::user()?->phone ?? 'Chưa cập nhật SĐT' }}</p>
                    </div>
                </div>
                <div class="text-left sm:text-right">
                    <span class="text-[10px] text-gray-400 uppercase font-bold block">Phiếu lương kỳ:</span>
                    <span class="text-sm font-black text-orange-600 font-mono">{{ $record?->period?->title ?? 'Chưa có kỳ quyết toán' }}</span>
                </div>
            </div>

            <!-- Breakdown Grids -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                
                <!-- Income Components -->
                <div class="space-y-3 p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <h3 class="font-bold text-gray-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-emerald-600 text-base">add_circle</span>
                        <span>1. Chi tiết các khoản thu nhập</span>
                    </h3>
                    <div class="space-y-2">
                        @foreach ($record?->earningLines() ?? [] as $line)
                            <div class="flex justify-between py-1 border-b border-gray-200 last:border-0">
                                <span class="text-gray-600">{{ $line['label'] }}:</span>
                                <span class="font-mono font-bold {{ $line['key'] === 'base_salary' ? 'text-gray-900' : 'text-emerald-600' }}">{{ $line['key'] === 'base_salary' ? '' : '+' }}{{ number_format($line['amount']) }}đ</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Deduction Components -->
                <div class="space-y-3 p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <h3 class="font-bold text-gray-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-rose-600 text-base">remove_circle</span>
                        <span>2. Chi tiết các khoản giảm trừ</span>
                    </h3>
                    <div class="space-y-2">
                        @foreach ($record?->deductionLines() ?? [] as $line)
                            <div class="flex justify-between py-1 border-b border-gray-200 last:border-0">
                                <span class="text-gray-600">{{ $line['label'] }}:</span>
                                <span class="font-mono font-bold text-rose-600">-{{ number_format($line['amount']) }}đ</span>
                            </div>
                        @endforeach
                        @if ($record?->adjustment_notes)
                            <p class="pt-1 text-[11px] text-gray-500 italic">Ghi chú kế toán: {{ $record->adjustment_notes }}</p>
                        @endif
                    </div>
                </div>

            </div>

            <!-- Verification Footer -->
            <div class="p-4 bg-orange-50/70 border border-orange-200 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                <div class="space-y-0.5">
                    <p class="font-bold text-orange-950">Xác thực phiếu lương điện tử MEnglish:</p>
                    <p class="text-[11px] text-orange-700">Mọi thắc mắc về số giờ dạy hoặc khoản thưởng KPI, vui lòng phản hồi phòng Kế toán trước ngày 03 hàng tháng.</p>
                </div>
                <div class="shrink-0">
                    @if ($record)
                        <span class="px-3 py-1 rounded-full border font-bold text-[10px] uppercase shadow-2xs {{ $record->period->status_badge }}">
                            {{ $record->period->status_label }}
                        </span>
                    @else
                        <span class="px-3 py-1 bg-gray-100 text-gray-500 rounded-full font-bold text-[10px] uppercase">Chưa có kỳ lương đã duyệt</span>
                    @endif
                </div>
            </div>

        </div>

    </div>
</x-app-layout>
