<script setup>
/** Sửa permission: mở từ danh sách → modal; mở thẳng URL → trang form đầy đủ. */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Sửa permission' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    permission: { type: Object, default: null },
});
const title = computed(() => (props.permission ? 'Sửa permission' : 'Thêm permission'));
const action = computed(() => (props.permission ? route('permissions.update', props.permission.id) : route('permissions.store')));
const method = computed(() => (props.permission ? 'put' : 'post'));
</script>

<template>
    <UiModalFrame v-if="asModal" :title="title" description="Đổi mã permission ảnh hưởng mọi vai trò đang dùng quyền này." :action="action" :method="method" submit-label="Lưu permission" submit-icon="save">
        <UiInput name="name" id="modal-permission-name" label="Tên permission (module.action)" required :value="permission?.name" placeholder="vd: report.export" class="font-code" hint="Chữ thường, gạch dưới, dạng module.action." />
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="title">
            <template #breadcrumbs>
                <Link :href="route('permissions.index')" class="hover:text-primary">Danh mục quyền</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ title }}</span>
            </template>
        </UiPageHeader>

        <div class="max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            <UiForm id="permission-form" :action="action" :method="method" class="space-y-md">
                <UiInput name="name" label="Tên permission (module.action)" required :value="permission?.name" placeholder="vd: report.export" class="font-code" hint="Chữ thường, gạch dưới, dạng module.action." />
                <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                    <UiButton variant="secondary" :href="route('permissions.index')">Hủy</UiButton>
                    <UiButton type="submit" icon="save">Lưu permission</UiButton>
                </div>
            </UiForm>
        </div>
    </template>
</template>
