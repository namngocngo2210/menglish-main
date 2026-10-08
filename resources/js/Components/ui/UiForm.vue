<script setup>
/**
 * Form gửi bằng Inertia (thay <form method="POST"> + @csrf/@method + hx-boost). Trường con dùng `name` như form HTML:
 * lỗi validate hiện ngay dưới trường, nút submit tự khoá khi đang gửi, không mất dữ liệu đã nhập khi có lỗi.
 *   <UiForm :action="route('holidays.store')" method="post">
 *       <UiInput name="name" label="Tên ngày nghỉ" required />
 *       <UiButton type="submit">Lưu</UiButton>
 *   </UiForm>
 *   <UiForm :action="route('courses.destroy', course.id)" method="delete" :confirm="`Xóa khóa học ${course.name}?`" confirm-label="Xóa" danger>
 *       <UiButton type="submit" variant="danger-text" icon="delete">Xóa</UiButton>
 *   </UiForm>
 * Trong modal (trang mở bằng <UiButton modal>): gửi kèm header X-Remote-Modal; lưu xong đóng modal và trang nền
 * nhận dữ liệu mới (server trả về trang hiện tại kèm thông báo — RendersModals::modalSaved). `stay` → giữ modal mở, tải lại nội dung.
 * `back`: form ngay trên trang (vd. nút Xóa trên từng dòng) cũng muốn ở lại trang hiện tại (giữ bộ lọc, trang số) như form trong modal.
 * File: dùng method="post" + <input type="hidden" name="_method" value="put"> (PHP không đọc multipart của PUT).
 * Slot nhận { processing, errors, isDirty, submit, reset } của Inertia <Form>.
 */
import { computed, ref } from 'vue';
import { Form } from '@inertiajs/vue3';
import { confirmDialog, DEFAULT_CONFIRM_TITLE } from '@/lib/confirm';
import { closeRemoteModal, reloadRemoteModal } from '@/lib/remoteModal';
import { useRemoteModal } from './modalContext';

const props = defineProps({
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
    confirm: { type: String, default: null },
    confirmTitle: { type: String, default: DEFAULT_CONFIRM_TITLE },
    confirmLabel: { type: String, default: 'Đồng ý' },
    danger: { type: Boolean, default: false },
    stay: { type: Boolean, default: false },
    back: { type: Boolean, default: false },
    preserveScroll: { type: Boolean, default: true },
    preserveState: { type: [Boolean, String], default: null },
    only: { type: Array, default: null },
    errorBag: { type: String, default: null },
    resetOnSuccess: { type: [Boolean, Array], default: false },
    headers: { type: Object, default: () => ({}) },
    transform: { type: Function, default: (data) => data },
});
const emit = defineEmits(['success', 'error', 'finish']);
const modal = useRemoteModal();
const formRef = ref(null);
let confirmed = false;

const headers = computed(() => (modal || props.back ? { ...props.headers, 'X-Remote-Modal': 'true' } : props.headers));
const options = computed(() => {
    const opts = { preserveScroll: props.preserveScroll };
    if (props.preserveState !== null) opts.preserveState = props.preserveState;
    if (props.only) opts.only = props.only;
    return opts;
});

function onBefore(visit) {
    if (!props.confirm || confirmed) {
        confirmed = false;
        return true;
    }
    confirmDialog({ title: props.confirmTitle, message: props.confirm, confirmLabel: props.confirmLabel, danger: props.danger }).then((ok) => {
        if (!ok) return;
        confirmed = true;
        formRef.value?.submit();
    });
    return false;
}

function onSuccess(page) {
    if (modal) {
        if (props.stay) {
            // Đã lưu, modal giữ mở → bỏ đánh dấu "đã sửa" (tải lại êm không đổi key nên host không tự bỏ).
            modal.markClean?.();
            reloadRemoteModal();
        } else closeRemoteModal();
    }
    emit('success', page);
}

defineExpose({ submit: () => formRef.value?.submit(), reset: (...fields) => formRef.value?.reset(...fields) });
</script>

<template>
    <Form
        ref="formRef"
        :action="action"
        :method="method"
        :headers="headers"
        :error-bag="errorBag"
        :options="options"
        :reset-on-success="resetOnSuccess"
        :transform="transform"
        :on-before="onBefore"
        :on-success="onSuccess"
        :on-error="(errors) => emit('error', errors)"
        :on-finish="() => emit('finish')"
        #default="form"
    >
        <slot v-bind="form" />
    </Form>
</template>
