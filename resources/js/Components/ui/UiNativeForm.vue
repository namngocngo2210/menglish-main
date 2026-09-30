<script setup>
/**
 * Form HTML thường (trình duyệt gửi, trang tải lại / tải file) — chỉ dùng khi phản hồi KHÔNG phải trang Inertia:
 * xuất Excel / tải file bằng POST, chuyển sang trang ngoài app. Còn lại dùng <UiForm>.
 *   <UiNativeForm :action="route('payroll.export')" method="post"><UiButton type="submit" icon="download">Xuất Excel</UiButton></UiNativeForm>
 * Tự thêm _token (CSRF) và _method (put/patch/delete).
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
});
const page = usePage();
const verb = computed(() => props.method.toLowerCase());
</script>

<template>
    <form :action="action" :method="verb === 'get' ? 'get' : 'post'">
        <input v-if="verb !== 'get'" type="hidden" name="_token" :value="page.props.csrf" />
        <input v-if="!['get', 'post'].includes(verb)" type="hidden" name="_method" :value="verb" />
        <slot />
    </form>
</template>
