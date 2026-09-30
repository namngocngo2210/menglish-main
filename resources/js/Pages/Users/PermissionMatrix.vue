<script setup>
/**
 * Ma trận phân quyền cá nhân — dùng chung trang đầy đủ và modal 4xl (Permissions.vue).
 * Ô tích = có quyền (sau khi áp phân quyền cá nhân). Gửi overrides[module][action] = inherit (trùng vai trò gốc) | allow (cấp thêm) | deny (thu hồi);
 * action có dấu "." gửi bằng ":" (formKey). Mỗi module: phạm vi dữ liệu (data_scope[module]) và phạm vi áp dụng (scope[module][type|ids]).
 * Chọn tất cả / Bỏ chọn / nhấn đúp tiêu đề cột → áp dụng nhanh; reset() (nút "Đặt lại mặc định") → về đúng quyền vai trò.
 */
import { reactive } from 'vue';

const props = defineProps({
    columns: { type: Array, required: true },
    matrix: { type: Array, required: true },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    asModal: { type: Boolean, default: false },
});

const allCells = props.matrix.flatMap((group) => group.modules.flatMap((m) => [...Object.values(m.cells).filter(Boolean), ...m.extra]));
const on = reactive(Object.fromEntries(allCells.map((c) => [c.name, c.decision === 'allow' || (c.decision === 'inherit' && c.role)])));
const scopeType = reactive(Object.fromEntries(props.matrix.flatMap((group) => group.modules.map((m) => [m.module, m.scope.type]))));
const columnValue = reactive(Object.fromEntries(props.columns.map((c) => [c.value, true])));

const decision = (cell) => (on[cell.name] === cell.role ? 'inherit' : on[cell.name] ? 'allow' : 'deny');
const cellTitle = (cell) => `${cell.description ? cell.description + ' — ' : ''}${cell.name} — vai trò gốc: ${cell.role ? 'có quyền' : 'không có quyền'}`;
const unitLabels = (module) => {
    const list = module.scope.type === 'branch' ? props.branches : props.classes;
    return list.filter((o) => module.scope.ids.includes(Number(o.value))).map((o) => o.label);
};

function setAll(value) {
    allCells.forEach((c) => (on[c.name] = value));
}
function toggleColumn(action) {
    allCells.filter((c) => c.action === action).forEach((c) => (on[c.name] = columnValue[action]));
    columnValue[action] = !columnValue[action];
}
function reset() {
    allCells.forEach((c) => (on[c.name] = c.role));
}

defineExpose({ reset });
</script>

<template>
    <UiDataTable min-width="1100px">
        <template #header>
            <div class="flex w-full flex-wrap items-center justify-between gap-sm">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">grid_view</span>Ma trận phân quyền chi tiết
                </h2>
                <div class="flex items-center gap-xs font-body-small text-body-small">
                    <span class="text-on-surface-variant">Chọn nhanh:</span>
                    <UiButton size="sm" variant="secondary" @click="setAll(true)">Chọn tất cả</UiButton>
                    <UiButton size="sm" variant="secondary" @click="setAll(false)">Bỏ chọn</UiButton>
                </div>
            </div>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Danh mục Module</th>
                    <th v-for="column in columns" :key="column.value" class="cursor-pointer select-none text-center" title="Nhấn đúp để áp dụng nhanh cho toàn bộ cột" @dblclick="toggleColumn(column.value)">{{ column.label }}</th>
                    <th class="min-w-[180px]">Phạm vi dữ liệu</th>
                    <th>Phạm vi áp dụng</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="group in matrix" :key="group.label">
                    <tr class="bg-surface-container-low" :data-permission-group="group.label">
                        <td :colspan="3 + columns.length" class="font-label text-label uppercase text-on-surface-variant">{{ group.label }}</td>
                    </tr>
                    <tr v-for="module in group.modules" :key="module.module" class="align-top" :data-module="module.module">
                        <td>
                            <div class="flex items-start gap-sm">
                                <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">{{ module.icon }}</span>
                                <div>
                                    <div class="font-semibold text-on-surface">{{ module.label }}</div>
                                    <details v-if="module.extra.length" class="mt-xs" :open="module.extraOpen">
                                        <summary class="cursor-pointer font-caption text-caption font-semibold text-secondary">Thao tác khác ({{ module.extra.length }})</summary>
                                        <div class="mt-xs space-y-xs">
                                            <label v-for="cell in module.extra" :key="cell.name" class="flex cursor-pointer items-start justify-between gap-sm" :title="cellTitle(cell)">
                                                <span class="font-body-small text-body-small text-on-surface">
                                                    {{ cell.label }}
                                                    <span v-if="cell.audience" class="rounded bg-secondary-fixed/60 px-xs font-caption text-caption text-secondary">Đối tượng</span>
                                                </span>
                                                <span class="inline-flex flex-col items-center gap-[2px]">
                                                    <input type="hidden" :name="`overrides[${module.module}][${cell.formKey}]`" :value="decision(cell)" />
                                                    <input v-model="on[cell.name]" type="checkbox" :data-perm="cell.name" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                                                    <span :class="['text-xs font-semibold', on[cell.name] === cell.role ? 'invisible' : on[cell.name] ? 'text-tertiary' : 'text-error']">{{ on[cell.name] === cell.role ? '·' : on[cell.name] ? 'Cấp thêm' : 'Thu hồi' }}</span>
                                                </span>
                                            </label>
                                        </div>
                                    </details>
                                </div>
                            </div>
                        </td>
                        <td v-for="column in columns" :key="column.value" class="text-center">
                            <label v-if="module.cells[column.value]" class="inline-flex cursor-pointer flex-col items-center gap-[2px]" :title="cellTitle(module.cells[column.value])">
                                <span class="inline-flex flex-col items-center gap-[2px]">
                                    <input type="hidden" :name="`overrides[${module.module}][${module.cells[column.value].formKey}]`" :value="decision(module.cells[column.value])" />
                                    <input v-model="on[module.cells[column.value].name]" type="checkbox" :data-perm="module.cells[column.value].name" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                                    <span :class="['text-xs font-semibold', on[module.cells[column.value].name] === module.cells[column.value].role ? 'invisible' : on[module.cells[column.value].name] ? 'text-tertiary' : 'text-error']">{{ on[module.cells[column.value].name] === module.cells[column.value].role ? '·' : on[module.cells[column.value].name] ? 'Cấp thêm' : 'Thu hồi' }}</span>
                                </span>
                            </label>
                            <span v-else class="text-outline" title="Module không có quyền này">—</span>
                        </td>
                        <td>
                            <template v-if="module.dataScope">
                                <UiSelect :name="`data_scope[${module.module}]`" :value="module.dataScope.personal" :aria-label="`Phạm vi dữ liệu ${module.label}`">
                                    <option value="inherit" :selected="module.dataScope.personal === 'inherit'">Theo vai trò ({{ module.dataScope.roleLabel }})</option>
                                    <option v-for="level in module.dataScope.levels" :key="level.value" :value="level.value" :selected="module.dataScope.personal === level.value" :title="level.title">{{ level.label }}</option>
                                </UiSelect>
                                <p class="mt-xs font-caption text-caption text-on-surface-variant">Hiệu lực: {{ module.dataScope.effective }}</p>
                            </template>
                            <span v-else class="font-body-small text-body-small text-on-surface-variant">—</span>
                        </td>
                        <td class="min-w-[230px]">
                            <template v-if="module.supportsScope">
                                <UiSelect v-model="scopeType[module.module]" :name="`scope[${module.module}][type]`" aria-label="Phạm vi áp dụng">
                                    <option value="all" :selected="scopeType[module.module] === 'all'">Toàn hệ thống (Mặc định)</option>
                                    <option value="branch" :selected="scopeType[module.module] === 'branch'">Theo chi nhánh</option>
                                    <option value="class" :selected="scopeType[module.module] === 'class'">Theo lớp</option>
                                </UiSelect>
                                <UiSelect v-show="scopeType[module.module] === 'branch'" :name="`scope[${module.module}][ids][]`" :id="`scope-${module.module}-branch-ids`" multiple :disabled="scopeType[module.module] !== 'branch'" aria-label="Chi nhánh" class="mt-xs h-20">
                                    <option v-for="branch in branches" :key="branch.value" :value="branch.value" :selected="module.scope.type === 'branch' && module.scope.ids.includes(Number(branch.value))">{{ branch.label }}</option>
                                </UiSelect>
                                <UiSelect v-show="scopeType[module.module] === 'class'" :name="`scope[${module.module}][ids][]`" :id="`scope-${module.module}-class-ids`" multiple :disabled="scopeType[module.module] !== 'class'" aria-label="Lớp" class="mt-xs h-20">
                                    <option v-for="klass in classes" :key="klass.value" :value="klass.value" :selected="module.scope.type === 'class' && module.scope.ids.includes(Number(klass.value))">{{ klass.label }}</option>
                                </UiSelect>
                                <div v-if="module.scope.type !== 'all' && module.scope.ids.length" class="mt-xs flex flex-wrap gap-xs">
                                    <span v-for="unit in unitLabels(module)" :key="unit" class="rounded bg-secondary-fixed/60 px-sm font-caption text-caption text-secondary">{{ unit }}</span>
                                </div>
                                <p class="mt-xs font-caption text-caption text-on-surface-variant">Cấp riêng Xem / Sửa / Xóa cho chi nhánh / lớp cụ thể. Giữ Ctrl/⌘ để chọn nhiều.</p>
                            </template>
                            <span v-else-if="module.hasAccess" class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" title="Phân hệ này không cấp riêng theo chi nhánh/lớp cụ thể — dùng Phạm vi dữ liệu">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">language</span>Toàn hệ thống (Mặc định)
                            </span>
                            <span v-else class="font-body-small text-body-small italic text-on-surface-variant">Không có quyền truy cập</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <template v-if="!asModal" #footer>
            <div class="flex flex-col gap-sm px-md py-md md:flex-row md:items-center md:justify-between">
                <p class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                    Nhấn đúp vào tiêu đề cột để áp dụng nhanh cho toàn bộ cột. "Phạm vi dữ liệu" (Của tôi / Chi nhánh / Toàn hệ thống) thay mức của vai trò cho riêng người này.
                </p>
                <div class="flex gap-sm">
                    <UiButton variant="secondary" @click="reset()">Đặt lại mặc định</UiButton>
                    <UiButton type="submit">Lưu phân quyền</UiButton>
                </div>
            </div>
        </template>
    </UiDataTable>
</template>
