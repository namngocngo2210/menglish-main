<script setup>
/** Ghi chú trên báo cáo tuần Học thuật (Sheet 4 câu 9): Quản lý và chính người làm báo cáo xem và ghi chú. */
defineProps({
    notes: { type: Array, default: () => [] },
    url: { type: String, default: null },
});
</script>

<template>
    <section class="space-y-sm rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
        <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
            <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">sticky_note_2</span>Ghi chú
        </h3>
        <p v-if="!notes.length" class="font-body-small text-body-small text-on-surface-variant">Chưa có ghi chú.</p>
        <ul v-else class="space-y-sm">
            <li v-for="(n, i) in notes" :key="i" class="font-body-small text-body-small">
                <strong class="text-on-surface">{{ n.user ?? '—' }}</strong> <span class="text-on-surface-variant">· {{ formatDate(n.created_at, 'H:i d/m') }}</span>
                <p class="whitespace-pre-line text-on-surface">{{ n.note }}</p>
            </li>
        </ul>
        <UiForm v-if="url" :action="url" method="post" class="space-y-sm" reset-on-success>
            <UiTextarea name="note" label="Thêm ghi chú" :rows="2" placeholder="VD: Tuần sau ưu tiên đề chặng 2 cho lớp FAM 1." />
            <div class="flex justify-end"><UiButton type="submit" size="sm" icon="send">Ghi chú</UiButton></div>
        </UiForm>
    </section>
</template>
