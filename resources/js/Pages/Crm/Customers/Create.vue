<script setup>
/**
 * Thêm khách mới (mockup them-khach-moi): mở từ Kanban / danh sách → modal 2xl; mở thẳng URL → trang đầy đủ.
 * Trường phụ (email, ngày sinh, khóa quan tâm, hạn liên hệ, người phụ trách…) gom vào "Thông tin bổ sung".
 * Lưu xong trong modal: trang nền tải lại (RendersModals::modalSaved); trang đầy đủ: sang hồ sơ khách vừa tạo.
 */
import { Head, Link } from '@inertiajs/vue3';
import CustomerCreateFields from './CustomerCreateFields.vue';

defineOptions({ layout: { title: 'Thêm khách mới' } });

defineProps({
    asModal: { type: Boolean, default: false },
    branches: { type: Array, default: () => [] },
    salesUsers: { type: Array, default: () => [] },
    leadSources: { type: Array, default: () => [] },
    defaultBranchId: { type: Number, default: null },
    defaultAssigneeId: { type: Number, default: null },
});
</script>

<template>
    <UiModalFrame
        v-if="asModal"
        title="Thêm khách mới"
        description="Khách mới vào giai đoạn Mới; SĐT / email không được trùng khách đang hoạt động."
        :action="route('crm.customers.store')"
        method="post"
        :cancel="false"
        submit-icon="save"
    >
        <CustomerCreateFields v-bind="{ branches, salesUsers, leadSources, defaultBranchId, defaultAssigneeId }" />
    </UiModalFrame>

    <div v-else class="mx-auto w-full max-w-[560px] py-md">
        <Head title="Thêm khách mới" />
        <div class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-level-3">
            <div class="flex items-center justify-between px-xl pb-md pt-xl">
                <h1 class="font-h2 text-h2 text-primary">Thêm khách mới</h1>
                <Link :href="route('crm.customers.index')" aria-label="Đóng" class="rounded-full p-xs text-on-surface-variant transition-colors hover:bg-surface-variant active:scale-95">
                    <span class="material-symbols-outlined block">close</span>
                </Link>
            </div>
            <UiForm id="add-lead-form" :action="route('crm.customers.store')" method="post" class="space-y-md px-xl pb-xl">
                <CustomerCreateFields v-bind="{ branches, salesUsers, leadSources, defaultBranchId, defaultAssigneeId }" />
                <div class="flex items-center justify-end gap-md pt-lg">
                    <UiButton variant="secondary" :href="route('crm.customers.index')">Hủy</UiButton>
                    <UiButton type="submit">Lưu thông tin</UiButton>
                </div>
            </UiForm>
        </div>
    </div>
</template>
