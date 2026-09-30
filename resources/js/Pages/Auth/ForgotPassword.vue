<script setup>
/** Quên mật khẩu: gửi link đặt lại mật khẩu qua email (layout GuestLayout). */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

defineProps({ status: { type: String, default: null } });

// Email giữ bằng v-model: trường có lỗi vẽ lại sẽ không bị xoá chữ đã nhập (như old('email') của Blade).
const email = ref('');
</script>

<template>
    <Head title="Quên mật khẩu" />
    <div class="mb-4 text-sm text-on-surface-variant">Nhập email đăng nhập của bạn. Hệ thống sẽ gửi một đường link để bạn đặt mật khẩu mới.</div>

    <UiAlert v-if="status" type="success" class="mb-4">{{ status }}</UiAlert>

    <UiForm :action="route('password.email')" method="post">
        <div>
            <UiInput id="email" v-model="email" type="email" name="email" label="Email" required autofocus />
        </div>

        <div class="mt-4 flex items-center justify-end">
            <UiButton type="submit">Gửi link đặt lại mật khẩu</UiButton>
        </div>
    </UiForm>
</template>
