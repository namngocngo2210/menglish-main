<script setup>
/**
 * Hộp thoại hiện mật khẩu tạm sau khi quản trị đặt lại mật khẩu (shared prop `tempPassword`, flash 1 lần).
 * Mật khẩu bôi đen được + nút Sao chép; không lưu ở đâu ngoài state của hộp thoại.
 */
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { copyText } from '@/lib/clipboard';

const page = usePage();
const data = ref(null);
const copied = ref(false);

watch(
    () => page.props.tempPassword,
    (value) => {
        if (value?.password) {
            data.value = { user: value.user, password: value.password };
            copied.value = false;
        }
    },
    { immediate: true },
);

async function copy() {
    copied.value = await copyText(data.value.password, null);
}
function close() {
    data.value = null;
}
</script>

<template>
    <div v-if="data" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/40 p-md" role="dialog" aria-modal="true" aria-labelledby="temp-password-title" @keydown.esc="close">
        <div class="w-full max-w-md rounded-xl bg-surface p-lg shadow-level-3">
            <h2 id="temp-password-title" class="font-h3 text-h3">Mật khẩu tạm thời</h2>
            <p class="mt-xs font-body-medium text-body-medium">Tài khoản: <strong>{{ data.user }}</strong>. Chỉ hiển thị một lần — hãy sao chép và gửi cho người dùng. Họ phải đổi mật khẩu ở lần đăng nhập đầu tiên.</p>
            <div class="mt-md flex items-center gap-sm">
                <input :value="data.password" readonly type="text" class="select-all w-full rounded border border-outline-variant bg-surface-container-low px-sm py-xs font-code" aria-label="Mật khẩu tạm thời" @focus="$event.target.select()" />
                <button type="button" class="shrink-0 rounded bg-primary px-md py-xs font-semibold text-on-primary" @click="copy">{{ copied ? 'Đã chép' : 'Sao chép' }}</button>
            </div>
            <div class="mt-md text-right">
                <button type="button" class="rounded px-md py-xs font-semibold text-primary hover:underline" @click="close">Đóng</button>
            </div>
        </div>
    </div>
</template>
