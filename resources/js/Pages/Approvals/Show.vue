<script setup>
/**
 * Chi tiết 1 mục chờ duyệt: mở từ "Việc cần duyệt" → modal; mở thẳng URL → trang đầy đủ.
 * Duyệt / Từ chối gửi tới approvals.bulk với 1 mục (single=1): trong modal → đóng modal, danh sách nền cập nhật + thông báo;
 * trang đầy đủ → về "Việc cần duyệt".
 */
import { computed, nextTick, ref } from 'vue';
import { useBackLink } from '@/lib/backLink';
import { shortenCodesIn } from '@/lib/format';
import { route } from '@/lib/route';
import ApprovalDetail from './ApprovalDetail.vue';

const props = defineProps({
    asModal: { type: Boolean, default: false },
    source: { type: Object, required: true },
    item: { type: Object, required: true },
    canApprove: { type: Boolean, default: false },
    canReject: { type: Boolean, default: false },
});
const back = useBackLink(() => route('approvals.index'), 'Việc cần duyệt');

defineOptions({ layout: (props) => ({ title: shortenCodesIn(props.item?.title ?? '') }) });

const rejecting = ref(false);
const title = computed(() => shortenCodesIn(props.item.title));
const description = computed(() => `${props.source.label} · ${props.source.group}`);

async function startReject() {
    rejecting.value = true;
    await nextTick();
    document.getElementById('approval-reject-reason')?.focus();
}
</script>

<template>
    <UiModalFrame v-if="asModal" :title="title" :description="description" cancel="Đóng">
        <ApprovalDetail :item="item" :can-approve="canApprove" :can-reject="canReject" :rejecting="rejecting" />
        <template #footer>
            <UiButton variant="secondary" icon="open_in_new" :href="item.url" class="mr-auto">Mở màn gốc</UiButton>
            <template v-if="canReject">
                <UiButton v-show="!rejecting" variant="danger-text" icon="block" @click="startReject">Từ chối</UiButton>
                <UiButton v-show="rejecting" variant="danger" type="submit" form="approval-reject-form" icon="block">Xác nhận từ chối</UiButton>
            </template>
            <UiButton v-if="canApprove" v-show="!rejecting" type="submit" form="approval-approve-form" icon="check">Duyệt</UiButton>
        </template>
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="title" :description="description">
            <template #actions>
                <UiButton variant="secondary" icon="arrow_back" :href="back.href" data-back-link>{{ back.label }}</UiButton>
                <UiButton icon="open_in_new" :href="item.url">Mở màn gốc</UiButton>
            </template>
        </UiPageHeader>
        <div class="max-w-3xl rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
            <ApprovalDetail :item="item" :can-approve="canApprove" :can-reject="canReject" :rejecting="rejecting" />
            <div v-if="canApprove || canReject" class="mt-lg flex flex-wrap justify-end gap-sm border-t border-surface-container pt-md">
                <template v-if="canReject">
                    <UiButton v-show="!rejecting" variant="danger-text" icon="block" @click="startReject">Từ chối</UiButton>
                    <UiButton v-show="rejecting" variant="danger" type="submit" form="approval-reject-form" icon="block">Xác nhận từ chối</UiButton>
                </template>
                <UiButton v-if="canApprove" v-show="!rejecting" type="submit" form="approval-approve-form" icon="check">Duyệt</UiButton>
            </div>
        </div>
    </template>
</template>
