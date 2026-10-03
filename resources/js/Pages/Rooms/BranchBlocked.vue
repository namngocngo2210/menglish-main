<script setup>
/** Tài khoản ở phạm vi "chi nhánh" nhưng chưa được gán chi nhánh: không hiện bộ lọc hay bảng nào. */
</script>

<template>
    <div class="mx-auto max-w-xl rounded-xl border border-outline-variant bg-surface-container-lowest px-lg py-xl text-center shadow-sm" data-testid="room-branch-blocked">
        <span class="mx-auto mb-md flex h-14 w-14 items-center justify-center rounded-full bg-error-container text-error">
            <span class="material-symbols-outlined text-[28px]" aria-hidden="true">lock_person</span>
        </span>
        <h2 class="font-h3 text-h3 text-on-surface">Tài khoản chưa được gán chi nhánh, vui lòng liên hệ Admin</h2>
        <p class="mt-xs font-body-medium text-body-medium text-on-surface-variant">Bạn cần được chỉ định chi nhánh làm việc chính thức để xem và quản lý danh sách phòng học thuộc cơ sở đó.</p>
        <UiButton v-if="can('support_ticket.create')" variant="secondary" icon="contact_support" class="mt-md" :href="route('tickets.create')">Gửi yêu cầu phân quyền</UiButton>
    </div>
</template>
