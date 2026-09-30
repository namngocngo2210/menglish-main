<script setup>
/** Nhập lại mật khẩu trước khi vào khu vực cần bảo mật (layout GuestLayout). */
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Xác nhận mật khẩu" />
    <div class="mb-4 text-sm text-on-surface-variant">Đây là khu vực cần bảo mật. Vui lòng nhập lại mật khẩu để tiếp tục.</div>

    <UiForm :action="route('password.confirm')" method="post">
        <div>
            <UiInput id="password" type="password" name="password" label="Mật khẩu" required autocomplete="current-password" />
        </div>

        <div class="mt-4 flex justify-end">
            <UiButton type="submit">Xác nhận</UiButton>
        </div>
    </UiForm>
</template>
