<script setup>
/**
 * Lập / Sửa phiếu thu học phí (mockup lap-phieu-thu-hoc-phi).
 * Mở từ dòng học viên / khoản học phí → modal 4xl; "Lập phiếu thu mới" (lập tự do) và mở thẳng URL → trang riêng.
 * Lưu xong trong modal: đóng modal, trang nền có dữ liệu mới. Nút "Lưu nháp" / "Gửi duyệt" gửi kèm submit_action.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useRemoteModal } from '@/Components/ui/modalContext';
import ReceiptFields from './Partials/ReceiptFields.vue';
import { useReceiptForm } from './Partials/useReceiptForm';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Lập phiếu thu học phí', hideErrors: true } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    tuitions: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    initialTuitionId: { type: String, default: '' },
    initialStudentId: { type: String, default: '' },
    defaultBank: { type: Object, default: null },
    editing: { type: Object, default: null },
    nextReceiptNumber: { type: String, required: true },
    recentRejection: { type: Object, default: null },
});

const modal = useRemoteModal();
const form = useReceiptForm(props);
const action = computed(() => (props.editing ? route('tuition.receipts.update', props.editing.id) : route('tuition.receipts.store')));
const pageTitle = computed(() => (props.editing ? 'Sửa phiếu thu học phí' : 'Lập phiếu thu học phí'));
const submitLabel = computed(() => (props.editing ? 'Lưu & Gửi duyệt lại' : 'Lưu phiếu thu & Gửi duyệt'));
const cancelUrl = computed(() => (props.editing ? route('tuition.receipts.approve', { selected_id: props.editing.id }) : route('tuition.students')));
const formId = computed(() => (modal ? 'modal-receipt-form' : 'receiptForm'));
</script>

<template>
    <UiModalFrame v-if="modal" :title="`${pageTitle} · ${nextReceiptNumber}`" description="Lập, đối soát thanh toán và gửi duyệt phiếu thu học phí / phụ thu.">
        <UiForm :id="formId" :action="action" method="post" class="space-y-6">
            <input v-if="editing" type="hidden" name="_method" value="put" />
            <ReceiptFields :form="form" prefix="modal-" :recent-rejection="recentRejection" :has-default-bank="!!defaultBank" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" type="submit" :form="formId" name="submit_action" value="draft" icon="drafts">Lưu nháp</UiButton>
            <UiButton type="submit" :form="formId" name="submit_action" value="submit" icon="save" :disabled="!form.isValidReceipt">
                {{ submitLabel }} (<span class="font-code">{{ formatMoney(form.totalAmount || 0) }}</span>)
            </UiButton>
        </template>
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="pageTitle" description="Quy trình lập, đối soát thanh toán và xuất hóa đơn/biên lai học viên">
            <template #breadcrumbs>
                <Link :href="route('tuition.students')" class="hover:text-primary">Danh sách thu phí</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ editing ? 'Sửa phiếu thu' : 'Lập phiếu thu' }}</span>
            </template>
            <template #actions>
                <UiBadge v-if="editing" :color="editing.status === 'rejected' ? 'error' : 'warning'">{{ editing.status_label }}</UiBadge>
                <UiBadge v-else color="warning">Bản nháp</UiBadge>
                <span class="rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small">
                    <span class="font-label text-label uppercase text-on-surface-variant">Mã phiếu:</span>
                    <UiCode :value="nextReceiptNumber" class="ml-xs font-code text-code text-primary" />
                </span>
            </template>
        </UiPageHeader>

        <div class="mx-auto max-w-5xl">
            <UiForm :id="formId" :action="action" method="post" class="space-y-6">
                <input v-if="editing" type="hidden" name="_method" value="put" />
                <ReceiptFields :form="form" :recent-rejection="recentRejection" :has-default-bank="!!defaultBank" />

                <!-- Thanh thao tác dính đáy trong cột nội dung (sticky, không phải fixed) nên không đè lên sidebar. -->
                <div class="sticky bottom-0 z-30 -mx-md mt-lg border-t border-surface-container-highest/80 bg-surface-container-lowest px-md py-sm shadow-[0_-4px_12px_rgba(0,0,0,0.06)] sm:mx-0 sm:rounded-t-xl">
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <div class="flex items-center gap-sm">
                            <UiButton variant="secondary" :href="cancelUrl">Hủy bỏ</UiButton>
                            <UiButton type="submit" variant="secondary" icon="drafts" name="submit_action" value="draft">Lưu nháp</UiButton>
                        </div>
                        <div class="flex w-full items-center sm:w-auto">
                            <UiButton type="submit" icon="save" name="submit_action" value="submit" :disabled="!form.isValidReceipt" class="w-full sm:w-auto">
                                <span>{{ submitLabel }} (<span class="font-code">{{ formatMoney(form.totalAmount || 0) }}</span>)</span>
                            </UiButton>
                        </div>
                    </div>
                </div>
            </UiForm>
        </div>
    </template>
</template>
