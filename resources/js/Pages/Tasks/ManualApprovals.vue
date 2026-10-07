<script setup>
/**
 * Xác nhận hoàn thành thủ công: việc "Chờ xác nhận" không ảnh minh chứng và báo cáo trực lớp không ảnh — chỉ Admin
 * xác nhận / trả lại (06/10/2026).
 * Bấm dòng → chi tiết mở trong modal (?selected_id= / ?report=); đóng modal thì bỏ tham số khỏi thanh địa chỉ.
 */
import { nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Xác nhận hoàn thành thủ công' } });

const props = defineProps({
    items: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    assigneeOptions: { type: Array, default: () => [] },
    dismissUrl: { type: String, required: true },
});

const kinds = [
    { value: 'task', label: 'Đầu việc' },
    { value: 'report', label: 'Báo cáo trực lớp' },
];
const open = ref(!!props.selected);
const openReject = ref(false);
watch(
    () => props.selected?.key,
    (key) => {
        open.value = !!key;
        openReject.value = false;
    },
);
function showReject() {
    openReject.value = true;
    nextTick(() => document.getElementById('reject-note')?.focus());
}
</script>

<template>
    <UiPageHeader
        title="Xác nhận hoàn thành thủ công"
        description="Danh sách các đầu việc chờ xác nhận từ Trợ giảng: báo cáo không đính kèm ảnh minh chứng cần Admin xác nhận."
    />

    <UiFilterBar :action="route('tasks.manual-approvals')" search="q" placeholder="Đầu việc, lớp, trợ giảng...">
        <UiSelect name="kind" label="Loại" :options="kinds" placeholder="Tất cả loại" />
        <UiSelect name="assignee_id" label="Người thực hiện" :options="assigneeOptions" placeholder="Tất cả người thực hiện" />
    </UiFilterBar>

    <UiDataTable>
        <table>
            <thead>
                <tr>
                    <th>Đầu việc</th>
                    <th>Trợ giảng</th>
                    <th>Ngày giao</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.key" :data-href="item.url" :class="['cursor-pointer', item.selected ? 'bg-primary-fixed/40' : '']" :data-item="item.key">
                    <td>
                        <div class="flex flex-col gap-xs">
                            <span class="flex flex-wrap items-center gap-xs">
                                <UiBadge color="status-pending">Chờ xác nhận</UiBadge>
                                <UiBadge v-if="item.kind === 'report'" color="info" :dot="false">Báo cáo trực lớp</UiBadge>
                            </span>
                            <Link :href="item.url" class="font-semibold text-on-surface hover:text-primary">{{ item.title }}</Link>
                            <span class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ item.icon }}</span>
                                {{ item.class_label }}
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="flex items-center gap-sm">
                            <UiAvatar :name="item.person ?? '—'" size="sm" />
                            <span class="font-body-small text-body-small">{{ item.person ?? 'Chưa phân công' }}</span>
                        </div>
                    </td>
                    <td class="whitespace-nowrap font-code text-body-small">
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">calendar_today</span>{{ item.date }}</span>
                    </td>
                    <td class="text-right">
                        <div class="flex justify-end gap-xs">
                            <UiButton size="sm" variant="ghost" icon="visibility" :href="item.url">Xem</UiButton>
                            <UiForm :action="item.approve_url" method="post">
                                <UiButton type="submit" size="sm" variant="secondary">Xác nhận</UiButton>
                            </UiForm>
                        </div>
                    </td>
                </tr>
                <tr v-if="!items.length">
                    <td colspan="4">
                        <UiEmptyState icon="task_alt" title="Không có đầu việc nào cần xác nhận thủ công" description="Các báo cáo đã được xử lý hoặc đã hoàn thành tự động (có ảnh minh chứng)." />
                    </td>
                </tr>
            </tbody>
        </table>
    </UiDataTable>

    <!-- Chi tiết + xác nhận: mở sẵn khi URL chọn 1 mục; đóng → bỏ selected_id / report khỏi thanh địa chỉ. -->
    <UiModal v-if="selected" :show="open" :title="selected.title" max-width="2xl" :dismiss-url="dismissUrl" @close="open = false">
        <div class="space-y-md font-body-small text-body-small" :data-detail="selected.key">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <span class="flex flex-wrap items-center gap-xs">
                    <UiBadge color="status-pending">Chờ xác nhận</UiBadge>
                    <UiBadge v-if="selected.kind === 'report'" color="info" :dot="false">Báo cáo trực lớp</UiBadge>
                </span>
                <span class="font-caption text-caption text-on-surface-variant">Cập nhật: {{ selected.updated }}</span>
            </div>
            <p class="flex items-center gap-xs text-secondary">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">class</span>
                {{ selected.class_label }}
            </p>

            <div class="space-y-xs rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                <p class="font-label text-label uppercase text-on-surface-variant">Thông tin giao việc</p>
                <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Người thực hiện:</span><span class="font-semibold">{{ selected.person ?? '—' }}</span></p>
                <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Người giao việc:</span><span>{{ selected.creator ?? '—' }}</span></p>
                <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Ngày giao:</span><span class="font-code">{{ selected.assigned_date }}</span></p>
                <p class="flex justify-between gap-sm">
                    <span class="text-on-surface-variant">Hạn chót:</span><span class="font-code font-semibold text-error">{{ selected.due ?? '—' }}</span>
                </p>
                <p v-if="selected.kind === 'report'" class="flex justify-between gap-sm"><span class="text-on-surface-variant">Người xác nhận:</span><span class="font-semibold">{{ selected.confirmer }}</span></p>
            </div>

            <div class="space-y-sm">
                <p class="flex items-center gap-xs font-label text-label uppercase text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px] text-warning" aria-hidden="true">warning</span>
                    {{ selected.kind === 'report' ? 'Báo cáo trực lớp' : 'Báo cáo từ Trợ giảng' }}
                </p>
                <div class="space-y-sm rounded-lg border border-primary-fixed bg-primary-light p-sm text-on-surface">
                    <template v-if="selected.kind === 'report'">
                        <p><span class="font-semibold">Hôm nay học gì:</span> {{ selected.topics }}</p>
                        <p v-if="selected.teaching_log"><span class="font-semibold">Nhật ký dạy:</span> {{ selected.teaching_log }}</p>
                        <div v-if="selected.supports.length">
                            <p class="font-semibold">Học sinh cần bổ trợ ({{ selected.supports.length }}):</p>
                            <ul class="ml-md list-disc">
                                <li v-for="sup in selected.supports" :key="sup.id">{{ sup.name }} — {{ sup.reason }}</li>
                            </ul>
                        </div>
                    </template>
                    <p v-else class="whitespace-pre-line">{{ selected.note || 'Trợ giảng không ghi chú khi báo hoàn thành.' }}</p>
                    <a
                        v-for="link in selected.links"
                        :key="link"
                        :href="link"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex items-center gap-xs truncate rounded border border-primary-fixed bg-surface-container-lowest p-xs text-secondary hover:underline"
                    >
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">link</span>{{ link }}
                    </a>
                    <p class="flex items-start gap-xs rounded border border-error/20 bg-error-container/40 p-xs font-caption text-caption text-error">
                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">info</span>
                        <span><strong>Ghi chú:</strong> TA báo cáo hoàn thành nhưng không đính kèm ảnh chụp minh chứng lên hệ thống.</span>
                    </p>
                </div>
            </div>

            <UiForm v-show="!openReject" id="approvalForm" :action="selected.approve_url" method="post" class="space-y-xs">
                <UiTextarea name="admin_note" label="Ghi chú xác nhận (Tùy chọn)" :rows="2" placeholder="Nhập ghi chú hoặc phản hồi cho TA..." />
            </UiForm>
            <UiForm v-show="openReject" id="rejectForm" :action="selected.reject_url" method="post" class="space-y-xs rounded-lg border border-error/30 bg-error-container/30 p-sm">
                <UiTextarea id="reject-note" :name="selected.reject_field" label="Lý do từ chối / yêu cầu làm lại" :rows="2" required placeholder="Ví dụ: thiếu ảnh lớp, thông tin chưa chính xác..." />
            </UiForm>
        </div>

        <template #footer>
            <div v-if="!openReject" class="flex flex-wrap justify-end gap-sm">
                <UiButton variant="danger-text" icon="undo" @click="showReject">Từ chối / Yêu cầu bổ sung</UiButton>
                <UiButton type="submit" form="approvalForm" icon="check_circle">Xác nhận hoàn thành</UiButton>
            </div>
            <div v-else class="flex flex-wrap justify-end gap-sm">
                <UiButton variant="secondary" @click="openReject = false">Quay lại</UiButton>
                <UiButton type="submit" form="rejectForm" variant="danger" icon="send">Gửi yêu cầu làm lại</UiButton>
            </div>
        </template>
    </UiModal>
</template>
