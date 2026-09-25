<x-app-layout>
    @include('crm.partials.header-tabs')

    @php
        $trend = function (float|int $delta, bool $upIsGood = true) {
            $good = $delta == 0 ? null : (($delta > 0) === $upIsGood);
            return [
                'arrow' => $delta > 0 ? 'trending_up' : ($delta < 0 ? 'trending_down' : 'trending_flat'),
                'tone' => $good === null ? 'text-on-surface-variant' : ($good ? 'text-tertiary' : 'text-error'),
                'sign' => $delta > 0 ? '+' : '',
            ];
        };
        $cards = [
            ['Số lượng khách', $metricTotalLeads, 'group', 'text-secondary', $leadDeltaPercent.'%', $trend($leadDiff), ($leadDiff >= 0 ? 'Tăng ' : 'Giảm ').abs($leadDiff).' khách so với kỳ trước'],
            ['Khách đã chốt', $metricWonDeals, 'check_circle', 'text-tertiary', $wonDeltaPercent.'%', $trend($wonDiff), ($wonDiff >= 0 ? 'Tăng ' : 'Giảm ').abs($wonDiff).' khách so với kỳ trước'],
            ['Tỷ lệ chốt thành công', $metricConversionRate.'%', 'bookmark', 'text-primary-container', $conversionDeltaPercent.' điểm %', $trend($conversionDeltaPercent), 'So với tỷ lệ kỳ trước'],
            ['Khách không chốt', $metricLostDeals, 'person_remove', 'text-error', $lostDeltaPercent.'%', $trend($lostDiff, false), ($lostDiff >= 0 ? 'Tăng ' : 'Giảm ').abs($lostDiff).' khách thất bại so với kỳ trước'],
        ];
    @endphp

    {{-- Mockup crm-ui-mockup/bao-cao-doanh-so: bộ lọc (khoảng thời gian, chi nhánh), giai đoạn chuyển đổi, lý do không chốt, ghi chú nguồn dữ liệu --}}
    <div class="flex flex-col gap-lg">
        <header class="flex flex-col gap-xs sm:flex-row sm:items-baseline sm:justify-between">
            <h2 class="font-h2 text-h2 text-on-surface">Báo cáo doanh số</h2>
            <span class="font-body-small text-body-small italic text-on-surface-variant">Cập nhật: {{ now()->format('H:i d/m/Y') }}</span>
        </header>

        <form method="GET" action="{{ route('crm.reports') }}" class="space-y-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <div class="flex flex-wrap items-center gap-sm">
                <span class="font-label text-label uppercase text-on-surface-variant">Chọn nhanh:</span>
                @foreach (['today' => 'Hôm nay', 'yesterday' => 'Hôm qua', 'last_7_days' => '7 ngày', 'last_week' => 'Tuần trước', 'last_30_days' => '30 ngày', 'last_60_days' => '60 ngày', 'last_90_days' => '90 ngày', 'last_6_months' => '6 tháng', 'last_year' => '1 năm'] as $key => $label)
                    <button type="submit" name="preset" value="{{ $key }}"
                            class="rounded-full px-md py-xs font-body-small text-body-small transition-colors {{ $preset === $key ? 'bg-primary-container font-semibold text-white' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-col gap-md border-t border-surface-container pt-md lg:flex-row lg:items-end">
                <div class="flex flex-col gap-xs">
                    <label class="font-label text-label uppercase text-on-surface-variant">Khoảng thời gian</label>
                    <div class="flex items-center gap-sm">
                        <input type="date" name="start_date" value="{{ $startDate->format('Y-m-d') }}" aria-label="Từ ngày" class="rounded-lg border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20" />
                        <span class="font-body-small text-body-small text-on-surface-variant">đến</span>
                        <input type="date" name="end_date" value="{{ $endDate->format('Y-m-d') }}" aria-label="Đến ngày" class="rounded-lg border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20" />
                    </div>
                </div>
                <div class="flex min-w-[220px] flex-col gap-xs">
                    <label for="report_branch" class="font-label text-label uppercase text-on-surface-variant">Chi nhánh</label>
                    <select id="report_branch" name="branch_id" class="rounded-lg border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20">
                        <option value="">Tất cả</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) $branchId === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" name="preset" value="custom" icon="search">Lọc dữ liệu</x-ui.button>
            </div>
        </form>

        <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cards as [$label, $value, $icon, $tone, $delta, $t, $hint])
                <div class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-body-medium text-body-medium text-on-surface-variant">{{ $label }}</span>
                        <span class="material-symbols-outlined {{ $tone }}">{{ $icon }}</span>
                    </div>
                    <div class="mt-sm flex items-baseline justify-between">
                        <span class="font-h1 text-h1 {{ $tone }}">{{ $value }}</span>
                        <span class="flex items-center gap-xs font-body-small text-body-small font-semibold {{ $t['tone'] }}">
                            <span class="material-symbols-outlined text-[16px]">{{ $t['arrow'] }}</span>{{ $t['sign'] }}{{ $delta }}
                        </span>
                    </div>
                    <p class="mt-xs font-caption text-caption text-on-surface-variant">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        {{-- Giai đoạn chuyển đổi (8 bước A6) --}}
        <section class="space-y-md">
            <h2 class="font-h3 text-h3 text-on-surface">Giai đoạn chuyển đổi</h2>
            <div class="space-y-sm rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                @forelse ($funnelStages as $stage)
                    <div class="flex justify-center">
                        <div class="flex w-full items-stretch overflow-hidden rounded-lg border border-surface-container-highest bg-surface-container-low" title="{{ $stage['desc'] }}" style="max-width: {{ 100 - ($loop->first ? 0 : min(42, $loop->index * 6)) }}%;">
                            <div class="flex w-24 shrink-0 items-center justify-center py-sm font-h3 text-h3 text-white {{ $stage['bar_color'] }}">{{ number_format($stage['count'], 0, ',', '.') }}</div>
                            <div class="flex flex-1 items-center justify-between px-md">
                                <span class="font-body-semibold text-body-semibold text-on-surface">{{ $stage['name'] }}</span>
                                <span class="flex items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                                    {{ $stage['percent'] }}%
                                    <span class="material-symbols-outlined {{ $stage['text_color'] }}">{{ $stage['icon'] }}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="py-md text-center font-body-small text-body-small text-on-surface-variant">Chưa có khách nào trong kỳ.</p>
                @endforelse
            </div>
        </section>

        {{-- Lý do khách không chốt --}}
        <section class="space-y-md">
            <div class="flex flex-wrap items-baseline justify-between gap-sm">
                <h2 class="font-h3 text-h3 text-on-surface">Lý do khách không chốt</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Tổng cộng <span class="font-semibold text-error">{{ $metricLostDeals }}</span> hồ sơ thất bại trong kỳ</p>
            </div>
            <x-ui.data-table min-width="760px">
                <table>
                    <thead>
                        <tr>
                            <th>Khách hàng</th>
                            <th>Thời điểm ghi nhận</th>
                            <th>Nội dung lý do (Log chi tiết)</th>
                            <th>Nhân viên phụ trách</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lostReasons as $lost)
                            <tr>
                                <td class="whitespace-nowrap"><a href="{{ route('crm.customers.show', $lost->id) }}" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ $lost->name }}</a></td>
                                <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ ($lost->lost_at ?? $lost->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="text-on-surface">{{ $lost->lost_reason ?? 'Chưa ghi nhận lý do' }}</td>
                                <td class="whitespace-nowrap text-on-surface-variant">{{ $lost->assignedUser?->name ?? 'Chưa phân công' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state icon="sentiment_satisfied" title="Không có khách thất bại trong kỳ" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($metricLostDeals > $lostReasons->count())
                    <x-slot:footer>
                        <div class="flex justify-center p-sm">
                            <x-ui.button variant="ghost" icon="expand_more" :href="route('crm.lost-deals', array_filter(['from' => $startDate->toDateString(), 'to' => $endDate->toDateString(), 'branch_id' => $branchId]))">Xem thêm lý do không chốt</x-ui.button>
                        </div>
                    </x-slot:footer>
                @endif
            </x-ui.data-table>
        </section>

        <!-- Sales Performance Table by Rep (Bảng hiệu suất & Tỷ lệ chốt theo người phụ trách) -->
        <div class="space-y-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-gray-100 gap-2">
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">
                        Bảng hiệu suất &amp; Tỷ lệ chốt theo người phụ trách
                    </h2>
                    <p class="text-xs text-gray-500">Thống kê số lượng khách, doanh số và hoa hồng theo từng chuyên viên</p>
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
                            <th class="py-3 px-3 text-center">Số lượng khách</th>
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

        </div>

        <footer class="flex items-start gap-md rounded-xl border border-surface-container-highest bg-surface-container-low p-md">
            <span class="material-symbols-outlined text-secondary">info</span>
            <div>
                <p class="font-body-semibold text-body-semibold text-on-surface">Ghi chú về nguồn dữ liệu</p>
                <p class="font-body-small text-body-small text-on-surface-variant">Báo cáo được tổng hợp dựa trên số lượng hồ sơ thực tế trong CRM. Doanh thu = tiền thực thu của khách mới (phiếu thu đã duyệt trong kỳ). Các giai đoạn được sắp xếp theo quy trình 8 bước.
                    @can('commission_config.manage') Hoa hồng tính tự động theo <a href="{{ route('payroll.config.commission-tiers') }}" class="font-semibold text-primary hover:underline">Cấu hình mốc hoa hồng</a>. @endcan</p>
            </div>
        </footer>
    </div>
</x-app-layout>
