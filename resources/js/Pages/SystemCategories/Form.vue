<script setup>
/** Thêm / Sửa danh mục: mở từ danh sách → modal; mở thẳng URL create → trang thêm riêng (sửa: modal mở sẵn ở trang danh sách, ?edit=). */
import { computed } from 'vue';
import CategoryFields from './CategoryFields.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Thêm danh mục mới' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    category: { type: Object, required: true },
    typeLabels: { type: Array, default: () => [] },
    nextOrder: { type: Number, default: null },
});
const exists = computed(() => !!props.category.id);
const action = computed(() => (exists.value ? route('system-categories.update', props.category.id) : route('system-categories.store')));
const method = computed(() => (exists.value ? 'put' : 'post'));
</script>

<template>
    <UiModalFrame v-if="asModal" :title="exists ? 'Sửa giá trị danh mục' : 'Thêm danh mục mới'" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH." :action="action" :method="method" submit-icon="save" :form-options="{ id: 'modal-category-form' }">
        <CategoryFields :category="category" :type-labels="typeLabels" :next-order="nextOrder" show-type-label id-prefix="modal-category-" />
    </UiModalFrame>

    <template v-else>
        <UiPageHeader title="Thêm danh mục mới" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH." />

        <div class="max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            <UiForm id="category-form" :action="action" :method="method" class="space-y-md">
                <CategoryFields :category="category" :type-labels="typeLabels" :next-order="nextOrder" />
                <div class="flex gap-sm">
                    <UiButton type="submit" icon="save" class="flex-1">Lưu thông tin</UiButton>
                    <UiButton variant="secondary" :href="route('system-categories.index', { type: category.type })">Hủy bỏ</UiButton>
                </div>
            </UiForm>
        </div>
    </template>
</template>
