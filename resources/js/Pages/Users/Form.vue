<script setup>
/**
 * Thêm / Sửa nhân sự: mở từ danh sách / trang chi tiết → modal 3xl, 3 tab Tài khoản / Hồ sơ / Hợp đồng & Lương trong 1 form;
 * mở thẳng URL → trang đầy đủ (cùng form). Có file hợp đồng → gửi multipart (POST + _method=put khi sửa).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UserFields from './UserFields.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Người dùng' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    user: { type: Object, default: null },
    currentRole: { type: String, default: null },
    currentConcurrent: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
    concurrentOptions: { type: Array, default: () => [] },
    initialTab: { type: String, default: 'account' },
});
const title = computed(() => (props.user ? 'Sửa thông tin người dùng' : 'Thêm người dùng mới'));
const action = computed(() => (props.user ? route('users.update', props.user.id) : route('users.store')));
const submitLabel = computed(() => (props.user ? 'Cập nhật tài khoản' : 'Lưu tài khoản'));
const fields = computed(() => ({
    user: props.user,
    currentRole: props.currentRole,
    currentConcurrent: props.currentConcurrent,
    branches: props.branches,
    roleOptions: props.roleOptions,
    concurrentOptions: props.concurrentOptions,
    initialTab: props.initialTab,
}));
</script>

<template>
    <UiModalFrame v-if="asModal" :title="title" :description="user ? `${user.name} · ${user.email}` : 'Tài khoản mới phải đổi mật khẩu ở lần đăng nhập đầu tiên.'" :action="action" method="post" :submit-label="submitLabel" submit-icon="save" :form-options="{ id: 'modal-user-form' }">
        <input v-if="user" type="hidden" name="_method" value="put" />
        <UserFields v-bind="fields" id-prefix="modal-user-" />
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="title">
            <template #breadcrumbs>
                <Link :href="route('users.index')" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">group</span>Người dùng</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ user ? 'Sửa thông tin' : 'Thêm mới' }}</span>
            </template>
        </UiPageHeader>

        <div class="max-w-3xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            <UiForm id="user-form" :action="action" method="post" class="space-y-md">
                <input v-if="user" type="hidden" name="_method" value="put" />
                <UserFields v-bind="fields" />
                <div class="flex items-center justify-end gap-sm border-t border-surface-container pt-md">
                    <UiButton variant="secondary" :href="route('users.index')">Hủy</UiButton>
                    <UiButton type="submit" icon="save">{{ submitLabel }}</UiButton>
                </div>
            </UiForm>
        </div>
    </template>
</template>
