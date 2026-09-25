<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600">emoji_events</span>
                    <span>Bảng Xếp Hạng KPI &amp; Hoa Hồng Công Khai</span>
                </h1>
                <p class="text-xs text-gray-500">Dữ liệu công khai nhằm mục đích thi đua khen thưởng toàn hệ thống MEnglish</p>
            </div>
            <a href="{{ route('payroll.config.commission-tiers') }}" class="px-3.5 py-2 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">settings</span>
                <span>Cấu hình Mốc hoa hồng</span>
            </a>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{ selectedBranch: 'all', selectedPeriod: '08/2026' }">
        
        <!-- Header Info Notice -->
        <div class="bg-blue-50/70 border border-blue-200 rounded-2xl p-4 flex items-start gap-3 text-xs">
            <span class="material-symbols-outlined text-blue-600 text-xl shrink-0 mt-0.5">info</span>
            <div class="space-y-0.5">
                <p class="font-bold text-blue-950">Chính sách công khai KPI minh bạch:</p>
                <p class="text-blue-700 leading-relaxed">
                    Bảng xếp hạng chỉ hiển thị số lượng học sinh duy trì, doanh số chốt và tổng tiền thưởng KPI/hoa hồng nhằm tạo động lực thi đua. Thông tin không bao gồm lương cơ bản, các khoản giảm trừ và thực nhận cá nhân.
                </p>
            </div>
        </div>

        {{-- $usersWithSales được tính ở PayrollController::kpiLeaderboard():
             doanh số theo commission_user_id (fallback assigned_user_id), bậc hoa hồng
             qua CommissionTier::matchForRevenue — cùng luật với tính lương. --}}

        <!-- Top 3 Podium Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @if ($usersWithSales->count() > 0)
                @php $top1 = $usersWithSales->get(0); @endphp
                <div class="bg-gradient-to-br from-amber-500 via-orange-500 to-amber-600 rounded-2xl p-6 text-white shadow-lg space-y-3 relative overflow-hidden">
                    <div class="absolute -right-4 -bottom-4 text-white/10 font-black text-8xl pointer-events-none select-none">1</div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-black uppercase tracking-wider bg-white/20 px-3 py-1 rounded-full flex items-center gap-1">
                            <span>🥇 QUÁN QUÂN KPI</span>
                        </span>
                        <span class="text-xs text-amber-100 font-medium">{{ $top1['branch_name'] }}</span>
                    </div>
                    <div>
                        <h3 class="text-xl font-black">{{ $top1['user']->name }}</h3>
                        <p class="text-xs text-amber-100">Duy trì <strong class="text-white font-mono">{{ $top1['retained_students'] }}</strong> học sinh</p>
                    </div>
                    <div class="pt-2 border-t border-white/20">
                        <span class="text-[10px] text-amber-100 uppercase font-bold block">Tổng KPI / Hoa hồng:</span>
                        <div class="text-2xl font-black font-mono tracking-tight text-white">{{ number_format($top1['commission']) }}đ</div>
                    </div>
                </div>
            @endif

            @if ($usersWithSales->count() > 1)
                @php $top2 = $usersWithSales->get(1); @endphp
                <div class="bg-gradient-to-br from-slate-700 via-slate-800 to-slate-900 rounded-2xl p-6 text-white shadow-md space-y-3 relative overflow-hidden">
                    <div class="absolute -right-4 -bottom-4 text-white/10 font-black text-8xl pointer-events-none select-none">2</div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-black uppercase tracking-wider bg-white/20 px-3 py-1 rounded-full flex items-center gap-1">
                            <span>🥈 Á QUÂN KPI</span>
                        </span>
                        <span class="text-xs text-slate-300 font-medium">{{ $top2['branch_name'] }}</span>
                    </div>
                    <div>
                        <h3 class="text-xl font-black">{{ $top2['user']->name }}</h3>
                        <p class="text-xs text-slate-300">Duy trì <strong class="text-white font-mono">{{ $top2['retained_students'] }}</strong> học sinh</p>
                    </div>
                    <div class="pt-2 border-t border-white/20">
                        <span class="text-[10px] text-slate-300 uppercase font-bold block">Tổng KPI / Hoa hồng:</span>
                        <div class="text-2xl font-black font-mono tracking-tight text-white">{{ number_format($top2['commission']) }}đ</div>
                    </div>
                </div>
            @endif

            @if ($usersWithSales->count() > 2)
                @php $top3 = $usersWithSales->get(2); @endphp
                <div class="bg-gradient-to-br from-amber-700 via-amber-800 to-amber-900 rounded-2xl p-6 text-white shadow-md space-y-3 relative overflow-hidden">
                    <div class="absolute -right-4 -bottom-4 text-white/10 font-black text-8xl pointer-events-none select-none">3</div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-black uppercase tracking-wider bg-white/20 px-3 py-1 rounded-full flex items-center gap-1">
                            <span>🥉 QUÝ QUÂN KPI</span>
                        </span>
                        <span class="text-xs text-amber-200 font-medium">{{ $top3['branch_name'] }}</span>
                    </div>
                    <div>
                        <h3 class="text-xl font-black">{{ $top3['user']->name }}</h3>
                        <p class="text-xs text-amber-200">Duy trì <strong class="text-white font-mono">{{ $top3['retained_students'] }}</strong> học sinh</p>
                    </div>
                    <div class="pt-2 border-t border-white/20">
                        <span class="text-[10px] text-amber-200 uppercase font-bold block">Tổng KPI / Hoa hồng:</span>
                        <div class="text-2xl font-black font-mono tracking-tight text-white">{{ number_format($top3['commission']) }}đ</div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Leaderboard Table Card -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-orange-600 text-base">leaderboard</span>
                    <span>Bảng Tổng Sắp Thành Tích Toàn Hệ Thống</span>
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3.5 px-4 text-center w-16">Hạng</th>
                            <th class="py-3.5 px-4">Nhân viên / Giáo viên</th>
                            <th class="py-3.5 px-4">Chi nhánh</th>
                            <th class="py-3.5 px-4 text-right">Số HS Giữ / Deals</th>
                            <th class="py-3.5 px-4 text-right">Doanh số chốt</th>
                            <th class="py-3.5 px-4 text-right font-black">Tổng KPI / Hoa hồng (VNĐ)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @foreach ($usersWithSales as $index => $item)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3.5 px-4 text-center">
                                    @if ($index === 0)
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-100 text-amber-700 font-black text-xs">🥇</span>
                                    @elseif ($index === 1)
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-black text-xs">🥈</span>
                                    @elseif ($index === 2)
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-50 text-amber-800 font-black text-xs">🥉</span>
                                    @else
                                        <span class="font-mono font-bold text-gray-500 text-xs">#{{ $index + 1 }}</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 font-bold text-gray-900">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center font-bold text-xs">
                                            {{ Str::substr($item['user']->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900">{{ $item['user']->name }}</p>
                                            <p class="text-[10px] text-gray-400 font-mono">{{ $item['tier_name'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-gray-600 font-medium">{{ $item['branch_name'] }}</td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-gray-900">{{ $item['retained_students'] }} HS ({{ $item['deals'] }} deals)</td>
                                <td class="py-3.5 px-4 text-right font-mono font-semibold text-indigo-700">{{ number_format($item['revenue']) }}đ</td>
                                <td class="py-3.5 px-4 text-right font-mono font-black text-orange-600 text-sm">
                                    {{ number_format($item['commission']) }}đ
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
