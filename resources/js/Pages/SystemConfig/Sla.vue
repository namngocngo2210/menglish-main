<script setup>
/**
 * Cấu hình SLA: mỗi SLA tự động một thẻ — ngưỡng (giờ / số lần), bật tắt, có tự lập biên bản phạt không và mức phạt gợi ý.
 * Thay đổi áp dụng cho mốc phát sinh từ lúc lưu; biên bản tự lập vẫn đi quy trình giải trình → xác nhận → bảng lương.
 */
defineOptions({ layout: { title: 'Cấu hình SLA' } });

defineProps({
    rules: { type: Array, default: () => [] },
});
</script>

<template>
    <UiPageHeader title="Cấu hình SLA" description="Ngưỡng đếm ngược, tự giao việc và tự lập biên bản phạt cho từng SLA. Biên bản tự lập chờ giải trình, được CM / Học thuật / Admin xác nhận rồi mới đồng bộ sang bảng lương cuối tháng." />

    <UiAlert type="info" title="Quy ước chung">
        Mốc SLA phát sinh trước lúc bật hệ thống không bị phạt hồi tố. Mức phạt 0đ nghĩa là người xác nhận quyết mức phạt khi chốt biên bản.
    </UiAlert>

    <div class="space-y-lg">
        <UiForm
            v-for="rule in rules"
            :key="rule.key"
            :action="route('system-config.sla.update', rule.key)"
            method="put"
            :error-bag="`sla_${rule.key}`"
            class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm"
            #default="{ errors }"
        >
            <div class="flex flex-wrap items-start justify-between gap-sm border-b border-surface-container pb-sm">
                <div class="space-y-xs">
                    <div class="flex flex-wrap items-center gap-sm">
                        <span class="rounded-lg bg-primary-fixed px-sm py-xs font-code text-code text-primary">{{ rule.key }}</span>
                        <h2 class="font-h3 text-h3 text-on-surface">{{ rule.label }}</h2>
                        <UiBadge v-if="rule.customized" color="warning">Đã tùy chỉnh</UiBadge>
                        <UiBadge v-if="rule.task" color="info">Tự giao việc</UiBadge>
                    </div>
                    <p class="max-w-3xl font-body-small text-body-small text-on-surface-variant">{{ rule.description }}</p>
                </div>
                <label class="flex items-center gap-xs font-body-small text-body-small">
                    <input type="hidden" name="enabled" value="0" />
                    <input type="checkbox" name="enabled" value="1" :checked="rule.enabled" class="rounded text-primary focus:ring-primary-container" />
                    Đang bật
                </label>
            </div>

            <div class="grid grid-cols-1 gap-lg md:grid-cols-3">
                <div class="space-y-xs">
                    <label :for="`value_${rule.key}`" class="block font-body-medium text-body-medium text-on-surface">{{ rule.unit === 'hours' ? 'Thời hạn' : 'Số lần liên tiếp' }}</label>
                    <div class="w-40">
                        <UiInput :id="`value_${rule.key}`" type="number" name="value" min="1" :value="rule.value" required :suffix="rule.unit === 'hours' ? 'Giờ' : 'Lần'" class="font-code text-code" />
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant">Mặc định: {{ rule.default_value }} {{ rule.unit === 'hours' ? 'giờ' : 'lần' }}.</p>
                    <p v-if="errors.value" class="font-caption text-caption text-error">{{ errors.value }}</p>
                </div>
                <div class="space-y-xs">
                    <span class="block font-body-medium text-body-medium text-on-surface">Quá hạn tự lập biên bản phạt</span>
                    <label class="flex items-center gap-xs font-body-small text-body-small">
                        <input type="hidden" name="penalty" value="0" />
                        <input type="checkbox" name="penalty" value="1" :checked="rule.penalty" class="rounded text-primary focus:ring-primary-container" />
                        Tự lập biên bản (kèm file chi tiết)
                    </label>
                    <p class="font-caption text-caption text-on-surface-variant">Tắt thì chỉ thông báo Admin và người phụ trách.</p>
                </div>
                <div class="space-y-xs">
                    <label :for="`amount_${rule.key}`" class="block font-body-medium text-body-medium text-on-surface">Mức phạt gợi ý</label>
                    <div class="w-48">
                        <UiInput :id="`amount_${rule.key}`" type="number" name="amount" min="0" step="1000" :value="rule.amount" suffix="đ" class="font-code text-code" />
                    </div>
                    <p v-if="errors.amount" class="font-caption text-caption text-error">{{ errors.amount }}</p>
                </div>
            </div>

            <div class="flex justify-end">
                <UiButton type="submit" variant="secondary" icon="save">Lưu</UiButton>
            </div>
        </UiForm>
    </div>
</template>
