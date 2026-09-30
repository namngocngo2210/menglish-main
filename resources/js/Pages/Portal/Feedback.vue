<script setup>
/**
 * Phụ huynh gửi feedback chặng học (MH #7): mức hài lòng 1–5 sao, lĩnh vực góp ý, nội dung — cần ít nhất 1 mục (R-04).
 * Đã gửi thì sửa / cập nhật được, hoặc xóa để nhập lại. Có sẵn giao diện "đợt đã đóng" (chỉ xem) và "chưa có đợt nào mở".
 */
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Góp ý chặng học', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    lastFeedback: { type: Object, default: null },
    stageName: { type: String, default: '' },
    className: { type: String, default: '' },
    showSuccess: { type: Boolean, default: false },
    feedbackSuccess: { type: Boolean, default: false },
});

const RATING_LABELS = ['Chưa chọn', 'Rất không hài lòng', 'Không hài lòng', 'Bình thường', 'Hài lòng', 'Rất hài lòng'];
const params = computed(() => ({ studentId: props.student?.id ?? null }));
const hasSaved = computed(() => !!props.lastFeedback);
// 'form-new' | 'form-updated' | 'state-closed' | 'state-empty'
const viewState = computed(() => (hasSaved.value ? 'form-updated' : 'form-new'));
const savedRating = computed(() => props.lastFeedback?.rating ?? 0);
const savedContent = computed(() => props.lastFeedback?.content ?? '');

const rating = ref(0);
const fbHocThuat = ref(false);
const fbGiaoVien = ref(false);
const fbKhac = ref(false);
const noiDung = ref('');
const showValidationError = ref(false);

function loadSaved() {
    rating.value = savedRating.value;
    fbHocThuat.value = !!props.lastFeedback?.fb_hoc_thuat;
    fbGiaoVien.value = !!props.lastFeedback?.fb_giao_vien;
    fbKhac.value = !!props.lastFeedback?.fb_khac;
    noiDung.value = savedContent.value;
}
loadSaved();
watch(() => props.lastFeedback, loadSaved);

function setRating(stars) {
    rating.value = stars;
    showValidationError.value = false;
}

/** Chặn gửi khi cả 3 mục đều trống (R-04) — chạy trước khi form gửi. */
function validateForm(event) {
    const hasRating = rating.value > 0;
    const hasCat = fbHocThuat.value || fbGiaoVien.value || fbKhac.value;
    const hasText = noiDung.value.trim().length > 0;
    if (!hasRating && !hasCat && !hasText) {
        event.preventDefault();
        event.stopPropagation();
        showValidationError.value = true;
        return;
    }
    showValidationError.value = false;
}
</script>

<template>
    <PortalPageHeader title="Góp ý chặng học" icon="rate_review" :back="route('portal.student.survey', params)">
        <template #actions>
            <UiButton variant="secondary" icon="assignment" :href="route('portal.student.survey', params)">Khảo sát định kỳ</UiButton>
        </template>
    </PortalPageHeader>

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-24 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="Feedback chặng" show-back :back-url="route('portal.student.survey', params)" />

        <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
            <Link :href="route('portal.student.survey', params)" class="flex items-center gap-1.5 border-b-2 border-transparent px-4 py-2 text-xs font-semibold text-on-surface-variant transition hover:text-on-surface">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Khảo sát định kỳ</span>
            </Link>
            <Link :href="route('portal.student.feedback', params)" class="flex items-center gap-1.5 border-b-2 border-primary-container px-4 py-2 text-xs font-bold text-primary">
                <span class="material-symbols-outlined text-[16px]">rate_review</span>
                <span>Feedback chặng học</span>
            </Link>
        </div>

        <div class="flex items-center justify-between border-b border-surface-container-highest px-4 pb-2 pt-3">
            <div>
                <UiBadge color="primary" pill :dot="false" class="mb-0.5 uppercase tracking-wider">Ý kiến đóng góp</UiBadge>
                <h2 class="text-base font-bold text-on-surface">Đánh giá chặng học</h2>
            </div>
            <div class="text-right">
                <span class="block text-xs font-medium text-on-surface-subtle">Học sinh</span>
                <span class="text-xs font-bold text-on-surface">{{ student?.name ?? '—' }}</span>
            </div>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto p-4">
            <!-- VIEW 1: FORM NHẬP / CHỈNH SỬA (lần đầu & đã gửi sửa tiếp) -->
            <div v-show="viewState === 'form-new' || viewState === 'form-updated'" class="space-y-4">
                <UiAlert v-if="showSuccess" type="success" title="Đã ghi nhận feedback thành công">
                    Bạn có thể điều chỉnh và bấm "Cập nhật" bất cứ lúc nào trong thời gian đợt thu thập còn mở.
                </UiAlert>

                <UiAlert v-show="viewState === 'form-updated' && !feedbackSuccess" type="info" title="Bạn đã gửi đánh giá trước đó">
                    Bạn có thể thay đổi số sao hoặc nội dung góp ý bên dưới rồi bấm "Cập nhật feedback".
                </UiAlert>

                <!-- Tên chặng đang mở thu thập (chỉ đọc, R-02) -->
                <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low p-3.5 shadow-2xs">
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Chặng học đang mở thu thập</span>
                        <UiBadge color="success" pill>Đang mở thu thập</UiBadge>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-container/10 text-sm font-black text-primary">C2</div>
                            <div>
                                <h3 class="text-xs font-bold leading-tight text-on-surface">{{ stageName }}</h3>
                                <p class="mt-0.5 text-xs text-on-surface-variant">Lớp: <span class="font-bold text-on-surface">{{ className }}</span></p>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-[18px] text-on-surface-subtle" title="Cố định theo chặng đang mở của lớp">lock</span>
                    </div>
                </div>

                <div @submit.capture="validateForm">
                    <UiForm :action="route('portal.student.feedback.store')" method="post" class="space-y-4">
                        <input type="hidden" name="student_id" :value="student?.id ?? 1" />
                        <input type="hidden" name="stage_name" :value="stageName" />
                        <input type="hidden" name="muc_do_hai_long" :value="rating" />

                        <!-- 1. Mức hài lòng 1–5 sao (R-04) -->
                        <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                            <div class="mb-2 flex items-center justify-between">
                                <label class="flex items-center gap-1 text-xs font-bold text-on-surface">
                                    <span>1. Mức độ hài lòng chung</span>
                                    <span class="text-xs font-normal text-on-surface-subtle">(Tùy chọn)</span>
                                </label>
                                <span class="text-xs font-bold text-primary">{{ RATING_LABELS[rating] }}</span>
                            </div>

                            <div class="flex items-center justify-between px-1 py-1">
                                <button v-for="star in 5" :key="star" type="button" class="group flex flex-col items-center gap-1 rounded-xl p-2 transition hover:bg-primary-container/10 focus:outline-none active:scale-95" @click="setRating(star)">
                                    <span :class="['material-symbols-outlined text-3xl transition-transform group-hover:scale-110', star <= rating ? 'text-warning/70' : 'text-on-surface-subtle']" :style="star <= rating ? { fontVariationSettings: `'FILL' 1` } : null">star</span>
                                    <span :class="['font-mono text-xs font-bold', star <= rating ? 'text-warning' : 'text-on-surface-subtle']">{{ star }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- 2. Lĩnh vực cần góp ý (R-04) -->
                        <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                            <label class="mb-2.5 flex items-center justify-between text-xs font-bold text-on-surface">
                                <span>2. Lĩnh vực cần góp ý</span>
                                <span class="text-xs font-normal text-on-surface-subtle">(Tùy chọn)</span>
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <label :class="['flex cursor-pointer flex-col items-center justify-center rounded-xl border p-2.5 transition-all', fbHocThuat ? 'border-primary-container bg-primary-container/10 shadow-2xs' : 'border-surface-container-highest hover:border-outline-variant']">
                                    <input v-model="fbHocThuat" type="checkbox" name="fb_hoc_thuat" value="1" class="mb-1 rounded border-outline-variant text-primary focus:ring-primary-container" @change="showValidationError = false" />
                                    <span class="select-none text-xs font-semibold text-on-surface-variant">Học thuật</span>
                                </label>
                                <label :class="['flex cursor-pointer flex-col items-center justify-center rounded-xl border p-2.5 transition-all', fbGiaoVien ? 'border-primary-container bg-primary-container/10 shadow-2xs' : 'border-surface-container-highest hover:border-outline-variant']">
                                    <input v-model="fbGiaoVien" type="checkbox" name="fb_giao_vien" value="1" class="mb-1 rounded border-outline-variant text-primary focus:ring-primary-container" @change="showValidationError = false" />
                                    <span class="select-none text-xs font-semibold text-on-surface-variant">Giáo viên</span>
                                </label>
                                <label :class="['flex cursor-pointer flex-col items-center justify-center rounded-xl border p-2.5 transition-all', fbKhac ? 'border-primary-container bg-primary-container/10 shadow-2xs' : 'border-surface-container-highest hover:border-outline-variant']">
                                    <input v-model="fbKhac" type="checkbox" name="fb_khac" value="1" class="mb-1 rounded border-outline-variant text-primary focus:ring-primary-container" @change="showValidationError = false" />
                                    <span class="select-none text-xs font-semibold text-on-surface-variant">Khác</span>
                                </label>
                            </div>
                        </div>

                        <!-- 3. Nội dung feedback chi tiết (R-04) -->
                        <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                            <div class="mb-2 flex items-center justify-between">
                                <label for="feedback-content" class="text-xs font-bold text-on-surface">3. Nội dung feedback chi tiết</label>
                                <span class="text-xs text-on-surface-subtle">(Tùy chọn)</span>
                            </div>
                            <UiTextarea id="feedback-content" v-model="noiDung" name="noi_dung_feedback" :rows="4" placeholder="Chia sẻ cảm nhận của phụ huynh/học sinh về giáo trình, phương pháp giảng dạy hoặc điểm cần hỗ trợ thêm..." class="resize-none" @input="showValidationError = false" />
                            <p class="mt-1 text-xs text-on-surface-subtle">Ý kiến chân thực giúp trung tâm nâng cao chất lượng dạy học.</p>
                        </div>

                        <!-- Cả 3 mục đều trống (R-04) -->
                        <UiAlert v-show="showValidationError" type="warning">Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.</UiAlert>

                        <div>
                            <UiButton type="submit" icon="send" class="w-full">
                                <span>{{ viewState === 'form-updated' ? 'Cập nhật feedback' : 'Gửi feedback' }}</span>
                            </UiButton>
                            <p class="mt-2 text-center text-xs text-on-surface-subtle">Sau khi gửi, bạn vẫn có thể chỉnh sửa lại trong thời gian đợt thu thập còn mở.</p>
                        </div>
                    </UiForm>
                </div>

                <div v-if="hasSaved" v-show="viewState === 'form-updated'" class="pt-1">
                    <UiForm :action="route('portal.student.feedback.destroy', { id: lastFeedback.id })" method="delete" confirm="Xóa bản feedback này?" confirm-label="Xóa" danger>
                        <UiButton type="submit" variant="danger-text" icon="delete_sweep" class="w-full">
                            <span>Xóa phản hồi đã lưu &amp; nhập lại</span>
                        </UiButton>
                    </UiForm>
                </div>
            </div>

            <!-- VIEW 2: CHỈ XEM KHI ĐỢT THU THẬP ĐÃ ĐÓNG (AC-R06b) -->
            <div v-show="viewState === 'state-closed'" class="space-y-4">
                <div class="flex items-start gap-3 rounded-2xl border border-outline-variant bg-surface-container p-3.5">
                    <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-outline text-white">
                        <span class="material-symbols-outlined text-[14px]">lock</span>
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-on-surface">Đợt thu thập feedback đã đóng</h4>
                        <p class="mt-0.5 text-xs leading-relaxed text-on-surface-variant">Trung tâm đã kết thúc đợt thu thập ý kiến cho chặng này. Dưới đây là nội dung bạn đã gửi (chỉ xem, không thể chỉnh sửa thêm).</p>
                    </div>
                </div>

                <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low p-4 shadow-2xs">
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Chặng học đã hoàn thành</span>
                        <UiBadge color="neutral" pill>Đã đóng</UiBadge>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-surface-container-high text-sm font-bold text-on-surface-variant">C1</div>
                        <div>
                            <h3 class="text-xs font-bold leading-tight text-on-surface">Chặng 1: Nền tảng Ngữ pháp &amp; Từ vựng</h3>
                            <p class="mt-0.5 text-xs text-on-surface-variant">Lớp: {{ className }}</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-3.5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                    <div class="border-b border-surface-container-highest pb-3">
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Mức độ hài lòng đã gửi</span>
                        <div class="flex items-center gap-2">
                            <div class="flex text-warning/70">
                                <span v-for="star in 5" :key="star" :class="['material-symbols-outlined text-[20px]', star <= savedRating ? 'text-warning/70' : 'text-surface-container-highest']" :style="star <= savedRating ? { fontVariationSettings: `'FILL' 1` } : null">star</span>
                            </div>
                            <span v-if="savedRating > 0" class="text-xs font-bold text-on-surface">{{ savedRating }} / 5</span>
                            <span class="text-xs font-medium text-on-surface-variant">({{ RATING_LABELS[savedRating] ?? 'Chưa chọn' }})</span>
                        </div>
                    </div>

                    <div class="border-b border-surface-container-highest pb-3">
                        <span class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Lĩnh vực đã chọn</span>
                        <div class="flex flex-wrap gap-2">
                            <UiBadge v-if="lastFeedback?.fb_hoc_thuat" color="secondary" :dot="false">✓ Học thuật</UiBadge>
                            <UiBadge v-if="lastFeedback?.fb_giao_vien" color="secondary" :dot="false">✓ Giáo viên</UiBadge>
                            <UiBadge v-if="lastFeedback?.fb_khac" color="secondary" :dot="false">✓ Khác</UiBadge>
                            <span v-if="!lastFeedback?.fb_hoc_thuat && !lastFeedback?.fb_giao_vien && !lastFeedback?.fb_khac" class="text-xs text-on-surface-variant">Không chọn lĩnh vực</span>
                        </div>
                    </div>

                    <div>
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Nội dung đã gửi</span>
                        <p class="rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-xs font-normal leading-relaxed text-on-surface">{{ savedContent.trim() ? savedContent : 'Không có nội dung chi tiết.' }}</p>
                    </div>
                </div>

                <div class="py-2 text-center text-xs text-on-surface-subtle">Cảm ơn bạn đã đóng góp ý kiến xây dựng chất lượng đào tạo.</div>
            </div>

            <!-- VIEW 3: TRẠNG THÁI RỖNG (chưa có đợt thu thập nào mở - R-02) -->
            <div v-show="viewState === 'state-empty'" class="flex flex-col items-center justify-center px-4 py-10 text-center">
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full border border-primary-container/30 bg-primary-container/10 text-primary shadow-inner">
                    <span class="material-symbols-outlined text-3xl">chat_bubble_outline</span>
                </div>
                <h3 class="mb-1 text-sm font-bold text-on-surface">Hiện chưa có đợt thu thập nào đang mở</h3>
                <p class="mb-6 max-w-[280px] text-xs leading-relaxed text-on-surface-variant">Giáo viên và Ban Học vụ sẽ mở cổng tiếp nhận đánh giá khi hoàn thành từng chặng của khóa học.</p>
                <div class="w-full rounded-2xl border border-surface-container-highest bg-surface-container-low p-3.5 text-left shadow-2xs">
                    <div class="mb-1 flex items-center gap-1.5 text-xs font-bold text-on-surface">
                        <span class="material-symbols-outlined text-[16px] text-primary">info</span>
                        <span>Lưu ý từ Trung tâm</span>
                    </div>
                    <p class="text-xs leading-relaxed text-on-surface-variant">Nếu phụ huynh hoặc học sinh cần phản ánh khẩn cấp về việc học tập, vui lòng liên hệ trực tiếp với Cố vấn Học tập (CM) qua mục Thông báo hoặc hotline trung tâm.</p>
                </div>
            </div>
        </div>

        <PortalBottomNav active-tab="survey" :student="student" :unread-count="unreadCount" />
    </div>
</template>
