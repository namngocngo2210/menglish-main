<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.index') }}" class="w-10 h-10 flex items-center justify-center rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary-container">military_tech</span>
                        <span>Cấu Hình Mốc Hoa Hồng &amp; Thưởng Tái Tục</span>
                    </h1>
                    <p class="text-xs text-gray-500">Quản lý và thiết lập các mốc chính sách hoa hồng tuyển mới và duy trì học viên</p>
                </div>
            </div>
            <a href="{{ route('payroll.config.teacher-rates') }}" class="px-3.5 py-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">price_change</span>
                <span>Đơn giá giờ dạy GV</span>
            </a>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{
        editModalOpen: false,
        editingTier: null,
        openEdit(tier) {
            this.editingTier = Object.assign({}, tier);
            this.editModalOpen = true;
        },
        closeEdit() {
            this.editModalOpen = false;
            this.editingTier = null;
        }
    }">

        @if (session('status'))
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3 text-xs font-semibold shadow-2xs">
                <span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="p-4 rounded-2xl bg-rose-50 text-rose-800 border border-rose-200 text-xs font-semibold">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        
        <!-- Warning Policy Banner (Historical Integrity Rule) -->
        <div class="bg-amber-50/80 border border-amber-200 rounded-2xl p-4 flex items-start gap-3 text-xs shadow-2xs">
            <span class="material-symbols-outlined text-amber-600 text-xl shrink-0 mt-0.5">verified_user</span>
            <div class="space-y-0.5">
                <p class="font-bold text-amber-950">Quy tắc bảo toàn dữ liệu lịch sử &amp; thời điểm áp dụng:</p>
                <p class="text-amber-800 leading-relaxed">
                    Khi bạn thay đổi hoặc điều chỉnh tỷ lệ %, mốc doanh số của bậc hoa hồng, hệ thống sẽ <strong>chỉ áp dụng mức % mới cho các kỳ tính lương và phát sinh sau thời điểm sửa</strong>. Các kỳ lương đã duyệt / đã quyết toán trong quá khứ được giữ nguyên vẹn 100% (bảo lưu lịch sử).
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- Left Column: Commission Tiers Table (Col 8) -->
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-slate-50/70 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-primary-container text-base">percent</span>
                            <span>Danh Sách Các Mốc Thưởng &amp; Hoa Hồng Đang Hiệu Lực</span>
                        </h3>
                        <span class="text-xs font-bold text-gray-500 font-mono">{{ $tiers->count() }} bậc</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                                    <th class="py-3 px-4">Bậc thưởng</th>
                                    <th class="py-3 px-4 text-right">Doanh số tối thiểu</th>
                                    <th class="py-3 px-4 text-center">% Tuyển mới</th>
                                    <th class="py-3 px-4 text-center">% Tái tục (Renew)</th>
                                    <th class="py-3 px-4 text-right">Thưởng vượt mốc</th>
                                    <th class="py-3 px-4 text-right">Hành động</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                                @forelse ($tiers as $tier)
                                    <tr class="hover:bg-orange-50/15 transition group">
                                        <td class="py-3.5 px-4 font-bold text-gray-900">{{ $tier->tier_name }}</td>
                                        <td class="py-3.5 px-4 font-mono font-bold text-gray-800 text-right">
                                            ≥ {{ number_format($tier->min_revenue) }}đ
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono font-bold text-xs">
                                                {{ $tier->new_sale_percent }}%
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200 font-mono font-bold text-xs">
                                                {{ $tier->renew_percent }}%
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 text-right font-mono font-black text-primary-container">
                                            {{ number_format($tier->bonus_amount) }}đ
                                        </td>
                                        <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-1">
                                                <button @click="openEdit({{ json_encode($tier) }})" class="p-1 rounded-lg text-gray-500 hover:text-primary-container hover:bg-orange-50 transition cursor-pointer" title="Sửa bậc hoa hồng">
                                                    <span class="material-symbols-outlined text-[18px]">edit</span>
                                                </button>
                                                <form action="{{ route('payroll.config.commission-tiers.destroy', $tier) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa bậc hoa hồng này?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="p-1 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition cursor-pointer" title="Xóa">
                                                        <span class="material-symbols-outlined text-[18px]">delete</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-8 text-gray-400 text-xs">Chưa có mốc hoa hồng nào được thiết lập.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Add New Tier Form (Col 4) -->
            <div class="lg:col-span-4 space-y-4">
                <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-4">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900 flex items-center gap-1.5 pb-2 border-b border-gray-100">
                        <span class="material-symbols-outlined text-primary-container text-base">add_circle</span>
                        <span>Thiết lập mốc hoa hồng mới</span>
                    </h3>

                    <form action="{{ route('payroll.config.commission-tiers.store') }}" method="POST" class="space-y-3.5 text-xs">
                        @csrf
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px] uppercase">Tên bậc thưởng <span class="text-rose-500">*</span></label>
                            <input type="text" name="tier_name" placeholder="VD: Bậc 4 (Kim Cương)" required class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2.5 bg-white text-gray-900 focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                        </div>

                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px] uppercase">Doanh số tối thiểu (VNĐ) <span class="text-rose-500">*</span></label>
                            <input type="number" name="min_revenue" value="200000000" step="5000000" min="0" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 bg-white text-gray-900 focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px] uppercase">% Tuyển mới <span class="text-rose-500">*</span></label>
                                <input type="number" step="0.1" min="0" max="100" name="new_sale_percent" value="10.0" required class="w-full text-xs font-mono font-bold text-emerald-700 rounded-xl border border-gray-200 p-2.5 bg-white focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-1 text-[11px] uppercase">% Tái tục <span class="text-rose-500">*</span></label>
                                <input type="number" step="0.1" min="0" max="100" name="renew_percent" value="15.0" required class="w-full text-xs font-mono font-bold text-blue-700 rounded-xl border border-gray-200 p-2.5 bg-white focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px] uppercase">Thưởng nóng vượt mốc (VNĐ)</label>
                            <input type="number" name="bonus_amount" value="5000000" step="500000" min="0" class="w-full text-xs font-mono font-bold text-primary-container rounded-xl border border-gray-200 p-2.5 bg-white focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                        </div>

                        <div class="pt-2 border-t border-gray-100">
                            <button type="submit" class="w-full py-2.5 bg-primary-container hover:bg-primary text-white font-bold rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                                <span class="material-symbols-outlined text-[16px]">save</span>
                                <span>Lưu Mốc Thưởng Mới</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

        <!-- Edit Commission Tier Modal -->
        <div x-show="editModalOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="editModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     @click="closeEdit()" class="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="editModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-gray-200">
                    
                    <form :action="'/payroll/config/commission-tiers/' + (editingTier ? editingTier.id : '')" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="bg-white p-6 space-y-4">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                                <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary-container">edit</span>
                                    <span>Chỉnh Sửa Mốc Hoa Hồng</span>
                                </h3>
                                <button type="button" @click="closeEdit()" class="p-1 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition">
                                    <span class="material-symbols-outlined text-[20px]">close</span>
                                </button>
                            </div>

                            <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800 space-y-1">
                                <p class="font-bold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">info</span>
                                    Lưu ý về mốc thời gian:
                                </p>
                                <p>Tỷ lệ % sau khi cập nhật chỉ có hiệu lực cho các kỳ tính lương và Deal phát sinh từ thời điểm này trở đi. Không thay đổi các kỳ đã khóa/duyệt trước đó.</p>
                            </div>

                            <div class="space-y-3 text-xs">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Tên bậc thưởng <span class="text-rose-500">*</span></label>
                                    <input type="text" name="tier_name" x-model="editingTier.tier_name" required class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2.5 bg-white text-gray-900 focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                                </div>

                                <div>
                                    <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Doanh số tối thiểu (VNĐ) <span class="text-rose-500">*</span></label>
                                    <input type="number" name="min_revenue" x-model="editingTier.min_revenue" step="5000000" min="0" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 bg-white text-gray-900 focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">% Tuyển mới <span class="text-rose-500">*</span></label>
                                        <input type="number" step="0.1" min="0" max="100" name="new_sale_percent" x-model="editingTier.new_sale_percent" required class="w-full text-xs font-mono font-bold text-emerald-700 rounded-xl border border-gray-200 p-2.5 bg-white focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                                    </div>
                                    <div>
                                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">% Tái tục <span class="text-rose-500">*</span></label>
                                        <input type="number" step="0.1" min="0" max="100" name="renew_percent" x-model="editingTier.renew_percent" required class="w-full text-xs font-mono font-bold text-blue-700 rounded-xl border border-gray-200 p-2.5 bg-white focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                                    </div>
                                </div>

                                <div>
                                    <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Thưởng nóng vượt mốc (VNĐ)</label>
                                    <input type="number" name="bonus_amount" x-model="editingTier.bonus_amount" step="500000" min="0" class="w-full text-xs font-mono font-bold text-primary-container rounded-xl border border-gray-200 p-2.5 bg-white focus:ring-primary-container focus:border-primary-container shadow-2xs" />
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-3">
                            <button type="button" @click="closeEdit()" class="px-4 py-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold transition shadow-2xs">
                                Hủy
                            </button>
                            <button type="submit" class="px-5 py-2 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                                <span class="material-symbols-outlined text-[16px]">save</span>
                                <span>Cập nhật bậc hoa hồng</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
