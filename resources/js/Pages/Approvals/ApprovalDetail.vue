<script setup>
/**
 * Thân chi tiết mục chờ duyệt + form Duyệt / Từ chối (dùng chung modal ↔ trang, Show.vue). Nút gửi nằm ở chân khung
 * (form="approval-approve-form" / "approval-reject-form"); `rejecting` = đang nhập lý do từ chối.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    item: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
    canReject: { type: Boolean, default: false },
    rejecting: { type: Boolean, default: false },
});
</script>

<template>
    <div class="space-y-md">
        <UiBadge v-if="item.flag" color="error">{{ item.flag }}</UiBadge>
        <dl class="grid grid-cols-1 gap-x-lg gap-y-sm sm:grid-cols-3">
            <template v-if="item.amount !== null">
                <dt class="font-body-small text-body-small text-on-surface-variant">Số tiền</dt>
                <dd class="sm:col-span-2"><UiMoney :value="item.amount" align="left" /></dd>
            </template>
            <template v-for="row in item.meta" :key="row.label">
                <dt class="font-body-small text-body-small text-on-surface-variant">{{ row.label }}</dt>
                <dd class="whitespace-pre-line break-words text-on-surface sm:col-span-2">{{ row.value }}</dd>
            </template>
            <template v-if="item.created_at">
                <dt class="font-body-small text-body-small text-on-surface-variant">Gửi lúc</dt>
                <dd class="text-on-surface sm:col-span-2">{{ formatDate(item.created_at, 'H:i d/m/Y') }} ({{ item.created_ago }})</dd>
            </template>
        </dl>

        <Link v-if="item.modalUrl" :href="item.modalUrl" class="inline-flex items-center gap-xs font-body-medium text-body-small text-primary hover:underline">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">visibility</span> Xem chi tiết đầy đủ
        </Link>

        <UiAlert v-if="!(canApprove || canReject)" type="info">Mục này cần nhập thêm thông tin khi duyệt — vui lòng xử lý ở màn gốc.</UiAlert>

        <UiForm v-if="canApprove" id="approval-approve-form" :action="route('approvals.bulk')" method="post">
            <input type="hidden" name="action" value="approve" />
            <input type="hidden" name="single" value="1" />
            <input type="hidden" name="items[]" :value="item.ref" />
        </UiForm>
        <UiForm v-if="canReject" v-show="rejecting" id="approval-reject-form" :action="route('approvals.bulk')" method="post">
            <input type="hidden" name="action" value="reject" />
            <input type="hidden" name="single" value="1" />
            <input type="hidden" name="items[]" :value="item.ref" />
            <UiTextarea id="approval-reject-reason" name="reason" label="Lý do từ chối" required :rows="3" maxlength="1000" />
        </UiForm>
    </div>
</template>
