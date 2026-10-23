<script setup>
/**
 * Danh sách đổi vai trò (bản thử nghiệm, App\Support\RoleSwitcher): mỗi vai trò một dòng — bấm tên vai trò là vào bằng
 * tài khoản đầu tiên, ô chọn bên cạnh để vào đúng một người. Server trả về tải lại hẳn trang đầu của tài khoản mới.
 * Dùng ở trang đăng nhập và menu "Đổi vai trò" trên topbar.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

defineProps({
    groups: { type: Array, required: true },
});

const switching = ref(false);

function switchTo(id) {
    if (!id || switching.value) return;
    switching.value = true;
    router.post(route('role-switcher.switch', id), {}, { onFinish: () => (switching.value = false) });
}

function accountLabel(account) {
    return [account.name, account.branch].filter(Boolean).join(' · ') + (account.current ? ' (đang xem)' : '');
}
</script>

<template>
    <ul class="divide-y divide-surface-container" data-role-switcher-accounts>
        <li v-for="group in groups" :key="group.role" class="flex items-center gap-sm px-md py-sm">
            <button
                type="button"
                class="min-w-0 flex-1 rounded-md text-left hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 disabled:opacity-60"
                :disabled="switching"
                @click="switchTo(group.accounts[0].id)"
            >
                <span class="block font-body-semibold text-body-small text-on-surface">{{ group.label }}</span>
                <span class="block truncate font-caption text-caption text-on-surface-variant">{{ group.accounts[0].name }}</span>
            </button>
            <select
                v-if="group.accounts.length > 1"
                class="w-36 shrink-0 rounded-md border-outline-variant py-1 pl-2 pr-7 font-caption text-caption text-on-surface focus:ring-primary-container/40"
                :aria-label="`Chọn người có vai trò ${group.label}`"
                :disabled="switching"
                @click.stop
                @change="switchTo($event.target.value)"
            >
                <option value="">{{ group.accounts.length }} người…</option>
                <option v-for="account in group.accounts" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
            </select>
        </li>
    </ul>
</template>
