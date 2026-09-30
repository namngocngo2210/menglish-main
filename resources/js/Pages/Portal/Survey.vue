<script setup>
/**
 * Khảo sát chất lượng (MH #6): đợt khảo sát đang mở do học vụ tạo — bấm một khảo sát → hộp thoại phản hồi (số sao + ý kiến);
 * khảo sát đã gửi (xóa để làm lại).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Khảo sát chất lượng', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    surveys: { type: Array, default: () => [] },
    pastSurveys: { type: Array, default: () => [] },
});

const params = computed(() => ({ studentId: props.student?.id ?? null }));
const responseOpen = ref(false);
const selectedSurvey = ref('');

function select(title) {
    selectedSurvey.value = title;
    responseOpen.value = true;
}
</script>

<template>
    <PortalPageHeader title="Khảo sát chất lượng" icon="contact_support" :back="route('portal.student.home', params)">
        <template #actions>
            <UiButton icon="rate_review" :href="route('portal.student.feedback', params)">Đánh giá chặng học</UiButton>
        </template>
    </PortalPageHeader>

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-24 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="Khảo sát" show-back :back-url="route('portal.student.home', params)" />

        <!-- Khảo sát chung / Feedback chặng -->
        <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
            <Link :href="route('portal.student.survey', params)" class="flex items-center gap-1.5 border-b-2 border-primary-container px-4 py-2 text-xs font-bold text-primary">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Khảo sát định kỳ</span>
            </Link>
            <Link :href="route('portal.student.feedback', params)" class="flex items-center gap-1.5 border-b-2 border-transparent px-4 py-2 text-xs font-semibold text-on-surface-variant transition hover:text-on-surface">
                <span class="material-symbols-outlined text-[16px]">rate_review</span>
                <span>Feedback chặng học</span>
            </Link>
        </div>

        <div class="w-full flex-1 space-y-4 overflow-y-auto p-4">
            <div class="pt-1">
                <h2 class="text-xl font-bold text-on-surface">Khảo sát &amp; Đánh giá</h2>
                <p class="mt-0.5 text-xs text-on-surface-variant">Hãy chia sẻ ý kiến của bạn để chúng tôi nâng cao chất lượng dịch vụ đào tạo.</p>
            </div>

            <!-- Khảo sát đang mở -->
            <section class="space-y-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Khảo sát đang mở</h3>
                <p v-if="surveys.length" class="text-xs text-on-surface-variant">Bấm vào một khảo sát để gửi phản hồi.</p>

                <button v-for="srv in surveys" :key="srv.id" type="button" class="w-full rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 text-left shadow-2xs transition-all hover:bg-primary-container/10" @click="select(srv.title)">
                    <div class="flex items-start justify-between gap-2">
                        <div class="pr-2">
                            <h4 class="mb-1 text-xs font-bold leading-snug text-on-surface">{{ srv.title }}</h4>
                            <p :class="['flex items-center gap-1 text-xs', srv.is_urgent ? 'font-semibold text-error' : 'text-on-surface-variant']">
                                <span class="material-symbols-outlined text-[13px]">event</span>
                                <span>{{ srv.status_text }}</span>
                            </p>
                        </div>
                        <span class="material-symbols-outlined shrink-0 text-lg text-on-surface-subtle" aria-hidden="true">chevron_right</span>
                    </div>
                </button>
                <p v-if="!surveys.length" class="rounded-xl border border-dashed border-surface-container-highest p-3.5 text-center text-xs text-on-surface-variant">Hiện chưa có khảo sát nào đang mở.</p>
            </section>

            <!-- Khảo sát đã gửi -->
            <section v-if="pastSurveys.length" class="space-y-2 pt-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Khảo sát đã gửi</h3>
                <div v-for="ps in pastSurveys" :key="ps.id" class="space-y-1.5 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3 text-xs shadow-2xs">
                    <div class="flex items-center justify-between">
                        <strong class="text-on-surface">{{ ps.title }}</strong>
                        <div class="flex items-center gap-1.5">
                            <UiBadge color="success" pill :dot="false">{{ ps.rating }} ★</UiBadge>
                            <UiForm :action="route('portal.student.survey.destroy', ps.id)" method="delete" confirm="Xóa bài khảo sát này?" confirm-label="Xóa" danger>
                                <UiButton type="submit" variant="ghost" size="sm" icon="delete" title="Xóa khảo sát" aria-label="Xóa khảo sát" />
                            </UiForm>
                        </div>
                    </div>
                    <p class="text-xs italic text-on-surface-variant">"{{ ps.feedback }}"</p>
                    <span class="block font-mono text-xs text-on-surface-subtle">{{ ps.submitted_at }}</span>
                </div>
            </section>
        </div>

        <!-- Phản hồi khảo sát: bấm một khảo sát ở danh sách → hộp thoại -->
        <UiModal :show="responseOpen" title="Phản hồi khảo sát" max-width="md" @close="responseOpen = false">
            <p class="mb-3 text-xs text-on-surface-variant">Đang phản hồi cho: <span class="font-bold text-primary">{{ selectedSurvey }}</span></p>
            <UiForm id="survey-response-form" :action="route('portal.student.survey.store')" method="post" class="space-y-3" reset-on-success @success="responseOpen = false">
                <input type="hidden" name="student_id" :value="student?.id ?? 1" />
                <input type="hidden" name="survey_title" :value="selectedSurvey" />

                <fieldset>
                    <legend class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Mức độ hài lòng chung</legend>
                    <div class="flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2.5">
                        <label v-for="s in 5" :key="s" class="flex flex-1 cursor-pointer flex-col items-center gap-1">
                            <input type="radio" name="rating" :value="s" :checked="s === 5" class="border-outline-variant text-primary focus:ring-primary-container" />
                            <span class="text-xs font-bold text-on-surface-variant">{{ s }} ★</span>
                        </label>
                    </div>
                </fieldset>

                <UiTextarea id="feedback-textarea" name="feedback" label="Ý KIẾN CỦA BẠN" :rows="4" required placeholder="Vui lòng nhập chi tiết phản hồi của bạn tại đây..." class="resize-none" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="responseOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="survey-response-form" icon="send">Gửi phản hồi khảo sát</UiButton>
            </template>
        </UiModal>

        <PortalBottomNav active-tab="survey" :student="student" :unread-count="unreadCount" />
    </div>
</template>
