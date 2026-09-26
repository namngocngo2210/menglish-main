<x-app-layout>
    @include('crm.partials.header-tabs')

    {{-- Mockup crm-ui-mockup/khach-khong-chot-lost-deals. A6: khách Thất bại không mở lại, chỉ xem để đối soát. --}}
    <div class="flex flex-col gap-lg">
        <div class="flex flex-col gap-md lg:flex-row lg:items-center">
            <x-ui.stat-card label="Tổng số khách không chốt" :value="number_format($lostTotal, 0, ',', '.')" icon="person_off" tone="error" class="shadow-sm lg:min-w-[280px]" />
            <div class="flex-1 [&>form]:mb-0">
                @include('crm.partials.list-filters', ['dateLabel' => 'Thời điểm dừng', 'exportable' => true, 'searchPlaceholder' => 'Tìm theo lý do không chốt, tên, SĐT...', 'exportLabel' => 'Xuất báo cáo'])
            </div>
        </div>

        <x-ui.data-table min-width="1040px">
            <table>
                <thead>
                    <tr>
                        <th>Họ tên khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Lý do không chốt</th>
                        <th>Người phụ trách trước khi fail</th>
                        <th>Thời điểm dừng</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lostCustomers as $lc)
                        <tr>
                            <td>
                                <div class="flex items-center gap-sm">
                                    <x-ui.avatar :name="$lc->name" size="sm" />
                                    <div class="min-w-0">
                                        <a href="{{ route('crm.customers.show', $lc->id) }}" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ $lc->name }}</a>
                                        <div class="font-caption text-caption text-on-surface-variant">Nhu cầu: {{ $lc->course_interest ?: 'Chưa ghi nhận' }} · {{ $lc->branch?->name ?? '—' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $lc->phone }}</td>
                            <td class="min-w-[280px] max-w-md">
                                <div class="rounded-lg border border-error/10 bg-error-container/30 p-sm">
                                    <p class="line-clamp-3 font-body-small text-body-small text-on-surface" title="{{ $lc->lost_reason }}">{{ $lc->lost_reason ?? 'Chưa ghi nhận lý do' }}</p>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-xs text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[18px]">account_circle</span>
                                    <span>{{ $lc->assignedUser?->name ?? 'Chưa phân công' }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap font-code text-caption text-on-surface-variant">{{ $lc->lost_at?->format('H:i - d/m/Y') ?? '—' }}</td>
                            <td class="text-right">
                                <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', $lc->id)" title="Xem chi tiết (không mở lại khách Thất bại)">Xem chi tiết</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6"><x-ui.empty-state icon="search_off" title="Không có khách không chốt" description="Không có khách thất bại phù hợp bộ lọc." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$lostCustomers" unit="khách" />
            </x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
