<script setup>
/**
 * Giao bài tập về nhà (mockup 03_Cong_Giao_Vien/03_giao_bai_tap_ve_nha): Thông tin chung (buổi học, hạn nộp, ghi chú, tài liệu
 * tham khảo) + Hạng mục bài tập (≥ 1, mỗi hạng mục có yêu cầu chi tiết; hạng mục đã có học sinh nộp thì khóa).
 */
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Giao bài tập về nhà' } });

const props = defineProps({
    classroom: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    selectedSessionId: { type: String, default: null },
    dueDefault: { type: String, default: null },
    editing: { type: Object, default: null },
    homeworks: { type: Array, default: () => [] },
});

const input = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50';

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const firstError = computed(() => Object.values(errors.value).find((v) => typeof v === 'string') ?? null);

const locked = computed(() => props.editing?.locked ?? []);
const picked = ref([]);
const itemTexts = ref({});
function init() {
    picked.value = [...new Set([...Object.keys(props.editing?.items ?? {}), ...locked.value])];
    itemTexts.value = { ...(props.editing?.items ?? {}) };
}
init();
watch(() => props.editing?.id, init);

const isLocked = (key) => locked.value.includes(key);
function toggle(key) {
    if (isLocked(key)) return;
    picked.value = picked.value.includes(key) ? picked.value.filter((x) => x !== key) : [...picked.value, key];
}

const action = computed(() => (props.editing ? route('teacher.homework.update', [props.classroom.id, props.editing.id]) : route('teacher.homework.store', props.classroom.id)));
function onSaved() {
    if (!props.editing) init();
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0">
        <UiPageHeader :title="editing ? 'Sửa bài tập về nhà' : 'Giao bài tập về nhà'" :back="route('teacher.home')" back-label="Về lịch dạy">
            <template #meta>
                <span class="inline-flex items-center gap-xs rounded-full bg-secondary-fixed/50 px-md py-[2px] font-body-small text-body-small text-on-secondary-fixed">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span> Lớp {{ classroom.name }} <span class="font-code">({{ classroom.code }})</span>
                </span>
            </template>
        </UiPageHeader>

        <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>

        <UiForm :key="editing?.id ?? 'new'" :action="action" method="post" class="space-y-lg" :reset-on-success="!editing" @success="onSaved">
            <input v-if="editing" type="hidden" name="_method" value="PUT" />

            <!-- 1. Thông tin chung -->
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">info</span> Thông tin chung</h2>
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <UiSelect id="hw_session" label="Buổi học" name="class_session_id" required placeholder="Chọn buổi học..." :options="sessions" :value="selectedSessionId" />
                    <UiInput id="hw_due" label="Hạn nộp" type="datetime-local" name="due_at" required :value="dueDefault" />
                </div>
                <UiTextarea name="class_note" label="Ghi chú nhắc nhở cả lớp (Không bắt buộc)" :rows="2" :value="editing?.class_note" placeholder="Ví dụ: Các em nhớ làm bài tập trước 12h trưa chủ nhật nhé..." />
                <div class="space-y-sm rounded-lg bg-surface-container-low p-md">
                    <p class="font-body-medium text-body-medium text-on-surface">Tài liệu tham khảo (Không bắt buộc)</p>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <UiInput name="youtube_url" type="url" label="Link YouTube nghe mẫu" icon="smart_display" :value="editing?.youtube_url" placeholder="https://youtube.com/..." />
                        <UiField label="File nghe đính kèm" name="audio" for="hw_audio" :hint="editing?.has_audio ? 'Đã có file — chọn file mới để thay' : null">
                            <input id="hw_audio" type="file" name="audio" accept="audio/*" class="block w-full font-body-small text-body-small text-on-surface-variant file:mr-sm file:rounded-lg file:border-0 file:bg-primary-container/10 file:px-md file:py-xs file:font-semibold file:text-primary" />
                        </UiField>
                        <UiInput name="quizizz_url" type="url" label="Link Quizizz luyện thêm" icon="quiz" :value="editing?.quizizz_url" placeholder="https://quizizz.com/..." />
                    </div>
                </div>
            </section>

            <!-- 2. Hạng mục bài tập -->
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
                <div class="flex items-center justify-between gap-sm">
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">assignment_add</span> Hạng mục bài tập <span class="text-error">*</span></h2>
                    <span class="font-caption text-caption text-on-surface-variant">Chọn ít nhất 1 hạng mục</span>
                </div>
                <div class="grid grid-cols-2 gap-sm md:grid-cols-3">
                    <label
                        v-for="cat in categories"
                        :key="cat.key"
                        :class="['relative flex cursor-pointer items-center justify-between gap-sm rounded-lg border p-md transition', picked.includes(cat.key) ? 'border-primary-container bg-primary-container/5' : 'border-outline-variant hover:bg-surface-container-low']"
                        :title="isLocked(cat.key) ? 'Hạng mục này đã có học sinh nộp bài, không thể chỉnh sửa phân loại.' : undefined"
                    >
                        <span class="flex items-center gap-sm">
                            <input type="checkbox" name="categories[]" :value="cat.key" :checked="picked.includes(cat.key)" :disabled="isLocked(cat.key)" class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40" @change="toggle(cat.key)" />
                            <span class="font-body-medium text-body-medium text-on-surface">{{ cat.label }}</span>
                        </span>
                        <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">{{ isLocked(cat.key) ? 'lock' : cat.icon }}</span>
                        <template v-if="isLocked(cat.key)">
                            <input type="hidden" name="categories[]" :value="cat.key" />
                            <span class="absolute -top-2 right-2 rounded bg-warning-container px-xs font-caption text-caption font-semibold text-on-warning-container">Đã có học sinh nộp</span>
                        </template>
                    </label>
                </div>

                <div class="space-y-sm">
                    <div v-for="cat in categories" v-show="picked.includes(cat.key)" :key="cat.key" class="rounded-lg border border-outline-variant p-md">
                        <div class="mb-sm flex items-center justify-between">
                            <h4 class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">{{ cat.icon }}</span> Yêu cầu: {{ cat.label }}</h4>
                            <span v-if="isLocked(cat.key)" class="material-symbols-outlined text-[18px] text-on-surface-variant" title="Không thể xóa do đã có người nộp" aria-hidden="true">lock</span>
                            <UiButton v-else variant="ghost" size="sm" icon="close" :aria-label="`Bỏ hạng mục ${cat.label}`" @click="toggle(cat.key)" />
                        </div>
                        <textarea
                            v-model="itemTexts[cat.key]"
                            :name="`items[${cat.key}]`"
                            rows="2"
                            :placeholder="cat.placeholder"
                            :required="picked.includes(cat.key)"
                            :disabled="!picked.includes(cat.key)"
                            :class="[input, errors['items.' + cat.key] ? 'border-error' : '']"
                        ></textarea>
                        <p v-if="errors['items.' + cat.key]" class="mt-xs font-caption text-caption text-error">{{ errors['items.' + cat.key] }}</p>
                    </div>
                </div>
                <p v-if="picked.length === 0" class="flex items-center gap-xs font-caption text-caption text-error">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">error</span> Cần giao ít nhất 1 hạng mục.
                </p>
            </section>

            <footer class="flex justify-end gap-sm">
                <UiButton variant="secondary" :href="editing ? route('teacher.homework', classroom.id) : route('teacher.home')">Hủy</UiButton>
                <UiButton type="submit" icon="save" :disabled="picked.length === 0">Lưu bài tập</UiButton>
            </footer>
        </UiForm>

        <!-- Bài tập đã giao — ẩn khi đang sửa (?edit=): màn sửa là trang riêng, Hủy quay về danh sách. -->
        <section v-if="!editing" class="space-y-sm">
            <h2 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Bài tập đã giao ({{ homeworks.length }})</h2>
            <div v-for="hw in homeworks" :key="hw.id" class="flex items-start justify-between gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
                <div class="min-w-0 space-y-xs">
                    <div class="font-body-semibold text-body-semibold text-on-surface">{{ hw.title }}</div>
                    <div class="font-caption text-caption text-on-surface-variant">
                        Hạn nộp: {{ hw.due_label }}
                        <template v-if="hw.session_date"> · Buổi {{ hw.session_date }}</template>
                        · GV: {{ hw.teacher_name }}
                    </div>
                    <div v-if="hw.items.length" class="flex flex-wrap gap-xs">
                        <UiBadge v-for="item in hw.items" :key="item.key" :color="item.locked ? 'warning' : 'secondary'" :dot="false" :title="item.text">{{ item.label }}{{ item.locked ? ' · đã có bài nộp' : '' }}</UiBadge>
                    </div>
                    <p v-else-if="hw.description" class="whitespace-pre-line font-body-small text-body-small text-on-surface-variant">{{ hw.description }}</p>
                    <p v-if="hw.class_note" class="font-body-small text-body-small italic text-on-surface-variant">“{{ hw.class_note }}”</p>
                    <div class="flex flex-wrap gap-sm font-caption text-caption">
                        <a v-if="hw.youtube_url" :href="hw.youtube_url" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">smart_display</span>YouTube</a>
                        <a v-if="hw.audio_url" :href="hw.audio_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">audio_file</span>File nghe</a>
                        <a v-if="hw.quizizz_url" :href="hw.quizizz_url" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">quiz</span>Quizizz</a>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-xs">
                    <UiButton variant="ghost" icon="edit" :href="route('teacher.homework', { classId: classroom.id, edit: hw.id })" title="Sửa" :aria-label="`Sửa ${hw.title}`" />
                    <UiForm v-if="hw.deletable" :action="route('teacher.homework.destroy', [classroom.id, hw.id])" method="delete" confirm="Xóa bài tập này?" confirm-label="Xóa" danger>
                        <UiButton type="submit" variant="danger-text" icon="delete" title="Xoá" :aria-label="`Xoá ${hw.title}`" />
                    </UiForm>
                </div>
            </div>
            <div v-if="!homeworks.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <UiEmptyState icon="assignment" title="Chưa giao bài tập nào" description="Bài tập đã giao cho lớp sẽ hiện ở đây." />
            </div>
        </section>
    </div>

    <TeacherBottomNav />
</template>
