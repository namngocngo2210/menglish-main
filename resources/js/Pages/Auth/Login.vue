<script setup>
/** Đăng nhập (layout GuestLayout). Đăng nhập xong server trả về tải lại hẳn trang đích (phiên mới). */
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    status: { type: String, default: null },
    canResetPassword: { type: Boolean, default: true },
});

// Email giữ bằng v-model: trường có lỗi vẽ lại sẽ không bị xoá chữ đã nhập (như old('email') của Blade).
const email = ref('');
</script>

<template>
    <Head title="Đăng nhập" />
    <div class="mb-lg">
        <h1 class="font-h2 text-h2 text-on-surface">Đăng nhập</h1>
        <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Dùng email hoặc mã học viên do trung tâm cấp.</p>
    </div>

    <UiAlert v-if="status" type="success" class="mb-4">{{ status }}</UiAlert>

    <UiForm :action="route('login')" method="post" :reset-on-success="['password']">
        <div>
            <UiInput id="email" v-model="email" type="text" name="email" label="Email hoặc mã học viên" required autofocus autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" />
        </div>

        <div class="mt-4">
            <UiInput id="password" type="password" name="password" label="Mật khẩu" required autocomplete="current-password" />
        </div>

        <div class="mt-4 block">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-outline-variant text-primary-container shadow-sm focus:ring-primary-container/40" name="remember" />
                <span class="ms-2 font-body-small text-body-small text-on-surface-variant">Ghi nhớ đăng nhập</span>
            </label>
        </div>

        <div class="mt-lg flex flex-wrap items-center justify-end gap-md">
            <Link v-if="canResetPassword" class="rounded-md font-body-small text-body-small text-on-surface-variant underline hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40" :href="route('password.request')">Quên mật khẩu?</Link>
            <UiButton type="submit">Đăng nhập</UiButton>
        </div>
    </UiForm>
</template>
