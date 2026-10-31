<script setup>
/** Shared receipt editor: remote modal or a full page with a persistent summary. */
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
    promotions: { type: Array, default: () => [] },
    initialTuitionId: { type: String, default: '' },
    initialStudentId: { type: String, default: '' },
    defaultBank: { type: Object, default: null },
    bankByBranch: { type: Object, default: () => ({}) },
    editing: { type: Object, default: null },
    nextReceiptNumber: { type: String, required: true },
    recentRejection: { type: Object, default: null },
    merchandiseItems: { type: Array, default: () => [] },
    stockByBranch: { type: Object, default: () => ({}) },
    paperInvoiceNext: { type: Object, default: () => ({}) },
    canRequestCancel: { type: Boolean, default: false },
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
            <ReceiptFields :form="form" prefix="modal-" layout="modal" :recent-rejection="recentRejection" :can-request-cancel="canRequestCancel" />
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
                <Link :href="route('tuition.students')" class="inline-flex min-h-11 items-center hover:text-primary">Danh sách thu phí</Link>
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

        <div class="mx-auto max-w-6xl">
            <UiForm :id="formId" :action="action" method="post" class="space-y-6">
                <input v-if="editing" type="hidden" name="_method" value="put" />
                <ReceiptFields :form="form" layout="page" :submit-label="submitLabel" :cancel-url="cancelUrl" :recent-rejection="recentRejection" :can-request-cancel="canRequestCancel" />

                <!-- Sticky within the content column so sidebar and floating actions stay clear. -->
                <div class="sticky bottom-0 z-30 mt-lg border-t border-surface-container-highest bg-surface-container-lowest px-3 py-3 shadow-sm lg:hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-on-surface-variant">Tổng thực thu</p>
                            <p class="break-words font-code text-xl font-bold text-primary">{{ formatMoney(form.totalAmount || 0) }}</p>
                        </div>
                        <UiButton type="submit" variant="secondary" icon="drafts" name="submit_action" value="draft" class="min-h-11">Lưu nháp</UiButton>
                        <UiButton type="submit" icon="save" name="submit_action" value="submit" data-testid="receipt-submit-mobile" :disabled="!form.isValidReceipt" class="min-h-11 w-full !whitespace-normal [&>.material-symbols-outlined]:shrink-0 sm:w-auto">
                            <span class="min-w-0">{{ submitLabel }} (<span class="font-code">{{ formatMoney(form.totalAmount || 0) }}</span>)</span>
                        </UiButton>
                    </div>
                </div>
            </UiForm>
        </div>
    </template>
</template>
