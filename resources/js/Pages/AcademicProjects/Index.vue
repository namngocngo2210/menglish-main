<script setup>
/**
 * Danh sách dự án học thuật (soạn sách, chương trình). Người quản lý thấy mọi dự án; thành viên chỉ thấy dự án mình tham gia.
 * Bấm dòng → trang chi tiết dự án. Tab của workspace Giáo trình.
 */
import { Link } from '@inertiajs/vue3';
import ProgressBar from './ProgressBar.vue';

defineOptions({ layout: { title: 'Dự án học thuật' } });

defineProps({
    projects: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});
</script>

<template>
    <UiPageHeader title="Dự án soạn sách & chương trình" icon="auto_stories" description="Kế hoạch, mốc deadline, khối lượng hoàn thành và báo cáo tiến độ của từng dự án học thuật.">
        <template v-if="canManage" #actions>
            <UiButton icon="add" :href="route('academic-projects.create')" modal="2xl">Tạo dự án</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar placeholder="Tìm mã, tên dự án…">
        <UiSelect name="type" label="Loại" :options="types" placeholder="Tất cả loại" />
        <UiSelect name="status" label="Trạng thái" :options="statuses" placeholder="Tất cả trạng thái" />
        <UiSelect name="owner_id" label="Phụ trách" :options="owners" placeholder="Tất cả người phụ trách" />
    </UiFilterBar>

    <UiDataTable min-width="980px">
        <table>
            <thead>
                <tr>
                    <th>Dự án</th>
                    <th>Phụ trách</th>
                    <th>Thời gian</th>
                    <th>Tiến độ</th>
                    <th>Mốc tiếp theo</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in projects.data" :key="p.id" :data-href="route('academic-projects.show', p.id)" class="cursor-pointer">
                    <td>
                        <Link :href="route('academic-projects.show', p.id)" class="font-semibold text-on-surface hover:text-primary">{{ p.name }}</Link>
                        <span class="block font-body-small text-body-small text-on-surface-variant"><span class="font-code">{{ p.code }}</span> · {{ p.type_label }} · {{ p.members_count }} thành viên</span>
                    </td>
                    <td>{{ p.owner ?? '—' }}</td>
                    <td class="whitespace-nowrap font-code text-body-small">
                        {{ p.start_date ? formatDate(p.start_date) : '—' }} → {{ p.deadline ? formatDate(p.deadline) : '—' }}
                    </td>
                    <td>
                        <ProgressBar :value="p.progress" :overdue="p.milestones_overdue > 0" :label="`${p.progress}% · ${p.milestones_done}/${p.milestones_total} mốc`" />
                        <span v-if="p.milestones_overdue" class="mt-[2px] block text-xs font-semibold text-error">{{ p.milestones_overdue }} mốc trễ hạn</span>
                    </td>
                    <td>
                        <template v-if="p.next_milestone">
                            <span class="block max-w-[220px] truncate">{{ p.next_milestone.title }}</span>
                            <span class="font-code text-xs text-on-surface-variant">Hạn {{ formatDate(p.next_milestone.due_date) }}</span>
                        </template>
                        <span v-else class="text-on-surface-subtle">—</span>
                    </td>
                    <td><UiBadge :color="p.status_color">{{ p.status_label }}</UiBadge></td>
                </tr>
                <tr v-if="!projects.data.length">
                    <td colspan="6">
                        <UiEmptyState icon="auto_stories" title="Chưa có dự án nào" :description="canManage ? 'Tạo dự án sau buổi họp thống nhất, rồi thêm mốc và chốt tiến độ.' : 'Bạn chưa tham gia dự án học thuật nào.'">
                            <UiButton v-if="canManage" icon="add" :href="route('academic-projects.create')" modal="2xl">Tạo dự án</UiButton>
                        </UiEmptyState>
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="projects" unit="dự án" /></template>
    </UiDataTable>
</template>
