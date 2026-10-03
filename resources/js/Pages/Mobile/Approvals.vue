<script setup>
/**
 * Cần duyệt trên điện thoại: mọi mục chờ bạn duyệt (cùng nguồn với "Việc cần duyệt"), nhóm theo loại.
 * Bấm 1 mục → chi tiết + Duyệt / Từ chối (modal dùng chung approvals.show).
 */
import { Head, Link } from '@inertiajs/vue3';
import MobileLayout from '@/Layouts/MobileLayout.vue';
import { shortenCodesIn } from '@/lib/format';
import { openRemoteModal } from '@/lib/remoteModal';

defineOptions({ layout: MobileLayout });

defineProps({
    sections: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Cần duyệt" />

    <div class="space-y-lg">
        <section v-for="section in sections" :key="section.key" :data-approval-source="section.key">
            <div class="mb-sm flex items-center justify-between gap-sm">
                <h2 class="font-body-semibold text-body-semibold text-on-surface">{{ section.label }} <span class="text-on-surface-variant">({{ section.count }})</span></h2>
                <Link v-if="section.count > section.items.length" :href="section.indexUrl" class="font-body-small text-body-small text-primary hover:underline">Xem tất cả</Link>
            </div>
            <ul class="space-y-sm">
                <li v-for="item in section.items" :key="item.ref">
                    <a
                        :href="item.url"
                        class="flex items-start gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm hover:bg-surface-container-low"
                        @click.prevent="openRemoteModal(item.url, { size: '2xl' })"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-body-semibold text-body-semibold text-on-surface">{{ shortenCodesIn(item.title) }}</p>
                            <p v-if="item.subtitle" class="line-clamp-2 font-body-small text-body-small text-on-surface-variant">{{ item.subtitle }}</p>
                            <p class="mt-xs font-caption text-caption text-on-surface-subtle">
                                {{ item.created_ago }}<template v-if="item.amount !== null"> · {{ formatMoney(item.amount) }}</template>
                            </p>
                        </div>
                        <span class="material-symbols-outlined text-on-surface-subtle" aria-hidden="true">chevron_right</span>
                    </a>
                </li>
            </ul>
        </section>

        <UiEmptyState v-if="!sections.length" icon="task_alt" title="Không có mục nào chờ bạn duyệt" description="Khi có đơn hoặc yêu cầu mới, số đếm hiện trên thanh dưới." />
    </div>
</template>
