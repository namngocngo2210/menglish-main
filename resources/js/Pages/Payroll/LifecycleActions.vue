<script setup>
/** Nút chuyển trạng thái do máy chủ quyết định quyền và điều kiện thực hiện. */
defineProps({ period: { type: Object, required: true } });

const confirmations = {
    approve: 'Chốt bảng lương sẽ đóng băng các con số và ngừng chỉnh sửa phiếu lương. Nhân sự chưa xem được phiếu; bạn vẫn có thể Mở lại để tính lại.',
    reopen: 'Mở lại kỳ lương sẽ đưa các phiếu về trạng thái chờ tính. Hãy bấm Đồng bộ & Tính lại, kiểm tra và chốt lại trước khi Gửi GV xem.',
    publish: 'Sau khi gửi, nhân sự xem được phiếu lương và dữ liệu chấm công / phạt / đơn giá của kỳ bị khóa; không thể tính lại. Các khoản phạt và hoa hồng được tất toán, nhân sự nhận thông báo.',
    'mark-paid': 'Xác nhận đã trả lương cho toàn bộ nhân sự trong kỳ? Kỳ lương và các phiếu sẽ được đánh dấu Đã trả.',
};
const aliases = { closed: 'approve', draft: 'reopen', published: 'publish', paid: 'mark-paid', mark_paid: 'mark-paid' };
const actionKey = (key) => aliases[key] ?? key;
const icons = { approve: 'task_alt', reopen: 'lock_open', publish: 'send', 'mark-paid': 'payments' };
</script>

<template>
    <div v-for="action in period.actions ?? []" :key="action.key" class="flex max-w-full flex-col gap-xs">
        <UiForm :action="action.route" method="post" :confirm="confirmations[actionKey(action.key)]" :confirm-title="action.label" :confirm-label="action.label">
            <UiButton type="submit" :variant="actionKey(action.key) === 'reopen' || actionKey(action.key) === 'mark-paid' ? 'secondary' : 'primary'" :icon="icons[actionKey(action.key)]" :disabled="!action.enabled" :aria-describedby="!action.enabled && action.reason ? `lifecycle-reason-${period.id}-${action.key}` : undefined">
                {{ action.label }}
            </UiButton>
        </UiForm>
        <p v-if="!action.enabled && action.reason" :id="`lifecycle-reason-${period.id}-${action.key}`" class="max-w-xs font-caption text-caption text-on-surface-variant">{{ action.reason }}</p>
    </div>
</template>
