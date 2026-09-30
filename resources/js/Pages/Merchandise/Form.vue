<script setup>
/** Thêm/Sửa hàng hóa: mở từ danh sách → modal; mở thẳng URL → trang form đầy đủ (breadcrumb + khung form). */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useRemoteModal } from '@/Components/ui/modalContext';
import MerchandiseFields from './MerchandiseFields.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Hàng hóa & Vật phẩm' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    item: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    isEdit: { type: Boolean, default: false },
});
const modal = useRemoteModal();
const title = computed(() => (props.isEdit ? `Cập nhật Hàng hóa: ${props.item.name}` : 'Thêm mới Hàng hóa & Vật phẩm'));
const action = computed(() => (props.isEdit ? route('merchandise.update', props.item.id) : route('merchandise.store')));
const method = computed(() => (props.isEdit ? 'put' : 'post'));
const submitLabel = computed(() => (props.isEdit ? 'Lưu cập nhật' : 'Tạo mới Hàng hóa'));
</script>

<template>
    <UiModalFrame v-if="modal" :title="title" description="Hàng hóa đang kinh doanh được chọn khi lập Hóa đơn / Phiếu thu." :action="action" :method="method" submit-icon="save" :submit-label="submitLabel">
        <MerchandiseFields :item="item" :categories="categories" />
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="title">
            <template #breadcrumbs>
                <Link :href="route('merchandise.index')" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">inventory_2</span>Hàng hóa &amp; Vật phẩm</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ isEdit ? 'Cập nhật' : 'Thêm mới' }}</span>
            </template>
        </UiPageHeader>

        <div class="max-w-3xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg">
            <UiForm id="merchandise-form" :action="action" :method="method" class="space-y-md">
                <MerchandiseFields :item="item" :categories="categories" />
                <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                    <UiButton variant="secondary" :href="route('merchandise.index')">Hủy bỏ</UiButton>
                    <UiButton type="submit" icon="save">{{ submitLabel }}</UiButton>
                </div>
            </UiForm>
        </div>
    </template>
</template>
