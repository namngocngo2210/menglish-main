<script setup>
/** Đặt lại mật khẩu từ link trong email (layout GuestLayout). */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: null },
});

// Email giữ bằng v-model: trường có lỗi vẽ lại sẽ không bị xoá chữ đã nhập (như old('email') của Blade).
const email = ref(props.email ?? '');
</script>

<template>
    <Head title="Đặt lại mật khẩu" />
    <UiForm :action="route('password.store')" method="post" :reset-on-success="['password', 'password_confirmation']">
        <input type="hidden" name="token" :value="token" />

        <div>
            <UiInput id="email" v-model="email" type="email" name="email" label="Email" required autofocus autocomplete="username" />
        </div>

        <div class="mt-4">
            <UiInput id="password" type="password" name="password" label="Mật khẩu" required autocomplete="new-password" />
        </div>

        <div class="mt-4">
            <UiInput id="password_confirmation" type="password" name="password_confirmation" label="Nhập lại mật khẩu" required autocomplete="new-password" />
        </div>

        <div class="mt-4 flex items-center justify-end">
            <UiButton type="submit">Đặt lại mật khẩu</UiButton>
        </div>
    </UiForm>
</template>
