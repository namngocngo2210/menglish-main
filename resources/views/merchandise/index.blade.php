{{-- Danh mục Hàng hóa & Vật phẩm. Thêm/Sửa mở modal (htmx), Xóa qua modal xác nhận;
     lưu/xóa xong server phát "merchandise-changed" → #merchandise-list tự tải lại (giữ bộ lọc, trang hiện tại). --}}
<x-app-layout>
    <x-ui.page-header title="Danh mục Hàng hóa & Vật phẩm" icon="inventory_2">
        <x-slot:actions>
            <x-ui.button icon="add_circle" :href="route('merchandise.create')" modal="xl">Thêm Hàng hóa mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ del: { url: '', name: '' } }">

        {{-- Metrics Overview Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <x-ui.stat-card label="Tổng mặt hàng" :value="number_format($metrics['total'])" icon="category" :hint="$metrics['active'] . ' đang bán'" />
            <x-ui.stat-card label="Sách & Giáo trình" :value="number_format($metrics['books'])" tone="secondary" icon="menu_book" hint="Giáo trình + Bài tập" />
            <x-ui.stat-card label="Đồng phục & Balo" :value="number_format($metrics['uniforms'])" tone="success" icon="apparel" hint="Áo polo, balo, túi" />
            <x-ui.stat-card label="Tổng tồn kho" :value="number_format($metrics['total_stock'])" tone="warning" icon="warehouse" hint="Số lượng trong kho" />
            <x-ui.stat-card label="Tích hợp Hoá đơn" value="Bóc tách tự động" tone="secondary" icon="receipt_long" hint="Đồng bộ Closing Wizard" class="col-span-2 sm:col-span-1" />
        </div>

        {{-- Filter & Search Bar --}}
        <div class="bg-surface-container-lowest p-4 rounded-2xl border border-surface-container-highest shadow-xs space-y-3">
            <form action="{{ route('merchandise.index') }}" method="GET" class="flex flex-col md:flex-row gap-3 items-center justify-between">
                <div class="flex-1 w-full flex flex-col sm:flex-row gap-2">
                    {{-- Search Input --}}
                    <div class="flex-1">
                        <x-ui.input name="q" icon="search" :value="$search" placeholder="Tìm kiếm theo mã hàng, tên sách, đồng phục..." class="text-xs" />
                    </div>

                    {{-- Category Filter Dropdown --}}
                    <div class="w-full sm:w-52 shrink-0">
                        <x-ui.select name="category" onchange="this.form.submit()" class="text-xs" placeholder="-- Tất cả nhóm hàng --" :value="$selectedCategory"
                                     :options="collect($categories)->map(fn ($cat) => $cat['label'])" />
                    </div>

                    {{-- Status Filter Dropdown --}}
                    <div class="w-full sm:w-40 shrink-0">
                        <x-ui.select name="status" onchange="this.form.submit()" class="text-xs" placeholder="-- Trạng thái --" :value="$selectedStatus"
                                     :options="['active' => 'Đang kinh doanh', 'inactive' => 'Tạm ngừng']" />
                    </div>
                </div>

                <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
                    <x-ui.button type="submit" variant="secondary">Lọc</x-ui.button>
                    @if ($search || $selectedCategory || $selectedStatus)
                        <x-ui.button variant="ghost" :href="route('merchandise.index')">Xóa lọc</x-ui.button>
                    @endif
                </div>
            </form>

            {{-- Quick Category Pills --}}
            <div class="flex flex-wrap gap-1.5 pt-2 border-t border-surface-container-highest text-xs">
                <a 
                    href="{{ route('merchandise.index', array_filter(['q' => $search, 'status' => $selectedStatus])) }}" 
                    class="px-3 py-1 rounded-lg font-medium transition {{ empty($selectedCategory) ? 'bg-primary-container text-white font-bold' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}"
                >
                    Tất cả ({{ $metrics['total'] }})
                </a>
                @foreach ($categories as $catKey => $cat)
                    <a 
                        href="{{ route('merchandise.index', array_filter(['q' => $search, 'category' => $catKey, 'status' => $selectedStatus])) }}" 
                        class="px-3 py-1 rounded-lg font-medium transition flex items-center gap-1.5 {{ $selectedCategory === $catKey ? 'bg-primary-container text-white font-bold' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}"
                    >
                        <span class="material-symbols-outlined text-sm">{{ $cat['icon'] }}</span>
                        <span>{{ $cat['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Merchandise Table --}}
        <x-ui.data-table id="merchandise-list" hx-get="{{ route('merchandise.index', request()->query()) }}" hx-trigger="merchandise-changed from:body" hx-select="#merchandise-list" hx-swap="outerHTML" hx-disinherit="*">
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th>Mã hàng</th>
                            <th>Tên hàng hóa &amp; Vật phẩm</th>
                            <th>Nhóm phân loại</th>
                            <th class="text-center">ĐVT</th>
                            <th class="text-right">Đơn giá niêm yết</th>
                            <th class="text-center">Tồn kho</th>
                            <th class="text-center">Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            @php
                                $meta = $item->category_meta;
                            @endphp
                            <tr>
                                {{-- Mã hàng --}}
                                <td class="font-mono font-bold">
                                    <span class="bg-surface-container px-2 py-0.5 rounded border border-surface-container-highest/80">
                                        {{ $item->code }}
                                    </span>
                                </td>

                                {{-- Tên & mô tả --}}
                                <td>
                                    <div class="font-bold text-on-surface">{{ $item->name }}</div>
                                    @if ($item->description)
                                        <div class="text-[11px] text-on-surface-variant/70 truncate max-w-sm">{{ $item->description }}</div>
                                    @endif
                                </td>

                                {{-- Nhóm hàng --}}
                                <td>
                                    <x-ui.badge :dot="false" :pill="true">
                                        <span class="material-symbols-outlined text-[13px]">{{ $meta['icon'] }}</span>
                                        <span>{{ $meta['label'] }}</span>
                                    </x-ui.badge>
                                </td>

                                {{-- Đơn vị tính --}}
                                <td class="text-center text-on-surface-variant font-medium">
                                    {{ $item->unit }}
                                </td>

                                {{-- Đơn giá niêm yết --}}
                                <td class="text-right font-mono font-black text-primary-container text-sm">
                                    {{ $item->formatted_price }}
                                </td>

                                {{-- Tồn kho --}}
                                <td class="text-center">
                                    <span class="font-mono font-bold {{ $item->stock_quantity <= 10 ? 'text-error bg-error/10 px-2 py-0.5 rounded' : 'text-on-surface-variant' }}">
                                        {{ number_format($item->stock_quantity) }}
                                    </span>
                                </td>

                                {{-- Trạng thái --}}
                                <td class="text-center">
                                    <form action="{{ route('merchandise.toggle', $item) }}" method="POST" class="inline">
                                        @csrf
                                        <button 
                                            type="submit" 
                                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold transition {{ $item->is_active ? 'bg-tertiary/10 text-on-tertiary-container hover:bg-tertiary/20' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest' }}"
                                            title="Bấm để bật/tắt kinh doanh"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full {{ $item->is_active ? 'bg-tertiary' : 'bg-on-surface-variant' }}"></span>
                                            <span>{{ $item->is_active ? 'Đang bán' : 'Tạm ngừng' }}</span>
                                        </button>
                                    </form>
                                </td>

                                {{-- Thao tác --}}
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button size="sm" variant="ghost" icon="edit" :href="route('merchandise.edit', $item)" modal="xl" title="Sửa mặt hàng" aria-label="Sửa {{ $item->name }}" />
                                        <x-ui.button size="sm" variant="danger-text" icon="delete" title="Xóa mặt hàng" aria-label="Xóa {{ $item->name }}"
                                                     data-url="{{ route('merchandise.destroy', $item) }}" data-name="{{ $item->name }}"
                                                     @click="del = { url: $el.dataset.url, name: $el.dataset.name }; $dispatch('open-modal', 'delete-merchandise')" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <x-ui.empty-state icon="inventory_2" title="Không tìm thấy hàng hóa / vật phẩm nào phù hợp điều kiện lọc.">
                                        <x-ui.button variant="ghost" size="sm" :href="route('merchandise.create')" modal="xl">+ Thêm hàng hóa mới ngay</x-ui.button>
                                    </x-ui.empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

            <x-slot:footer><x-ui.pagination :paginator="$items" /></x-slot:footer>
        </x-ui.data-table>

        {{-- Xác nhận xóa (dùng chung cho mọi dòng; url/tên lấy từ nút Xóa) --}}
        <x-ui.modal name="delete-merchandise" title="Xóa mặt hàng?" max-width="md">
            <p>Xóa mặt hàng <strong class="font-semibold" x-text="del.name"></strong>?</p>
            <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Mặt hàng được chuyển vào thùng rác, không còn chọn được khi lập hóa đơn.</p>
            <form id="delete-merchandise-form" method="POST" :action="del.url" hx-boost="true" hx-swap="none" hx-push-url="false">
                @csrf @method('DELETE')
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'delete-merchandise')">Hủy</x-ui.button>
                <x-ui.button variant="danger" type="submit" form="delete-merchandise-form" icon="delete">Xóa</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
