<x-app-layout>
    @include('crm.partials.header-tabs', ['title' => 'Báo cáo tuyển sinh', 'description' => 'Cập nhật: '.now()->format('H:i d/m/Y')])

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
        <x-ui.filter-bar :action="route('crm.reports')" :reset-url="route('crm.reports')" :search="false" class="!mb-0">
            <x-slot:quick>
                {{-- Input ẩn đứng trước các nút chọn nhanh: nút Lọc gửi preset=custom, còn bấm nút chọn nhanh thì giá trị của nút đó (đứng sau) thắng --}}
                <input type="hidden" name="preset" value="custom">
                <div class="flex flex-wrap items-center gap-sm">
                    <span class="font-label text-label uppercase text-on-surface-variant">Chọn nhanh:</span>
                    @foreach (['today' => 'Hôm nay', 'yesterday' => 'Hôm qua', 'last_7_days' => '7 ngày', 'last_week' => 'Tuần trước', 'last_30_days' => '30 ngày', 'last_60_days' => '60 ngày', 'last_90_days' => '90 ngày', 'last_6_months' => '6 tháng', 'last_year' => '1 năm'] as $key => $label)
                        <button type="submit" name="preset" value="{{ $key }}"
                                class="rounded-full px-md py-xs font-body-small text-body-small transition-colors {{ $preset === $key ? 'bg-primary-container font-semibold text-white' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </x-slot:quick>
            <x-ui.date-range label="Khoảng thời gian" from="start_date" to="end_date" :from-value="$startDate->format('Y-m-d')" :to-value="$endDate->format('Y-m-d')" />
            <x-ui.select name="branch_id" label="Chi nhánh" :options="$branches->pluck('name', 'id')" :value="(string) $branchId" placeholder="Tất cả chi nhánh" />
        </x-ui.filter-bar>

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

        {{-- Sales Performance Table by Rep (Bảng hiệu suất & Tỷ lệ chốt theo người phụ trách) --}}
        <div class="space-y-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-surface-container-highest gap-2">
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">
                        Bảng hiệu suất &amp; Tỷ lệ chốt theo người phụ trách
                    </h2>
                    <p class="text-xs text-on-surface-variant">Thống kê số lượng khách, doanh số và hoa hồng theo từng chuyên viên</p>
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

            <x-ui.data-table>
                <table>
                    <thead>
                        <tr>
                            <th>Người phụ trách</th>
                            <th class="text-center">Số lượng khách</th>
                            <th class="text-center">SL chốt thành công</th>
                            <th class="text-center">% Chốt thành công</th>
                            <th class="text-right">Doanh thu</th>
                            <th class="text-right bg-brand-surface !text-on-primary-fixed-variant font-bold">Hoa hồng (Tier)</th>
                            <th class="text-center">Biến động % vs kỳ trước</th>
                            <th class="text-center">Đánh giá</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($repsData as $rep)
                            <tr>
                                {{-- Người phụ trách --}}
                                <td class="font-bold">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-7 h-7 rounded-full bg-primary-container text-white flex items-center justify-center font-bold text-xs shrink-0">
                                            {{ $rep['avatar_letter'] }}
                                        </span>
                                        <div>
                                            <div class="font-bold text-on-surface">{{ $rep['name'] }}</div>
                                            <div class="text-xs text-on-surface-subtle font-normal">{{ $rep['role'] }}</div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Số lượng Lead --}}
                                <td class="text-center font-mono font-bold">
                                    {{ $rep['leads'] }}
                                </td>

                                {{-- SL chốt thành công --}}
                                <td class="text-center font-mono font-bold !text-tertiary">
                                    {{ $rep['won'] }}
                                </td>

                                {{-- % Chốt thành công --}}
                                <td class="text-center font-mono font-bold">
                                    {{ $rep['rate'] }}%
                                </td>

                                {{-- Doanh thu (giữ định dạng number_format mặc định — test đối chiếu "350,000") --}}
                                <td class="text-right font-mono font-bold whitespace-nowrap">
                                    {{ \App\Support\Money::format($rep['revenue']) }}
                                </td>

                                {{-- Hoa hồng --}}
                                <td class="text-right bg-brand-surface">
                                    <div class="font-mono font-bold text-primary-container whitespace-nowrap">
                                        {{ \App\Support\Money::format($rep['commission_amount']) }}
                                    </div>
                                    <div class="text-xs text-on-surface-variant font-sans">
                                        {{ $rep['tier_name'] }} ({{ $rep['commission_percent'] }}%{{ $rep['commission_bonus'] > 0 ? ' + ' . \App\Support\Money::format($rep['commission_bonus']) : '' }})
                                    </div>
                                </td>

                                {{-- Biến động % vs kỳ trước --}}
                                <td class="text-center font-mono font-bold {{ str_starts_with($rep['delta'], '+') ? '!text-tertiary' : '!text-error' }}">
                                    {{ $rep['delta'] }}
                                </td>

                                {{-- Đánh giá --}}
                                <td class="text-center">
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border {{ $rep['rating_badge'] }}">
                                        {{ $rep['rating'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

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
