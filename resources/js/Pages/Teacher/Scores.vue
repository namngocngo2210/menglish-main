<script setup>
/**
 * Nhập điểm mini test (mockup 03_Cong_Giao_Vien/06_nhap_diem_mini_test): Chọn Unit + Chọn học sinh, điểm 4 kỹ năng (bắt buộc đủ),
 * nhận xét chung. Bên dưới: bảng điểm đã nhập của Unit đang chọn (bấm để sửa).
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhập điểm mini test' } });

const props = defineProps({
    classroom: { type: Object, required: true },
    skills: { type: Object, required: true },
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
const firstError = computed(() => Object.values(errors.value).find((v) => typeof v === 'string') ?? null);

const skillValues = ref({});
const loadSkills = () => {
    skillValues.value = { ...props.current.skills };
};
loadSkills();
watch(() => [props.selectedStudentId, props.unitId, props.testName], loadSkills);

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
                    <div class="flex items-center justify-between gap-sm">
                        <h2 class="font-h3 text-h3 text-on-surface">Điểm kỹ năng</h2>
                        <label class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                            Thang điểm tối đa
                            <input type="number" name="max_score" :value="current.max_score" min="1" max="100" step="0.5" class="w-20 rounded-lg border border-outline-variant px-sm py-xs text-center font-code" />
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-md md:grid-cols-4">
                        <UiField v-for="(label, key) in skills" :key="key" :label="label" name="skills" :for="'score_' + key" required>
                            <input
                                :id="'score_' + key"
                                v-model="skillValues[key]"
                                type="number"
                                :name="`skills[${key}]`"
                                min="0"
                                step="0.1"
                                required
                                placeholder="Nhập điểm"
                                :class="[input, 'text-center font-code', errors.skills && (skillValues[key] ?? '') === '' ? 'border-error ring-2 ring-error/20' : '']"
                            />
                        </UiField>
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
                            <th v-for="(label, key) in skills" :key="key" class="text-center">{{ label }}</th>
                            <th class="text-center">Tổng (TB)</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="student in students" :key="student.id">
                            <td class="font-semibold">{{ student.name }}</td>
                            <td v-for="(label, key) in skills" :key="key" class="text-center font-code">{{ student.skills[key] }}</td>
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
