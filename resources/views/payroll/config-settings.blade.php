<x-app-layout>
    <x-ui.page-header title="Cấu hình Tham số Lương" icon="tune" :back="route('payroll.periods.index')" description="BHXH / Công đoàn Full-time, quỹ KPI Học vụ, bảng % thưởng tái tục." />

    @php
        $renewalRows = old('renewal', collect($settings['renewal_table'])->map(fn ($row, $quits) => ['quits' => $quits, 'percent' => $row['percent'], 'pending' => $row['pending']])->values()->all());
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    @endphp

    <div class="max-w-3xl mx-auto space-y-5">

        <x-ui.alert type="info">
            <div class="space-y-1 text-xs leading-relaxed text-on-surface-variant">
                <p>Thay đổi chỉ áp dụng khi <strong>tính/tính lại</strong> các kỳ lương về sau. Kỳ lương <strong>Đã duyệt / Đã chi trả</strong> không bị ảnh hưởng.</p>
                <p><strong>Part-time</strong> = số buổi × đơn giá buổi riêng (màn Đơn giá GV) + KPI giữ HS (bậc {{ collect($settings['retention_tiers'])->map(fn ($t) => number_format($t, 0, ',', '.'))->implode(' / ') }}đ, chọn trên phiếu lương) + buổi có GVNN (chờ BA) + phụ cấp tự do − khoản trừ. Không BHXH / Công đoàn.</p>
                <p><strong>Full-time</strong> = lương cơ bản + KPI / hoa hồng / thưởng tái tục / phụ cấp − BHXH − Công đoàn − thuế TNCN (nhập tay) − trừ vi phạm.</p>
            </div>
        </x-ui.alert>

        <form action="{{ route('payroll.config.settings.store') }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm divide-y divide-surface-container-highest"
              x-data="{ rows: @js(array_values($renewalRows)) }">
            @csrf

            <div class="p-6 space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <x-ui.field label="BHXH (% lương cơ bản)" name="insurance_rate_percent" for="insurance_rate_percent">
                        <div class="relative">
                            <input type="number" id="insurance_rate_percent" name="insurance_rate_percent" min="0" max="100" step="0.1"
                                   value="{{ old('insurance_rate_percent', $fmt($settings['insurance_rate_percent'])) }}"
                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm pr-8 font-mono text-body-base text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-on-surface-variant/70 pointer-events-none">%</div>
                        </div>
                    </x-ui.field>
                    <x-ui.field label="Công đoàn (% lương cơ bản)" name="union_rate_percent" for="union_rate_percent">
                        <div class="relative">
                            <input type="number" id="union_rate_percent" name="union_rate_percent" min="0" max="100" step="0.1"
                                   value="{{ old('union_rate_percent', $fmt($settings['union_rate_percent'])) }}"
                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm pr-8 font-mono text-body-base text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-on-surface-variant/70 pointer-events-none">%</div>
                        </div>
                    </x-ui.field>
                    <x-ui.field label="Quỹ KPI Học vụ / tháng" name="academic_kpi_fund" for="academic_kpi_fund">
                        <div class="relative">
                            <input type="number" id="academic_kpi_fund" name="academic_kpi_fund" min="0" step="1000"
                                   value="{{ old('academic_kpi_fund', (int) $settings['academic_kpi_fund']) }}"
                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm pr-8 font-mono text-body-base text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-xs font-bold text-on-surface-variant/70 pointer-events-none">đ</div>
                        </div>
                    </x-ui.field>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xs font-bold text-on-surface-variant">Thưởng tái tục — % doanh thu lớp theo số HS nghỉ trong kỳ</h3>
                            <p class="text-[11px] text-on-surface-variant">BA mới chốt: giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%. Các mốc khác đánh dấu <strong>chờ BA</strong> cho tới khi có bảng đầy đủ.</p>
                        </div>
                        <x-ui.button variant="secondary" size="sm" x-on:click="rows.push({quits: rows.length, percent: 0, pending: true})">+ Thêm mốc</x-ui.button>
                    </div>
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-[11px] uppercase text-on-surface-variant">
                                <th class="py-1">Số HS nghỉ</th><th class="py-1">% doanh thu lớp</th><th class="py-1">Chờ BA</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr>
                                    <td class="py-1 pr-2"><input type="number" min="0" :name="`renewal[${i}][quits]`" x-model="row.quits" class="w-24 px-2 py-1.5 text-xs font-mono border border-surface-container-highest rounded-lg"></td>
                                    <td class="py-1 pr-2"><input type="number" min="0" max="100" step="0.05" :name="`renewal[${i}][percent]`" x-model="row.percent" class="w-28 px-2 py-1.5 text-xs font-mono border border-surface-container-highest rounded-lg"></td>
                                    <td class="py-1 pr-2">
                                        <input type="hidden" :name="`renewal[${i}][pending]`" :value="row.pending ? 1 : 0">
                                        <input type="checkbox" x-model="row.pending" class="rounded border-outline-variant">
                                        <span x-show="row.pending" class="ml-1 text-[10px] font-semibold text-warning">chờ BA</span>
                                    </td>
                                    <td class="py-1 text-right"><x-ui.button variant="danger-text" size="sm" x-on:click="rows.splice(i, 1)">Xoá</x-ui.button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="flex items-center gap-2">
                        <label for="renewal_beyond_percent" class="text-xs font-semibold text-on-surface-variant">Nghỉ nhiều hơn các mốc trên:</label>
                        <input type="number" id="renewal_beyond_percent" name="renewal_beyond_percent" min="0" max="100" step="0.05"
                               value="{{ old('renewal_beyond_percent', $fmt($settings['renewal_beyond_percent'])) }}" class="w-28 px-2 py-1.5 text-xs font-mono border border-surface-container-highest rounded-lg">
                        <span class="text-xs text-on-surface-variant">% (chờ BA)</span>
                    </div>
                    @error('renewal') <p class="text-[11px] text-error mt-1">{{ $message }}</p> @enderror
                    @error('renewal.*') <p class="text-[11px] text-error mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="p-6 bg-surface-container-low border-t border-surface-container-highest flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-on-surface-variant text-center sm:text-left">
                    Mặc định (config/payroll.php): BHXH 10,5% · Công đoàn 0,5% · quỹ KPI Học vụ 2.000.000đ.
                </div>
                <x-ui.button type="submit" icon="save" class="w-full sm:w-auto">Lưu tham số</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
