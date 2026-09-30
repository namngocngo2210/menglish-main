<script setup>
/** Layout trang chưa đăng nhập (đăng nhập, quên mật khẩu…) — thay layouts/guest.blade.php. Trang dùng: defineOptions({ layout: GuestLayout }). */
import { Link } from '@inertiajs/vue3';
import ToastHost from './Shell/ToastHost.vue';

defineProps({ flash: { type: Array, default: () => [] } });
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-background pt-6 sm:justify-center sm:pt-0">
        <div>
            <Link href="/">
                <img src="/images/menglish-logo.png" alt="MEnglish" class="h-20 w-20 rounded-xl bg-white object-contain shadow-sm" width="80" height="80" />
            </Link>
        </div>
        <div class="mt-6 w-full overflow-hidden border border-surface-container-highest bg-surface-container-lowest px-6 py-4 shadow-level-2 sm:max-w-md sm:rounded-xl">
            <slot />
        </div>
        <div class="py-6 text-center font-caption text-caption text-on-surface-variant">
            <span>Phát triển bởi</span>
            <a href="https://vmst.vn" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary hover:underline">VMST Media</a>
        </div>
    </div>
    <ToastHost :flash="flash" />
</template>
