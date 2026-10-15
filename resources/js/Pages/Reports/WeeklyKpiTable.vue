<script setup>
/**
 * Bảng số lần phát sinh theo mục KPI của báo cáo tuần Học vụ (WeeklyReportMetrics::kpiGroups): nguồn (Tự động / Nhật ký),
 * số lần, icon "i" cách đếm và danh sách bản ghi đã đếm (bấm "Xem N dòng"). Dùng ở màn nhập báo cáo tuần và modal Chi tiết của Admin.
 */
import { reactive } from 'vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    groups: { type: Array, default: () => [] },
});

const open = reactive({});
const toneColor = { success: 'success', warning: 'warning', error: 'error', muted: 'neutral' };
</script>

<template>
<UiDataTable>
    <table>
        <thead>
            <tr>
                <th>Hạng mục công việc</th>
                <th class="hidden w-28 sm:table-cell">Nguồn</th>
                <th class="w-32 text-right">Số lần phát sinh</th>
            </tr>
        </thead>
        <tbody>
            <template v-for="group in groups" :key="group.name">
                <tr class="bg-surface-container-low">
                    <td colspan="3" class="font-body-small text-body-small font-semibold italic text-on-surface-variant">Nhóm {{ group.name }}</td>
                </tr>
                <template v-for="item in group.items" :key="item.id">
                    <tr :data-kpi-row="item.code">
                        <td>
                            <span class="inline-flex items-center gap-xs">
                                {{ item.name }} <span class="font-code text-xs text-on-surface-subtle">{{ item.code }}</span>
                                <UiInfo :title="item.name" :label="`Cách tính: ${item.name}`"><p>{{ item.info }}</p></UiInfo>
                            </span>
                            <UiBadge class="mt-xs sm:hidden" :color="item.source === 'auto' ? 'info' : 'neutral'" :dot="false">{{ item.source === 'auto' ? 'Tự động' : 'Nhật ký' }}</UiBadge>
                            <button
                                v-if="item.details.length"
                                type="button"
                                class="block font-body-small text-body-small font-semibold text-primary hover:underline"
                                @click="open[item.id] = !open[item.id]"
                            >
                                {{ open[item.id] ? 'Ẩn chi tiết' : `Xem ${item.details.length} dòng` }}
                            </button>
                        </td>
                        <td class="hidden sm:table-cell">
                            <UiBadge :color="item.source === 'auto' ? 'info' : 'neutral'" :dot="false">{{ item.source === 'auto' ? 'Tự động' : 'Nhật ký' }}</UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-right font-semibold text-on-surface" data-kpi-count>{{ item.count }} <span class="font-normal text-on-surface-variant">{{ item.unit }}</span></td>
                    </tr>
                    <tr v-if="open[item.id]" class="bg-surface-container-lowest">
                        <td colspan="3">
                            <ul class="space-y-xs pl-md">
                                <li v-for="(d, i) in item.details" :key="i" class="font-body-small text-body-small">
                                    <UiBadge v-if="d.tag" :color="toneColor[d.tone] ?? 'neutral'" :dot="false" class="mr-xs align-middle">{{ d.tag }}</UiBadge>
                                    <Link v-if="d.url" :href="d.url" class="text-on-surface hover:text-primary hover:underline">{{ d.text }}</Link>
                                    <span v-else :class="d.tone === 'muted' ? 'text-on-surface-variant line-through' : 'text-on-surface'">{{ d.text }}</span>
                                </li>
                            </ul>
                        </td>
                    </tr>
                </template>
            </template>
            <tr v-if="!groups.length">
                <td colspan="3"><UiEmptyState icon="tune" title="Chưa có mục KPI nào đang áp dụng" description="Admin cấu hình mục KPI ở màn Tiêu chí KPI." /></td>
            </tr>
        </tbody>
    </table>
</UiDataTable>
</template>
