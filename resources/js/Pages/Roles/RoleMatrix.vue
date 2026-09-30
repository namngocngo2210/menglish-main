<script setup>
/**
 * Ma trận quyền của vai trò: nhóm → module; cột Xem / Thêm / Sửa / Xóa / Duyệt, "Thao tác khác", "Phạm vi dữ liệu".
 * Ô tích gửi permissions[], phạm vi gửi scope[module] (như form HTML).
 */
import { reactive } from 'vue';

const props = defineProps({
    columns: { type: Array, required: true },
    matrix: { type: Array, required: true },
    selected: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const levels = reactive(Object.fromEntries(props.matrix.flatMap((group) => group.modules.map((m) => [m.module, m.level]))));
const isOn = (name) => props.selected.includes(name);
const levelDescription = (module) => module.levels.find((l) => l.value === levels[module.module])?.description ?? '';
</script>

<template>
    <input v-if="!readonly" type="hidden" name="matrix_submitted" value="1" />
    <UiDataTable min-width="1080px" data-testid="role-matrix">
        <template #header>
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">grid_view</span>Ma trận quyền theo module
            </h2>
            <p class="font-body-small text-body-small text-on-surface-variant">Di chuột lên từng quyền để xem mô tả. "Đối tượng" = người dùng là ai (cổng, được xếp dạy lớp…), không phải thao tác.</p>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Module</th>
                    <th v-for="column in columns" :key="column.value" class="text-center">{{ column.label }}</th>
                    <th>Thao tác khác</th>
                    <th class="min-w-[180px]">Phạm vi dữ liệu</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="group in matrix" :key="group.label">
                    <tr class="bg-surface-container-low" :data-permission-group="group.label">
                        <td :colspan="3 + columns.length" class="font-label text-label uppercase text-on-surface-variant">{{ group.label }}</td>
                    </tr>
                    <tr v-for="module in group.modules" :key="module.module" class="align-top" :data-module="module.module">
                        <td>
                            <div class="font-semibold text-on-surface">{{ module.label }}</div>
                            <div class="font-code text-caption text-on-surface-variant">{{ module.module }}</div>
                        </td>
                        <td v-for="column in columns" :key="column.value" class="text-center">
                            <label v-if="module.cells[column.value]" class="inline-flex cursor-pointer" :title="`${module.cells[column.value].label} — ${module.cells[column.value].description} (${module.cells[column.value].name})`">
                                <input type="checkbox" name="permissions[]" :value="module.cells[column.value].name" :data-perm="module.cells[column.value].name" :checked="isOn(module.cells[column.value].name)" :disabled="readonly" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                                <span class="sr-only">{{ module.cells[column.value].label }}</span>
                            </label>
                            <span v-else class="text-outline" title="Module không có quyền này">—</span>
                        </td>
                        <td class="min-w-[280px]">
                            <div v-if="module.others.length" class="space-y-xs">
                                <label v-for="permission in module.others" :key="permission.name" class="flex cursor-pointer items-start gap-sm" :title="`${permission.description} (${permission.name})`">
                                    <input type="checkbox" name="permissions[]" :value="permission.name" :data-perm="permission.name" :checked="isOn(permission.name)" :disabled="readonly" class="mt-0.5 h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                                    <span class="font-body-small text-body-small text-on-surface">
                                        {{ permission.label }}
                                        <span v-if="permission.audience" class="rounded bg-secondary-fixed/60 px-xs font-caption text-caption text-secondary">Đối tượng</span>
                                        <span class="block font-caption text-caption text-on-surface-variant">{{ permission.description }} <span class="font-code">{{ permission.name }}</span></span>
                                    </span>
                                </label>
                            </div>
                            <span v-else class="text-outline">—</span>
                        </td>
                        <td>
                            <template v-if="module.levels.length">
                                <UiSelect v-model="levels[module.module]" :name="`scope[${module.module}]`" :aria-label="`Phạm vi dữ liệu ${module.label}`" :disabled="readonly" class="font-body-small text-body-small">
                                    <option v-for="level in module.levels" :key="level.value" :value="level.value" :selected="levels[module.module] === level.value" :title="level.description">{{ level.label }}</option>
                                </UiSelect>
                                <p class="mt-xs font-caption text-caption text-on-surface-variant">{{ levelDescription(module) }}</p>
                            </template>
                            <span v-else class="font-body-small text-body-small text-on-surface-variant">Toàn hệ thống</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </UiDataTable>
</template>
