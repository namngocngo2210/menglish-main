<script setup>
/**
 * Dashboard Nhật ký sự vụ: ticket hỗ trợ nổi cộm, nhật ký sự vụ (staff_reports) và nhật ký học vụ của các lớp / cơ sở;
 * lọc theo cơ sở, lớp, mức độ, trạng thái.
 */
import { Link } from '@inertiajs/vue3';
import SectionTabs from '@/Components/Academic/SectionTabs.vue';

defineOptions({ layout: { title: 'Báo cáo & sự vụ' } });

defineProps({
    severity: { type: String, default: 'all' },
    status: { type: String, default: 'all' },
    branchId: { type: String, default: '' },
    classId: { type: String, default: '' },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    tickets: { type: Array, default: () => [] },
    journals: { type: Array, default: () => [] },
    incidents: { type: Array, default: () => [] },
    totalIncidents: { type: Number, default: 0 },
    resolvedCount: { type: Number, default: 0 },
    openCount: { type: Number, default: 0 },
    urgentCount: { type: Number, default: 0 },
    canSubmitJournal: { type: Boolean, default: false },
});

const priorityLabels = { urgent: 'Khẩn cấp', high: 'Cao', medium: 'Trung bình', low: 'Thấp' };
const priorityColors = { urgent: 'error', high: 'warning', medium: 'info' };
const ticketStatusColors = { open: 'warning', in_progress: 'info', resolved: 'success' };
const severityOptions = [{ value: 'all', label: 'Mọi mức độ' }, ...Object.entries(priorityLabels).map(([value, label]) => ({ value, label }))];
const statusOptions = [
    { value: 'all', label: 'Mọi trạng thái' },
    { value: 'open', label: 'Đang xử lý' },
    { value: 'resolved', label: 'Đã giải quyết' },
];
const journalStatusColor = { resolved: 'success', following: 'info' };
const journalStatusLabel = { resolved: 'Đã xử lý', following: 'Đang theo dõi' };

function submit(event) {
    event.target.form?.requestSubmit();
}
</script>

<template>
    <UiPageHeader title="Báo cáo & sự vụ" icon="monitoring" description="Báo cáo đào tạo ngày / tuần / tháng và sự vụ của các lớp, các cơ sở.">
        <template #actions>
            <UiButton v-if="canSubmitJournal" icon="add" :href="route('reports.journal')">Ghi sự vụ</UiButton>
        </template>
    </UiPageHeader>
    <SectionTabs />

    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <UiStatCard label="Tổng sự vụ ghi nhận" :value="`${totalIncidents} vụ`" icon="auto_stories" />
            <UiStatCard label="Khẩn cấp cần xử lý" :value="`${urgentCount} ticket khẩn cấp`" tone="error" icon="emergency" />
            <UiStatCard label="Đang theo dõi / Xử lý" :value="`${openCount} vụ`" tone="warning" icon="hourglass_top" />
            <UiStatCard label="Đã giải quyết" :value="`${resolvedCount} vụ`" tone="success" icon="check_circle" />
        </div>

        <UiFilterBar :action="route('academic.dashboards.incidents')" :search="false" class="!mb-0" aria-label="Lọc sự vụ">
            <UiSelect name="branch_id" label="Cơ sở" placeholder="Tất cả cơ sở" :value="branchId" :options="branches" @change="submit" />
            <UiSelect name="class_id" label="Lớp" placeholder="Mọi lớp" :value="classId" :options="classes" @change="submit" />
            <UiSelect name="severity" label="Mức độ" :value="severity" :options="severityOptions" @change="submit" />
            <UiSelect name="status" label="Trạng thái" :value="status" :options="statusOptions" @change="submit" />
        </UiFilterBar>

        <UiDataTable>
            <template #header>
                <div>
                    <h3 class="text-sm font-bold text-on-surface">Nhật ký sự vụ các lớp và cơ sở</h3>
                    <p class="text-xs text-on-surface-variant">Theo dõi, giao quyền xử lý và ghi nhận giải pháp khắc phục</p>
                </div>
            </template>

            <table>
                <thead>
                    <tr>
                        <th>Mã / Cơ sở</th>
                        <th>Phân loại sự vụ</th>
                        <th>Chi tiết sự vụ phát sinh</th>
                        <th>Mức độ</th>
                        <th>Người phụ trách &amp; Biện pháp</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ticket in tickets" :key="'t' + ticket.id" :class="ticket.priority === 'urgent' ? 'bg-error/5' : ''">
                        <td>
                            <Link v-if="ticket.can_open" :href="route('tickets.show', ticket.id)" class="font-code font-bold text-on-surface hover:underline">{{ ticket.code }}</Link>
                            <span v-else class="font-code font-bold text-on-surface">{{ ticket.code }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ ticket.branch ?? 'Chưa cập nhật' }}</span>
                        </td>
                        <td><UiBadge color="neutral" pill :dot="false">{{ ticket.category_label }}</UiBadge></td>
                        <td class="max-w-sm">
                            <p class="font-semibold">{{ ticket.title }}</p>
                            <p class="line-clamp-1 text-xs text-on-surface-variant">{{ ticket.excerpt }}</p>
                        </td>
                        <td><UiBadge :color="priorityColors[ticket.priority] ?? 'neutral'" pill>{{ priorityLabels[ticket.priority] ?? 'Chưa cập nhật' }}</UiBadge></td>
                        <td>
                            <span class="font-bold">{{ ticket.assignee ?? 'Chưa phân công' }}</span>
                            <span class="block text-xs text-on-surface-variant">Người tạo: {{ ticket.creator ?? 'Chưa cập nhật' }}</span>
                        </td>
                        <td><UiBadge :color="ticketStatusColors[ticket.status] ?? 'neutral'" pill>{{ ticket.status_label }}</UiBadge></td>
                    </tr>
                    <tr v-for="journal in journals" :key="'j' + journal.id" :class="journal.severity === 'urgent' && journal.status !== 'resolved' ? 'bg-error/5' : ''">
                        <td>
                            <span class="font-code font-bold">Sự vụ #{{ journal.id }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ journal.branch ?? 'Chưa cập nhật' }}</span>
                            <Link v-if="journal.class_id" :href="route('classes.show', { id: journal.class_id, tab: 'incidents' })" class="block text-xs font-semibold text-primary hover:underline">Lớp {{ journal.class_code }}</Link>
                        </td>
                        <td><UiBadge color="warning" pill :dot="false">Nhật ký sự vụ</UiBadge></td>
                        <td class="max-w-sm">
                            <p class="font-semibold">{{ journal.title }}</p>
                            <p v-if="journal.content" class="line-clamp-1 text-xs text-on-surface-variant">{{ journal.content }}</p>
                        </td>
                        <td><UiBadge :color="priorityColors[journal.priority] ?? 'neutral'" pill>{{ journal.severity_label }}</UiBadge></td>
                        <td>
                            <span class="font-bold">{{ journal.user ?? 'Chưa cập nhật' }}</span>
                            <span v-if="journal.followup" class="line-clamp-1 block text-xs text-on-surface-variant">{{ journal.followup }}</span>
                        </td>
                        <td><UiBadge :color="journalStatusColor[journal.status] ?? 'warning'" pill>{{ journalStatusLabel[journal.status] ?? 'Mới' }}</UiBadge></td>
                    </tr>
                    <tr v-for="incident in incidents" :key="'i' + incident.id">
                        <td>
                            <span class="font-code font-bold">{{ incident.code }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ incident.branch ?? 'Chưa cập nhật' }}</span>
                        </td>
                        <td><UiBadge color="warning" pill :dot="false">Nhật ký học vụ</UiBadge></td>
                        <td class="max-w-sm">
                            <p class="font-semibold">{{ incident.title }}</p>
                            <p v-if="incident.content" class="line-clamp-1 text-xs text-on-surface-variant">{{ incident.content }}</p>
                        </td>
                        <td class="text-xs text-on-surface-subtle">—</td>
                        <td><span class="font-bold">{{ incident.user ?? 'Chưa cập nhật' }}</span></td>
                        <td><UiBadge color="neutral" pill>{{ incident.status_label }}</UiBadge></td>
                    </tr>
                    <tr v-if="!tickets.length && !incidents.length && !journals.length">
                        <td colspan="6"><UiEmptyState title="Chưa có sự vụ nào phù hợp bộ lọc." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
</template>
