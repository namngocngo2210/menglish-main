<script setup>
/** So sánh các cơ sở trong tháng (Admin): doanh thu, học viên, lead / chốt, lớp, việc quá hạn. */
defineProps({ branches: { type: Array, required: true } });
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-branches>
        <div class="border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">So sánh cơ sở trong tháng</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left font-body-small text-body-small">
                <thead class="text-on-surface-variant">
                    <tr class="border-b border-surface-variant">
                        <th class="whitespace-nowrap px-md py-sm font-semibold">Cơ sở</th>
                        <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Doanh thu</th>
                        <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Học viên</th>
                        <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Lead / chốt</th>
                        <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Lớp</th>
                        <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Việc quá hạn</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in branches" :key="b.id" class="border-b border-surface-variant/60 last:border-0">
                        <td class="whitespace-nowrap px-md py-sm font-semibold text-on-surface">{{ b.name }}</td>
                        <td class="whitespace-nowrap px-md py-sm text-right tabular-nums">{{ b.revenue }}</td>
                        <td class="px-md py-sm text-right tabular-nums">{{ formatNumber(b.studying) }}</td>
                        <td class="whitespace-nowrap px-md py-sm text-right tabular-nums">{{ formatNumber(b.leads) }} / {{ formatNumber(b.closed) }}</td>
                        <td class="px-md py-sm text-right tabular-nums">{{ formatNumber(b.classes) }}</td>
                        <td class="px-md py-sm text-right">
                            <span :class="['rounded-full px-sm py-0.5 font-caption text-caption font-semibold tabular-nums', b.overdue > 0 ? 'bg-error-container text-error' : 'bg-surface-container-high text-on-surface-variant']">{{ formatNumber(b.overdue) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <UiEmptyState v-if="!branches.length" icon="apartment" title="Chưa có cơ sở đang hoạt động" />
    </section>
</template>
