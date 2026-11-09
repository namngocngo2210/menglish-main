<script setup>
/**
 * Nhập điểm mini test (mockup 03_Cong_Giao_Vien/06_nhap_diem_mini_test): Chọn Unit + Chọn học sinh, thành phần điểm động,
 * nhận xét chung. Bên dưới: bảng điểm đã nhập của Unit đang chọn (bấm để sửa).
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhập điểm mini test' } });

const props = defineProps({
    classroom: { type: Object, required: true },
    units: { type: Array, default: () => [] },
    unitId: { type: Number, default: null },
    testName: { type: String, default: null },
    testDate: { type: String, default: null },
    selectedStudentId: { type: Number, default: null },
    rowParams: { type: Object, default: () => ({}) },
    current: { type: Object, required: true },
    students: { type: Array, default: () => [] },
    scoredCount: { type: Number, default: 0 },
});

const input = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50';

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const firstError = computed(() => errors.value.components ?? Object.values(errors.value).find((v) => typeof v === 'string') ?? null);

const components = ref([]);
const loadComponents = () => {
    const initial = props.current?.components;
    if (Array.isArray(initial) && initial.length > 0) {
        components.value = initial.map((c) => ({
            name: c.name ?? '',
            score: c.score ?? '',
        }));
    } else {
        components.value = [{ name: '', score: '' }];
    }
};
loadComponents();
watch(() => [props.selectedStudentId, props.unitId, props.testName], loadComponents);

function addComponent() {
    components.value.push({ name: '', score: '' });
}

function removeComponent(index) {
    components.value.splice(index, 1);
}

const studentOptions = computed(() => props.students.map((s) => ({ value: s.id, label: s.name + (s.has_score ? ' ✓' : '') })));

function pickUnit(event) {
    const value = event?.target ? event.target.value : event;
    router.visit(route('teacher.scores', props.classroom.id) + '?unit_id=' + value);
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0">
        <UiPageHeader title="Nhập điểm mini test" :back="route('teacher.home')" back-label="Về lịch dạy">
            <template #meta>
                Vui lòng chọn thông tin và nhập điểm cho học sinh · Lớp {{ classroom.name }} <span class="font-code">({{ classroom.code }})</span>
            </template>
        </UiPageHeader>

        <div v-if="!students.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
            <UiEmptyState icon="group_off" title="Lớp chưa có học sinh" description="Lớp này chưa có học sinh nào trong danh sách." />
        </div>
        <template v-else>
            <UiForm :action="route('teacher.scores.store', classroom.id)" method="post" class="space-y-lg rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
                <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

                <section class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <UiSelect v-if="units.length" id="unitSelect" label="Chọn Unit" name="unit_id" required :value="unitId ?? ''" @change="pickUnit">
                        <option value="" disabled :selected="!unitId">Chọn Unit bài học</option>
                        <option v-for="u in units" :key="u.value" :value="u.value" :selected="unitId === u.value">{{ u.label }}</option>
                    </UiSelect>
                    <UiField v-else label="Tên bài kiểm tra" name="unit_id" for="testName" required hint="Lớp chưa gắn giáo trình có Unit — nhập tên bài.">
                        <input id="testName" name="name" required :value="testName" :class="input" />
                    </UiField>
                    <UiSelect id="studentSelect" label="Chọn học sinh" name="student_id" required :value="selectedStudentId ?? ''">
                        <option value="" disabled :selected="!selectedStudentId">Chọn học sinh từ danh sách</option>
                        <option v-for="o in studentOptions" :key="o.value" :value="o.value" :selected="selectedStudentId === o.value">{{ o.label }}</option>
                    </UiSelect>
                </section>

                <hr class="border-surface-container" />

                <section class="space-y-md">
                    <h2 class="font-h3 text-h3 text-on-surface">Thành phần điểm</h2>
                    <div class="space-y-sm">
                        <div v-for="(component, index) in components" :key="index" class="flex items-center gap-sm">
                            <input
                                v-model="component.name"
                                type="text"
                                :name="`components[${index}][name]`"
                                placeholder="Tên thành phần, vd: Nghe"
                                :class="[input, 'flex-1']"
                            />
                            <input
                                v-model="component.score"
                                type="number"
                                :name="`components[${index}][score]`"
                                min="0"
                                step="0.1"
                                placeholder="Điểm"
                                :class="[input, 'w-28 shrink-0 text-center font-code sm:w-36']"
                            />
                            <UiButton
                                type="button"
                                variant="ghost"
                                icon="delete"
                                aria-label="Xóa thành phần"
                                title="Xóa thành phần"
                                @click="removeComponent(index)"
                            />
                        </div>
                    </div>
                    <div>
                        <UiButton type="button" variant="secondary" icon="add" @click="addComponent">
                            Thêm thành phần
                        </UiButton>
                    </div>
                </section>

                <UiTextarea name="note" label="Nhận xét chung (Không bắt buộc)" :rows="3" :value="current.note" placeholder="Nhập nhận xét về phần làm bài của học sinh..." />
                <input type="hidden" name="test_date" :value="testDate" />

                <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                    <UiButton variant="secondary" :href="route('teacher.home')">Hủy</UiButton>
                    <UiButton type="submit" icon="save">Lưu điểm</UiButton>
                </div>
            </UiForm>

            <UiDataTable v-if="testName">
                <template #header>
                    <h3 class="font-h3 text-h3 text-on-surface">Điểm đã nhập — {{ testName }}</h3>
                    <span class="font-body-small text-body-small text-on-surface-variant">{{ scoredCount }}/{{ students.length }} học sinh</span>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Học sinh</th>
                            <th>Thành phần</th>
                            <th class="text-center">Tổng (TB)</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="student in students" :key="student.id">
                            <td class="font-semibold">{{ student.name }}</td>
                            <td>{{ student.components }}</td>
                            <td :class="['text-center font-code font-semibold', student.low ? 'text-error' : '']">{{ student.total ?? 'Chưa nhập' }}</td>
                            <td class="text-right">
                                <UiButton size="sm" variant="ghost" icon="edit" :href="route('teacher.scores', { ...rowParams, student_id: student.id })">{{ student.has_score ? 'Sửa' : 'Nhập' }}</UiButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <template #footer>
                    <p class="px-md py-sm font-caption text-caption text-on-surface-variant">Điểm tổng dưới 7/10 tự đưa học sinh vào danh sách bổ trợ.</p>
                </template>
            </UiDataTable>
        </template>
    </div>

    <TeacherBottomNav />
</template>
