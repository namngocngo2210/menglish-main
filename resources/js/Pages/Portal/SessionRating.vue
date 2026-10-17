<script setup>
/**
 * Phụ huynh chấm một buổi học ngay dưới nhận xét của giáo viên (chủ dự án 17/10/2026): 1–5 sao, góp ý về (tùy chọn), nội dung.
 * Đã chấm thì hiện lại số sao; sửa được trong 48 giờ sau khi gửi (StudentPortalController::submitSessionFeedback).
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';

const props = defineProps({
    studentId: { type: Number, required: true },
    card: { type: Object, required: true },
    categories: { type: Object, default: () => ({}) },
});

const RATING_LABELS = ['Chọn số sao', 'Rất không hài lòng', 'Không hài lòng', 'Bình thường', 'Hài lòng', 'Rất hài lòng'];
const saved = computed(() => props.card.feedback);
const editing = ref(!saved.value);
const rating = ref(saved.value?.rating ?? 0);
const picked = ref([...(saved.value?.categories ?? [])]);
const content = ref(saved.value?.content ?? '');
const toggle = (key) => (picked.value = picked.value.includes(key) ? picked.value.filter((k) => k !== key) : [...picked.value, key]);
const savedLabels = computed(() => (saved.value?.categories ?? []).map((k) => props.categories[k]).filter(Boolean));
</script>

<template>
    <div class="border-t border-surface-container-highest pt-3" :data-session-rating="card.session_id">
        <p v-if="card.absent" class="text-xs text-on-surface-subtle">Học viên vắng buổi này.</p>

        <div v-else-if="saved && !editing" class="flex flex-wrap items-center gap-2" data-session-rating-saved>
            <span class="text-xs font-bold text-on-surface">Phụ huynh đã đánh giá:</span>
            <span class="flex items-center" :aria-label="`${saved.rating} trên 5 sao`">
                <span v-for="star in 5" :key="star" :class="['material-symbols-outlined text-lg', star <= saved.rating ? 'text-warning/70' : 'text-on-surface-subtle']" :style="star <= saved.rating ? { fontVariationSettings: `'FILL' 1` } : null" aria-hidden="true">star</span>
            </span>
            <span v-if="savedLabels.length" class="text-xs text-on-surface-variant">· {{ savedLabels.join(', ') }}</span>
            <p v-if="saved.content" class="w-full text-xs text-on-surface-variant">"{{ saved.content }}"</p>
            <button v-if="card.can_rate" type="button" class="text-xs font-bold text-primary hover:underline" @click="editing = true">Sửa đánh giá</button>
        </div>

        <UiForm v-else-if="card.can_rate" :action="route('portal.student.session-feedback.store')" method="post" class="space-y-3" preserve-scroll @success="editing = false">
            <input type="hidden" name="student_id" :value="studentId" />
            <input type="hidden" name="class_session_id" :value="card.session_id" />
            <input type="hidden" name="rating" :value="rating || ''" />
            <input v-for="key in picked" :key="key" type="hidden" name="categories[]" :value="key" />
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold text-on-surface">Phụ huynh đánh giá buổi học này</p>
                <span class="text-xs font-bold text-primary">{{ RATING_LABELS[rating] }}</span>
            </div>
            <div class="flex items-center justify-between" role="group" aria-label="Chọn số sao">
                <button
                    v-for="star in 5"
                    :key="star"
                    type="button"
                    class="flex h-11 w-11 items-center justify-center rounded-xl transition hover:bg-primary-container/10 active:scale-95"
                    :aria-label="`${star} sao`"
                    :aria-pressed="star <= rating"
                    @click="rating = star"
                >
                    <span :class="['material-symbols-outlined text-3xl', star <= rating ? 'text-warning/70' : 'text-on-surface-subtle']" :style="star <= rating ? { fontVariationSettings: `'FILL' 1` } : null" aria-hidden="true">star</span>
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="(label, key) in categories"
                    :key="key"
                    type="button"
                    :aria-pressed="picked.includes(key)"
                    :class="['min-h-[36px] rounded-full border px-3 text-xs font-semibold transition', picked.includes(key) ? 'border-primary-container bg-primary-container/10 text-primary' : 'border-surface-container-highest text-on-surface-variant hover:border-outline-variant']"
                    @click="toggle(key)"
                >
                    {{ label }}
                </button>
            </div>
            <UiTextarea v-model="content" name="content" :rows="2" placeholder="Góp ý thêm cho buổi học (không bắt buộc)..." class="resize-none" />
            <div class="flex items-center gap-2">
                <UiButton type="submit" size="sm" icon="send" :disabled="!rating">{{ saved ? 'Cập nhật đánh giá' : 'Gửi đánh giá' }}</UiButton>
                <button v-if="saved" type="button" class="text-xs font-semibold text-on-surface-variant hover:underline" @click="editing = false">Hủy</button>
            </div>
            <p class="text-xs text-on-surface-subtle">Đánh giá gửi tới Học vụ phụ trách lớp. Anh/chị sửa được trong 48 giờ sau khi gửi.</p>
        </UiForm>
    </div>
</template>
