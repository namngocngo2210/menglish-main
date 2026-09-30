<script setup>
/**
 * Ô nhập điểm test đầu vào theo thang điểm khối lớp (BA Q2) — dùng trong <UiForm> của màn chấm bài (PlacementTests/Result).
 * Tổng = Nghe + Đọc & Viết + Nói (điểm thô theo thang khối) → lớp đề xuất; chọn lại lớp được;
 * nhận xét từng kỹ năng gợi ý theo băng điểm (sửa được). Speaking luôn nhập tay.
 * Thay partial Blade placement-tests/partials/rubric-score-fields (partial đó vẫn dùng ở hồ sơ khách CRM).
 *   <RubricScoreFields :rubric="rubric" />   // rubric = PlacementTestController::rubricFormState($submission, $group)
 */
import { computed, reactive, ref, watch } from 'vue';
import { usePage, useFormContext } from '@inertiajs/vue3';

const props = defineProps({
    rubric: { type: Object, required: true },
});

const SKILLS = ['listening', 'reading_writing', 'speaking'];
const skillStyles = {
    listening: 'text-secondary border-secondary/20 bg-secondary/5',
    reading_writing: 'text-tertiary border-tertiary/20 bg-tertiary/5',
    speaking: 'text-primary border-primary/20 bg-primary/5',
};

const page = usePage();
const form = useFormContext();
const config = props.rubric.config;
const initial = props.rubric.initial;

const group = ref(initial.group);
const scores = reactive({ listening: initial.listening, reading_writing: initial.reading_writing, speaking: initial.speaking });
const chosen = ref(initial.chosen || '');
// Nhận xét người chấm đã tự sửa thì không ghi đè bằng gợi ý nữa.
const edited = reactive({ ...initial.edited });

const rubricGroup = computed(() => config.groups[group.value] || null);
const hasRubric = computed(() => !!(rubricGroup.value && rubricGroup.value.has_rubric));
const max = (skill) => (rubricGroup.value ? rubricGroup.value.max[skill] : 10);
const num = (skill) => {
    const v = parseFloat(scores[skill]);
    return Number.isFinite(v) ? v : null;
};
const complete = computed(() => SKILLS.every((s) => num(s) !== null));
const total = computed(() => Math.round(SKILLS.reduce((sum, s) => sum + (num(s) || 0), 0) * 10) / 10);
const maxTotal = computed(() => max('listening') + max('reading_writing') + max('speaking'));
const suggestedClass = computed(() => {
    if (!hasRubric.value || !complete.value) return null;
    const t = total.value;
    const hit = rubricGroup.value.placements.find((p) => (p.lt !== null ? t < p.lt : p.lte !== null ? t <= p.lte : true));
    return hit ? hit.class : null;
});
function suggestion(skill) {
    const score = num(skill);
    if (!hasRubric.value || score === null) return '';
    let text = '';
    (rubricGroup.value.bands[skill] || []).forEach((band) => {
        if (score >= band.min) text = band.text;
    });
    return text;
}
const overMax = (skill) => {
    const v = num(skill);
    return v !== null && v > max(skill);
};
const percent = (skill) => {
    const v = num(skill);
    return v === null ? 0 : Math.max(0, Math.min(100, Math.round((v / max(skill)) * 100)));
};

const comments = reactive(Object.fromEntries(SKILLS.map((s) => [s, edited[s] ? initial.comments[s] || '' : suggestion(s) || ''])));
for (const skill of SKILLS) {
    watch(
        () => suggestion(skill),
        (text) => {
            if (!edited[skill]) comments[skill] = text || '';
        },
    );
}

// Nhận xét đang khớp gợi ý theo thang điểm (chưa sửa tay).
const synced = computed(() => hasRubric.value && complete.value && SKILLS.every((s) => !edited[s]));

function resetComment(skill) {
    edited[skill] = false;
    comments[skill] = suggestion(skill) || '';
}
// "Tự động tạo nhận xét & Xếp lớp": áp lại gợi ý cho cả 3 kỹ năng và lớp đề xuất.
function applyAll() {
    SKILLS.forEach((s) => resetComment(s));
    if (suggestedClass.value) chosen.value = suggestedClass.value;
}

const skillLabels = Object.fromEntries(props.rubric.skills.map((s) => [s.value, s.label]));
const lower = (text) => text.toLocaleLowerCase('vi');
function errorFor(key) {
    const own = form?.errors ?? {};
    const errors = Object.keys(own).length ? own : (page.props.errors ?? {});
    return errors[key] ?? null;
}
</script>

<template>
    <div class="space-y-md font-body-small text-body-small" data-rubric-form>
        <!-- Mockup kh_i_test_online: "Select Khối / Thang điểm tự động" + "Tự động tạo nhận xét & Xếp lớp" -->
        <div class="flex flex-col gap-sm rounded-lg bg-surface-container-low p-md sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="grade_group" class="mb-xs flex items-center gap-xs font-label text-label uppercase text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px]">school</span>Khối lớp / Thang điểm tự động <span class="text-error">*</span>
                </label>
                <UiSelect id="grade_group" v-model="group" name="grade_group" required :options="rubric.gradeGroups" />
                <p v-if="errorFor('grade_group')" class="mt-xs text-error">{{ errorFor('grade_group') }}</p>
            </div>
            <UiButton variant="info" icon="auto_awesome" :disabled="!hasRubric" @click="applyAll()">Tự động tạo nhận xét &amp; Xếp lớp</UiButton>
        </div>
        <p v-show="!hasRubric" class="rounded-lg border-l-4 border-warning bg-warning-container px-md py-sm font-semibold text-on-warning-container">
            {{ rubric.noRubricNotice }}. Điểm từng kỹ năng nhập theo thang tạm 0–10, không quy đổi ra lớp.
        </p>

        <!-- Ô điểm từng kỹ năng: điểm / tối đa, thanh tiến độ, nhận xét gợi ý -->
        <div class="grid grid-cols-1 gap-md md:grid-cols-3">
            <div v-for="skill in SKILLS" :key="skill" :class="['space-y-sm rounded-lg border p-md', skillStyles[skill]]">
                <div class="flex items-center justify-between gap-xs">
                    <label :for="`score_${skill}`" class="font-label text-label uppercase">
                        Điểm {{ skillLabels[skill] }}
                        <span v-if="skill === 'speaking'" class="block normal-case text-on-surface-variant">(Nhập tay)</span>
                    </label>
                    <span class="font-code text-code">{{ hasRubric ? '/ ' + max(skill) : 'điểm' }}</span>
                </div>
                <input
                    :id="`score_${skill}`"
                    v-model="scores[skill]"
                    type="number"
                    step="0.5"
                    min="0"
                    :max="max(skill)"
                    :name="`${skill}_score`"
                    :required="skill === 'speaking' ? hasRubric : true"
                    :class="['w-full rounded-lg border bg-surface-container-lowest p-sm text-center font-code text-h3', overMax(skill) ? 'border-error ring-2 ring-error/20' : 'border-outline-variant']"
                />
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-container-high">
                    <div class="h-full rounded-full bg-current transition-all" :style="`width: ${percent(skill)}%`"></div>
                </div>
                <p class="min-h-[2.5rem] font-caption text-caption italic text-on-surface-variant">{{ suggestion(skill) || `Nhận xét ${lower(skillLabels[skill])} sẽ tự động sinh...` }}</p>
                <p v-if="errorFor(`${skill}_score`)" class="text-error">{{ errorFor(`${skill}_score`) }}</p>
            </div>
        </div>

        <!-- Nhận xét gợi ý (tự động theo thang điểm) — người chấm sửa được -->
        <div class="space-y-sm rounded-lg border border-surface-container-highest p-md">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <span class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary">auto_awesome</span>Nhận xét gợi ý (Tự động theo Thang điểm)
                </span>
                <span v-show="synced" class="inline-flex items-center gap-xs rounded-full bg-tertiary/10 px-sm py-0.5 font-caption text-caption font-bold text-tertiary">
                    <span class="material-symbols-outlined text-[14px]">check_circle</span>Đã đồng bộ Thang điểm
                </span>
            </div>
            <div v-for="skill in SKILLS" :key="skill">
                <div class="mb-0.5 flex items-center justify-between gap-sm">
                    <label :for="`comment_${skill}`" class="font-caption text-caption font-semibold text-on-surface-variant">{{ skillLabels[skill] }}</label>
                    <button v-show="edited[skill] && suggestion(skill)" type="button" class="font-caption text-caption font-semibold text-primary hover:underline" @click="resetComment(skill)">Dùng lại gợi ý</button>
                </div>
                <UiTextarea
                    :id="`comment_${skill}`"
                    v-model="comments[skill]"
                    :name="`${skill}_comment`"
                    rows="2"
                    maxlength="3000"
                    class="font-body-small text-body-small leading-relaxed"
                    :placeholder="`Nhận xét ${lower(skillLabels[skill])}...`"
                    @input="edited[skill] = true"
                />
            </div>
            <UiTextarea id="teacher_comments" name="teacher_comments" label="Ghi chú chung / lời khuyên của người chấm" rows="2" maxlength="3000" :value="initial.teacher_comments" class="font-body-small text-body-small" placeholder="Định hướng lộ trình, lưu ý cho tư vấn viên..." />
            <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">info</span>CM/Tư vấn viên có thể chỉnh sửa bổ sung nội dung này trước khi lưu.</p>
        </div>

        <!-- Tổng điểm hệ thống + Đề xuất xếp lớp tự động -->
        <div class="flex flex-col gap-md rounded-lg bg-inverse-surface p-md text-inverse-on-surface sm:flex-row sm:items-center">
            <div class="flex items-center gap-md">
                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-h3 text-h3 text-white">{{ complete ? total : '—' }}</div>
                <div>
                    <p class="font-label text-label uppercase opacity-80">Tổng điểm hệ thống</p>
                    <p class="font-h2 text-h2">{{ complete ? total : '—' }} <span class="font-body-medium text-body-medium opacity-70">{{ hasRubric ? '/ ' + maxTotal + ' điểm' : 'điểm' }}</span></p>
                </div>
            </div>
            <div class="hidden h-10 w-px bg-white/20 sm:block"></div>
            <div class="flex-1">
                <p class="font-label text-label uppercase opacity-80">Đề xuất xếp lớp tự động</p>
                <p class="flex items-center gap-xs font-body-semibold text-body-semibold">
                    <span class="material-symbols-outlined text-[18px]">school</span>
                    <span>{{ hasRubric ? suggestedClass || 'Nhập đủ 3 kỹ năng để tra lớp' : 'Không có — chọn lớp thủ công' }}</span>
                </p>
            </div>
        </div>

        <div>
            <label for="chosen_class" class="mb-xs block font-body-medium text-body-medium text-on-surface">
                Lớp xếp cho học viên <span v-show="!hasRubric" class="text-error">*</span>
                <span v-show="hasRubric" class="font-body-small text-body-small text-on-surface-variant">(để trống = theo lớp đề xuất; có thể chọn lại)</span>
            </label>
            <UiInput
                id="chosen_class"
                v-model="chosen"
                name="chosen_class"
                list="rubric-class-options"
                :required="!hasRubric"
                maxlength="255"
                :placeholder="suggestedClass || 'Nhập / chọn lớp phù hợp'"
                class="font-body-medium text-body-medium text-primary"
            />
            <datalist id="rubric-class-options">
                <option v-for="option in rubric.classOptions" :key="option" :value="option"></option>
            </datalist>
            <p v-if="errorFor('chosen_class')" class="mt-xs text-error">{{ errorFor('chosen_class') }}</p>
        </div>
    </div>
</template>
