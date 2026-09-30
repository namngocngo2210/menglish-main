<script setup>
/**
 * Nhật ký sự vụ Học vụ: danh sách sự vụ (đổi trạng thái chọn là lưu, thêm follow-up) + modal "Ghi sự vụ".
 * Người xem tổng (staff_report.view_all) thấy thêm tên người ghi.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Nhật ký sự vụ Học vụ' } });

defineProps({
    journals: { type: Object, required: true },
    isPriv: { type: Boolean, default: false },
    classes: { type: Array, default: () => [] },
    today: { type: String, default: null },
});

const creating = ref(false);
const severityColor = { urgent: 'error', important: 'warning' };
const statusColor = { resolved: 'success', following: 'secondary' };
const statusLabel = { resolved: 'Đã xử lý', following: 'Đang theo dõi' };
const statusOptions = [
    { value: 'open', label: 'Mới' },
    { value: 'following', label: 'Đang theo dõi' },
    { value: 'resolved', label: 'Đã xử lý' },
];
const submitOnChange = (event) => event.target.form?.requestSubmit();
</script>

<template>
    <UiPageHeader title="Nhật ký sự vụ Học vụ" icon="event_note">
        <template #actions>
            <UiButton icon="add" @click="creating = true">Ghi sự vụ</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div class="space-y-3">
            <div v-for="j in journals.data" :key="j.id" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <UiBadge :color="severityColor[j.severity] ?? 'neutral'" pill :dot="false">{{ j.severity_label }}</UiBadge>
                            <UiBadge :color="statusColor[j.status] ?? 'primary'" pill :dot="false">{{ statusLabel[j.status] ?? 'Mới' }}</UiBadge>
                            <span class="text-sm font-bold text-on-surface">{{ j.title }}</span>
                        </div>
                        <div class="mt-1 text-xs text-on-surface-subtle">
                            {{ formatDate(j.report_date) }}
                            <template v-if="j.class"> · <Link :href="route('classes.show', { id: j.class.id, tab: 'incidents' })" class="font-semibold text-primary hover:underline">Lớp {{ j.class.code }}</Link></template>
                            <template v-if="isPriv"> · <span class="font-semibold text-on-surface-variant">{{ j.user }}</span></template>
                        </div>
                        <p v-if="j.content" class="mt-2 text-sm text-on-surface-variant">{{ j.content }}</p>
                    </div>
                    <UiForm :action="route('reports.journal.status', j.id)" method="post" class="shrink-0">
                        <UiSelect :id="`journal-status-${j.id}`" name="status" :value="j.status" :options="statusOptions" aria-label="Trạng thái sự vụ" class="text-xs" @change="submitOnChange" />
                    </UiForm>
                </div>

                <!-- Follow-ups -->
                <div v-if="j.followups.length" class="space-y-1.5 border-l-2 border-surface-container-highest pl-3">
                    <div v-for="f in j.followups" :key="f.id" class="text-xs text-on-surface-variant">
                        <span class="font-semibold text-on-surface">{{ f.user ?? 'N/A' }}:</span> {{ f.content }}
                        <span class="text-on-surface-subtle">· {{ formatDate(f.created_at, 'd/m H:i') }}</span>
                    </div>
                </div>

                <!-- Thêm follow-up (tạo tác vụ) -->
                <UiForm :action="route('reports.journal.followup', j.id)" method="post" class="flex items-center gap-2" reset-on-success>
                    <input type="text" name="content" required placeholder="Nhập nội dung tác vụ / follow-up..." aria-label="Nội dung tác vụ" class="flex-1 rounded-lg border-outline-variant bg-surface-container-lowest text-xs text-on-surface placeholder:text-on-surface-subtle focus:border-primary-container focus:ring-primary-container/50" />
                    <UiButton type="submit" variant="secondary" size="sm" icon="add_task">Tạo tác vụ</UiButton>
                </UiForm>
            </div>
            <div v-if="!journals.data.length" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <UiEmptyState icon="event_note" title="Chưa có sự vụ nào được ghi nhận." />
            </div>

            <UiPagination :paginator="journals" :options="[]" />
        </div>
    </div>

    <!-- Form ghi sự vụ mới (lỗi validate hiện ngay trong modal, giữ dữ liệu đã nhập) -->
    <UiModal :show="creating" title="Ghi nhận sự vụ mới" @close="creating = false">
        <UiForm id="new-journal-form" :action="route('reports.journal.store')" method="post" class="space-y-md" reset-on-success @success="creating = false">
            <UiInput name="title" label="Tiêu đề sự vụ" required />
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect name="severity" label="Mức độ" :value="'normal'" :options="[{ value: 'normal', label: 'Bình thường' }, { value: 'important', label: 'Quan trọng' }, { value: 'urgent', label: 'Khẩn cấp' }]" />
                <UiDate name="report_date" label="Ngày sự vụ" :value="today" />
            </div>
            <UiTextarea name="content" label="Mô tả chi tiết" :rows="3" />
            <!-- Gắn lớp để sự vụ hiện ở tab "Sự vụ" của Trang lớp -->
            <UiSelect id="journal_class_id" name="class_id" label="Lớp liên quan" placeholder="Không gắn lớp (sự vụ chung)" :value="''" :options="classes" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
            <UiButton type="submit" form="new-journal-form" icon="add">Ghi sự vụ</UiButton>
        </template>
    </UiModal>
</template>
