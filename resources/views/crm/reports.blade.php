<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-6">
        <!-- Page Title & Realtime Status -->
        <div class="flex flex-col sm:flex-row sm:items-baseline sm:justify-between gap-1">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Báo cáo doanh số</h1>
                <p class="text-xs text-gray-500 mt-0.5">Tổng quan hiệu suất chuyển đổi &amp; lý do thất bại theo khoảng thời gian</p>
            </div>
            <div class="text-xs text-gray-500 italic">
                Cập nhật: Realtime
            </div>
        </div>

        <!-- Filter Card matching Screenshot 1 -->
        <form method="GET" action="{{ route('crm.reports') }}" class="bg-white rounded-2xl border border-gray-200 p-5 shadow-xs space-y-4">
            <!-- CHỌN NHANH MỐC THỜI GIAN -->
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider text-gray-700 mb-2">
                    CHỌN NHANH MỐC THỜI GIAN
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @php
                        $presetButtons = [
                            'today' => 'Hôm nay',
                            'yesterday' => 'Hôm qua',
                            'last_7_days' => '7 ngày trước',
                            'last_week' => 'Tuần trước',
                            'last_30_days' => '30 ngày trước',
                            'last_60_days' => '60 ngày',
                            'last_90_days' => '90 ngày',
                            'last_6_months' => '6 tháng',
                            'last_year' => '1 năm',
                        ];
                    @endphp

                    @foreach ($presetButtons as $key => $label)
                        <button 
                            type="submit" 
                            name="preset" 
                            value="{{ $key }}"
                            class="px-3.5 py-1.5 rounded-xl text-xs transition {{ $preset === $key ? 'bg-primary-container text-white font-bold shadow-xs' : 'bg-[#f0f4f9] text-slate-700 hover:bg-slate-200 font-medium' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="border-t border-gray-100 pt-3.5 grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
                <!-- KHOẢNG THỜI GIAN -->
                <div class="lg:col-span-7 space-y-1">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-gray-700">
                        KHOẢNG THỜI GIAN
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="relative flex-1">
                            <input 
                                type="date" 
                                name="start_date" 
                                value="{{ $startDate->format('Y-m-d') }}" 
                                class="w-full text-xs font-medium rounded-xl border border-gray-200 px-3.5 py-2 bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container"
                            />
                        </div>
                        <span class="text-xs text-gray-500 font-medium shrink-0">đến</span>
                        <div class="relative flex-1">
                            <input 
                                type="date" 
                                name="end_date" 
                                value="{{ $endDate->format('Y-m-d') }}" 
                                class="w-full text-xs font-medium rounded-xl border border-gray-200 px-3.5 py-2 bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container"
                            />
                        </div>
                    </div>
                </div>

                <!-- CHI NHÁNH & NÚT LỌC DỮ LIỆU -->
                <div class="lg:col-span-5 space-y-1">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-gray-700">
                        CHI NHÁNH
                    </div>
                    <div class="flex items-center gap-2">
                        <select 
                            name="branch_id" 
                            class="w-full text-xs font-medium rounded-xl border border-gray-200 px-3.5 py-2 bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container"
                        >
                            <option value="">Tất cả chi nhánh</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (string)$branchId === (string)$branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>

                        <button 
                            type="submit" 
                            name="preset" 
                            value="custom"
                            class="px-4 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition inline-flex items-center gap-1.5 shrink-0"
                        >
                            <span class="material-symbols-outlined text-base">search</span>
                            <span>Lọc dữ liệu</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <!-- 4 Top Metric Cards matching Screenshot 1 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Card 1: Số lượng Lead -->
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-xs relative">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700">Số lượng Lead</span>
                    <span class="material-symbols-outlined text-blue-500 text-xl font-light">group</span>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="text-3xl font-black text-gray-900 font-mono tracking-tight">
                        {{ $metricTotalLeads }}
                    </div>
                    <div class="text-xs font-bold text-emerald-600 flex items-center gap-0.5">
                        <span>↗</span>
                        <span>+{{ $leadDeltaPercent }}%</span>
                    </div>
                </div>
                <div class="text-[11px] text-gray-500 mt-2">
                    Tăng <span class="font-bold text-gray-800">+{{ $leadDiff }} lead</span> so với kỳ trước
                </div>
            </div>

            <!-- Card 2: Khách đã chốt -->
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-xs relative">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700">Khách đã chốt</span>
                    <span class="material-symbols-outlined text-emerald-500 text-xl font-light">check_circle</span>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="text-3xl font-black text-emerald-600 font-mono tracking-tight">
                        {{ $metricWonDeals }}
                    </div>
                    <div class="text-xs font-bold text-emerald-600 flex items-center gap-0.5">
                        <span>↗</span>
                        <span>+{{ $wonDeltaPercent }}%</span>
                    </div>
                </div>
                <div class="text-[11px] text-gray-500 mt-2">
                    Tăng <span class="font-bold text-gray-800">+{{ $wonDiff }} khách</span> so với kỳ trước
                </div>
            </div>

            <!-- Card 3: Tỷ lệ chốt thành công -->
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-xs relative">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700">Tỷ lệ chốt thành công</span>
                    <span class="material-symbols-outlined text-primary-container text-xl font-light">bookmark</span>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="text-3xl font-black text-primary-container font-mono tracking-tight">
                        {{ $metricConversionRate }}%
                    </div>
                    <div class="text-xs font-bold text-emerald-600 flex items-center gap-0.5">
                        <span>↗</span>
                        <span>+{{ $conversionDeltaPercent }}%</span>
                    </div>
                </div>
                <div class="text-[11px] text-gray-500 mt-2">
                    Cải thiện so với trung bình kỳ trước
                </div>
            </div>

            <!-- Card 4: Khách không chốt -->
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-xs relative">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700">Khách không chốt</span>
                    <span class="material-symbols-outlined text-rose-500 text-xl font-light">person_remove</span>
                </div>
                <div class="mt-3 flex items-baseline justify-between">
                    <div class="text-3xl font-black text-rose-600 font-mono tracking-tight">
                        {{ $metricLostDeals }}
                    </div>
                    <div class="text-xs font-bold text-emerald-600 flex items-center gap-0.5">
                        <span>↘</span>
                        <span>{{ $lostDeltaPercent }}%</span>
                    </div>
                </div>
                <div class="text-[11px] text-gray-500 mt-2">
                    Giảm <span class="font-bold text-gray-800">{{ $lostDiff }} lead fail</span> so với kỳ trước
                </div>
            </div>
        </div>

        <!-- Conversion Funnel (Giai đoạn chuyển đổi) matching Screenshot 2 -->
        <div class="space-y-3">
            <h2 class="text-lg font-bold text-gray-900 tracking-tight">Giai đoạn chuyển đổi</h2>

            <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-6 shadow-xs space-y-3">
                @forelse ($funnelStages as $stage)
                    @php $indent = $loop->first ? 0 : min(42, $loop->index * 10); @endphp
                    <div class="{{ $loop->first ? 'w-full' : 'flex justify-end' }}">
                        <div class="w-full flex items-stretch rounded-xl overflow-hidden bg-gray-50 text-gray-800 border border-gray-200 shadow-2xs"
                             title="{{ $stage['desc'] }}"
                             @unless($loop->first) style="max-width: {{ 100 - $indent }}%;" @endunless>
                            <div class="w-24 sm:w-28 flex items-center justify-center font-bold text-base {{ $stage['bar_color'] }} text-white shrink-0 py-3.5">
                                {{ $stage['count'] }}
                            </div>
                            <div class="flex-1 flex items-center justify-between px-4 sm:px-6">
                                <span class="text-xs sm:text-[13px] font-bold uppercase tracking-wider">
                                    {{ mb_strtoupper($stage['name']) }}
                                </span>
                                <div class="flex items-center gap-3 font-semibold">
                                    <span class="text-xs">{{ $stage['percent'] }}%</span>
                                    <span class="material-symbols-outlined text-lg">{{ $loop->first ? 'group' : ($loop->last ? 'check_circle' : 'filter_alt') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 text-center py-4">Chưa có lead nào trong kỳ.</p>
                @endforelse
            </div>
        </div>

        <!-- Sales Performance Table by Rep (Bảng hiệu suất & Tỷ lệ chốt theo người phụ trách) -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs p-5 sm:p-6 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-gray-100 gap-2">
                <div>
                    <h2 class="text-base font-bold text-gray-900 tracking-tight">
                        Bảng hiệu suất &amp; Tỷ lệ chốt theo người phụ trách
                    </h2>
                    <p class="text-xs text-gray-500">Thống kê chi tiết số lượng Lead, doanh số và hoa hồng theo từng chuyên viên</p>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button variant="secondary" size="sm" icon="download" :href="request()->fullUrlWithQuery(['export' => 'xlsx'])">Xuất Excel</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" :href="request()->fullUrlWithQuery(['export' => 'csv'])">CSV</x-ui.button>
                </div>

                @can('commission_config.manage')
                <a href="{{ route('payroll.config.commission-tiers') }}" class="text-xs font-bold text-primary-container hover:underline inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">settings</span>
                    <span>Cấu hình công thức hoa hồng &rarr;</span>
                </a>
                @endcan
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200 text-[11px]">
                        <tr>
                            <th class="py-3 px-4">Người phụ trách</th>
                            <th class="py-3 px-3 text-center">Số lượng Lead</th>
                            <th class="py-3 px-3 text-center">SL chốt thành công</th>
                            <th class="py-3 px-3 text-center">% Chốt thành công</th>
                            <th class="py-3 px-4 text-right">Doanh thu</th>
                            <th class="py-3 px-4 text-right bg-brand-surface text-on-primary-fixed-variant font-bold">Hoa hồng (Tier)</th>
                            <th class="py-3 px-3 text-center">Biến động % vs kỳ trước</th>
                            <th class="py-3 px-3 text-center">Đánh giá</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @foreach ($repsData as $rep)
                            <tr class="hover:bg-orange-50/20 transition">
                                <!-- Người phụ trách -->
                                <td class="py-3.5 px-4 font-bold text-gray-900">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-7 h-7 rounded-full bg-primary-container text-white flex items-center justify-center font-bold text-xs shrink-0">
                                            {{ $rep['avatar_letter'] }}
                                        </span>
                                        <div>
                                            <div class="font-bold text-gray-900">{{ $rep['name'] }}</div>
                                            <div class="text-[10px] text-gray-400 font-normal">{{ $rep['role'] }}</div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Số lượng Lead -->
                                <td class="py-3.5 px-3 text-center font-mono font-bold text-gray-800">
                                    {{ $rep['leads'] }}
                                </td>

                                <!-- SL chốt thành công -->
                                <td class="py-3.5 px-3 text-center font-mono font-bold text-emerald-600">
                                    {{ $rep['won'] }}
                                </td>

                                <!-- % Chốt thành công -->
                                <td class="py-3.5 px-3 text-center font-mono font-bold text-gray-900">
                                    {{ $rep['rate'] }}%
                                </td>

                                <!-- Doanh thu -->
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-gray-900">
                                    {{ number_format($rep['revenue']) }} đ
                                </td>

                                <!-- Hoa hồng -->
                                <td class="py-3.5 px-4 text-right bg-brand-surface">
                                    <div class="font-mono font-bold text-primary-container">
                                        {{ number_format($rep['commission_amount']) }} đ
                                    </div>
                                    <div class="text-[10px] text-gray-500 font-sans">
                                        {{ $rep['tier_name'] }} ({{ $rep['commission_percent'] }}%{{ $rep['commission_bonus'] > 0 ? ' + ' . number_format($rep['commission_bonus']) . 'đ' : '' }})
                                    </div>
                                </td>

                                <!-- Biến động % vs kỳ trước -->
                                <td class="py-3.5 px-3 text-center font-mono font-bold {{ str_starts_with($rep['delta'], '+') ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ $rep['delta'] }}
                                </td>

                                <!-- Đánh giá -->
                                <td class="py-3.5 px-3 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border {{ $rep['rating_badge'] }}">
                                        {{ $rep['rating'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Footer Plugin Note matching Prompt -->
            <div class="pt-2 flex flex-col sm:flex-row sm:items-center justify-between text-xs text-gray-500 gap-2">
                <div>
                    <span class="font-mono font-bold text-gray-700">[Plugin: crm_sales_report_tab]</span>
                    <span>Dữ liệu được tổng hợp theo thời gian thực từ CRM.</span>
                </div>
                <div>
                    Công thức hoa hồng được tính tự động @can('commission_config.manage') từ <a href="{{ route('payroll.config.commission-tiers') }}" class="font-bold text-primary-container hover:underline">Cấu hình Mốc Hoa hồng</a> @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
