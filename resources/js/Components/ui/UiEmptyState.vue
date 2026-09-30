<script setup>
/**
 * Trạng thái rỗng (như <x-ui.empty-state>).
 *   <UiEmptyState icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khoá hoặc xoá bộ lọc.">
 *       <UiButton variant="secondary" :href="route('crm.customers.index')">Xoá bộ lọc</UiButton>
 *   </UiEmptyState>
 * compact: một dòng ngang (khối rỗng không nên chiếm chỗ).
 */
defineProps({
    icon: { type: String, default: 'inbox' },
    title: { type: String, required: true },
    description: { type: String, default: null },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div v-if="compact" class="flex items-start gap-sm rounded-lg bg-surface-container-low px-md py-sm">
        <span class="material-symbols-outlined mt-0.5 text-[20px] text-on-surface-subtle" aria-hidden="true">{{ icon }}</span>
        <div class="min-w-0">
            <p class="font-body-semibold text-body-semibold text-on-surface">{{ title }}</p>
            <p v-if="description" class="font-body-small text-body-small text-on-surface-variant">{{ description }}</p>
        </div>
        <div v-if="$slots.default" class="ml-auto flex shrink-0 items-center gap-sm"><slot /></div>
    </div>
    <div v-else class="flex flex-col items-center justify-center gap-sm px-md py-xl text-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-low text-on-surface-subtle">
            <span class="material-symbols-outlined text-[32px]" aria-hidden="true">{{ icon }}</span>
        </div>
        <p class="font-h3 text-h3 text-on-surface">{{ title }}</p>
        <p v-if="description" class="max-w-md font-body-base text-body-base text-on-surface-variant">{{ description }}</p>
        <div v-if="$slots.default" class="mt-sm flex flex-wrap items-center justify-center gap-sm"><slot /></div>
    </div>
</template>
