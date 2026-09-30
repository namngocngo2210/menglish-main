<script setup>
/**
 * Nhập khách hàng loạt từ Excel: bước 1 tải file → bước 2 xem trước + lỗi từng dòng → nhập các dòng hợp lệ.
 * Mở từ nút "Nhập Excel" (CRM) → modal: 2 bước đổi nội dung ngay trong modal (form `stay` tải lại modal, bước 2 nới rộng 4xl);
 * mở thẳng URL → trang đầy đủ (form + bảng xem trước).
 */
import { computed, ref, watch } from 'vue';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import ImportPreview from '@/Components/Crm/ImportPreview.vue';
import ImportUploadForm from '@/Components/Crm/ImportUploadForm.vue';
import { remoteModal } from '@/lib/remoteModal';
import { useRemoteModal } from '@/Components/ui/modalContext';

defineOptions({ layout: { title: 'Nhập khách hàng loạt từ Excel', workspaceTabs: false } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    branches: { type: Array, default: () => [] },
    salesUsers: { type: Array, default: () => [] },
    canAssign: { type: Boolean, default: false },
    userName: { type: String, default: '' },
    defaultBranchId: { type: Number, default: null },
    defaultAssigneeId: { type: Number, default: null },
    preview: { type: Object, default: null },
});

const modal = useRemoteModal();
// Bước 2 (xem trước) cần modal rộng hơn; quay lại bước 1 thì trả về cỡ mặc định của nút mở.
watch(
    () => !!props.preview,
    (hasPreview) => {
        if (modal) remoteModal.size = hasPreview ? '4xl' : 'lg';
    },
    { immediate: true },
);

const confirmErrors = ref([]);
const confirmLabel = computed(() =>
    props.preview?.error_count > 0 ? `Bỏ qua ${props.preview.error_count} dòng lỗi, nhập ${props.preview.valid_count} khách` : `Nhập ${props.preview?.valid_count ?? 0} khách hợp lệ`,
);
const uploadProps = computed(() => ({
    branches: props.branches,
    salesUsers: props.salesUsers,
    canAssign: props.canAssign,
    userName: props.userName,
    defaultBranchId: props.defaultBranchId,
    defaultAssigneeId: props.defaultAssigneeId,
}));
</script>

<template>
    <UiModalFrame
        v-if="asModal"
        :title="preview ? 'Xem trước dữ liệu nhập' : 'Nhập khách hàng loạt từ Excel'"
        :description="preview ? 'Bước 2/2 — kiểm tra lỗi từng dòng, chỉ các dòng hợp lệ được nhập.' : 'Bước 1/2 — tải file .xlsx / .csv (dòng 1 là tiêu đề).'"
        cancel="Đóng"
    >
        <template v-if="preview">
            <UiErrors :messages="confirmErrors" />
            <ImportPreview :preview="preview" />
            <!-- Form rỗng cho 2 nút ở chân modal -->
            <UiForm id="modal-crm-import-confirm" :action="route('crm.import.store')" method="post" class="hidden" @error="confirmErrors = Object.values($event)" />
            <UiForm id="modal-crm-import-cancel" :action="route('crm.import.store')" method="post" stay class="hidden">
                <input type="hidden" name="cancel" value="1" />
            </UiForm>
        </template>
        <ImportUploadForm v-else v-bind="uploadProps" as-modal />

        <template #footer>
            <template v-if="preview">
                <UiButton type="submit" form="modal-crm-import-cancel" variant="secondary" icon="undo">Hủy, chọn file khác</UiButton>
                <UiButton type="submit" form="modal-crm-import-confirm" icon="upload" :disabled="preview.valid_count === 0">{{ confirmLabel }}</UiButton>
            </template>
            <template v-else>
                <UiButton variant="secondary" icon="download" :href="route('crm.import.template')" native>Tải file mẫu</UiButton>
                <UiButton type="submit" form="modal-crm-import-form" icon="fact_check">Kiểm tra dữ liệu</UiButton>
            </template>
        </template>
    </UiModalFrame>

    <template v-else>
        <CrmHeader />
        <div class="space-y-4">
            <UiPageHeader
                title="Nhập khách hàng loạt từ Excel"
                description="Tải file .xlsx / .csv (dòng 1 là tiêu đề). Hệ thống kiểm tra từng dòng (họ tên, SĐT Việt Nam, trùng trong file / trùng CRM, email) trước khi nhập."
            >
                <template #actions>
                    <UiButton variant="secondary" icon="download" :href="route('crm.import.template')" native>Tải file mẫu</UiButton>
                </template>
            </UiPageHeader>

            <ImportUploadForm v-bind="uploadProps" />

            <ImportPreview v-if="preview" :preview="preview">
                <template #actions>
                    <UiForm :action="route('crm.import.store')" method="post" class="flex items-center gap-sm">
                        <UiButton type="submit" name="cancel" value="1" variant="ghost">Hủy</UiButton>
                        <UiButton type="submit" icon="upload" :disabled="preview.valid_count === 0">{{ confirmLabel }}</UiButton>
                    </UiForm>
                </template>
            </ImportPreview>
        </div>
    </template>
</template>
