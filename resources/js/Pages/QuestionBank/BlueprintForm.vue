<script setup>
/**
 * Sửa khung đề một khối × kỳ: thời gian + các dòng {kỹ năng, dạng bài, số câu}. Dạng bài nhập tự do, gợi ý các dạng đã có
 * trong ngân hàng (datalist) để khớp đúng tên importer đã ghi. Mở từ lưới → modal; mở thẳng URL → trang đầy đủ.
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Khung đề luyện tập' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    blueprint: { type: Object, required: true },
    skills: { type: Array, default: () => [] },
    typeSuggestions: { type: Object, default: () => ({}) },
});

let nextKey = 0;
const makeRow = (item) => ({ key: nextKey++, skill: item.skill, type: item.type, count: item.count });
const rows = ref(props.blueprint.items.map(makeRow));
const skillOptions = computed(() => props.skills.map((skill) => ({ value: skill, label: skill })));
const total = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.count) || 0), 0));
const description = computed(() => `Khối ${props.blueprint.grade} · ${props.blueprint.term_label}`);

const addRow = () => rows.value.push(makeRow({ skill: props.skills[0] ?? '', type: '', count: 1 }));
const removeRow = (key) => (rows.value = rows.value.filter((row) => row.key !== key));
</script>

<template>
    <UiModalFrame title="Sửa khung đề" :description="description" :action="route('question-bank.blueprints.update', [blueprint.grade, blueprint.term])" method="put" :back="route('question-bank.blueprints.index')" submit-icon="save" page-width="max-w-4xl">
        <div class="space-y-md">
            <UiInput name="duration_minutes" type="number" label="Thời gian làm bài" suffix="phút" required min="5" max="180" :value="blueprint.duration_minutes" class="sm:max-w-xs" />

            <div class="space-y-sm">
                <div class="flex items-center justify-between">
                    <h3 class="font-body-medium text-body-medium font-semibold text-on-surface">Các dạng bài</h3>
                    <span class="font-body-small text-body-small text-on-surface-variant">Tổng: <b class="font-code text-on-surface">{{ total }}</b> câu</span>
                </div>

                <div v-for="(row, i) in rows" :key="row.key" class="grid grid-cols-1 items-end gap-sm rounded-lg border border-outline-variant p-sm sm:grid-cols-[1fr_1.4fr_6rem_auto]" data-blueprint-row>
                    <UiSelect :name="`items[${i}][skill]`" label="Kỹ năng" required :options="skillOptions" :value="row.skill" />
                    <UiInput :name="`items[${i}][type]`" label="Dạng bài" required maxlength="100" list="blueprint-types" :value="row.type" />
                    <UiInput :name="`items[${i}][count]`" type="number" label="Số câu" required min="0" max="100" v-model="row.count" />
                    <UiButton variant="ghost" icon="delete" :disabled="rows.length <= 1" title="Xóa dòng" :aria-label="`Xóa dòng ${i + 1}`" @click="removeRow(row.key)" />
                </div>

                <UiButton variant="secondary" size="sm" icon="add" :disabled="rows.length >= 30" @click="addRow">Thêm dạng bài</UiButton>
                <datalist id="blueprint-types">
                    <template v-for="(types, skill) in typeSuggestions" :key="skill">
                        <option v-for="type in types" :key="`${skill}|${type}`" :value="type">{{ skill }}</option>
                    </template>
                </datalist>
            </div>
        </div>
    </UiModalFrame>
</template>
