<script setup>
/** Tạo ticket hỗ trợ: mở từ danh sách → modal 2xl; mở thẳng URL → trang riêng. Lưu xong server trả về (modal: trang nền + thông báo). */
import { ref } from 'vue';
import TicketFields from './TicketFields.vue';

defineOptions({ layout: { title: 'Tạo yêu cầu hỗ trợ' } });

defineProps({
    asModal: { type: Boolean, default: false },
    staffs: { type: Array, default: () => [] },
});

const fields = ref(null);
</script>

<template>
    <UiModalFrame
        v-if="asModal"
        title="Tạo yêu cầu hỗ trợ (Ticket)"
        description="Mô tả sự cố / yêu cầu; có thể đính kèm ảnh chụp màn hình (dán Ctrl + V)."
        :action="route('tickets.store')"
        method="post"
        submit-label="Tạo & Gửi Ticket"
        submit-icon="send"
        :form-options="{ id: 'modal-ticket-form' }"
    >
        <TicketFields :staffs="staffs" />
    </UiModalFrame>

    <template v-else>
        <UiPageHeader title="Tạo yêu cầu hỗ trợ" icon="add_task" :back="route('tickets.index')" />

        <div class="max-w-3xl">
            <UiForm id="ticket-form" :action="route('tickets.store')" method="post" class="space-y-6 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm" @success="fields?.reset()">
                <TicketFields ref="fields" :staffs="staffs" />
                <div class="flex items-center justify-end gap-3 border-t border-surface-container-highest pt-4">
                    <UiButton variant="secondary" :href="route('tickets.index')">Hủy</UiButton>
                    <UiButton type="submit" icon="send">Tạo &amp; Gửi Ticket</UiButton>
                </div>
            </UiForm>
        </div>
    </template>
</template>
