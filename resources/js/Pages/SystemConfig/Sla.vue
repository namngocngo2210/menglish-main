<script setup>
/**
 * Cấu hình SLA: chia theo nhóm (tab), mỗi SLA một dòng gọn (tên, ngưỡng hiện tại, trạng thái); bấm dòng mở hộp thoại sửa
 * ngưỡng (giờ / ngày / số lần / giờ trong ngày / ngày trong tháng), bật tắt, có tự lập biên bản phạt không và mức phạt gợi ý.
 * SLA chỉ nhắc / chặn thao tác thì không có ô phạt; SLA luôn áp dụng thì không có nút tắt. Thay đổi áp dụng cho mốc phát sinh từ lúc lưu.
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Cấu hình SLA' } });

const props = defineProps({
    rules: { type: Array, default: () => [] },
    resetMonths: { type: Number, default: 12 },
});

const groups = computed(() => {
    const out = [];
    for (const rule of props.rules) {
        let group = out.find((g) => g.name === rule.group);
        if (!group) out.push((group = { name: rule.group, rules: [] }));
        group.rules.push(rule);
    }
    return out;
});

// Lưu một mục giữ nguyên trang (preserve-state) nên tab đang xem không bị đặt lại.
const activeGroup = ref(props.rules[0]?.group ?? null);
const currentGroup = computed(() => groups.value.find((g) => g.name === activeGroup.value) ?? groups.value[0]);

const openKey = ref(null);
const openRule = computed(() => props.rules.find((r) => r.key === openKey.value) ?? null);

const UNIT = {
    hours: { label: 'Thời hạn', suffix: 'Giờ', short: 'giờ' },
    days: { label: 'Thời hạn', suffix: 'Ngày', short: 'ngày' },
    count: { label: 'Số lần liên tiếp', suffix: 'Lần', short: 'lần' },
    time: { label: 'Giờ trong ngày', suffix: null, short: null },
    day_of_month: { label: 'Ngày trong tháng', suffix: 'Ngày', short: null },
};
const unitOf = (rule) => UNIT[rule.unit] ?? UNIT.count;
const valueLabel = (rule) => {
    if (rule.unit === 'time') return rule.value;
    if (rule.unit === 'day_of_month') return `Ngày ${rule.value}`;
    return `${rule.value} ${unitOf(rule).short}`;
};
const formatMoney = (v) => `${Number(v || 0).toLocaleString('vi-VN')} đ`;
const penaltyLabel = (rule) => {
    if (!rule.has_penalty) return null;
    if (!rule.penalty) return 'Chỉ thông báo';
    if (rule.has_ladder) return 'Phạt theo bậc';
    if (Number(rule.amount) <= 0) return 'Lập biên bản';
    return `Phạt ${formatMoney(rule.amount)}${rule.amount_per ? '/' + rule.amount_per : ''}`;
};
</script>

<template>
    <UiPageHeader title="Cấu hình SLA" description="Ngưỡng đếm ngược, tự giao việc và tự lập biên bản phạt cho từng SLA. Biên bản tự lập chờ giải trình, được CM / Học thuật / Admin xác nhận rồi mới đồng bộ sang bảng lương cuối tháng." />

    <UiAlert type="info" title="Quy ước chung">
        Mốc SLA phát sinh trước lúc bật hệ thống không bị phạt hồi tố. Mức phạt 0đ nghĩa là người xác nhận quyết mức phạt khi chốt biên bản. Bấm vào từng mục để xem chi tiết và sửa.
    </UiAlert>

    <UiTabs>
        <UiTab v-for="group in groups" :key="group.name" :active="currentGroup?.name === group.name" :count="group.rules.length" @click="activeGroup = group.name">
            {{ group.name }}
        </UiTab>
    </UiTabs>

    <ul v-if="currentGroup" class="divide-y divide-surface-container overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
        <li v-for="rule in currentGroup.rules" :key="rule.key">
            <button
                type="button"
                class="flex w-full items-center gap-md px-lg py-md text-left transition-colors hover:bg-surface-container-low focus-visible:bg-surface-container-low focus-visible:outline-none"
                :aria-label="`Sửa SLA ${rule.label}`"
                @click="openKey = rule.key"
            >
                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-md gap-y-xs">
                    <span class="flex min-w-0 flex-1 basis-56 flex-wrap items-center gap-sm">
                        <span class="font-body-medium text-body-medium font-semibold text-on-surface">{{ rule.label }}</span>
                        <UiBadge v-if="rule.customized" color="warning">Đã tùy chỉnh</UiBadge>
                    </span>
                    <span class="flex flex-wrap items-center gap-sm">
                        <span class="rounded-lg bg-surface-container px-sm py-xs font-code text-code text-on-surface">{{ valueLabel(rule) }}</span>
                        <UiBadge v-if="!rule.enabled" color="neutral">Đang tắt</UiBadge>
                        <UiBadge v-if="rule.task" color="info">Tự giao việc</UiBadge>
                        <UiBadge v-if="penaltyLabel(rule)" :color="rule.penalty ? 'error' : 'neutral'">{{ penaltyLabel(rule) }}</UiBadge>
                        <UiBadge v-else color="neutral">Chỉ nhắc / chặn</UiBadge>
                    </span>
                </span>
                <span class="material-symbols-outlined shrink-0 text-[20px] text-on-surface-variant" aria-hidden="true">chevron_right</span>
            </button>
        </li>
    </ul>

    <UiForm :action="route('system-config.sla.settings')" method="post" error-bag="slaSettings" class="flex flex-wrap items-end gap-lg rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm" #default="{ errors }">
        <div class="space-y-xs">
            <label for="ladder_reset_months" class="block font-body-medium text-body-medium text-on-surface">Cộng dồn lần tái phạm (bậc phạt)</label>
            <p class="font-caption text-caption text-on-surface-variant">Lần vi phạm thứ n của cùng một người, cùng một lỗi, tính trong khoảng này.</p>
            <div class="w-40">
                <UiInput id="ladder_reset_months" type="number" name="ladder_reset_months" min="1" max="60" :value="resetMonths" required suffix="Tháng" class="font-code text-code" />
            </div>
            <p v-if="errors.ladder_reset_months" class="font-caption text-caption text-error">{{ errors.ladder_reset_months }}</p>
        </div>
        <UiButton type="submit" variant="secondary" icon="save">Lưu</UiButton>
    </UiForm>

    <UiModal :show="!!openRule" :title="openRule?.label" max-width="2xl" @close="openKey = null">
        <UiForm
            v-if="openRule"
            :key="openRule.key"
            :action="route('system-config.sla.update', openRule.key)"
            method="put"
            :error-bag="`sla_${openRule.key}`"
            :preserve-state="true"
            class="space-y-lg"
            @success="openKey = null"
            #default="{ errors, processing }"
        >
            <div class="space-y-sm">
                <div class="flex flex-wrap items-center gap-sm">
                    <span class="rounded-lg bg-primary-fixed px-sm py-xs font-code text-code text-primary">{{ openRule.key }}</span>
                    <UiBadge color="neutral">{{ openRule.group }}</UiBadge>
                    <UiBadge v-if="openRule.customized" color="warning">Đã tùy chỉnh</UiBadge>
                    <UiBadge v-if="openRule.task" color="info">Tự giao việc</UiBadge>
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">{{ openRule.description }}</p>
            </div>

            <label v-if="openRule.switchable" class="flex items-center gap-xs font-body-medium text-body-medium">
                <input type="hidden" name="enabled" value="0" />
                <input type="checkbox" name="enabled" value="1" :checked="openRule.enabled" class="rounded text-primary focus:ring-primary-container" />
                Đang bật
            </label>
            <p v-else class="font-caption text-caption text-on-surface-variant">SLA này luôn áp dụng (chặn thao tác hoặc dùng để tính hạn), chỉ đổi được ngưỡng.</p>

            <div class="grid grid-cols-1 gap-lg sm:grid-cols-2">
                <div class="space-y-xs">
                    <label :for="`value_${openRule.key}`" class="block font-body-medium text-body-medium text-on-surface">{{ unitOf(openRule).label }}</label>
                    <div class="w-40">
                        <UiInput v-if="openRule.unit === 'time'" :id="`value_${openRule.key}`" type="time" name="value" :value="openRule.value" required class="font-code text-code" />
                        <UiInput v-else :id="`value_${openRule.key}`" type="number" name="value" :min="openRule.min" :max="openRule.max" :value="openRule.value" required :suffix="unitOf(openRule).suffix" class="font-code text-code" />
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant">Mặc định: {{ openRule.default_label }}.</p>
                    <p v-if="errors.value" class="font-caption text-caption text-error">{{ errors.value }}</p>
                </div>

                <template v-if="openRule.has_penalty">
                    <div class="space-y-xs">
                        <span class="block font-body-medium text-body-medium text-on-surface">Quá hạn tự lập biên bản phạt</span>
                        <label class="flex items-center gap-xs font-body-small text-body-small">
                            <input type="hidden" name="penalty" value="0" />
                            <input type="checkbox" name="penalty" value="1" :checked="openRule.penalty" class="rounded text-primary focus:ring-primary-container" />
                            Tự lập biên bản
                        </label>
                        <p class="font-caption text-caption text-on-surface-variant">Tắt thì chỉ thông báo người phụ trách và Admin.</p>
                    </div>
                    <div class="space-y-xs">
                        <label :for="`amount_${openRule.key}`" class="block font-body-medium text-body-medium text-on-surface">{{ openRule.amount_label }}</label>
                        <div class="w-48">
                            <UiInput :id="`amount_${openRule.key}`" type="number" name="amount" min="0" step="1000" :value="openRule.amount" suffix="đ" class="font-code text-code" />
                        </div>
                        <p v-if="errors.amount" class="font-caption text-caption text-error">{{ errors.amount }}</p>
                    </div>
                </template>
                <p v-else class="font-caption text-caption text-on-surface-variant sm:pt-lg">SLA này chỉ nhắc hoặc chặn thao tác, không lập biên bản phạt.</p>
            </div>

            <div v-if="openRule.has_ladder" class="space-y-xs">
                <label :for="`ladder_${openRule.key}`" class="block font-body-medium text-body-medium text-on-surface">Bậc phạt theo lần tái phạm (đ)</label>
                <p class="font-caption text-caption text-on-surface-variant">Các số tiền cách nhau dấu phẩy: lần 1, lần 2, lần 3 trở đi (số cuối lặp lại). 0 = nhắc nhở, không phạt tiền. Để trống để dùng mặc định.</p>
                <div class="max-w-md">
                    <UiInput :id="`ladder_${openRule.key}`" name="ladder" :value="openRule.ladder ?? ''" placeholder="0, 30000, 60000" class="font-code text-code" />
                </div>
                <p v-if="errors.ladder" class="font-caption text-caption text-error">{{ errors.ladder }}</p>
            </div>

            <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                <UiButton type="button" variant="secondary" @click="openKey = null">Hủy</UiButton>
                <UiButton type="submit" icon="save" :disabled="processing">Lưu</UiButton>
            </div>
        </UiForm>
    </UiModal>
</template>
