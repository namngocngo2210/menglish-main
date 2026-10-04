<script setup>
/**
 * Báo cáo dự án học thuật (Báo cáo → Đào tạo & Chất lượng lớp): số liệu tháng, tiến độ từng dự án, theo thành viên
 * (mốc đúng hạn / trễ, số lần cập nhật, biên bản), khó khăn chưa phản hồi và biên bản trễ deadline trong tháng.
 */
import { Link } from '@inertiajs/vue3';
import ProgressBar from './ProgressBar.vue';

defineOptions({ layout: { title: 'Báo cáo dự án học thuật' } });

defineProps({
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    issues: { type: Array, default: () => [] },
    penalties: { type: Array, default: () => [] },
});
</script>

<template>
    <UiPageHeader title="Báo cáo dự án học thuật" icon="auto_stories" description="Tiến độ soạn sách / chương trình, khối lượng hoàn thành, khó khăn và trễ deadline." />

    <UiFilterBar :search="false">
        <UiSelect name="month" label="Tháng" :options="months" :value="month" />
        <UiSelect name="status" label="Trạng thái" :options="statuses" placeholder="Dự án đang mở + đóng trong tháng" />
    </UiFilterBar>

    <div class="mb-lg grid grid-cols-2 gap-md md:grid-cols-3 xl:grid-cols-6">
        <UiStatCard label="Dự án đang thực hiện" :value="stats.active" icon="auto_stories" tone="primary" />
        <UiStatCard label="Mốc đến hạn trong tháng" :value="stats.due_in_month" icon="event" />
        <UiStatCard label="Mốc xong trong tháng" :value="stats.done_in_month" icon="task_alt" tone="success" />
        <UiStatCard label="Mốc đang trễ hạn" :value="stats.overdue" icon="alarm" :tone="stats.overdue ? 'error' : 'default'" />
        <UiStatCard label="Khó khăn chưa phản hồi" :value="stats.open_issues" icon="support" :tone="stats.open_issues ? 'warning' : 'default'" />
        <UiStatCard label="Biên bản trễ deadline" :value="stats.penalties_in_month" icon="gavel" :tone="stats.penalties_in_month ? 'error' : 'default'" />
    </div>

    <UiDataTable min-width="960px" class="mb-lg">
        <template #header><h2 class="font-h3 text-h3 text-on-surface">Tiến độ từng dự án</h2></template>
        <table>
            <thead>
                <tr>
                    <th>Dự án</th>
                    <th>Phụ trách</th>
                    <th>Deadline</th>
                    <th>Tiến độ</th>
                    <th class="text-right">Mốc trễ</th>
                    <th>Cập nhật gần nhất</th>
                    <th class="text-right">Khó khăn mở</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in projects" :key="p.id" :data-href="route('academic-projects.show', p.id)" class="cursor-pointer">
                    <td>
                        <Link :href="route('academic-projects.show', p.id)" class="font-semibold text-on-surface hover:text-primary">{{ p.name }}</Link>
                        <span class="block font-code text-xs text-on-surface-variant">{{ p.code }} · {{ p.type_label }}</span>
                    </td>
                    <td>{{ p.owner ?? '—' }}</td>
                    <td class="whitespace-nowrap font-code">{{ p.deadline ? formatDate(p.deadline) : '—' }}</td>
                    <td><ProgressBar :value="p.progress" :overdue="p.milestones_overdue > 0" :label="`${p.progress}% · ${p.milestones_done}/${p.milestones_total} mốc`" /></td>
                    <td :class="['text-right font-code', p.milestones_overdue ? 'font-semibold text-error' : '']">{{ p.milestones_overdue }}</td>
                    <td>
                        <template v-if="p.last_update">
                            <span class="block font-code text-body-small">{{ formatDate(p.last_update.at, 'd/m H:i') }}</span>
                            <span class="text-xs text-on-surface-variant">{{ p.last_update.user }}</span>
                        </template>
                        <span v-else class="text-on-surface-subtle">Chưa có</span>
                    </td>
                    <td :class="['text-right font-code', p.open_issues ? 'font-semibold text-warning' : '']">{{ p.open_issues }}</td>
                    <td><UiBadge :color="p.status_color">{{ p.status_label }}</UiBadge></td>
                </tr>
                <tr v-if="!projects.length">
                    <td colspan="8"><UiEmptyState icon="auto_stories" title="Không có dự án nào" /></td>
                </tr>
            </tbody>
        </table>
    </UiDataTable>

    <div class="grid grid-cols-1 gap-lg 2xl:grid-cols-2">
        <UiDataTable min-width="620px">
            <template #header><h2 class="font-h3 text-h3 text-on-surface">Theo thành viên</h2></template>
            <table>
                <thead>
                    <tr>
                        <th>Thành viên</th>
                        <th class="text-right">Mốc được giao</th>
                        <th class="text-right">Xong đúng hạn</th>
                        <th class="text-right">Xong trễ</th>
                        <th class="text-right">Đang trễ</th>
                        <th class="text-right">Cập nhật / tháng</th>
                        <th class="text-right">Biên bản</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in members" :key="m.id">
                        <td class="whitespace-nowrap font-semibold text-on-surface">{{ m.name }}</td>
                        <td class="text-right font-code">{{ m.assigned }}</td>
                        <td class="text-right font-code text-tertiary">{{ m.done_on_time }}</td>
                        <td class="text-right font-code">{{ m.done_late }}</td>
                        <td :class="['text-right font-code', m.overdue ? 'font-semibold text-error' : '']">{{ m.overdue }}</td>
                        <td class="text-right font-code">{{ m.updates_in_month }}</td>
                        <td class="text-right font-code">{{ m.penalties }}</td>
                    </tr>
                    <tr v-if="!members.length">
                        <td colspan="7"><UiEmptyState compact icon="group" title="Chưa có mốc nào được giao" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <section class="rounded-xl border border-surface-variant bg-surface-container-lowest">
            <h2 class="border-b border-surface-variant px-md py-sm font-h3 text-h3 text-on-surface">Khó khăn thành viên báo</h2>
            <ul class="divide-y divide-surface-variant">
                <li v-for="i in issues" :key="i.id" class="px-md py-sm">
                    <div class="flex flex-wrap items-center gap-sm">
                        <Link :href="route('academic-projects.show', i.project_id)" class="font-semibold text-on-surface hover:text-primary">{{ i.project }}</Link>
                        <span class="font-body-small text-body-small text-on-surface-variant">{{ i.user }} · {{ formatDate(i.created_at, 'd/m H:i') }}</span>
                        <UiBadge :color="i.responded ? 'success' : 'warning'">{{ i.responded ? 'Đã phản hồi' : 'Chưa phản hồi' }}</UiBadge>
                    </div>
                    <p class="mt-xs line-clamp-3 whitespace-pre-line text-on-surface">{{ i.difficulties }}</p>
                </li>
                <li v-if="!issues.length" class="p-md"><UiEmptyState compact icon="sentiment_satisfied" title="Chưa có khó khăn nào được báo" /></li>
            </ul>
        </section>
    </div>

    <UiDataTable v-if="penalties.length" min-width="620px" class="mt-lg">
        <template #header><h2 class="font-h3 text-h3 text-on-surface">Biên bản trễ deadline trong tháng</h2></template>
        <table>
            <thead>
                <tr><th>Biên bản</th><th>Người nhận</th><th>Mốc</th><th>Trạng thái</th><th class="text-right">Mức phạt</th></tr>
            </thead>
            <tbody>
                <tr v-for="p in penalties" :key="p.id">
                    <td><a :href="p.url" class="font-code font-semibold text-primary hover:underline">{{ p.code }}</a></td>
                    <td>{{ p.user }}</td>
                    <td>{{ p.milestone }}</td>
                    <td>{{ p.status_label }}</td>
                    <td class="text-right font-code">{{ p.amount ? formatMoney(p.amount) : '—' }}</td>
                </tr>
            </tbody>
        </table>
    </UiDataTable>
</template>
