{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/bang-kpi-cong-khai --}}
<x-app-layout>
    @php
        $rankBadge = function (int $rank) {
            return match ($rank) {
                1 => ['bg-amber-100 text-amber-700', 'emoji_events'],
                2 => ['bg-slate-100 text-slate-600', 'emoji_events'],
                3 => ['bg-orange-100 text-orange-800', 'emoji_events'],
                default => [null, null],
            };
        };
    @endphp

    <x-ui.page-header title="Bảng xếp hạng KPI & Hoa hồng"
                      description="Dữ liệu công khai nhằm mục đích thi đua khen thưởng. Thông tin không bao gồm lương cơ bản, các khoản khấu trừ và thực nhận cá nhân.">
        <x-slot:actions>
            @can('commission_config.manage')
                <x-ui.button variant="secondary" icon="settings" :href="route('payroll.config.commission-tiers')">Cấu hình mốc hoa hồng</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <form method="GET" action="{{ route('payroll.kpi-leaderboard') }}"
          class="mb-lg flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
        <x-ui.select name="period" label="Kỳ lương" :options="$periodOptions" :value="sprintf('%04d-%02d', $year, $month)" onchange="this.form.submit()" />
        <x-ui.select name="branch_id" label="Chi nhánh" :options="$branches->pluck('name', 'id')" placeholder="Tất cả" :value="$branchId" onchange="this.form.submit()" />
        <x-ui.button type="submit" variant="secondary" icon="filter_list">Xem</x-ui.button>
    </form>

    {{-- 1. KPI giữ học sinh (GV Part-time) — từ phiếu lương của kỳ --}}
    <x-ui.data-table min-width="760px" class="mb-lg">
        <x-slot:header>
            <div>
                <h3 class="font-h3 text-h3 text-on-surface">KPI giữ học sinh — giáo viên</h3>
                <p class="font-body-small text-body-small text-on-surface-variant">Số HS giữ được × đơn giá bậc (đ/HS/tháng), cùng số liệu phiếu lương kỳ {{ sprintf('%02d/%04d', $month, $year) }}.</p>
            </div>
            @if ($period && ! $period->isLocked())
                <x-ui.badge color="warning">Số liệu tạm tính — kỳ chưa chốt</x-ui.badge>
            @elseif ($period)
                <x-ui.badge color="success">Kỳ đã chốt</x-ui.badge>
            @endif
        </x-slot:header>
        <table>
            <thead>
                <tr>
                    <th class="w-16 text-center">Hạng</th>
                    <th>Nhân viên</th>
                    <th>Chi nhánh</th>
                    <th class="text-right">Số HS Giữ</th>
                    <th class="text-right">Đơn giá (VNĐ/hs)</th>
                    <th class="text-right">Tổng KPI (VNĐ)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($retentionPage as $i => $r)
                    @php $rank = ($retentionPage->currentPage() - 1) * $retentionPage->perPage() + $i + 1; [$badgeClass, $badgeIcon] = $rankBadge($rank); @endphp
                    <tr>
                        <td class="text-center">
                            @if ($badgeClass)
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full {{ $badgeClass }}" aria-label="Hạng {{ $rank }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $badgeIcon }}</span></span>
                            @else
                                <span class="font-mono font-semibold text-on-surface-variant">{{ $rank }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$r->user?->name ?? '?'" size="sm" />
                                <div>
                                    <p class="font-semibold text-on-surface">{{ $r->user?->name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $r->user?->branch?->name ?? 'Hệ thống MEnglish' }}</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ $r->user?->branch?->name ?? 'Hệ thống MEnglish' }}</td>
                        <td class="text-right font-mono">{{ number_format((int) $r->retention_students) }} <span class="font-caption text-caption text-on-surface-variant">/ {{ (int) $r->retention_base_students }}</span></td>
                        <td class="text-right font-mono">{{ $r->retention_tier !== null ? number_format($r->retention_tier) : 'Chưa chọn bậc' }}</td>
                        <td class="text-right font-mono font-bold text-primary">{{ number_format($r->kpi_bonus) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="leaderboard" title="Chưa có KPI giữ học sinh"
                        :description="$period ? 'Kỳ này chưa có giáo viên Part-time được tính KPI giữ học sinh.' : 'Chưa khởi tạo bảng lương tháng '.sprintf('%02d/%04d', $month, $year).'.'" /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$retentionPage" unit="nhân viên" :options="[]" /></x-slot:footer>
    </x-ui.data-table>

    {{-- 2. Hoa hồng tuyển sinh (Sale) --}}
    <x-ui.data-table min-width="860px">
        <x-slot:header>
            <div>
                <h3 class="font-h3 text-h3 text-on-surface">Hoa hồng tuyển sinh — tư vấn</h3>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Doanh số tháng {{ $month }}/{{ $year }} = tiền thực thu (phiếu thu đã duyệt trong tháng) của khách mới, gồm cả tiền giáo trình / đồ dùng; không tính tái tục.
                    Hoa hồng phát sinh trước gate kép (30 ngày + 3/3 mốc chăm sóc) — số trả thực tế theo phiếu lương.
                </p>
            </div>
        </x-slot:header>
        <table>
            <thead>
                <tr>
                    <th class="w-16 text-center">Hạng</th>
                    <th>Nhân viên</th>
                    <th>Chi nhánh</th>
                    <th class="text-right">Số HS chốt</th>
                    <th class="text-right">Tỷ lệ (%)</th>
                    <th class="text-right">Thực thu khách mới</th>
                    <th class="text-right">Tổng hoa hồng (VNĐ)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($salesPage as $i => $item)
                    @php $rank = ($salesPage->currentPage() - 1) * $salesPage->perPage() + $i + 1; [$badgeClass, $badgeIcon] = $rankBadge($rank); @endphp
                    <tr>
                        <td class="text-center">
                            @if ($badgeClass)
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full {{ $badgeClass }}" aria-label="Hạng {{ $rank }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $badgeIcon }}</span></span>
                            @else
                                <span class="font-mono font-semibold text-on-surface-variant">{{ $rank }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$item['user']->name" size="sm" />
                                <div>
                                    <p class="font-semibold text-on-surface">{{ $item['user']->name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $item['tier_name'] }} · {{ $item['deals'] }} HV mới đóng phí</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ $item['branch_name'] }}</td>
                        <td class="text-right font-mono">{{ $item['closed'] }}</td>
                        <td class="text-right font-mono">{{ rtrim(rtrim(number_format($item['percent'], 2), '0'), '.') }}%</td>
                        <td class="text-right font-mono">{{ number_format($item['revenue']) }}</td>
                        <td class="text-right font-mono font-bold text-primary">{{ number_format($item['commission']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="leaderboard" title="Chưa có nhân viên tư vấn trong phạm vi lọc" /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$salesPage" unit="nhân viên" :options="[]" /></x-slot:footer>
    </x-ui.data-table>
</x-app-layout>
