<script setup>
/**
 * Ngăn kéo "Giao bài tập về nhà" cho đúng một buổi, mở từ Báo cáo buổi (TeacherPortalController::homeworkDrawer).
 * Hạn nộp điền sẵn = giờ vào buổi kế tiếp; chọn nhiều hạng mục, mỗi hạng mục mở ô yêu cầu; tài liệu kèm thu gọn.
 * Lưu → teacher.homework.store trả back() → ngăn đóng, báo cáo buổi tải lại.
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useRemoteModal } from '@/Components/ui/modalContext';
import { route } from '@/lib/route';

const props = defineProps({
    classroom: { type: Object, required: true },
    session: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    dueDefault: { type: String, default: null },
    dueHint: { type: String, default: null },
    prefill: { type: Object, default: null },
    existing: { type: Object, default: null },
});

const modal = useRemoteModal();
const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const firstError = computed(() => Object.values(errors.value).find((v) => typeof v === 'string') ?? null);

const picked = ref(Object.keys(props.prefill?.items ?? {}));
const texts = ref({ ...(props.prefill?.items ?? {}) });
const toggle = (key) => (picked.value = picked.value.includes(key) ? picked.value.filter((k) => k !== key) : [...picked.value, key]);
const pickedCats = computed(() => props.categories.filter((c) => picked.value.includes(c.key)));
const showFiles = ref(Boolean(props.prefill?.quizizz_url));

const field = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50';
const label = 'mb-xs block font-body-small text-body-small font-semibold text-on-surface';
</script>

<template>
    <UiForm :action="route('teacher.homework.store', classroom.id)" method="post" class="flex min-h-0 flex-1 flex-col" #default="{ processing }">
        <input type="hidden" name="class_session_id" :value="session.id" />
        <input v-if="prefill?.title" type="hidden" name="title" :value="prefill.title" />

        <div class="shrink-0 border-b border-surface-container px-lg py-md">
            <div class="flex items-start justify-between gap-md">
                <div class="min-w-0">
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ classroom.name }}</p>
                    <h2 :id="modal?.titleId" class="font-h3 text-h3 text-on-surface">Giao bài tập về nhà</h2>
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ session.label }}</p>
                </div>
                <button v-if="modal" type="button" class="shrink-0 rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" aria-label="Đóng" data-modal-close @click="modal.close()">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-md overflow-y-auto px-lg py-md">
            <UiAlert v-if="firstError" type="error">{{ firstError }}</UiAlert>
            <UiAlert v-if="existing" type="info">
                Buổi này đã giao: {{ existing.items.join(', ') || existing.title }}. Lưu ở đây là giao thêm một bài;
                <a :href="existing.url" class="font-semibold underline">sửa bài đã giao</a>.
            </UiAlert>

            <label class="block">
                <span :class="label">Hạn nộp</span>
                <input type="datetime-local" name="due_at" :value="dueDefault" required :class="[field, 'h-10']" />
                <span v-if="dueHint" class="mt-xs block text-xs text-on-surface-variant">{{ dueHint }}</span>
            </label>

            <fieldset>
                <legend :class="label">Hạng mục <span class="font-normal text-on-surface-variant">(chọn một hoặc nhiều)</span></legend>
                <div class="flex flex-wrap gap-xs">
                    <button
                        v-for="cat in categories"
                        :key="cat.key"
                        type="button"
                        :aria-pressed="picked.includes(cat.key) ? 'true' : 'false'"
                        :class="[
                            'inline-flex h-9 items-center gap-xs rounded-full border px-md text-sm font-semibold transition-colors',
                            picked.includes(cat.key) ? 'border-primary bg-primary-container/10 text-primary' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low',
                        ]"
                        :data-category="cat.key"
                        @click="toggle(cat.key)"
                    >
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ picked.includes(cat.key) ? 'check' : cat.icon }}</span>{{ cat.label }}
                    </button>
                </div>
            </fieldset>

            <div v-for="cat in pickedCats" :key="cat.key">
                <input type="hidden" name="categories[]" :value="cat.key" />
                <label :for="`hw_item_${cat.key}`" :class="label">{{ cat.label }}</label>
                <textarea :id="`hw_item_${cat.key}`" v-model="texts[cat.key]" :name="`items[${cat.key}]`" rows="2" required :placeholder="cat.placeholder" :class="field"></textarea>
                <p v-if="errors[`items.${cat.key}`]" class="mt-xs text-xs text-error">{{ errors[`items.${cat.key}`] }}</p>
            </div>
            <p v-if="!pickedCats.length" class="rounded-lg bg-surface-container-low px-sm py-xs text-xs text-on-surface-variant">Chọn hạng mục để nhập yêu cầu.</p>

            <div class="rounded-xl border border-outline-variant">
                <button type="button" class="flex w-full items-center justify-between px-sm py-xs text-sm font-semibold text-on-surface" :aria-expanded="showFiles ? 'true' : 'false'" @click="showFiles = !showFiles">
                    Tài liệu kèm (tùy chọn)
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ showFiles ? 'expand_less' : 'expand_more' }}</span>
                </button>
                <div v-show="showFiles" class="space-y-sm border-t border-surface-container p-sm">
                    <label class="block"><span :class="label">Link YouTube</span><input type="url" name="youtube_url" placeholder="https://youtube.com/..." :class="[field, 'h-10']" /></label>
                    <label class="block"><span :class="label">Link Quizizz</span><input type="url" name="quizizz_url" :value="prefill?.quizizz_url ?? ''" placeholder="https://quizizz.com/..." :class="[field, 'h-10']" /></label>
                    <label class="block"><span :class="label">File nghe</span><input type="file" name="audio" accept="audio/*" class="block w-full text-sm text-on-surface-variant" /></label>
                </div>
            </div>

            <label class="block">
                <span :class="label">Ghi chú cho lớp (tùy chọn)</span>
                <textarea name="class_note" rows="2" placeholder="Lưu ý chung gửi học viên / phụ huynh..." :class="field"></textarea>
            </label>
        </div>

        <div class="flex shrink-0 items-center justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
            <UiButton v-if="modal" variant="secondary" @click="modal.close()">Hủy</UiButton>
            <UiButton type="submit" icon="send" :disabled="processing || !pickedCats.length">Giao bài</UiButton>
        </div>
    </UiForm>
</template>
