<script setup>
/**
 * Học tập của tôi — Nộp bài tập (MH #3): bài tập GV giao nộp theo 3 giỏ (giấy = ảnh, video = video, Quizizz = bấm "Đã làm" —
 * docs/kho-syllabus.md §10). Mỗi thẻ là BTVN của một buổi (lớp, Buổi N / bài syllabus, ngày học, hạn nộp) — không còn nộp tự do.
 * Nhận xét buổi học (kèm phụ huynh chấm từng buổi), bảng điểm, lộ trình.
 * Nhân sự xem hộ thì quay về danh sách học viên; học viên quay về trang chủ cổng. Trên điện thoại dùng thanh điều hướng đáy.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import SessionRating from './SessionRating.vue';

defineOptions({ layout: { title: 'Học tập & Nộp bài tập', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    basketProgress: { type: Object, default: () => ({ submitted: 0, total: 0 }) },
    remarks: { type: Array, default: () => [] },
    feedbackCategories: { type: Object, default: () => ({}) },
    miniTests: { type: Array, default: () => [] },
    bigTestResults: { type: Array, default: () => [] },
    assignments: { type: Array, default: () => [] },
});

const REMARK_FIELDS = {
    monsters: ['Monsters', 'hotel_class', 'text-warning'],
    grammar: ['Thực hành', 'psychology', 'text-secondary'],
    hw_score: ['Điểm BTVN', 'assignment_turned_in', 'text-primary'],
    attitude: ['Tinh thần', 'mood', 'text-tertiary'],
    result: ['Kết quả', 'checklist', 'text-accent'],
};

const progressPercent = computed(() => (props.basketProgress.total ? Math.round((props.basketProgress.submitted / props.basketProgress.total) * 100) : 0));
const dueLabel = (hw) => (hw.due_at ? formatDate(hw.due_at, 'd/m/Y H:i') : hw.due_date ? formatDate(hw.due_date) : 'Không giới hạn');
const params = computed(() => ({ studentId: props.student?.id ?? null }));
const backUrl = computed(() => (can('student.view') ? route('students.index') : route('portal.student.home', params.value)));
const studentOptions = computed(() => props.students.map((s) => ({ value: s.id, label: `${s.name} (${s.class_name ?? 'Chưa xếp lớp'})` })));
const filled = (value) => value !== null && value !== undefined && String(value).trim() !== '';

// Nộp theo giỏ của bài tập GV giao.
const basketOpen = ref(null);
function openBasket(hw, basket) {
    basketOpen.value = { hw, basket };
    fileError.value = null;
}
// Máy chủ nhận tối đa 100MB / lần nộp: báo ngay trên máy thay vì để lỗi tải lên.
const MAX_UPLOAD = 100 * 1024 * 1024;
const fileError = ref(null);
function checkSize(event) {
    const file = event.target.files?.[0];
    fileError.value = file && file.size > MAX_UPLOAD ? 'Tệp quá lớn (tối đa 100MB) — quay video ngắn hơn hoặc giảm độ phân giải.' : null;
    if (fileError.value) event.target.value = '';
}

function switchStudent(event) {
    router.visit(route('portal.student.homework', { studentId: event.target.value }));
}
</script>

<template>
    <PortalPageHeader title="Học tập & Nộp bài tập" icon="upload_file" :back="backUrl">
        <template v-if="can('homework.grade')" #actions>
            <UiButton variant="secondary" icon="video_library" :href="route('portal.teacher.submissions')">Cổng GV: Xem bài nộp của lớp</UiButton>
        </template>
    </PortalPageHeader>

    <div class="mx-auto max-w-4xl space-y-6">
        <!-- Đổi học viên (phụ huynh nhiều con / nhân sự xem hộ) -->
        <div class="flex flex-col justify-between gap-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm sm:flex-row sm:items-center">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-container/10 font-bold text-primary">
                    <span class="material-symbols-outlined">account_circle</span>
                </div>
                <div>
                    <span class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Tài khoản học viên:</span>
                    <strong class="text-sm text-on-surface">{{ student?.name ?? '—' }}</strong>
                    <span class="font-mono text-xs text-on-surface-variant"> (<UiCode :value="student?.code" />)</span>
                </div>
            </div>

            <UiSelect inline-label="Đổi học viên:" :options="studentOptions" :value="student?.id" @change="switchStudent" />
        </div>

        <!-- Khung điện thoại -->
        <div class="mx-auto max-w-[420px] overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest pb-8 shadow-xl md:max-w-none md:shadow-sm">
            <div class="relative flex h-[140px] w-full flex-col justify-end bg-gradient-to-br from-primary-container to-primary-container/60 p-6 text-white">
                <div class="absolute right-3 top-3 rounded-full bg-surface-container-lowest/20 px-2.5 py-1 text-xs font-bold uppercase tracking-wider backdrop-blur-xs">MENGLISH LMS</div>
                <h2 class="text-2xl font-bold tracking-tight text-white drop-shadow-sm">Học tập của tôi</h2>
                <p class="mt-0.5 text-xs text-white/80">Lớp: {{ student?.class_name ?? 'Chưa xếp lớp' }}</p>
            </div>

            <!-- Nộp bài tập / Luyện phát âm -->
            <div class="flex items-center border-b border-surface-container-highest bg-surface-container-low px-3 pt-2">
                <Link :href="route('portal.student.homework', params)" class="flex items-center gap-1.5 border-b-2 border-primary-container px-4 py-2 text-xs font-bold text-primary">
                    <span class="material-symbols-outlined text-[16px]">assignment</span>
                    <span>Nộp bài tập</span>
                </Link>
                <Link :href="route('portal.student.pronunciation', params)" class="flex items-center gap-1.5 border-b-2 border-transparent px-4 py-2 text-xs font-semibold text-on-surface-variant transition hover:text-on-surface">
                    <span class="material-symbols-outlined text-[16px]">mic</span>
                    <span>Luyện phát âm AI</span>
                </Link>
            </div>

            <div class="mt-4 flex flex-col gap-6 px-4 pb-20 md:px-6 md:pb-4">
                <!-- 1. BÀI TẬP VỀ NHÀ — học viên vào trang để nộp bài nên đặt lên đầu -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">assignment</span>
                        <h2 class="text-base font-bold text-on-surface">Bài tập về nhà</h2>
                    </div>

                    <div v-if="basketProgress.total" class="mt-1 flex items-center justify-between" data-testid="homework-progress">
                        <p class="text-xs font-bold text-on-surface">Đã hoàn thành: {{ basketProgress.submitted }}/{{ basketProgress.total }} phần bài tập</p>
                        <div class="h-2 w-1/3 overflow-hidden rounded-full bg-surface-container">
                            <div class="h-full rounded-full bg-tertiary transition-all duration-300" :style="{ width: progressPercent + '%' }"></div>
                        </div>
                    </div>

                    <!-- BTVN GV giao theo từng buổi — nộp đúng giỏ theo loại để thống kê -->
                    <div v-for="hw in assignments" :key="hw.id" class="flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs" data-testid="assigned-homework">
                        <div>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs text-on-surface-variant" data-testid="homework-session">
                                <span class="font-bold text-primary">{{ hw.class_name }}</span>
                                <template v-if="hw.session">
                                    <span aria-hidden="true">·</span>
                                    <span class="font-bold text-on-surface">{{ hw.session.label }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>Ngày học {{ formatDate(hw.session.date) }}</span>
                                </template>
                                <UiBadge v-else color="neutral" pill>Không gắn buổi</UiBadge>
                            </div>
                            <p v-if="hw.session?.lesson" class="mt-0.5 text-xs text-on-surface-variant">{{ hw.session.lesson }}</p>
                            <h3 class="mt-1 text-sm font-bold text-on-surface">{{ hw.title }}</h3>
                            <p class="text-xs text-on-surface-variant">Hạn nộp: {{ dueLabel(hw) }}</p>
                            <p v-if="hw.note" class="mt-1 text-xs italic text-on-surface-variant">{{ hw.note }}</p>
                            <p v-if="hw.description" class="mt-1 whitespace-pre-line text-xs text-on-surface-variant">{{ hw.description }}</p>
                            <a v-if="hw.youtube_url" :href="hw.youtube_url" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 text-xs font-bold text-secondary hover:underline">Video hướng dẫn <span class="material-symbols-outlined text-[14px]" aria-hidden="true">open_in_new</span></a>
                            <p v-if="!hw.baskets.length" class="mt-1 text-xs text-on-surface-subtle">Bài này không cần nộp trên cổng.</p>
                        </div>
                        <div v-for="b in hw.baskets" :key="b.key" class="rounded-xl border border-surface-container-highest bg-surface-container-low p-2.5 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-1 font-bold text-on-surface"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ b.icon }}</span>{{ b.label }}</span>
                                <UiBadge v-if="b.submission?.status === 'reviewed'" color="success" pill>{{ b.submission.score ? 'Điểm: ' + b.submission.score : 'GV đã xem' }}</UiBadge>
                                <UiBadge v-else-if="b.submission" color="info" pill>{{ b.upload ? 'Đã nộp' : 'Đã làm' }}</UiBadge>
                                <UiBadge v-else color="error" pill>Chưa nộp</UiBadge>
                            </div>
                            <p v-if="b.text" class="mt-1 whitespace-pre-line text-on-surface-variant">{{ b.text }}</p>
                            <p v-if="b.submission?.feedback" class="mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1 text-on-surface"><strong>GV nhận xét:</strong> {{ b.submission.feedback }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <a v-if="b.link" :href="b.link" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-bold text-secondary hover:underline">Mở Quizizz <span class="material-symbols-outlined text-[14px]" aria-hidden="true">open_in_new</span></a>
                                <a v-if="b.submission?.file_url" :href="b.submission.file_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-bold text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">attachment</span>{{ b.submission.original_name ?? 'Bài đã nộp' }}</a>
                                <UiButton v-if="b.upload" size="sm" :variant="b.submission ? 'secondary' : 'primary'" icon="upload" class="ml-auto" @click="openBasket(hw, b)">{{ b.submission ? 'Nộp lại' : 'Nộp bài' }}</UiButton>
                                <UiForm v-else-if="!b.submission" :action="route('portal.student.homework.basket', [hw.id, b.key])" method="post" class="ml-auto">
                                    <input type="hidden" name="student_id" :value="student?.id" />
                                    <UiButton type="submit" size="sm" icon="task_alt">Đã làm</UiButton>
                                </UiForm>
                            </div>
                        </div>
                    </div>

                    <UiEmptyState v-if="!assignments.length" class="rounded-2xl border border-dashed border-surface-container-highest bg-surface-container-low" icon="assignment" title="Chưa có bài tập về nhà được giao" description="Bài tập giáo viên giao theo từng buổi học sẽ hiện ở đây để nộp." />
                </section>

                <!-- 2. NHẬN XÉT BUỔI HỌC -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">insights</span>
                        <h2 class="text-base font-bold text-on-surface">Nhận xét buổi học</h2>
                    </div>

                    <div v-for="item in remarks" :key="item.session_id" class="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs" :data-portal-session="item.session_id">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-bold text-on-surface">{{ item.label }}</p>
                                <p class="text-xs text-on-surface-subtle">{{ item.class_name }}<template v-if="item.teacher"> · GV {{ item.teacher }}</template></p>
                            </div>
                            <UiBadge v-if="item.remark" color="success" pill>Đã nhận xét</UiBadge>
                            <UiBadge v-else color="neutral" pill>Chưa có nhận xét</UiBadge>
                        </div>
                        <template v-if="item.remark">
                            <div class="grid grid-cols-2 gap-2">
                                <template v-for="(field, fieldKey) in REMARK_FIELDS" :key="fieldKey">
                                    <div v-if="filled(item.remark[fieldKey])" class="flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-2.5">
                                        <span :class="['material-symbols-outlined text-lg', field[2]]">{{ field[1] }}</span>
                                        <div>
                                            <p class="text-xs font-medium text-on-surface-subtle">{{ field[0] }}</p>
                                            <p class="text-xs font-bold text-on-surface">{{ item.remark[fieldKey] }}</p>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <div v-if="filled(item.remark.comment)" class="border-t border-surface-container-highest pt-3">
                                <p class="mb-1 flex items-center gap-1 text-xs font-bold text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[15px] text-primary">edit_note</span>
                                    Nhận xét chi tiết từ giáo viên
                                </p>
                                <p class="rounded-xl border border-primary-container/30 bg-primary-container/10 p-2.5 text-xs leading-relaxed text-on-surface-variant">{{ item.remark.comment }}</p>
                            </div>
                        </template>
                        <p v-else class="text-xs text-on-surface-subtle">Giáo viên chưa gửi nhận xét cho buổi này.</p>
                        <SessionRating v-if="student && (item.can_rate || item.feedback || item.absent)" :student-id="student.id" :card="item" :categories="feedbackCategories" />
                    </div>
                    <UiEmptyState v-if="!remarks.length" class="rounded-2xl border border-dashed border-surface-container-highest bg-surface-container-low" icon="insights" title="Chưa có buổi học nào gần đây." />
                </section>

                <!-- 3. BẢNG ĐIỂM -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">school</span>
                        <h2 class="text-base font-bold text-on-surface">Bảng điểm</h2>
                    </div>
                    <div class="flex flex-col gap-2">
                        <div v-for="mt in miniTests" :key="'mt' + mt.id" class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs">
                            <div>
                                <h4 class="text-xs font-bold text-on-surface">{{ mt.name }}</h4>
                                <p class="font-mono text-xs text-on-surface-subtle">Ngày thi: {{ mt.test_date }}</p>
                            </div>
                            <div class="font-mono text-lg font-black text-primary">{{ mt.score }}<span class="text-xs text-on-surface-subtle">/{{ mt.max_score }}</span></div>
                        </div>
                        <div v-for="bt in bigTestResults" :key="'bt' + bt.id" class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs">
                            <div>
                                <h4 class="text-xs font-bold text-on-surface">{{ bt.title }}</h4>
                                <p class="font-mono text-xs text-on-surface-subtle">Ngày thi: {{ bt.scheduled_at }}</p>
                            </div>
                            <UiBadge v-if="bt.is_absent" color="neutral" pill>Vắng thi</UiBadge>
                            <div v-else class="font-mono text-lg font-black text-primary">{{ bt.overall_score }}</div>
                        </div>
                        <UiEmptyState v-if="!miniTests.length && !bigTestResults.length" class="rounded-xl border border-dashed border-surface-container-highest bg-surface-container-low" icon="school" title="Chưa có điểm kiểm tra nào." />
                    </div>
                </section>

                <!-- 4. LỘ TRÌNH HỌC TẬP -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xl text-primary">map</span>
                        <h2 class="text-base font-bold text-on-surface">Lộ trình học tập</h2>
                    </div>
                    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-2xs">
                        <div class="flex flex-col items-center space-y-2 rounded-xl bg-surface-container-low p-4 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-container/10 text-primary">
                                <span class="material-symbols-outlined text-[28px]">trending_up</span>
                            </div>
                            <h3 class="text-xs font-bold text-on-surface">Lộ trình mục tiêu: IELTS 6.5</h3>
                            <p class="max-w-[280px] text-xs text-on-surface-variant">Đang học Chặng 2 (Intermediate). Đạt 65% thời lượng chương trình.</p>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-container-high">
                                <div class="h-full rounded-full bg-primary-container" style="width: 65%"></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <PortalBottomNav active-tab="learning" :student="student" :unread-count="unreadCount" />
        </div>

        <!-- Nộp theo giỏ của bài tập GV giao -->
        <UiModal :show="!!basketOpen" :title="basketOpen ? `${basketOpen.basket.label} — ${basketOpen.hw.session ? `${basketOpen.hw.session.label} (${formatDate(basketOpen.hw.session.date)})` : basketOpen.hw.title}` : ''" max-width="md" @close="basketOpen = null">
            <UiForm v-if="basketOpen" id="homework-basket-form" :key="`${basketOpen.hw.id}-${basketOpen.basket.key}`" :action="route('portal.student.homework.basket', [basketOpen.hw.id, basketOpen.basket.key])" method="post" class="space-y-4 text-xs" @success="basketOpen = null">
                <input type="hidden" name="student_id" :value="student?.id" />
                <UiField :label="basketOpen.basket.key === 'video' ? 'Chọn video bài làm' : 'Chụp / chọn ảnh bài làm'" name="file" for="homework-basket-file" :error="fileError">
                    <input id="homework-basket-file" type="file" name="file" :accept="basketOpen.basket.accept" @change="checkSize" class="w-full rounded-lg border border-outline-variant bg-surface-container-low p-2 text-xs" />
                </UiField>
                <UiTextarea name="note" label="Ghi chú gửi thầy cô (Tùy chọn)" :rows="2" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="basketOpen = null">Hủy</UiButton>
                <UiButton type="submit" form="homework-basket-form" icon="send">Nộp bài</UiButton>
            </template>
        </UiModal>
    </div>
</template>
