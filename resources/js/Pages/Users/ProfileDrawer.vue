<script setup>
/** Hồ sơ nhanh của người dùng (drawer bên phải) — mở từ nút "Xem hồ sơ nhanh" ở danh sách. Nút Sửa / Phân quyền mở modal và đóng drawer. */
import { computed } from 'vue';
import { openRemoteModal } from '@/lib/remoteModal';

const props = defineProps({
    open: { type: Boolean, default: false },
    user: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const has = (key) => !!props.user && key in props.user;
const contractTone = computed(() => ({
    'Đã hết hạn': 'bg-error-container text-error',
    'Sắp hết hạn': 'bg-warning-container text-on-warning-container',
    'Đang hiệu lực': 'bg-tertiary-fixed/50 text-tertiary',
    'Chưa cập nhật': 'bg-surface-container-high text-on-surface-variant',
})[props.user?.contract_status] ?? '');
const salary = computed(() => {
    if (!has('base_salary')) return 'Không có quyền xem';
    return props.user.base_salary ? Number(props.user.base_salary).toLocaleString('vi-VN') + 'đ' : 'Chưa cập nhật';
});

function openModal(url, size) {
    emit('close');
    openRemoteModal(url, { size });
}
</script>

<template>
    <div v-show="open" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Hồ sơ người dùng" @keydown.esc="emit('close')">
        <div class="absolute inset-0 bg-on-surface/40" @click="emit('close')"></div>
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="translate-x-full" leave-active-class="transition ease-in duration-150" leave-to-class="translate-x-full">
            <aside v-show="open" class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-surface-container-lowest shadow-xl">
                <header class="flex items-center justify-between border-b border-surface-container px-md py-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Hồ sơ người dùng</h2>
                    <UiButton variant="ghost" icon="close" aria-label="Đóng" @click="emit('close')" />
                </header>
                <div v-if="user" class="flex-1 space-y-md overflow-y-auto p-md font-body-small text-body-small">
                    <div class="flex items-center gap-md rounded-lg bg-primary-light p-md">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-h2 text-h2 text-white">{{ user.name.charAt(0) }}</span>
                        <div class="min-w-0">
                            <p class="font-h3 text-h3 text-on-surface">{{ user.name }}</p>
                            <p class="text-on-surface-variant">{{ (user.primary_role || 'Người dùng') + ' · ' + (user.branch ? user.branch.name : 'Chưa gán chi nhánh') }}</p>
                            <p class="font-code text-caption text-on-surface-variant">{{ user.email + ' · ' + user.employee_code }}</p>
                        </div>
                    </div>

                    <section class="space-y-sm rounded-lg border border-outline-variant p-md">
                        <h3 class="flex items-center gap-xs font-label text-label uppercase text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary-container">badge</span>Thông tin người dùng</h3>
                        <dl class="grid grid-cols-2 gap-sm">
                            <div><dt class="text-on-surface-variant">Số điện thoại</dt><dd class="font-code">{{ user.phone || 'Chưa cập nhật' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Số CCCD</dt><dd class="font-code">{{ has('id_card_number') ? (user.id_card_number || 'Chưa cập nhật') : 'Không có quyền xem' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Tốt nghiệp</dt><dd>{{ user.graduation_school || 'Chưa cập nhật' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Chứng chỉ</dt><dd>{{ user.certificates || 'Chưa cập nhật' }}</dd></div>
                            <div class="col-span-2"><dt class="text-on-surface-variant">Level giảng dạy</dt><dd>{{ user.teaching_level || 'Chưa cập nhật' }}</dd></div>
                        </dl>
                    </section>

                    <section class="space-y-sm rounded-lg border border-outline-variant p-md">
                        <div class="flex items-center justify-between">
                            <h3 class="flex items-center gap-xs font-label text-label uppercase text-on-surface"><span class="material-symbols-outlined text-[16px] text-secondary">contract</span>Hợp đồng lao động</h3>
                            <span :class="['rounded-full px-sm font-caption text-caption', contractTone]">{{ user.contract_status }}</span>
                        </div>
                        <dl class="grid grid-cols-2 gap-sm">
                            <div><dt class="text-on-surface-variant">Loại HĐ</dt><dd>{{ user.contract_type || 'Chưa cập nhật' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Lương cơ bản</dt><dd class="font-code">{{ salary }}</dd></div>
                            <div><dt class="text-on-surface-variant">Ngày bắt đầu</dt><dd>{{ user.contract_start_date || 'Chưa cập nhật' }}</dd></div>
                            <div><dt class="text-on-surface-variant">Ngày kết thúc</dt><dd>{{ user.contract_end_date || 'Chưa cập nhật' }}</dd></div>
                        </dl>
                        <a v-if="user.contract_url" :href="user.contract_url" class="inline-flex items-center gap-xs font-semibold text-secondary hover:underline"><span class="material-symbols-outlined text-[16px]">download</span>Tải file hợp đồng</a>
                        <p v-else class="italic text-on-surface-variant">Chưa có file hợp đồng.</p>
                    </section>

                    <section class="space-y-sm rounded-lg border border-outline-variant p-md">
                        <h3 class="flex items-center gap-xs font-label text-label uppercase text-on-surface"><span class="material-symbols-outlined text-[16px] text-tertiary">co_present</span>Kiêm nhiệm</h3>
                        <div class="flex flex-wrap gap-xs">
                            <span v-for="r in user.concurrent_roles" :key="r" class="rounded bg-surface-container-high px-sm font-caption text-caption">{{ r }}</span>
                            <span v-if="user.concurrent_roles.length === 0" class="italic text-on-surface-variant">Không kiêm nhiệm vai trò khác.</span>
                        </div>
                        <ul class="space-y-xs">
                            <li v-for="c in user.teaching" :key="c.code + c.role" class="flex items-center justify-between gap-sm rounded bg-surface-container-low px-sm py-xs">
                                <span class="truncate">{{ c.code + ' — ' + c.name }}</span>
                                <span class="shrink-0 font-caption text-caption text-secondary">{{ c.role }}</span>
                            </li>
                        </ul>
                        <p v-if="user.teaching.length === 0" class="italic text-on-surface-variant">Chưa phụ trách lớp nào.</p>
                    </section>
                </div>
                <footer class="flex flex-wrap gap-sm border-t border-surface-container bg-surface-container-low p-md">
                    <UiButton variant="secondary" class="flex-1" :href="user?.show_url ?? '#'" @click="emit('close')">Xem chi tiết</UiButton>
                    <div v-if="can('user.update')" class="flex flex-1">
                        <UiButton variant="secondary" icon="edit" class="flex-1" :disabled="!user" @click="openModal(route('users.edit', user.id), '3xl')">Sửa thông tin</UiButton>
                    </div>
                    <div v-if="can('permission.override')" class="flex flex-1">
                        <UiButton icon="admin_panel_settings" class="flex-1" :disabled="!user" @click="openModal(route('users.permissions.edit', user.id), '4xl')">Phân quyền</UiButton>
                    </div>
                </footer>
            </aside>
        </Transition>
    </div>
</template>
