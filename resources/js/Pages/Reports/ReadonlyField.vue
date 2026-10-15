<script setup>
/**
 * Ô chỉ đọc trông như ô nhập (nhãn + khung) cho modal Chi tiết báo cáo của Admin: hiện đủ nội dung, không cuộn như textarea;
 * ô trống hiện "—".
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    multiline: { type: Boolean, default: false },
});

const empty = (v) => v === null || v === undefined || String(v).trim() === '';
</script>

<template>
    <div class="flex flex-col gap-xs" data-readonly-field>
        <p class="font-body-small text-body-small text-on-surface-variant">{{ label }}</p>
        <div
            :class="[
                'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base',
                multiline ? 'min-h-[4.5rem] whitespace-pre-line break-words' : '',
                empty(value) ? 'text-on-surface-subtle' : 'text-on-surface',
            ]"
            data-field-value
        >{{ empty(value) ? '—' : value }}</div>
    </div>
</template>
