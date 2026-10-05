<script setup>
/**
 * Cấu hình Trình độ & Syllabus (mockup cau-hinh-trinh-do): thống kê, tìm kiếm, kéo thả sắp xếp, sửa/xóa, bật/tắt trạng thái,
 * nhóm trình độ, mô tả, gắn giáo trình — form thêm/sửa là panel trượt bên phải như mockup.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { postJson } from '@/lib/http';
import { toast } from '@/lib/toast';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Cấu hình Trình độ & Syllabus' } });

const props = defineProps({
    levels: { type: Object, required: true },
    stats: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    gradeLevels: { type: Array, default: () => [] },
    curriculums: { type: Array, default: () => [] },
    canReorder: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
    canUpdate: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const blank = () => ({ id: null, code: '', name: '', description: '', level_group: '', grade_levels: [], target: '', lessons_count: 24, syllabus_curriculum_id: '', is_active: true });
const open = ref(false);
const level = reactive(blank());
const pickSyllabus = ref(false);

const groupOptions = computed(() => props.groups.map((g) => ({ value: g, label: g })));
const groupSuggestions = computed(() => [...new Set([...props.groups, 'KIDS', 'TEENS', 'IELTS', 'ADULTS'])]);
const syllabi = computed(() => Object.fromEntries(props.curriculums.map((c) => [String(c.id), c])));
const pickedSyllabus = computed(() => (level.syllabus_curriculum_id ? syllabi.value[String(level.syllabus_curriculum_id)] : null));
const reorderable = computed(() => props.canUpdate && props.canReorder);

function create() {
    Object.assign(level, blank());
    pickSyllabus.value = false;
    open.value = true;
}
function edit(lv) {
    Object.assign(level, {
        id: lv.id,
        code: lv.code,
        name: lv.name,
        description: lv.description ?? '',
        level_group: lv.level_group ?? '',
        grade_levels: [...(lv.grade_levels ?? [])],
        target: lv.target,
        lessons_count: lv.lessons_count,
        syllabus_curriculum_id: lv.syllabus_curriculum_id ?? '',
        is_active: lv.is_active,
    });
    pickSyllabus.value = false;
    open.value = true;
}

// Panel mở → khoá cuộn trang nền; Esc đóng.
watch(open, (value) => document.body.classList.toggle('overflow-hidden', value));
function onKeydown(event) {
    if (event.key === 'Escape' && open.value) open.value = false;
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.classList.remove('overflow-hidden');
});

// Kéo thả sắp xếp trình độ: đổi vị trí dòng trên trang rồi lưu thứ tự mới (POST ids theo thứ tự).
const rows = ref([...props.levels.data]);
watch(() => props.levels, (value) => (rows.value = [...value.data]));
const dragging = ref(null);
const armed = ref(null);

function start(event, lv) {
    dragging.value = lv.id;
    event.dataTransfer.effectAllowed = 'move';
}
function end() {
    dragging.value = null;
    armed.value = null;
}
function over(event) {
    const row = event.target.closest('tr[data-level-id]');
    if (dragging.value === null || !row) return;
    const targetId = Number(row.dataset.levelId);
    if (targetId === dragging.value) return;
    const list = [...rows.value];
    const from = list.findIndex((r) => r.id === dragging.value);
    const moved = list.splice(from, 1)[0];
    let to = list.findIndex((r) => r.id === targetId);
    if (event.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2) to += 1;
    list.splice(to, 0, moved);
    rows.value = list;
}
function drop() {
    const ids = rows.value.map((r) => String(r.id));
    postJson(route('course-levels.reorder'), { ids })
        .then(({ ok, data }) => (ok ? toast(data.message, 'success') : Promise.reject()))
        .catch(() => toast('Không lưu được thứ tự, vui lòng tải lại trang.', 'error'));
}
</script>

<template>
    <div>
        <UiPageHeader title="Cấu hình Trình độ & Syllabus" description="Quản lý danh sách trình độ đào tạo và thiết lập giáo trình tương ứng.">
            <template #actions>
                <UiButton v-if="canCreate" icon="add_circle" @click="create()">Thêm trình độ mới</UiButton>
            </template>
        </UiPageHeader>

        <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-3">
            <UiStatCard label="Tổng số trình độ" :value="stats.total" icon="layers" tone="primary" :hint="`${stats.active} đang hoạt động`" />
            <UiStatCard label="Syllabus hoạt động" :value="stats.syllabus" icon="auto_stories" tone="success" hint="Giáo trình gắn với trình độ đang hoạt động" />
            <UiStatCard label="Nhóm đào tạo" :value="String(stats.groups).padStart(2, '0')" icon="groups" tone="secondary" />
        </div>

        <UiFilterBar placeholder="Tìm kiếm trình độ..." :action="route('course-levels.index')">
            <UiSelect name="group" :options="groupOptions" placeholder="Tất cả nhóm" label="Nhóm" />
            <UiSelect name="status" :options="[{ value: 'active', label: 'Hoạt động' }, { value: 'inactive', label: 'Ngừng hoạt động' }]" placeholder="Mọi trạng thái" label="Trạng thái" />
        </UiFilterBar>

        <UiDataTable min-width="960px">
            <template #header>
                <h3 class="font-h3 text-h3 text-on-surface">Danh sách trình độ đào tạo</h3>
                <span v-if="reorderable && rows.length > 1" class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">drag_indicator</span> Kéo biểu tượng ở cột STT để sắp xếp thứ tự
                </span>
            </template>
            <table>
                <thead>
                    <tr>
                        <th class="w-16">STT</th>
                        <th>Mã trình độ</th>
                        <th>Tên trình độ</th>
                        <th>Nhóm trình độ</th>
                        <th>Syllabus gắn kèm</th>
                        <th class="text-center">Khóa / Lớp</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody @dragover.prevent="reorderable && over($event)" @drop.prevent="reorderable && drop()">
                    <tr
                        v-for="(lv, index) in rows"
                        :key="lv.id"
                        :data-level-id="lv.id"
                        :draggable="reorderable && armed === lv.id ? 'true' : null"
                        :class="{ 'opacity-60': dragging === lv.id }"
                        @dragstart="reorderable && start($event, lv)"
                        @dragend="end()"
                    >
                        <td class="whitespace-nowrap text-on-surface-variant">
                            <span v-if="reorderable" class="material-symbols-outlined cursor-grab align-middle text-[20px] hover:text-primary" title="Kéo để sắp xếp" aria-hidden="true" @mousedown="armed = lv.id">drag_indicator</span>
                            <span class="font-code">{{ (levels.from ?? 1) + index }}</span>
                        </td>
                        <td class="font-code font-semibold">{{ lv.code }}</td>
                        <td>
                            <div class="font-semibold">{{ lv.name }}</div>
                            <div class="font-caption text-caption text-on-surface-variant">{{ lv.target }} · {{ lv.lessons_count }} buổi</div>
                            <div v-if="lv.description" class="max-w-xs truncate font-caption text-caption text-on-surface-variant" :title="lv.description">{{ lv.description }}</div>
                        </td>
                        <td>
                            <span v-if="lv.level_group" class="rounded bg-secondary-fixed px-sm py-[2px] font-caption text-caption font-semibold text-on-secondary-fixed">{{ lv.level_group }}</span>
                            <span v-else class="font-caption text-caption italic text-on-surface-variant">—</span>
                            <div v-if="lv.grade_labels?.length" class="mt-xs font-caption text-caption text-on-surface-variant">Test: {{ lv.grade_labels.join(', ') }}</div>
                        </td>
                        <td>
                            <UiBadge v-if="lv.syllabus" color="secondary" :dot="false" pill class="font-code font-semibold" :title="lv.syllabus.title">{{ lv.syllabus.label }}</UiBadge>
                            <span v-else class="font-caption text-caption italic text-on-surface-variant">Chưa gắn Syllabus</span>
                        </td>
                        <td class="text-center font-code">{{ lv.courses_count }} / {{ lv.classes_count }}</td>
                        <td>
                            <UiForm v-if="canUpdate" :action="route('course-levels.update', lv.id)" method="put">
                                <input type="hidden" name="toggle_status" value="1" />
                                <button type="submit" :title="`Bấm để ${lv.is_active ? 'ngừng' : 'kích hoạt lại'}`">
                                    <UiBadge :color="lv.is_active ? 'success' : 'neutral'" pill>{{ lv.is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}</UiBadge>
                                </button>
                            </UiForm>
                            <UiBadge v-else :color="lv.is_active ? 'success' : 'neutral'" pill>{{ lv.is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}</UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="inline-flex items-center justify-end gap-xs">
                                <UiButton v-if="canUpdate" variant="ghost" icon="edit" title="Chỉnh sửa" :aria-label="`Sửa ${lv.name}`" @click="edit(lv)" />
                                <template v-if="canDelete">
                                    <!-- Cảnh báo theo mockup: hover nút xóa → "Không thể xóa" + số lớp / học sinh tham chiếu. -->
                                    <div v-if="lv.courses_count > 0 || lv.classes_count > 0" class="group relative" data-delete-blocked>
                                        <span class="inline-flex cursor-not-allowed p-sm text-on-surface-variant/40" tabindex="0" role="img" :aria-label="`Không thể xóa ${lv.name}`">
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                        </span>
                                        <div class="pointer-events-none absolute bottom-full right-0 z-20 mb-xs hidden w-64 rounded-lg bg-inverse-surface p-sm text-left text-inverse-on-surface shadow-level-3 group-focus-within:block group-hover:block">
                                            <p class="flex items-center gap-xs font-body-small text-body-small font-semibold text-error-container">
                                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span> Không thể xóa
                                            </p>
                                            <p class="mt-xs whitespace-normal font-caption text-caption">
                                                Trình độ này đang có {{ lv.classes_count }} lớp học{{ lv.courses_count ? `, ${lv.courses_count} khóa học` : '' }} và {{ lv.students_using }} học sinh tham chiếu. Vui lòng chuyển sang 'Ngừng hoạt động'.
                                            </p>
                                        </div>
                                    </div>
                                    <UiForm v-else :action="route('course-levels.destroy', lv.id)" method="delete" class="inline" :confirm="`Xóa trình độ ${lv.code}?`" confirm-label="Xóa" danger>
                                        <UiButton type="submit" variant="danger-text" icon="delete" title="Xóa" :aria-label="`Xóa ${lv.name}`" />
                                    </UiForm>
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="8">
                            <UiEmptyState icon="layers" title="Không có trình độ phù hợp" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="levels" unit="trình độ" /></template>
        </UiDataTable>

        <!-- Panel thêm / sửa trình độ (trượt từ phải) -->
        <div v-show="open" class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="level-panel-title">
            <Transition enter-active-class="transition-opacity" enter-from-class="opacity-0" leave-active-class="transition-opacity" leave-to-class="opacity-0">
                <div v-show="open" class="absolute inset-0 bg-on-surface/40 backdrop-blur-xs" @click="open = false"></div>
            </Transition>
            <Transition enter-active-class="transition duration-200" enter-from-class="translate-x-full" enter-to-class="translate-x-0">
                <UiForm
                    v-show="open"
                    :action="level.id ? route('course-levels.update', level.id) : route('course-levels.store')"
                    :method="level.id ? 'put' : 'post'"
                    class="relative flex h-full w-full max-w-lg flex-col bg-surface-container-lowest shadow-level-3"
                    @success="open = false"
                >
                    <div class="flex items-start justify-between gap-md border-b border-surface-container p-lg">
                        <div>
                            <h2 id="level-panel-title" class="font-h2 text-h2 text-on-surface">Thêm/Sửa Trình độ đào tạo</h2>
                            <p class="font-body-small text-body-small text-on-surface-variant">{{ level.id ? `Đang sửa ${level.code} — nhập thông tin chi tiết và thiết lập Syllabus.` : 'Nhập thông tin chi tiết và thiết lập Syllabus.' }}</p>
                        </div>
                        <UiButton variant="ghost" icon="close" aria-label="Đóng" @click="open = false" />
                    </div>

                    <div class="flex-1 space-y-lg overflow-y-auto p-lg">
                        <section class="space-y-md">
                            <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Thông tin chung</h3>
                            <div class="grid grid-cols-2 gap-md">
                                <UiInput id="lv_code" v-model="level.code" label="Mã trình độ" name="code" :disabled="!!level.id" required maxlength="20" placeholder="KID-BEG-01" class="font-code" />
                                <div>
                                    <UiInput id="lv_group" v-model="level.level_group" label="Nhóm trình độ" name="level_group" hint="VD: KIDS, TEENS, IELTS, ADULTS" list="lv_groups" maxlength="50" class="uppercase" />
                                    <datalist id="lv_groups"><option v-for="g in groupSuggestions" :key="g" :value="g" /></datalist>
                                </div>
                            </div>
                            <UiInput id="lv_name" v-model="level.name" label="Tên trình độ" name="name" required placeholder="Nhập tên trình độ..." />
                            <UiTextarea id="lv_description" v-model="level.description" label="Mô tả" name="description" :rows="3" maxlength="1000" placeholder="Mô tả tóm tắt về trình độ này..." />
                            <UiInput id="lv_target" v-model="level.target" label="Chuẩn đầu ra (Target)" name="target" required placeholder="CEFR B1 / IELTS 5.0" />
                            <UiInput id="lv_lessons" v-model="level.lessons_count" type="number" label="Số buổi học" name="lessons_count" min="1" required />
                            <label class="flex cursor-pointer items-center justify-between gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md">
                                <span>
                                    <span class="block font-body-medium text-body-medium text-on-surface">Trạng thái hoạt động</span>
                                    <span class="block font-caption text-caption text-on-surface-variant">Cho phép sử dụng trình độ này trong tuyển sinh.</span>
                                </span>
                                <input type="hidden" name="is_active" value="0" />
                                <input v-model="level.is_active" type="checkbox" name="is_active" value="1" class="peer sr-only" />
                                <span class="relative h-6 w-11 shrink-0 rounded-full bg-surface-container-highest transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-surface-container-lowest after:shadow after:transition peer-checked:bg-primary-container peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-container/40" aria-hidden="true"></span>
                            </label>
                        </section>

                        <section class="space-y-sm border-t border-surface-container pt-lg">
                            <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Cấp độ test đầu vào</h3>
                            <p class="font-caption text-caption text-on-surface-variant">Học viên làm test ở cấp độ được chọn sẽ được gợi ý và xếp vào lớp có trình độ này.</p>
                            <div class="flex flex-wrap gap-xs" role="group" aria-label="Cấp độ test đầu vào">
                                <label
                                    v-for="grade in gradeLevels"
                                    :key="grade.value"
                                    :class="['inline-flex cursor-pointer items-center gap-xs rounded-full border px-md py-xs font-body-small text-body-small has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-container/40', level.grade_levels.includes(grade.value) ? 'border-primary-container bg-primary-container/10 font-semibold text-primary' : 'border-outline-variant text-on-surface-variant hover:border-primary-container/60']"
                                >
                                    <input v-model="level.grade_levels" type="checkbox" name="grade_levels[]" :value="grade.value" class="sr-only" />
                                    {{ grade.label }}
                                </label>
                            </div>
                        </section>

                        <section class="space-y-md border-t border-surface-container pt-lg">
                            <div class="flex items-center justify-between">
                                <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Thiết lập Syllabus</h3>
                                <UiButton variant="ghost" size="sm" icon="add" class="font-semibold !text-primary" @click="pickSyllabus = true">Gắn Syllabus mới</UiButton>
                            </div>
                            <input type="hidden" name="syllabus_curriculum_id" :value="level.syllabus_curriculum_id ?? ''" />
                            <div v-if="pickedSyllabus" class="flex items-center justify-between gap-md rounded-lg border border-primary-container/30 bg-primary-container/5 p-md">
                                <div class="flex min-w-0 items-center gap-md">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-container/10 text-primary"><span class="material-symbols-outlined" aria-hidden="true">menu_book</span></span>
                                    <div class="min-w-0">
                                        <p class="truncate font-body-medium text-body-medium font-semibold text-on-surface">{{ pickedSyllabus.label }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ `Cập nhật: ${pickedSyllabus.updated ?? '—'} | Trạng thái: Hiện tại` }}</p>
                                    </div>
                                </div>
                                <UiButton variant="ghost" icon="link_off" class="hover:!text-error" title="Gỡ Syllabus" aria-label="Gỡ Syllabus" @click="level.syllabus_curriculum_id = ''" />
                            </div>
                            <p v-if="!level.syllabus_curriculum_id && !pickSyllabus" class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small italic text-on-surface-variant">Chưa gắn Syllabus</p>
                            <div v-show="pickSyllabus">
                                <UiSelect
                                    id="lv_syllabus"
                                    v-model="level.syllabus_curriculum_id"
                                    label="Chọn giáo trình"
                                    placeholder="-- Chưa gắn Syllabus --"
                                    :options="curriculums.map((c) => ({ value: String(c.id), label: c.option }))"
                                    :error="$page.props.errors?.syllabus_curriculum_id"
                                    @change="pickSyllabus = false"
                                />
                            </div>
                        </section>
                    </div>

                    <div class="flex justify-end gap-sm border-t border-surface-container p-lg">
                        <UiButton variant="secondary" @click="open = false">Hủy bỏ</UiButton>
                        <UiButton type="submit">Lưu thay đổi</UiButton>
                    </div>
                </UiForm>
            </Transition>
        </div>
    </div>
</template>
