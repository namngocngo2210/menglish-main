<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('merchandise.index') }}" class="p-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight">
                    {{ $isEdit ? 'Cập nhật Hàng hóa: ' . $item->name : 'Thêm mới Hàng hóa & Vật phẩm' }}
                </h1>
                <p class="text-xs text-gray-500">Khai báo mã hàng, đơn vị tính, đơn giá niêm yết tính phụ phí vào hoá đơn và phiếu thu</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        <form 
            action="{{ $isEdit ? route('merchandise.update', $item) : route('merchandise.store') }}" 
            method="POST" 
            class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6"
        >
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            @if ($errors->any())
                <div class="p-4 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 space-y-1">
                    <div class="font-bold flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">error</span>
                        <span>Vui lòng kiểm tra lại thông tin:</span>
                    </div>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="space-y-4">
                <h2 class="text-xs font-bold text-gray-400 uppercase tracking-wider pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">info</span>
                    <span>1. Thông tin cơ bản</span>
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Mã hàng hóa -->
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Mã hàng hóa (SKU) <span class="text-rose-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="code" 
                            value="{{ old('code', $item->code) }}" 
                            placeholder="Ví dụ: BOOK-CAM-S3, UNI-POLO-M..." 
                            required 
                            class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2 uppercase"
                        />
                        <p class="text-[10px] text-gray-400 mt-1">Mã định danh duy nhất của hàng hóa trong hệ thống.</p>
                    </div>

                    <!-- Phân loại nhóm hàng -->
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Nhóm phân loại <span class="text-rose-500">*</span>
                        </label>
                        <select 
                            name="category" 
                            required 
                            class="w-full text-xs font-semibold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2 text-gray-800"
                        >
                            @foreach ($categories as $catKey => $cat)
                                <option value="{{ $catKey }}" {{ old('category', $item->category) === $catKey ? 'selected' : '' }}>
                                    {{ $cat['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- Tên mặt hàng -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">
                        Tên hàng hóa / Vật phẩm <span class="text-rose-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        value="{{ old('name', $item->name) }}" 
                        placeholder="Ví dụ: Bộ Giáo trình Cambridge Stage 3, Áo Polo Đồng phục MEnglish..." 
                        required 
                        class="w-full text-xs font-bold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2 text-gray-900"
                    />
                </div>

                <!-- Đơn vị tính -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Đơn vị tính <span class="text-rose-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="unit" 
                            value="{{ old('unit', $item->unit ?? 'Bộ') }}" 
                            placeholder="Bộ, Cuốn, Chiếc, Cái..." 
                            required 
                            class="w-full text-xs font-semibold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2"
                        />
                    </div>

                    <!-- Đơn giá niêm yết bán -->
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Đơn giá niêm yết (VNĐ) <span class="text-rose-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            name="price" 
                            value="{{ old('price', (int) $item->price) }}" 
                            min="0" 
                            step="1000" 
                            required 
                            class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2 text-primary-container"
                        />
                        <p class="text-[10px] text-gray-400 mt-1">Giá tính vào hợp đồng &amp; hoá đơn.</p>
                    </div>

                    <!-- Giá vốn nhập (tùy chọn) -->
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Giá vốn nhập (VNĐ)
                        </label>
                        <input 
                            type="number" 
                            name="cost_price" 
                            value="{{ old('cost_price', $item->cost_price ? (int) $item->cost_price : '') }}" 
                            min="0" 
                            step="1000" 
                            placeholder="Tùy chọn" 
                            class="w-full text-xs font-mono rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2 text-gray-600"
                        />
                    </div>
                </div>

                <!-- Tồn kho & Trạng thái -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Số lượng tồn kho ban đầu <span class="text-rose-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            name="stock_quantity" 
                            value="{{ old('stock_quantity', $item->stock_quantity ?? 0) }}" 
                            min="0" 
                            required 
                            class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container px-3 py-2 text-gray-800"
                        />
                    </div>

                    <div class="flex items-center pt-5">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input 
                                type="checkbox" 
                                name="is_active" 
                                value="1" 
                                {{ old('is_active', $item->is_active ?? true) ? 'checked' : '' }} 
                                class="w-4 h-4 rounded text-primary focus:ring-primary-container border-gray-300"
                            />
                            <span class="text-xs font-bold text-gray-800">Kích hoạt kinh doanh (Cho phép chọn khi tạo Hóa đơn / Phiếu thu)</span>
                        </label>
                    </div>
                </div>

                <!-- Mô tả chi tiết -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">
                        Mô tả &amp; Ghi chú về hàng hóa
                    </label>
                    <textarea 
                        name="description" 
                        rows="3" 
                        placeholder="Nhập thông tin chi tiết về sách, độ tuổi phù hợp, chất liệu đồng phục hoặc phụ kiện đi kèm..." 
                        class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container p-3 text-gray-800"
                    >{{ old('description', $item->description) }}</textarea>
                </div>
            </div>

            <!-- Nút thao tác -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <a href="{{ route('merchandise.index') }}" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 text-xs font-semibold hover:bg-gray-50 transition">
                    Hủy bỏ
                </a>
                <button type="submit" class="px-5 py-2 rounded-xl bg-primary-container text-white text-xs font-bold hover:bg-primary-hover shadow-sm shadow-primary-container/30 transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>{{ $isEdit ? 'Lưu cập nhật' : 'Tạo mới Hàng hóa' }}</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
