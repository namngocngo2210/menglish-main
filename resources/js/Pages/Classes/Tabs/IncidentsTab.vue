<script setup>
/** Trang lớp · Sự vụ: nhật ký sự vụ gắn với lớp này (staff_reports.class_id); ghi sự vụ mới bằng nút "Ghi sự vụ" (modal). */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/format';

defineProps({
    klass: { type: Object, required: true },
    incidents: { type: Array, default: () => [] },
});

const sevColors = { urgent: 'error', important: 'warning' };
const statusColors = { resolved: 'success', following: 'secondary' };
const severities = [
    { value: 'normal', label: 'Bình thường' },
    { value: 'important', label: 'Quan trọng' },
    { value: 'urgent', label: 'Khẩn cấp' },
];
const today = formatDate(new Date(), 'Y-m-d');
const open = ref(false);
</script>

<template>
    <div class="space-y-4">
        <UiDataTable>
            <template #header>
                <h2 class="text-base font-bold text-on-surface">Sự vụ của lớp</h2>
                <div v-if="can('staff_report.submit')" class="flex flex-wrap items-center gap-md">
                    <Link :href="route('reports.journal')" class="text-xs font-semibold text-primary hover:underline">Mở nhật ký sự vụ để theo dõi / cập nhật</Link>
                    <UiButton size="sm" icon="add" @click="open = true">Ghi sự vụ</UiButton>
                </div>
            </template>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th>Sự vụ</th>
                        <th>Mức độ</th>
                        <th>Người ghi</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="incident in incidents" :key="incident.id">
                        <td class="font-code">{{ formatDate(incident.report_date) }}</td>
                        <td class="max-w-md">
                            <p class="font-semibold text-on-surface">{{ incident.title }}</p>
                            <p v-if="incident.content" class="line-clamp-2 text-xs text-on-surface-variant">{{ incident.content }}</p>
                            <p v-if="incident.followups" class="mt-1 text-xs text-on-surface-variant">{{ incident.followups }} follow-up · mới nhất: {{ incident.latest_followup }}</p>
                        </td>
                        <td><UiBadge :color="sevColors[incident.severity] ?? 'neutral'">{{ incident.severity_label }}</UiBadge></td>
                        <td>{{ incident.user ?? '—' }}</td>
                        <td><UiBadge :color="statusColors[incident.status] ?? 'primary'" pill>{{ incident.status_label }}</UiBadge></td>
                    </tr>
                    <tr v-if="!incidents.length">
                        <td colspan="5"><UiEmptyState icon="task_alt" title="Lớp chưa có sự vụ nào." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <UiModal v-if="can('staff_report.submit')" :show="open" :title="`Ghi sự vụ cho lớp ${klass.code}`" @close="open = false">
            <UiForm id="new-incident-form" :action="route('reports.journal.store')" method="post" class="space-y-md" reset-on-success @success="open = false">
                <input type="hidden" name="class_id" :value="klass.id" />
                <UiInput id="incident_title" name="title" label="Tiêu đề sự vụ" required />
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiSelect id="incident_severity" name="severity" label="Mức độ" :options="severities" />
                    <UiDate id="incident_date" name="report_date" label="Ngày sự vụ" :value="today" />
                </div>
                <UiTextarea id="incident_content" name="content" label="Mô tả chi tiết" :rows="3" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="open = false">Hủy</UiButton>
                <UiButton type="submit" form="new-incident-form" icon="add">Ghi sự vụ</UiButton>
            </template>
        </UiModal>
    </div>
</template>
