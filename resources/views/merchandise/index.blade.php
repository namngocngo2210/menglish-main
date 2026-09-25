<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary shrink-0">
                    <span class="material-symbols-outlined text-2xl">inventory_2</span>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight">Danh mục Hàng hóa &amp; Vật phẩm</h1>
                    <p class="text-xs text-gray-500">Quản lý sách giáo trình, workbook, đồng phục, balo và học phẩm phục vụ tính phụ phí trên hoá đơn</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('merchandise.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-white text-xs font-bold hover:bg-primary-hover shadow-sm shadow-primary/30 transition">
                    <span class="material-symbols-outlined text-base">add_circle</span>
                    <span>Thêm Hàng hóa mới</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        @if (session('status'))
            <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 text-xs text-emerald-800 shadow-xs">
                <span class="material-symbols-outlined text-emerald-600 text-lg">check_circle</span>
                <span class="font-medium">{{ session('status') }}</span>
            </div>
        @endif

        <!-- Metrics Overview Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 text-xs font-medium">
                    <span>Tổng mặt hàng</span>
                    <span class="material-symbols-outlined text-gray-400 text-base">category</span>
                </div>
                <div class="text-xl font-black text-gray-900 mt-1 font-mono">{{ number_format($metrics['total']) }}</div>
                <div class="text-[11px] text-emerald-600 mt-0.5 font-medium">{{ $metrics['active'] }} đang bán</div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 text-xs font-medium">
                    <span>Sách &amp; Giáo trình</span>
                    <span class="material-symbols-outlined text-indigo-500 text-base">menu_book</span>
                </div>
                <div class="text-xl font-black text-indigo-600 mt-1 font-mono">{{ number_format($metrics['books']) }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Giáo trình + Bài tập</div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 text-xs font-medium">
                    <span>Đồng phục &amp; Balo</span>
                    <span class="material-symbols-outlined text-emerald-500 text-base">apparel</span>
                </div>
                <div class="text-xl font-black text-emerald-600 mt-1 font-mono">{{ number_format($metrics['uniforms']) }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Áo polo, balo, túi</div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 text-xs font-medium">
                    <span>Tổng tồn kho</span>
                    <span class="material-symbols-outlined text-amber-500 text-base">warehouse</span>
                </div>
                <div class="text-xl font-black text-amber-600 mt-1 font-mono">{{ number_format($metrics['total_stock']) }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Số lượng trong kho</div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-xs col-span-2 sm:col-span-1">
                <div class="flex items-center justify-between text-gray-500 text-xs font-medium">
                    <span>Tích hợp Hoá đơn</span>
                    <span class="material-symbols-outlined text-purple-500 text-base">receipt_long</span>
                </div>
                <div class="text-xs font-bold text-purple-700 mt-2">Bóc tách tự động</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Đồng bộ Closing Wizard</div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-xs space-y-3">
            <form action="{{ route('merchandise.index') }}" method="GET" class="flex flex-col md:flex-row gap-3 items-center justify-between">
                <div class="flex-1 w-full flex flex-col sm:flex-row gap-2">
                    <!-- Search Input -->
                    <div class="relative flex-1">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-gray-400 text-lg">search</span>
                        <input 
                            type="text" 
                            name="q" 
                            value="{{ $search }}" 
                            placeholder="Tìm kiếm theo mã hàng, tên sách, đồng phục..." 
                            class="w-full pl-9 pr-4 py-2 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary placeholder-gray-400"
                        />
                    </div>

                    <!-- Category Filter Dropdown -->
                    <div class="w-full sm:w-52 shrink-0">
                        <select 
                            name="category" 
                            onchange="this.form.submit()" 
                            class="w-full py-2 px-3 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary text-gray-700"
                        >
                            <option value="">-- Tất cả nhóm hàng --</option>
                            @foreach ($categories as $catKey => $cat)
                                <option value="{{ $catKey }}" {{ $selectedCategory === $catKey ? 'selected' : '' }}>
                                    {{ $cat['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Status Filter Dropdown -->
                    <div class="w-full sm:w-40 shrink-0">
                        <select 
                            name="status" 
                            onchange="this.form.submit()" 
                            class="w-full py-2 px-3 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary text-gray-700"
                        >
                            <option value="">-- Trạng thái --</option>
                            <option value="active" {{ $selectedStatus === 'active' ? 'selected' : '' }}>Đang kinh doanh</option>
                            <option value="inactive" {{ $selectedStatus === 'inactive' ? 'selected' : '' }}>Tạm ngừng</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white text-xs font-semibold hover:bg-gray-800 transition">
                        Lọc
                    </button>
                    @if ($search || $selectedCategory || $selectedStatus)
                        <a href="{{ route('merchandise.index') }}" class="px-3 py-2 rounded-xl border border-gray-200 text-gray-600 text-xs font-semibold hover:bg-gray-50 transition">
                            Xóa lọc
                        </a>
                    @endif
                </div>
            </form>

            <!-- Quick Category Pills -->
            <div class="flex flex-wrap gap-1.5 pt-2 border-t border-gray-100 text-xs">
                <a 
                    href="{{ route('merchandise.index', array_filter(['q' => $search, 'status' => $selectedStatus])) }}" 
                    class="px-3 py-1 rounded-lg font-medium transition {{ empty($selectedCategory) ? 'bg-primary text-white font-bold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                >
                    Tất cả ({{ $metrics['total'] }})
                </a>
                @foreach ($categories as $catKey => $cat)
                    <a 
                        href="{{ route('merchandise.index', array_filter(['q' => $search, 'category' => $catKey, 'status' => $selectedStatus])) }}" 
                        class="px-3 py-1 rounded-lg font-medium transition flex items-center gap-1.5 {{ $selectedCategory === $catKey ? 'bg-primary text-white font-bold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                    >
                        <span class="material-symbols-outlined text-sm">{{ $cat['icon'] }}</span>
                        <span>{{ $cat['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <!-- Merchandise Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Mã hàng</th>
                            <th class="py-3 px-4">Tên hàng hóa &amp; Vật phẩm</th>
                            <th class="py-3 px-4">Nhóm phân loại</th>
                            <th class="py-3 px-3 text-center">ĐVT</th>
                            <th class="py-3 px-4 text-right">Đơn giá niêm yết</th>
                            <th class="py-3 px-3 text-center">Tồn kho</th>
                            <th class="py-3 px-4 text-center">Trạng thái</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($items as $item)
                            @php
                                $meta = $item->category_meta;
                            @endphp
                            <tr class="hover:bg-slate-50/70 transition">
                                <!-- Mã hàng -->
                                <td class="py-3 px-4 font-mono font-bold text-gray-800">
                                    <span class="bg-gray-100 px-2 py-0.5 rounded border border-gray-200/80">
                                        {{ $item->code }}
                                    </span>
                                </td>

                                <!-- Tên & mô tả -->
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900">{{ $item->name }}</div>
                                    @if ($item->description)
                                        <div class="text-[11px] text-gray-400 truncate max-w-sm">{{ $item->description }}</div>
                                    @endif
                                </td>

                                <!-- Nhóm hàng -->
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 text-gray-700 border border-gray-200">
                                        <span class="material-symbols-outlined text-[13px]">{{ $meta['icon'] }}</span>
                                        <span>{{ $meta['label'] }}</span>
                                    </span>
                                </td>

                                <!-- Đơn vị tính -->
                                <td class="py-3 px-3 text-center text-gray-600 font-medium">
                                    {{ $item->unit }}
                                </td>

                                <!-- Đơn giá niêm yết -->
                                <td class="py-3 px-4 text-right font-mono font-black text-[#ea580c] text-sm">
                                    {{ $item->formatted_price }}
                                </td>

                                <!-- Tồn kho -->
                                <td class="py-3 px-3 text-center">
                                    <span class="font-mono font-bold {{ $item->stock_quantity <= 10 ? 'text-rose-600 bg-rose-50 px-2 py-0.5 rounded' : 'text-gray-700' }}">
                                        {{ number_format($item->stock_quantity) }}
                                    </span>
                                </td>

                                <!-- Trạng thái -->
                                <td class="py-3 px-4 text-center">
                                    <form action="{{ route('merchandise.toggle', $item) }}" method="POST" class="inline">
                                        @csrf
                                        <button 
                                            type="submit" 
                                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold transition {{ $item->is_active ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-gray-200 text-gray-600 hover:bg-gray-300' }}"
                                            title="Bấm để bật/tắt kinh doanh"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full {{ $item->is_active ? 'bg-emerald-600' : 'bg-gray-500' }}"></span>
                                            <span>{{ $item->is_active ? 'Đang bán' : 'Tạm ngừng' }}</span>
                                        </button>
                                    </form>
                                </td>

                                <!-- Thao tác -->
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a 
                                            href="{{ route('merchandise.edit', $item) }}" 
                                            class="p-1 text-gray-400 hover:text-primary hover:bg-primary/10 rounded-lg transition"
                                            title="Sửa mặt hàng"
                                        >
                                            <span class="material-symbols-outlined text-[17px]">edit</span>
                                        </a>

                                        <form action="{{ route('merchandise.destroy', $item) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa mặt hàng {{ $item->name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button 
                                                type="submit" 
                                                class="p-1 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                                title="Xóa mặt hàng"
                                            >
                                                <span class="material-symbols-outlined text-[17px]">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-10 text-gray-400">
                                    <div class="flex flex-col items-center gap-2">
                                        <span class="material-symbols-outlined text-4xl text-gray-300">inventory_2</span>
                                        <p class="text-xs">Không tìm thấy hàng hóa / vật phẩm nào phù hợp điều kiện lọc.</p>
                                        <a href="{{ route('merchandise.create') }}" class="mt-1 text-xs font-bold text-primary hover:underline">
                                            + Thêm hàng hóa mới ngay
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($items->hasPages())
                <div class="p-4 border-t border-gray-100 bg-gray-50/50">
                    {{ $items->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
