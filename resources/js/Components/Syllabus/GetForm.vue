<script setup>
/**
 * Form lọc GET nhỏ trong đầu bảng (thay <form method="GET"> + onchange="this.form.submit()" của Blade):
 * đổi <select> → lọc ngay; ô tìm → Enter. Gửi bằng Inertia (không tải lại trang), bỏ tham số rỗng.
 *   <GetForm class="flex items-center gap-2"><UiSelect name="status" :options="…" /></GetForm>
 * action: mặc định đường dẫn trang hiện tại (như form GET không có action — bỏ các tham số khác trên URL).
 */
import { router } from '@inertiajs/vue3';
import { compactQuery, currentUrl } from '@/lib/url';

const props = defineProps({ action: { type: String, default: null } });

function submit(form) {
    const data = {};
    new FormData(form).forEach((value, key) => {
        if (key.endsWith('[]')) (data[key.slice(0, -2)] ??= []).push(value);
        else data[key] = value;
    });
    router.get(props.action ?? currentUrl().pathname, compactQuery(data), { preserveScroll: true });
}

function onChange(event) {
    if (event.target.tagName === 'SELECT') submit(event.currentTarget);
}
</script>

<template>
    <form :action="action ?? undefined" method="GET" @submit.prevent="submit($event.target)" @change="onChange"><slot /></form>
</template>
