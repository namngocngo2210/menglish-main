<script setup>
/**
 * Nhập khách từ Excel — bước 1 (tải file). Dùng chung cho trang đầy đủ và modal (asModal).
 * Trong modal: form id "modal-crm-import-form" (nút gửi ở chân modal), gửi xong tải lại modal (bước xem trước).
 */
defineProps({
    asModal: { type: Boolean, default: false },
    branches: { type: Array, default: () => [] },
    salesUsers: { type: Array, default: () => [] },
    canAssign: { type: Boolean, default: false },
    userName: { type: String, default: '' },
    defaultBranchId: { type: Number, default: null },
    defaultAssigneeId: { type: Number, default: null },
});
</script>

<template>
    <UiForm
        :id="asModal ? 'modal-crm-import-form' : 'crm-import-form'"
        :action="route('crm.import.preview')"
        method="post"
        :stay="asModal"
        :class="['grid grid-cols-1 gap-md', asModal ? '' : 'rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-2 lg:grid-cols-4']"
    >
        <UiField label="File khách hàng (.xlsx, .csv)" name="file" :for="asModal ? 'modal-crm-import-file' : 'f_file'" required>
            <input :id="asModal ? 'modal-crm-import-file' : 'f_file'" type="file" name="file" required accept=".xlsx,.xls,.csv" class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest p-xs text-body-small" />
        </UiField>
        <UiSelect
            :id="asModal ? 'modal-crm-import-branch' : undefined"
            name="branch_id"
            label="Chi nhánh nhận khách"
            required
            placeholder="-- Chọn chi nhánh --"
            :options="branches"
            :value="defaultBranchId ?? ''"
        />
        <UiSelect
            v-if="canAssign"
            :id="asModal ? 'modal-crm-import-assignee' : undefined"
            name="assigned_user_id"
            label="Người phụ trách mặc định"
            placeholder="-- Tôi phụ trách --"
            :options="salesUsers"
            :value="defaultAssigneeId ?? ''"
            hint="Dòng có cột Người phụ trách (tên hoặc email Học vụ cùng cơ sở / Admin) dùng người đó."
        />
        <UiField v-else label="Người phụ trách">
            <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm">{{ userName }}</div>
        </UiField>
        <UiInput :id="asModal ? 'modal-crm-import-source' : undefined" name="default_source" label="Nguồn mặc định" placeholder="VD: Sự kiện Offline" hint="Dùng khi dòng không có cột Nguồn." />
        <div :class="['flex items-center justify-between gap-md', asModal ? '' : 'md:col-span-2 lg:col-span-4']">
            <p class="text-caption text-on-surface-variant">Cột nhận diện: Họ tên*, Số điện thoại*, Tên phụ huynh, SĐT phụ huynh, Email, Ngày sinh, Giới tính, Địa chỉ, Nguồn, Khóa học quan tâm, Ghi chú, Người phụ trách. Tối đa 1.000 dòng.</p>
            <UiButton v-if="!asModal" type="submit" icon="fact_check">Kiểm tra dữ liệu</UiButton>
        </div>
    </UiForm>
</template>
