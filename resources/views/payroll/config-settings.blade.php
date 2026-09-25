<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.index') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">tune</span>
                        Cấu hình Tham số Lương
                    </h1>
                    <p class="text-xs text-gray-500 mt-0.5">Các hệ số dùng khi tính/tính lại bảng lương: phụ cấp, thưởng KPI, BHXH và trừ GVNN.</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-5">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-700">{{ session('status') }}</div>
        @endif

        <div class="bg-blue-50/70 border border-blue-200/80 rounded-2xl p-4 flex items-start gap-3 shadow-2xs">
            <span class="material-symbols-outlined text-[20px] text-secondary shrink-0 mt-0.5">info</span>
            <div class="text-xs text-gray-700 leading-relaxed">
                Thay đổi chỉ áp dụng khi <strong>tính/tính lại</strong> các kỳ lương về sau. Kỳ lương đã
                <strong>Đã duyệt / Đã chi trả</strong> không bị ảnh hưởng. Bỏ trống/không lưu hệ số nào sẽ dùng giá trị mặc định.
            </div>
        </div>

        <form action="{{ route('payroll.config.settings.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
            @csrf

            <div class="p-6 space-y-5">
                <div>
                    <label for="allowance_amount" class="block text-xs font-bold text-gray-700 mb-1.5">Phụ cấp hàng tháng (khi có lương cứng)</label>
                    <div class="relative">
                        <input type="number" id="allowance_amount" name="allowance_amount" min="0" step="1000"
                               value="{{ old('allowance_amount', $settings['allowance_amount']) }}"
                               class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition">
                        <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">đ</div>
                    </div>
                    @error('allowance_amount') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="kpi_bonus_amount" class="block text-xs font-bold text-gray-700 mb-1.5">Thưởng KPI đạt ngưỡng giờ dạy</label>
                        <div class="relative">
                            <input type="number" id="kpi_bonus_amount" name="kpi_bonus_amount" min="0" step="1000"
                                   value="{{ old('kpi_bonus_amount', $settings['kpi_bonus_amount']) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">đ</div>
                        </div>
                        @error('kpi_bonus_amount') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="kpi_bonus_hours_threshold" class="block text-xs font-bold text-gray-700 mb-1.5">Ngưỡng giờ dạy nhận thưởng KPI</label>
                        <div class="relative">
                            <input type="number" id="kpi_bonus_hours_threshold" name="kpi_bonus_hours_threshold" min="1" step="1"
                                   value="{{ old('kpi_bonus_hours_threshold', $settings['kpi_bonus_hours_threshold']) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">giờ</div>
                        </div>
                        @error('kpi_bonus_hours_threshold') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="insurance_rate_percent" class="block text-xs font-bold text-gray-700 mb-1.5">Tỉ lệ BHXH trừ trên lương cứng</label>
                        <div class="relative">
                            <input type="number" id="insurance_rate_percent" name="insurance_rate_percent" min="0" max="100" step="0.1"
                                   value="{{ old('insurance_rate_percent', $settings['insurance_rate_percent']) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">%</div>
                        </div>
                        @error('insurance_rate_percent') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="foreign_teacher_deduction_rate" class="block text-xs font-bold text-gray-700 mb-1.5">Trừ mỗi buổi có GVNN đồng dạy</label>
                        <div class="relative">
                            <input type="number" id="foreign_teacher_deduction_rate" name="foreign_teacher_deduction_rate" min="0" step="1000"
                                   value="{{ old('foreign_teacher_deduction_rate', $settings['foreign_teacher_deduction_rate']) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">đ/buổi</div>
                        </div>
                        @error('foreign_teacher_deduction_rate') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="p-6 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-gray-500 text-center sm:text-left">
                    Giá trị mặc định: phụ cấp 500.000đ · thưởng KPI 1.000.000đ / 40 giờ · BHXH 10,5% · trừ GVNN 50.000đ/buổi.
                </div>
                <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-primary text-white text-xs font-bold shadow-sm hover:bg-primary-dark transition flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    <span>Lưu tham số</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
