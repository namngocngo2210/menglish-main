{{-- Danh sách khách: Thêm / Sửa mở modal 2xl, Xem mở modal xem nhanh 3xl (đẩy URL chi tiết);
     lưu xong server phát "crm-customers-changed" → #customer-list tự tải lại (giữ bộ lọc, trang hiện tại). --}}
<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="flex flex-col gap-lg">
        @if (session('import_skipped'))
            <x-ui.alert type="warning" title="Các dòng bị bỏ qua khi nhập Excel" dismissible>
                <ul class="list-disc pl-5">@foreach (session('import_skipped') as $line)<li>{{ $line }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        {{-- Bộ lọc (mockup danh-sach-khach): Từ khóa, Nguồn, Người phụ trách, Giai đoạn, Chi nhánh, "Lọc dữ liệu" --}}
        <form method="GET" action="{{ route('crm.customers.index') }}" role="search"
              class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            {{-- Giữ lọc nhanh "Chưa liên hệ >24h" khi lọc thêm --}}
            @if (request()->boolean('sla'))
                <input type="hidden" name="sla" value="1">
            @endif
            <div class="grid grid-cols-1 items-end gap-md md:grid-cols-2 lg:grid-cols-6">
                <x-ui.field label="Từ khóa (Tên/SĐT)" name="search" for="f_search">
                    <div class="relative">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                        <input id="f_search" type="search" name="search" value="{{ request('search') }}" placeholder="Nhập tên hoặc SĐT..."
                               class="w-full rounded-lg border border-outline-variant bg-surface-container-low py-sm pl-10 pr-sm font-body-base text-body-base focus:border-secondary focus:ring-2 focus:ring-secondary/20">
                    </div>
                </x-ui.field>
                <x-ui.select name="source" label="Nguồn" :options="$filterSources->mapWithKeys(fn ($s) => [$s => $s])" placeholder="Tất cả nguồn" />
                <x-ui.select name="assigned_user_id" label="Người phụ trách" :options="$filterSales->pluck('name', 'id')" placeholder="Tất cả" />
                <x-ui.select name="stage" label="Giai đoạn" :options="\App\Models\CrmCustomer::PIPELINE_STAGES + ['lost' => \App\Models\CrmCustomer::stageLabel('lost')]" placeholder="Tất cả giai đoạn" />
                @if ($filterBranches->isNotEmpty())
                    <x-ui.select name="branch_id" label="Chi nhánh" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" />
                @else
                    <x-ui.field label="Chi nhánh">
                        <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-base text-body-base text-on-surface-variant">
                            {{ auth()->user()->branch?->name ?? 'Chi nhánh của tôi' }}
                        </div>
                    </x-ui.field>
                @endif
                <div class="flex gap-sm">
                    <button type="submit" class="flex flex-1 items-center justify-center gap-xs rounded-lg bg-secondary px-md py-2 font-body-medium text-body-medium text-white transition-all hover:opacity-90">
                        <span class="material-symbols-outlined text-[18px]">filter_list</span>
                        Lọc dữ liệu
                    </button>
                    @if (request()->hasAny(['search', 'branch_id', 'stage', 'source', 'assigned_user_id', 'sla']))
                        <x-ui.button variant="ghost" icon="filter_alt_off" :href="route('crm.customers.index')" aria-label="Xóa bộ lọc" title="Xóa bộ lọc" />
                    @endif
                </div>
            </div>
        </form>

        <div id="customer-list" hx-get="{{ route('crm.customers.index', request()->query()) }}" hx-trigger="crm-customers-changed from:body" hx-select="#customer-list" hx-swap="outerHTML" hx-disinherit="*">
        <x-ui.data-table min-width="1020px">
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
                                <a href="{{ route('crm.customers.show', $c->id) }}" hx-get="{{ route('crm.customers.show', $c->id) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" hx-push-url="true" data-modal-size="3xl"
                                   class="font-body-medium text-body-medium text-on-background transition hover:text-primary">{{ $c->name }}</a>
                                <div class="font-code text-caption text-on-surface-variant">{{ $c->code }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $c->phone }}</td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ $c->parent_name ?: '—' }}</td>
                            <td class="whitespace-nowrap">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[12px] font-bold {{ $c->stage_badge }}">{{ $c->stage_label }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($c->assignedUser)
                                    <div class="flex items-center gap-xs">
                                        <x-ui.avatar :name="$c->assignedUser->name" size="sm" class="!h-6 !w-6 !text-[10px]" />
                                        <span class="text-on-surface-variant">{{ $c->assignedUser->name }}</span>
                                    </div>
                                @else
                                    <span class="text-on-surface-variant/70">Chưa phân công</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ $c->branch?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap font-caption text-caption text-on-surface-variant" title="{{ $c->updated_at?->format('d/m/Y H:i') }}">
                                @php($updated = $c->updated_at ?? $c->created_at)
                                {{ $updated->isToday() ? $updated->format('H:i').', hôm nay' : ($updated->isYesterday() ? 'Hôm qua' : ($updated->gt(now()->subDays(7)) ? $updated->diffForHumans() : $updated->format('H:i, d/m/Y'))) }}
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', $c->id)" modal="3xl" hx-push-url="true" title="Xem nhanh" aria-label="Xem nhanh" />
                                    @can('lead.update')
                                        <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('crm.customers.edit', $c->id)" modal="2xl" title="Sửa thông tin" aria-label="Sửa thông tin" />
                                    @endcan
                                    @can('lead.delete')
                                        @if ($c->stage !== \App\Models\CrmCustomer::STAGE_LOST)
                                            <form action="{{ route('crm.customers.destroy', $c->id) }}" method="POST" class="inline" data-confirm="Bạn có chắc chắn muốn xóa khách {{ $c->name }} ({{ $c->code }})?">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa khách" aria-label="Xóa khách" />
                                            </form>
                                        @endif
                                    @endcan
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

    @push('scripts')
    <script>
        // Confirm xoá khách qua data-confirm (thay cho inline onsubmit — tránh XSS qua tên khách)
        document.addEventListener('submit', function (event) {
            const form = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
            if (form && !window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }, true);
    </script>
    @endpush
</x-app-layout>
