<script setup>
/**
 * Lịch sử hoạt động trên hồ sơ khách: lọc theo loại (server, ?log_type=), form ghi chú liên hệ mới (hình thức: gọi / Zalo / gặp…),
 * dòng thời gian (khách Thất bại hiện khung lý do).
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

const props = defineProps({
    customer: { type: Object, required: true },
    histories: { type: Array, required: true },
    historyTotal: { type: Number, default: 0 },
    logType: { type: String, default: null },
    logTypeOptions: { type: Array, default: () => [] },
    card: { type: String, required: true },
});
const noteTypes = [
    ['call', 'Gọi điện'],
    ['message', 'Zalo/SMS'],
    ['meet', 'Trực tiếp'],
    ['test', 'Test đầu vào'],
    ['note', 'Ghi chú'],
];
const noteType = ref('call');
const dirty = ref(false);
// Nút lưu kiểu phụ, chỉ tô cam khi form đang được sửa.
const dirtySave = '!border-transparent !bg-primary-container !text-white hover:!bg-primary';

function filterLog(event) {
    const value = event.target.value;
    router.get(route('crm.customers.show', value ? { id: props.customer.id, log_type: value } : props.customer.id), {}, { preserveScroll: true, preserveState: true });
}
function iconTone(history) {
    if (history.is_lost) return 'bg-error-container text-error';
    if (['call', 'message', 'meet'].includes(history.type)) return 'bg-secondary-fixed text-secondary';
    if (['test', 'result', 'trial'].includes(history.type)) return 'bg-tertiary-fixed/50 text-tertiary';
    if (history.type === 'assign') return 'bg-primary-fixed text-primary';
    return 'bg-surface-container-high text-on-surface-variant';
}
</script>

<template>
    <div id="timeline" :class="card">
        <div class="flex flex-wrap items-center justify-between gap-md border-b border-surface-container-highest p-lg">
            <h3 class="font-h3 text-h3 text-on-surface">Lịch sử hoạt động <span class="font-body-small text-body-small text-on-surface-variant">({{ histories.length }}{{ logType ? '/' + historyTotal : '' }})</span></h3>
            <UiSelect name="log_type" :value="logType ?? ''" :searchable="false" aria-label="Lọc lịch sử" placeholder="Tất cả hoạt động" :options="logTypeOptions" class="py-xs font-body-small text-body-small" @change="filterLog" />
        </div>

        <UiForm
            v-if="can('lead.update')"
            :action="route('crm.customers.notes.store', customer.id)"
            method="post"
            class="border-b border-surface-container-highest bg-surface-container-low/40 p-lg"
            :reset-on-success="['content']"
            @input="dirty = true"
            @success="dirty = false"
        >
            <input type="hidden" name="type" :value="noteType" />
            <div class="flex flex-col gap-md sm:flex-row sm:items-end">
                <div class="flex-1 space-y-sm">
                    <UiTextarea id="history_note_content" name="content" :rows="2" required placeholder="Ghi chú nội dung liên hệ mới..." aria-label="Ghi chú nội dung liên hệ" />
                    <div class="flex flex-wrap items-center gap-sm">
                        <span class="font-body-small text-body-small text-on-surface-variant">Hình thức:</span>
                        <button
                            v-for="[key, label] in noteTypes"
                            :key="key"
                            type="button"
                            :class="['rounded-full border px-md py-xs font-body-small text-body-small transition-colors', noteType === key ? 'border-secondary bg-secondary text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-high']"
                            @click="noteType = key"
                        >{{ label }}</button>
                    </div>
                </div>
                <UiButton type="submit" variant="secondary" icon="send" :class="dirty ? dirtySave : ''">Lưu ghi chú</UiButton>
            </div>
        </UiForm>

        <div class="space-y-lg p-lg">
            <div v-for="history in histories" :key="history.id" class="flex items-start gap-md">
                <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-full', iconTone(history)]">
                    <span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1">{{ history.is_lost ? 'person_off' : history.type_icon }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <span class="font-body-semibold text-body-semibold text-on-surface">{{ history.user ?? 'Hệ thống' }}</span>
                        <span class="font-code text-caption text-on-surface-variant">{{ history.created_at }}</span>
                    </div>
                    <div v-if="history.is_lost" class="mt-xs rounded-lg border-l-4 border-error bg-error-container/30 p-sm">
                        <span class="font-label text-label uppercase text-error">Lý do thất bại</span>
                        <p class="font-body-base text-body-base text-on-surface">{{ history.reason || history.content }}</p>
                    </div>
                    <p v-else class="mt-xs whitespace-pre-line font-body-base text-body-base text-on-surface-variant">{{ history.content }}</p>
                    <span v-if="history.type_label" class="mt-xs inline-block rounded bg-surface-container-high px-sm py-0.5 font-caption text-caption text-on-surface-variant">{{ history.type_label }}</span>
                </div>
            </div>
            <UiEmptyState v-if="!histories.length" icon="history" title="Chưa có lịch sử hoạt động" />
            <div class="flex items-center gap-sm pt-sm">
                <span class="h-px flex-1 bg-surface-container-highest"></span>
                <span class="font-caption text-caption text-on-surface-variant">Bắt đầu tạo hồ sơ - {{ customer.created_date }}</span>
                <span class="h-px flex-1 bg-surface-container-highest"></span>
            </div>
        </div>
    </div>
</template>
