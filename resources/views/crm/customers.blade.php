{{-- Danh sách khách: Thêm mở modal 2xl; bấm tên / nút sửa → trang hồ sơ đầy đủ (sửa trực tiếp trong trang);
     lưu xong server phát "crm-customers-changed" → #customer-list tự tải lại (giữ bộ lọc, trang hiện tại). --}}
<x-app-layout>
    @include('crm.partials.header-tabs', ['title' => 'Danh sách khách hàng'])

    <div class="flex flex-col gap-lg">
        @if (session('import_skipped'))
            <x-ui.alert type="warning" title="Các dòng bị bỏ qua khi nhập Excel" dismissible>
                <ul class="list-disc pl-5">@foreach (session('import_skipped') as $line)<li>{{ $line }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        {{-- Bộ lọc (mockup danh-sach-khach): Từ khóa, Nguồn, Người phụ trách, Giai đoạn, Chi nhánh, nút "Lọc" (kiểu phụ, cùng nhãn với các màn CRM khác) --}}
        <x-ui.filter-bar :action="route('crm.customers.index')" search="search" placeholder="Tìm tên hoặc SĐT..." :reset-url="route('crm.customers.index')">
            <x-slot:quick><x-ui.workspace-chips workspace="crm" /></x-slot:quick>
            {{-- Giữ lọc nhanh "Chưa liên hệ >24h" khi lọc thêm --}}
            @if (request()->boolean('sla'))
                <input type="hidden" name="sla" value="1">
            @endif
            <x-ui.select name="source" label="Nguồn" :options="$filterSources->mapWithKeys(fn ($s) => [$s => $s])" placeholder="Tất cả nguồn" />
            <x-ui.select name="assigned_user_id" label="Người phụ trách" :options="\App\Services\Crm\LeadOwners::options($filterSales)" placeholder="Tất cả người phụ trách" />
            <x-ui.select name="stage" label="Giai đoạn" :options="\App\Models\CrmCustomer::PIPELINE_STAGES + ['lost' => \App\Models\CrmCustomer::stageLabel('lost')]" placeholder="Tất cả giai đoạn" />
            @if ($filterBranches->isNotEmpty())
                <x-ui.select name="branch_id" label="Chi nhánh" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" />
            @endif
        </x-ui.filter-bar>

        <div id="customer-list" hx-get="{{ route('crm.customers.index', request()->query()) }}" hx-trigger="crm-customers-changed from:body" hx-select="#customer-list" hx-swap="outerHTML" hx-disinherit="*">
        <x-ui.data-table min-width="1020px" sticky="both">
            <table>
                <thead>
                    <tr>
                        <th>Họ tên</th>
                        <th>Số điện thoại</th>
                        <th>Tên phụ huynh</th>
                        <th>Giai đoạn</th>
                        <th>Người phụ trách</th>
                        <th>Chi nhánh</th>
                        <th>Cập nhật gần nhất</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $c)
                        <tr class="group">
                            <td class="whitespace-nowrap">
                                <a href="{{ route('crm.customers.show', $c->id) }}"
                                   class="font-body-medium text-body-medium text-on-background transition hover:text-primary">{{ $c->name }}</a>
                                <div class="font-code text-caption text-on-surface-variant" title="{{ $c->code }}">{{ $c->short_code }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $c->phone }}</td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ $c->parent_name ?: '—' }}</td>
                            <td class="whitespace-nowrap">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[12px] font-bold {{ $c->stage_badge }}">{{ $c->stage_label }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($c->assignedUser)
                                    <div class="flex items-center gap-xs">
                                        <x-ui.avatar :name="$c->assignedUser->name" size="sm" class="!h-6 !w-6 !text-xs" />
                                        <span class="text-on-surface-variant">{{ $c->assignedUser->name }}</span>
                                    </div>
                                @else
                                    <span class="text-on-surface-subtle">Chưa phân công</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ $c->branch?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap font-caption text-caption text-on-surface-variant" title="{{ $c->updated_at?->format('d/m/Y H:i') }}">
                                @php($updated = $c->updated_at ?? $c->created_at)
                                {{ $updated->isToday() ? $updated->format('H:i').', hôm nay' : ($updated->isYesterday() ? 'Hôm qua' : ($updated->gt(now()->subDays(7)) ? $updated->diffForHumans() : $updated->format('H:i, d/m/Y'))) }}
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    {{-- Sửa = mở hồ sơ đầy đủ ở tab "Thông tin khách hàng" (sửa trực tiếp trong trang) --}}
                                    <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('crm.customers.show', ['id' => $c->id, 'tab' => 'info'])" title="Mở hồ sơ" aria-label="Mở hồ sơ {{ $c->name }}" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>
                <x-ui.pagination :paginator="$customers" unit="khách" />
            </x-slot:footer>
        </x-ui.data-table>
        </div>
    </div>

</x-app-layout>
