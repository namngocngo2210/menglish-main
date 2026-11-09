<script setup>
// Payroll parameters, including renewal percentages for academic staff.
defineOptions({ layout: { title: 'Cấu hình Tham số Lương' } });

defineProps({
    settings: { type: Object, required: true },
});
</script>

<template>
    <div>
        <UiPageHeader title="Cấu hình Tham số Lương" icon="tune" :back="route('payroll.periods.index')" description="BHXH / Công đoàn Full-time, quỹ KPI Học vụ, tỷ lệ thưởng tái tục.">
            <template #actions>
                <UiButton variant="secondary" icon="military_tech" :href="route('payroll.config.kpi-tiers')">Bảng bậc KPI giữ HS</UiButton>
            </template>
        </UiPageHeader>

        <div class="max-w-3xl space-y-5">
            <UiAlert type="info">
                <div class="space-y-1 text-xs leading-relaxed text-on-surface-variant">
                    <p>Thay đổi chỉ áp dụng khi <strong>tính/tính lại</strong> các kỳ lương về sau. Kỳ lương <strong>Đã chốt / Đã gửi GV xem / Đã trả</strong> không bị ảnh hưởng.</p>
                    <p><strong>Part-time</strong> = số buổi × đơn giá buổi riêng (màn Đơn giá GV) + KPI giữ HS (lũy tiến theo thâm niên, cấu hình tại trang Bậc KPI) + buổi có GVNN (chờ BA) + phụ cấp tự do − khoản trừ. Không BHXH / Công đoàn.</p>
                    <p><strong>Full-time</strong> = (lương cơ bản + phụ cấp hợp đồng) ÷ công chuẩn × công hưởng lương + KPI / hoa hồng / thưởng tái tục (chỉ Học vụ) / phụ cấp − BHXH − Công đoàn (trên mức lương đóng BHXH, chỉ GV full-time / Học vụ / Học thuật) − đi muộn − quên chấm công − thuế TNCN (nhập tay) − trừ vi phạm.</p>
                    <p><strong>Trợ giảng</strong> = số giờ dạy hợp lệ × đơn giá giờ (bậc {{ settings.assistant_hourly_tiers }}đ, chọn ở màn Đơn giá GV) − khoản trừ. Không KPI, không phụ cấp, không thưởng tái tục.</p>
                    <p><strong>Học thuật kiêm nhiệm</strong> = KPI giữ HS tính theo bậc đơn giá cố định ({{ settings.retention_tiers }}đ/hs, chọn trên phiếu lương).</p>
                    <div class="pt-2">
                        <UiButton variant="secondary" size="sm" icon="military_tech" :href="route('payroll.config.kpi-tiers')">Cấu hình Bảng bậc KPI giữ HS</UiButton>
                    </div>
                </div>
            </UiAlert>

            <UiForm :action="route('payroll.config.settings.store')" method="post" preserve-state="errors" class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <div class="space-y-5 p-6">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <UiInput id="insurance_salary_base" type="number" name="insurance_salary_base" label="Mức lương đóng BHXH" suffix="đ" min="0" step="1000" :value="settings.insurance_salary_base" class="font-mono" hint="Chỉ để tính BHXH / Công đoàn của GV full-time, Học vụ, Học thuật — không cộng vào thu nhập." />
                        <UiInput id="insurance_rate_percent" type="number" name="insurance_rate_percent" label="BHXH (% mức lương đóng BHXH)" suffix="%" min="0" max="100" step="0.1" :value="settings.insurance_rate_percent" class="font-mono" />
                        <UiInput id="union_rate_percent" type="number" name="union_rate_percent" label="Công đoàn (% mức lương đóng BHXH)" suffix="%" min="0" max="100" step="0.1" :value="settings.union_rate_percent" class="font-mono" />
                        <UiInput id="academic_kpi_fund" type="number" name="academic_kpi_fund" label="Quỹ KPI Học vụ / tháng" suffix="đ" min="0" step="1000" :value="settings.academic_kpi_fund" class="font-mono" />
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-xs font-bold text-on-surface-variant">Chấm công hằng ngày (Full-time)</h3>
                        <p class="text-xs text-on-surface-variant">Đi muộn cộng dồn cả tháng × công giờ (lương cơ bản ÷ công chuẩn ÷ 7), không lập biên bản từng lần. Quên chấm vào / chấm ra: trừ mỗi lần trên phiếu lương.</p>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <UiInput id="missing_punch_fine" type="number" name="missing_punch_fine" label="Phạt mỗi lần quên chấm vào / chấm ra" suffix="đ" min="0" step="1000" :value="settings.missing_punch_fine" class="font-mono" />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-xs font-bold text-on-surface-variant">GV đi muộn / về sớm</h3>
                        <p class="text-xs text-on-surface-variant">Có báo trước: trả theo số phút thực dạy. Không báo trước: dưới ngưỡng thì trừ theo từng phút; từ ngưỡng trở lên thì không tính buổi.</p>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <UiInput id="late_threshold_minutes" type="number" name="late_threshold_minutes" label="Ngưỡng không tính buổi (không báo trước)" suffix="phút" min="1" max="120" step="1" :value="settings.late_threshold_minutes" class="font-mono" />
                            <UiInput id="late_deduction_per_minute" type="number" name="late_deduction_per_minute" label="Trừ mỗi phút (dưới ngưỡng, 4.000–5.000đ)" suffix="đ" min="4000" max="5000" step="100" :value="settings.late_deduction_per_minute" class="font-mono" />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-xs font-bold text-on-surface-variant">Học thuật kiêm nhiệm giảng dạy</h3>
                        <p class="text-xs text-on-surface-variant">Lương đứng lớp = mỗi buổi dạy hợp lệ × học phí theo buổi của các HS trong lớp × %. Người có đơn giá "% học phí" riêng ở màn Đơn giá giáo viên thì dùng mức riêng.</p>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <UiInput id="teaching_share_percent" type="number" name="teaching_share_percent" label="% học phí theo buổi (mặc định)" suffix="%" min="0" max="100" step="0.5" :value="settings.teaching_share_percent" class="font-mono" />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-xs font-bold text-on-surface-variant">Thưởng tái tục — % doanh thu lớp</h3>
                        <p class="text-xs text-on-surface-variant">Chỉ áp dụng cho Học vụ, trên các lớp mình phụ trách có HS đầu kỳ. Có bất kỳ HS đầu kỳ nào nghỉ trong kỳ → 0%. Không có HS nghỉ: giữ nguyên số HS → tỷ lệ giữ nguyên; có HS mới vào trong kỳ còn học cuối kỳ, số HS cuối kỳ tăng so với đầu kỳ → tỷ lệ tăng HS. Tỷ lệ tăng số HS phải lớn hơn tỷ lệ giữ nguyên số HS.</p>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <UiInput id="renewal_same_percent" type="number" name="renewal_same_percent" label="Giữ nguyên số HS (%)" min="0" max="100" step="any" required :value="settings.renewal_same_percent" class="font-mono" />
                            <UiInput id="renewal_increase_percent" type="number" name="renewal_increase_percent" label="Tăng số HS (%)" min="0" max="100" step="any" required :value="settings.renewal_increase_percent" class="font-mono" />
                        </div>
                    </div>
                </div>

                <div class="flex flex-col items-center justify-between gap-4 border-t border-surface-container-highest bg-surface-container-low p-6 sm:flex-row">
                    <div class="text-center text-xs text-on-surface-variant sm:text-left">
                        Mặc định (config/payroll.php): BHXH 10,5% · Công đoàn 0,5% · quỹ KPI Học vụ 2.000.000đ · đi muộn ngưỡng 15 phút, trừ 5.000đ/phút · Học thuật kiêm giảng dạy 40% học phí.
                    </div>
                    <UiButton type="submit" icon="save" class="w-full sm:w-auto">Lưu tham số</UiButton>
                </div>
            </UiForm>
        </div>
    </div>
</template>
