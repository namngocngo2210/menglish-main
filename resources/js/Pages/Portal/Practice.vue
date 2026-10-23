<script setup>
/**
 * Luyện tập (cổng học viên): đề luyện thi theo khối × kỳ sinh từ ngân hàng câu hỏi (docs/luyen-tap-ngan-hang-cau-hoi.md §6.1).
 * 4 thẻ kỳ (số câu, thời gian; kỳ chưa có câu đã duyệt → mờ "Chưa có đề"; có lượt đang làm → "Làm tiếp") và lịch sử
 * các lượt đã nộp. Nhân sự xem cổng (canStart = false) chỉ xem lịch sử.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Luyện tập', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    grade: { type: Number, default: null },
    schoolYear: { type: String, default: '' },
    terms: { type: Array, default: () => [] },
    history: { type: Object, required: true },
    canStart: { type: Boolean, default: false },
});

const params = computed(() => ({ studentId: props.student?.id ?? null }));
const gradeSupported = computed(() => props.grade !== null && props.grade >= 6 && props.grade <= 9);

function durationText(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m} phút ${s} giây` : `${s} giây`;
}

const scoreText = (score) => Number(score).toLocaleString('vi-VN', { maximumFractionDigits: 2 });
</script>

<template>
    <PortalPageHeader title="Luyện tập" icon="quiz" :back="route('portal.student.home', params)" />

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-low pb-24 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="Luyện tập" />

        <div class="w-full flex-1 space-y-4 overflow-y-auto p-4">
            <!-- Khối & năm học -->
            <section class="rounded-2xl bg-gradient-to-r from-primary-container to-warning p-4 text-white shadow-md">
                <template v-if="grade !== null">
                    <h2 class="text-lg font-extrabold">Khối {{ grade }} — năm học {{ schoolYear }}</h2>
                    <p v-if="gradeSupported" class="mt-1 text-xs text-white/90">Đề luyện thi giữa kì / cuối kì theo chương trình lớp {{ grade }}. Mỗi lần làm là một đề mới.</p>
                    <p v-else class="mt-1 text-xs text-white/90">Luyện tập hiện có cho học viên khối 6–9.</p>
                </template>
                <template v-else>
                    <h2 class="text-lg font-extrabold">Chưa xác định khối</h2>
                    <p class="mt-1 text-xs text-white/90">Hồ sơ học viên chưa có ngày sinh. Vui lòng liên hệ trung tâm để cập nhật, sau đó bạn có thể luyện tập.</p>
                </template>
            </section>

            <UiAlert v-if="student && gradeSupported && !canStart" type="info">Bạn đang xem cổng của học viên — chỉ xem lịch sử, không bắt đầu bài.</UiAlert>
            <UiEmptyState v-if="!student" icon="person_search" title="Chưa có học viên" description="Không có học viên nào trong phạm vi bạn được xem." />

            <!-- 4 kỳ -->
            <section v-if="student" aria-label="Chọn kỳ luyện tập" class="grid grid-cols-2 gap-3">
                <div
                    v-for="term in terms"
                    :key="term.key"
                    :class="['flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3.5 shadow-2xs', term.available ? '' : 'opacity-60']"
                >
                    <div>
                        <h3 class="text-sm font-bold text-on-surface">{{ term.label }}</h3>
                        <p v-if="term.questionCount" class="mt-0.5 text-xs text-on-surface-variant">{{ term.questionCount }} câu · {{ term.duration }} phút</p>
                    </div>
                    <template v-if="term.available">
                        <template v-if="canStart">
                            <UiButton v-if="term.inProgressAttemptId" icon="play_arrow" size="sm" class="w-full justify-center" :href="route('portal.student.practice.attempt', term.inProgressAttemptId)">Làm tiếp</UiButton>
                            <UiForm v-else :action="route('portal.student.practice.start')" method="post">
                                <input type="hidden" name="student_id" :value="student.id" />
                                <input type="hidden" name="term" :value="term.key" />
                                <UiButton type="submit" icon="edit_note" size="sm" class="w-full justify-center">Bắt đầu</UiButton>
                            </UiForm>
                        </template>
                    </template>
                    <span v-else class="text-xs font-semibold text-on-surface-subtle">Chưa có đề</span>
                </div>
            </section>

            <!-- Lịch sử -->
            <section v-if="student" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <h2 class="text-sm font-bold text-on-surface">Lịch sử luyện tập</h2>
                <div class="space-y-2">
                    <Link
                        v-for="row in history.data"
                        :key="row.id"
                        :href="route('portal.student.practice.attempt', row.id)"
                        class="flex items-center justify-between gap-3 rounded-xl border border-surface-container-highest/80 bg-surface-container-low p-3 transition hover:bg-surface-container"
                    >
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-on-surface">{{ row.term_label }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-on-surface-variant">
                                <span>{{ formatDate(row.started_at, 'd/m/Y H:i') }}</span>
                                <span aria-hidden="true">•</span>
                                <span>{{ durationText(row.duration_seconds) }}</span>
                            </p>
                            <div v-if="row.submit_reason" class="mt-1">
                                <UiBadge v-if="row.submit_reason === 'violations'" color="error" pill>Vi phạm</UiBadge>
                                <UiBadge v-else color="warning" pill>Tự nộp</UiBadge>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="text-xl font-extrabold text-primary">{{ scoreText(row.score) }}</span>
                            <span class="text-xs text-on-surface-variant">/10</span>
                        </div>
                    </Link>
                    <UiEmptyState v-if="!history.data.length" icon="quiz" title="Chưa có lượt luyện tập" description="Chọn một kỳ ở trên để bắt đầu làm đề." />
                </div>
                <UiPagination :paginator="history" unit="lượt" :options="[]" />
            </section>
        </div>

        <PortalBottomNav active-tab="practice" :student="student" :unread-count="unreadCount" />
    </div>
</template>
