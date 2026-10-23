<script setup>
/**
 * Đăng nhập (layout GuestLayout). Đăng nhập xong server trả về tải lại hẳn trang đích (phiên mới).
 * Bản thử nghiệm (prop chung `roleSwitcher`): khung "Xem thử theo vai trò" — nhập mã truy cập, rồi chọn vai trò không cần mật khẩu.
 */
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import RoleSwitcherAccounts from '@/Layouts/Shell/RoleSwitcherAccounts.vue';

defineProps({
    status: { type: String, default: null },
    canResetPassword: { type: Boolean, default: true },
});

// Mã / email giữ bằng v-model: trường có lỗi vẽ lại sẽ không bị xoá chữ đã nhập (như old('email') của Blade).
const email = ref('');

const page = usePage();
const roleSwitcher = computed(() => page.props.roleSwitcher);
</script>

<template>
    <Head title="Đăng nhập" />
    <div class="mb-lg">
        <h1 class="font-h2 text-h2 text-on-surface">Đăng nhập</h1>
        <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Nhân viên dùng mã nhân viên, học viên dùng mã học viên do trung tâm cấp. Vẫn đăng nhập được bằng email.</p>
    </div>

    <UiAlert v-if="status" type="success" class="mb-4">{{ status }}</UiAlert>

    <section v-if="roleSwitcher" class="mb-lg overflow-hidden rounded-lg border border-warning/30 bg-warning-container/40" data-role-switcher-login>
        <div class="px-md pt-sm">
            <h2 class="font-body-semibold text-body-medium text-on-warning-container">Xem thử theo vai trò</h2>
            <p class="font-caption text-caption text-on-warning-container">Bản thử nghiệm: chọn vai trò để vào thẳng màn hình của vai trò đó, không cần mật khẩu.</p>
        </div>
        <RoleSwitcherAccounts v-if="roleSwitcher.unlocked" :groups="roleSwitcher.groups" class="mt-xs bg-surface-container-lowest" />
        <UiForm v-else :action="route('role-switcher.unlock')" method="post" class="flex items-end gap-sm px-md pb-md pt-sm">
            <div class="min-w-0 flex-1">
                <UiInput name="code" type="password" label="Mã truy cập" required autocomplete="off" />
            </div>
            <UiButton type="submit" variant="secondary">Mở</UiButton>
        </UiForm>
    </section>

    <UiForm :action="route('login')" method="post" :reset-on-success="['password']">
        <div>
            <UiInput id="email" v-model="email" type="text" name="email" label="Mã nhân viên hoặc email" placeholder="VD: MES012" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" />
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
