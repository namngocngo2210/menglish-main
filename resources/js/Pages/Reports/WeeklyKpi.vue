<script setup>
/**
 * Báo cáo tuần Học vụ (mockup 02/07 + chủ dự án 15/10/2026): số lần phát sinh theo từng mục KPI và chỉ số quy mô đều do hệ thống
 * tính cho cả tuần (StaffReportController::weeklyKpi → WeeklyReportMetrics), người viết chỉ nhập ghi chú tuần. Mục KPI có nguồn
 * tự động đếm như phiếu KPI tháng, còn lại đếm sự vụ trong Nhật ký Học vụ gắn mã KPI. Ghi chú các báo cáo ngày trong tuần hiện kèm.
 */
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import DailyMetric from './DailyMetric.vue';

defineOptions({ layout: { title: 'Báo cáo tuần Học vụ' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    week: { type: String, required: true },
    weeks: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    metrics: { type: Object, default: () => ({ items: [] }) },
    daily: { type: Object, default: () => ({ submitted: 0, expected: 0, missing: [], notes: [] }) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    note: { type: String, default: null },
    legacy: { type: Array, default: () => [] },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
});

const open = reactive({});
const pickWeek = (value) => value && value !== props.week && router.get(route('reports.periodic.weekly-kpi'), { week: value });
const refresh = () => router.get(route('reports.periodic.weekly-kpi'), { week: props.week, refresh: 1 }, { preserveScroll: true });
const toneColor = { success: 'success', warning: 'warning', error: 'error', muted: 'neutral' };
</script>

<template>
    <UiPageHeader title="Báo cáo tuần" icon="assignment_turned_in">
        <template #meta>
            <span class="inline-flex items-center gap-xs">
                Số liệu lấy tự động từ hệ thống, bạn chỉ cần ghi chú.
                <UiInfo title="Số liệu báo cáo tuần">
                    <p>Mọi số được tính lại từ dữ liệu gốc cho cả tuần (thứ 2 → chủ nhật), cùng cách đếm của báo cáo ngày, nên ngày quên nộp báo cáo ngày vẫn đủ số.</p>
                    <p>Mục KPI ghi <strong class="text-on-surface">Tự động</strong> đếm như phiếu KPI tháng. Mục ghi <strong class="text-on-surface">Nhật ký</strong> đếm sự vụ trong Nhật ký Học vụ có gắn mã KPI đó: thiếu thì ghi thêm ở Nhật ký Học vụ rồi bấm Cập nhật số liệu.</p>
                    <p>Khi bấm Lưu, số liệu lúc đó được lưu vào báo cáo để Admin xem đúng số bạn đã thấy.</p>
                </UiInfo>
            </span>
        </template>
        <template #actions>
            <div class="w-72">
                <p class="mb-xs font-body-small text-body-small font-semibold text-on-surface-variant">Tuần báo cáo</p>
                <UiSelect :model-value="week" :options="weeks" :searchable="false" aria-label="Tuần báo cáo" @update:model-value="pickWeek" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiForm id="weekly-kpi-form" :action="route('reports.periodic.weekly-kpi.store')" method="post" class="space-y-lg">
        <input type="hidden" name="week" :value="week" />
        <UiAlert v-if="submittedAt" type="info">
            Đã nộp tuần này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}.
            <template v-if="!live">Số liệu bên dưới là số lúc nộp.</template>
            <template v-else>Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo.</template>
        </UiAlert>

        <div class="flex flex-wrap items-center justify-between gap-sm">
            <p class="font-body-small text-body-small text-on-surface-variant" data-computed-at>
                <span class="material-symbols-outlined align-middle text-[16px]" aria-hidden="true">sync</span>
                Số liệu tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m/Y') : '—' }}
            </p>
            <UiButton type="button" variant="ghost" size="sm" icon="refresh" data-refresh-metrics @click="refresh">Cập nhật số liệu</UiButton>
        </div>

        <section v-if="legacy.length" class="space-y-sm rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-weekly-legacy>
            <h2 class="font-h3 text-h3 text-on-surface">Số liệu đã nhập tay (bản cũ)</h2>
            <p class="font-body-small text-body-small text-on-surface-variant">Báo cáo này nộp trước khi chuyển sang số liệu tự động. Lưu lại sẽ thay bằng số liệu hệ thống.</p>
            <dl class="grid grid-cols-1 gap-sm md:grid-cols-2">
                <div v-for="f in legacy" :key="f.label">
                    <dt class="font-label text-label uppercase text-on-surface-variant">{{ f.label }}</dt>
                    <dd class="font-body-medium text-body-medium text-on-surface">{{ f.value }}</dd>
                </div>
            </dl>
        </section>

        <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_380px]">
            <section class="min-w-0 rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-weekly-kpi>
                <h2 class="flex items-center gap-xs border-b border-surface-container-highest px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">assignment_turned_in</span>Số lần phát sinh theo mục KPI
                </h2>
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
            </section>

            <div class="min-w-0 space-y-lg">
                <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-weekly-metrics>
                    <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">monitoring</span>Thông tin theo dõi
                    </h2>
                    <p class="font-body-small text-body-small text-on-surface-variant">(Chỉ số quy mô, không tính KPI)</p>
                    <div class="space-y-sm">
                        <DailyMetric v-for="item in metrics.items" :key="item.key" :item="item" />
                    </div>
                </section>
            </div>
        </div>

        <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-weekly-daily>
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">event_note</span>Ghi chú tuần
            </h2>
            <p class="font-body-small text-body-small text-on-surface-variant" data-daily-submitted>
                Báo cáo ngày đã nộp {{ daily.submitted }}/{{ daily.expected }} ngày<template v-if="daily.missing.length"> · còn thiếu {{ daily.missing.join(', ') }}</template>.
            </p>
            <div v-if="daily.notes.length" class="grid grid-cols-1 gap-md md:grid-cols-3">
                <div v-for="n in daily.notes" :key="n.key" class="rounded-lg border border-surface-variant bg-surface-container-low p-md">
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ n.label }}</p>
                    <ul class="space-y-xs">
                        <li v-for="(l, i) in n.lines" :key="i" class="whitespace-pre-line font-body-small text-body-small text-on-surface">{{ l.text }}</li>
                    </ul>
                </div>
            </div>
            <UiTextarea name="note" label="Ghi chú tuần" :rows="3" placeholder="Tổng kết tuần, việc cần lưu ý tuần tới..." :value="note" />
        </section>

        <div class="flex justify-end">
            <UiButton type="submit" icon="save">Lưu báo cáo tuần</UiButton>
        </div>
    </UiForm>

    <div class="mt-lg">
        <ReportHistory :items="history" :current="week" title="Các tuần đã nộp" />
    </div>
</template>
