<script setup>
/**
 * Dòng cộng / trừ tự do có tên trên phiếu lương (phụ cấp mở rộng, thưởng khác, khấu trừ khác…).
 * Dùng chung mảng `lines` của form phiếu lương (Record.vue) — mỗi chỗ đặt chỉ hiển thị một loại (`kind`);
 * tên trường theo vị trí trong mảng chung: lines[i][kind|label|amount].
 * Không sửa được (kỳ đã khoá / không có quyền): liệt kê các khoản đã lưu (`saved`).
 */
import { computed, reactive } from 'vue';
import { money } from './format';

const props = defineProps({
    kind: { type: String, required: true },
    lines: { type: Array, required: true },
    saved: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    addLabel: { type: String, required: true },
    placeholder: { type: String, default: null },
});

const suggestions = computed(() =>
    props.kind === 'earning'
        ? ['Hỗ trợ thỏa thuận', 'Phụ cấp gửi xe', 'Thưởng khác', 'Phụ cấp ăn trưa', 'Phụ cấp xăng xe', 'Phụ cấp trách nhiệm', 'Lương giảng dạy', 'Hỗ trợ']
        : ['Tạm ứng', 'Vi phạm nội quy', 'Khấu trừ khác'],
);
const readLines = computed(() => props.saved.filter((line) => line.kind === props.kind));
const draft = reactive({ label: '', amount: '' });

function add() {
    if (draft.label.trim() === '') return;
    props.lines.push({ kind: props.kind, label: draft.label.trim(), amount: draft.amount });
    draft.label = '';
    draft.amount = '';
}
</script>

<template>
    <template v-if="canEdit">
        <datalist :id="`payslip-suggest-${kind}`">
            <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion"></option>
        </datalist>
        <div class="space-y-sm">
            <template v-for="(line, i) in lines" :key="i">
                <div v-if="line.kind === kind" class="flex items-center gap-sm">
                    <input type="hidden" :name="`lines[${i}][kind]`" :value="line.kind" />
                    <input v-model="line.label" type="text" :name="`lines[${i}][label]`" aria-label="Tên khoản" :list="`payslip-suggest-${kind}`" class="min-w-0 flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-xs font-body-medium text-body-medium" />
                    <span class="relative w-40 shrink-0">
                        <input v-model="line.amount" type="number" min="0" step="1000" :name="`lines[${i}][amount]`" placeholder="0" aria-label="Số tiền" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg text-right font-mono text-body-medium" />
                        <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant">đ</span>
                    </span>
                    <UiButton variant="danger-text" size="sm" icon="delete" aria-label="Xoá khoản" @click="lines.splice(i, 1)" />
                </div>
            </template>
            <div class="flex flex-wrap items-end gap-sm rounded-lg border border-dashed border-outline-variant p-sm">
                <div class="min-w-[180px] flex-1">
                    <UiInput v-model="draft.label" :label="kind === 'earning' ? 'Tên khoản' : 'Lý do / Hạng mục'" :id="`payslip-draft-label-${kind}`" :placeholder="placeholder" :list="`payslip-suggest-${kind}`" />
                </div>
                <div class="w-40">
                    <UiInput v-model="draft.amount" type="number" label="Số tiền (VNĐ)" :id="`payslip-draft-amount-${kind}`" min="0" step="1000" placeholder="0" class="text-right font-mono" />
                </div>
                <UiButton variant="secondary" size="sm" icon="add" @click="add">{{ addLabel }}</UiButton>
            </div>
        </div>
    </template>
    <ul v-else-if="readLines.length" class="space-y-xs font-body-medium text-body-medium">
        <li v-for="(line, i) in readLines" :key="i" class="flex justify-between">
            <span>{{ line.label }}</span><span :class="['font-mono', kind === 'deduction' ? 'text-error' : '']">{{ kind === 'deduction' ? '-' : '+' }}{{ money(line.amount) }}</span>
        </li>
    </ul>
</template>
