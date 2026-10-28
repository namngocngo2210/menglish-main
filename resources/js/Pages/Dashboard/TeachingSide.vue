<script setup>
/** Cột phải của giáo viên / trợ giảng: lương tạm tính tháng (luôn bằng đồng) + học sinh cần chú ý (mini test dưới 7/10). */
import { Link } from '@inertiajs/vue3';

defineProps({ shifts: { type: Object, required: true } });
</script>

<template>
    <div class="flex flex-col gap-md">
        <component :is="shifts.salary.url ? Link : 'div'" :href="shifts.salary.url ?? undefined" class="flex flex-col gap-xs rounded-xl border border-surface-variant bg-surface-container-lowest p-md" data-overview-salary>
            <span class="font-body-small text-body-small font-semibold text-on-surface-variant">Lương tạm tính tháng này</span>
            <span v-if="shifts.salary.amount" class="font-h1 text-h1 font-bold tabular-nums text-on-surface">{{ shifts.salary.amount }}</span>
            <span v-else class="font-h3 text-h3 font-bold text-on-surface">Chưa có đơn giá</span>
            <span class="font-caption text-caption text-on-surface-variant">
                {{ String(shifts.salary.hours).replace('.', ',') }} giờ dạy đã chấm công<template v-if="shifts.salary.missingRate && shifts.salary.amount"> · có buổi chưa có đơn giá</template>
            </span>
        </component>
        <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest">
            <div class="border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Học sinh cần chú ý</h3>
            </div>
            <div v-for="(s, i) in shifts.attention" :key="i" class="border-b border-surface-variant/60 px-md py-sm last:border-0">
                <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ s.name }}<span v-if="s.class" class="font-normal text-on-surface-variant"> · {{ s.class }}</span></p>
                <p class="font-caption text-caption text-error">{{ s.why }}</p>
            </div>
            <UiEmptyState v-if="!shifts.attention.length" icon="sentiment_satisfied" title="Không có học sinh điểm thấp trong 30 ngày" />
        </section>
    </div>
</template>
