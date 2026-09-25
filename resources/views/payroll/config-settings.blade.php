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
                    <p class="text-xs text-gray-500 mt-0.5">Công thức lương theo BA (Q3): BHXH / Công đoàn Full-time, quỹ KPI Học vụ, bảng % thưởng tái tục.</p>
                </div>
            </div>
        </div>
    </x-slot>

    @php
        $renewalRows = old('renewal', collect($settings['renewal_table'])->map(fn ($row, $quits) => ['quits' => $quits, 'percent' => $row['percent'], 'pending' => $row['pending']])->values()->all());
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    @endphp

    <div class="max-w-3xl mx-auto space-y-5">

        <div class="bg-blue-50/70 border border-blue-200/80 rounded-2xl p-4 flex items-start gap-3 shadow-2xs">
            <span class="material-symbols-outlined text-[20px] text-secondary shrink-0 mt-0.5">info</span>
            <div class="text-xs text-gray-700 leading-relaxed space-y-1">
                <p>Thay đổi chỉ áp dụng khi <strong>tính/tính lại</strong> các kỳ lương về sau. Kỳ lương <strong>Đã duyệt / Đã chi trả</strong> không bị ảnh hưởng.</p>
                <p><strong>Part-time</strong> = số buổi × đơn giá buổi riêng (màn Đơn giá GV) + KPI giữ HS (bậc {{ collect($settings['retention_tiers'])->map(fn ($t) => number_format($t, 0, ',', '.'))->implode(' / ') }}đ, chọn trên phiếu lương) + buổi có GVNN (chờ BA) + phụ cấp tự do − khoản trừ. Không BHXH / Công đoàn.</p>
                <p><strong>Full-time</strong> = lương cơ bản + KPI / hoa hồng / thưởng tái tục / phụ cấp − BHXH − Công đoàn − thuế TNCN (nhập tay) − trừ vi phạm.</p>
            </div>
        </div>

        <form action="{{ route('payroll.config.settings.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100"
              x-data="{ rows: @js(array_values($renewalRows)) }">
            @csrf

            <div class="p-6 space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div>
                        <label for="insurance_rate_percent" class="block text-xs font-bold text-gray-700 mb-1.5">BHXH (% lương cơ bản)</label>
                        <div class="relative">
                            <input type="number" id="insurance_rate_percent" name="insurance_rate_percent" min="0" max="100" step="0.1"
                                   value="{{ old('insurance_rate_percent', $fmt($settings['insurance_rate_percent'])) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">%</div>
                        </div>
                        @error('insurance_rate_percent') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="union_rate_percent" class="block text-xs font-bold text-gray-700 mb-1.5">Công đoàn (% lương cơ bản)</label>
                        <div class="relative">
                            <input type="number" id="union_rate_percent" name="union_rate_percent" min="0" max="100" step="0.1"
                                   value="{{ old('union_rate_percent', $fmt($settings['union_rate_percent'])) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">%</div>
                        </div>
                        @error('union_rate_percent') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="academic_kpi_fund" class="block text-xs font-bold text-gray-700 mb-1.5">Quỹ KPI Học vụ / tháng</label>
                        <div class="relative">
                            <input type="number" id="academic_kpi_fund" name="academic_kpi_fund" min="0" step="1000"
                                   value="{{ old('academic_kpi_fund', (int) $settings['academic_kpi_fund']) }}"
                                   class="w-full px-3.5 py-2.5 text-xs font-mono bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-gray-400 pointer-events-none">đ</div>
                        </div>
                        @error('academic_kpi_fund') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xs font-bold text-gray-700">Thưởng tái tục — % doanh thu lớp theo số HS nghỉ trong kỳ</h3>
                            <p class="text-[11px] text-gray-500">BA mới chốt: giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%. Các mốc khác đánh dấu <strong>chờ BA</strong> cho tới khi có bảng đầy đủ.</p>
                        </div>
                        <button type="button" @click="rows.push({quits: rows.length, percent: 0, pending: true})" class="px-3 py-1.5 rounded-lg border border-gray-300 text-xs font-semibold text-gray-700 hover:bg-gray-50">+ Thêm mốc</button>
                    </div>
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-[11px] uppercase text-gray-500">
                                <th class="py-1">Số HS nghỉ</th><th class="py-1">% doanh thu lớp</th><th class="py-1">Chờ BA</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr>
                                    <td class="py-1 pr-2"><input type="number" min="0" :name="`renewal[${i}][quits]`" x-model="row.quits" class="w-24 px-2 py-1.5 text-xs font-mono border border-gray-200 rounded-lg"></td>
                                    <td class="py-1 pr-2"><input type="number" min="0" max="100" step="0.05" :name="`renewal[${i}][percent]`" x-model="row.percent" class="w-28 px-2 py-1.5 text-xs font-mono border border-gray-200 rounded-lg"></td>
                                    <td class="py-1 pr-2">
                                        <input type="hidden" :name="`renewal[${i}][pending]`" :value="row.pending ? 1 : 0">
                                        <input type="checkbox" x-model="row.pending" class="rounded border-gray-300">
                                        <span x-show="row.pending" class="ml-1 text-[10px] font-semibold text-amber-700">chờ BA</span>
                                    </td>
                                    <td class="py-1 text-right"><button type="button" @click="rows.splice(i, 1)" class="text-rose-500 hover:underline">Xoá</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="flex items-center gap-2">
                        <label for="renewal_beyond_percent" class="text-xs font-semibold text-gray-700">Nghỉ nhiều hơn các mốc trên:</label>
                        <input type="number" id="renewal_beyond_percent" name="renewal_beyond_percent" min="0" max="100" step="0.05"
                               value="{{ old('renewal_beyond_percent', $fmt($settings['renewal_beyond_percent'])) }}" class="w-28 px-2 py-1.5 text-xs font-mono border border-gray-200 rounded-lg">
                        <span class="text-xs text-gray-500">% (chờ BA)</span>
                    </div>
                    @error('renewal') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    @error('renewal.*') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="p-6 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-gray-500 text-center sm:text-left">
                    Mặc định (config/payroll.php): BHXH 10,5% · Công đoàn 0,5% · quỹ KPI Học vụ 2.000.000đ.
                </div>
                <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-primary-container text-white text-xs font-bold shadow-sm hover:bg-primary-dark transition flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    <span>Lưu tham số</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
