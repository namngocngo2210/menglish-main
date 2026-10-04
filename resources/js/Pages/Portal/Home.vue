<script setup>
/**
 * Trang chủ cổng học viên (MH #2): không có nút quay lại; tiêu đề trang là lời chào (h1) trong nội dung.
 * Trên điện thoại: thanh điều hướng đáy là điều hướng chính, ẩn dải tab.
 * Thứ tự khối: Lịch học → Bài tập (truy cập nhanh) → Tiến độ → Điểm danh / Big Test → Học phí → Thông tin học sinh.
 * Hộp thoại: Lịch sử thu học phí, Báo đóng học phí, Sửa thông tin học viên.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import WorkspaceTabs from '@/Layouts/Shell/WorkspaceTabs.vue';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Trang chủ', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    totalPaid: { type: Number, default: 0 },
    debtAmount: { type: Number, default: 0 },
    nextTermFee: { type: Number, default: 0 },
    receipts: { type: Array, default: () => [] },
    learningProgress: { type: Object, required: true },
    upcomingDays: { type: Number, default: 14 },
    upcomingSessions: { type: Array, default: () => [] },
    attendanceHistory: { type: Array, default: () => [] },
    bigTestResults: { type: Array, default: () => [] },
    studentClasses: { type: Array, default: () => [] },
});

const params = computed(() => ({ studentId: props.student?.id ?? null }));
const historyOpen = ref(false);
const requestOpen = ref(false);
const profileOpen = ref(false);

function sessionBadge(s) {
    if (s.cancelled) return ['neutral', 'Nghỉ'];
    if (s.type === 'makeup') return ['secondary', 'Học bù'];
    if (s.type === 'support') return ['secondary', 'Phụ đạo'];
    return [s.type === 'regular' ? 'success' : 'secondary', 'Buổi học'];
}
</script>

<template>
    <Head title="Trang chủ" />
    <WorkspaceTabs class="hidden md:block" />

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-background pb-20 shadow-2xl md:min-h-0 md:max-w-6xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="MENGLISH" />

        <div class="flex w-full flex-1 flex-col gap-5 overflow-y-auto p-4 md:grid md:grid-cols-2 md:items-start md:gap-6 md:p-6">
            <!-- Lời chào -->
            <div class="flex flex-col gap-1 pt-1 md:col-span-2">
                <span class="text-sm font-normal text-on-surface-variant">Xin chào,</span>
                <h1 class="text-2xl font-bold text-primary">{{ student?.name ?? 'Học viên' }}</h1>
            </div>

            <!-- Lịch học sắp tới (buổi học thật của các lớp + buổi phụ đạo) -->
            <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm" data-section="upcoming-schedule">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-primary">calendar_month</span>
                    Lịch học sắp tới
                </h2>
                <div class="flex flex-col gap-2">
                    <div v-for="s in upcomingSessions" :key="s.id" :class="['flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-xs', s.cancelled ? 'border-surface-container-highest bg-surface-container-low text-on-surface-subtle' : 'border-surface-container-highest']">
                        <div>
                            <div :class="['font-bold', s.cancelled ? 'line-through' : 'text-on-surface']">{{ s.label }}</div>
                            <div class="text-xs text-on-surface-variant">{{ s.class_name }}<template v-if="s.room_label"> · {{ s.room_label }}</template></div>
                        </div>
                        <UiBadge :color="sessionBadge(s)[0]" pill>{{ sessionBadge(s)[1] }}</UiBadge>
                    </div>
                    <p v-if="!upcomingSessions.length" class="text-xs text-on-surface-variant">Chưa có buổi học nào trong {{ upcomingDays }} ngày tới.</p>
                </div>
            </div>

            <!-- Bài tập: lối vào nhanh Nộp bài tập / Luyện phát âm -->
            <div class="grid grid-cols-2 gap-3 md:col-span-2">
                <Link :href="route('portal.student.homework', params)" class="flex items-center gap-2.5 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-2xs transition hover:border-primary-container">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-container/10 text-primary">
                        <span class="material-symbols-outlined text-[20px]">upload_file</span>
                    </div>
                    <div class="overflow-hidden">
                        <span class="block truncate text-xs font-bold text-on-surface">Nộp bài tập</span>
                        <span class="block text-xs text-on-surface-subtle">Video &amp; bài viết</span>
                    </div>
                </Link>

                <Link :href="route('portal.student.pronunciation', params)" class="flex items-center gap-2.5 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-2xs transition hover:border-primary-container">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-error/10 text-error">
                        <span class="material-symbols-outlined text-[20px]">mic</span>
                    </div>
                    <div class="overflow-hidden">
                        <span class="block truncate text-xs font-bold text-on-surface">Luyện phát âm</span>
                        <span class="block text-xs text-on-surface-subtle">Thu âm AI</span>
                    </div>
                </Link>
            </div>

            <!-- Tiến độ học tập -->
            <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-bold text-on-surface">Tiến độ học tập đã duyệt</h2>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-tertiary/10 p-2"><div class="text-lg font-black text-tertiary">{{ learningProgress.attendance_present }}/{{ learningProgress.attendance_total }}</div><div class="text-xs text-on-surface-variant">Chuyên cần</div></div>
                    <div class="rounded-xl bg-secondary/10 p-2"><div class="text-lg font-black text-secondary">{{ learningProgress.homework_submitted }}/{{ learningProgress.homework_total }}</div><div class="text-xs text-on-surface-variant">Bài tập</div></div>
                    <div class="rounded-xl bg-primary-container/10 p-2"><div class="text-lg font-black text-primary">{{ learningProgress.latest_big_test ?? '—' }}</div><div class="text-xs text-on-surface-variant">Big Test mới nhất</div></div>
                </div>
            </div>

            <!-- Điểm danh gần đây -->
            <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm" data-section="attendance-history">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-primary">fact_check</span>
                    Lịch sử điểm danh
                </h2>
                <div class="divide-y divide-surface-container-highest">
                    <div v-for="a in attendanceHistory" :key="a.id" class="flex items-center justify-between py-2 text-xs">
                        <div>
                            <div class="font-semibold text-on-surface">{{ a.date }}</div>
                            <div class="text-xs text-on-surface-variant">{{ a.class_name }}<template v-if="a.note"> · {{ a.note }}</template></div>
                        </div>
                        <UiBadge :color="a.present ? 'success' : 'error'" pill>{{ a.status_label }}</UiBadge>
                    </div>
                    <p v-if="!attendanceHistory.length" class="text-xs text-on-surface-variant">Chưa có dữ liệu điểm danh.</p>
                </div>
            </div>

            <!-- Kết quả Big Test (đã duyệt / đã gửi phụ huynh) -->
            <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm" data-section="big-test-results">
                <h2 class="mb-3 flex items-center gap-1.5 text-sm font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-primary">workspace_premium</span>
                    Kết quả Big Test
                </h2>
                <div class="flex flex-col gap-2">
                    <div v-for="r in bigTestResults" :key="r.id" class="rounded-xl border border-surface-container-highest px-3 py-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-on-surface">{{ r.title }}</span>
                            <span class="font-mono font-black text-primary">{{ r.is_absent ? 'Vắng thi' : r.overall_score }}</span>
                        </div>
                        <div v-if="!r.is_absent" class="mt-1 grid grid-cols-4 gap-1 text-center text-xs text-on-surface-variant">
                            <span>Nghe {{ r.listening_score ?? '—' }}</span><span>Đọc {{ r.reading_score ?? '—' }}</span><span>Viết {{ r.writing_score ?? '—' }}</span><span>Nói {{ r.speaking_score ?? '—' }}</span>
                        </div>
                        <p v-if="r.progress_note" class="mt-1 text-xs text-on-surface-variant">{{ r.progress_note }}</p>
                    </div>
                    <p v-if="!bigTestResults.length" class="text-xs text-on-surface-variant">Chưa có kết quả Big Test đã duyệt.</p>
                </div>
            </div>

            <!-- Thông tin học phí -->
            <div class="relative flex flex-col gap-4 overflow-hidden rounded-2xl bg-gradient-to-br from-primary-container to-primary p-4 text-white shadow-lg">
                <div class="pointer-events-none absolute right-[-10%] top-[-20%] h-32 w-32 rounded-full bg-surface-container-lowest/15 blur-2xl"></div>
                <div class="pointer-events-none absolute bottom-[-20%] left-[-10%] h-24 w-24 rounded-full bg-black/10 blur-xl"></div>

                <div class="relative z-10 flex items-center justify-between">
                    <h2 class="flex items-center gap-1.5 text-base font-bold text-white">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">account_balance_wallet</span>
                        Thông tin học phí
                    </h2>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="flex items-center gap-1 rounded-full bg-surface-container-lowest/20 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur-xs transition-colors hover:bg-surface-container-lowest/30 active:scale-95" @click="requestOpen = true">
                            <span class="material-symbols-outlined text-[13px]">send</span> Báo đóng
                        </button>
                        <button type="button" class="flex items-center gap-1 rounded-full bg-surface-container-lowest/20 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur-xs transition-colors hover:bg-surface-container-lowest/30 active:scale-95" @click="historyOpen = true">
                            Lịch sử <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        </button>
                    </div>
                </div>

                <div class="relative z-10 grid grid-cols-2 gap-3 rounded-xl border border-white/10 bg-black/15 p-3 backdrop-blur-xs">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-semibold uppercase tracking-wider text-white/80">Tổng đã đóng</span>
                        <span class="font-mono text-xl font-bold">{{ formatMoney(totalPaid) }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5 border-l border-white/20 pl-3">
                        <span class="text-xs font-semibold uppercase tracking-wider text-white/80">Còn nợ</span>
                        <span class="font-mono text-lg font-bold text-error-container">{{ formatMoney(debtAmount) }}</span>
                    </div>
                </div>

                <div class="relative z-10 flex items-center justify-between rounded-xl bg-surface-container-lowest/10 px-3 py-2 text-xs">
                    <span class="font-medium text-white/90">Dự kiến khóa tới:</span>
                    <span class="font-mono font-bold text-white">{{ formatMoney(nextTermFee) }}</span>
                </div>
            </div>

            <!-- Thông tin học sinh -->
            <div class="relative flex flex-col gap-4 overflow-hidden rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-4 shadow-sm">
                <div class="pointer-events-none absolute right-0 top-0 h-24 w-24 rounded-bl-full bg-primary-container/5"></div>

                <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                    <h2 class="flex items-center gap-1.5 text-base font-bold text-on-surface">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">person</span>
                        Thông tin học sinh
                    </h2>
                    <div class="flex items-center gap-1.5">
                        <UiButton variant="secondary" size="sm" icon="edit" @click="profileOpen = true">Sửa</UiButton>
                        <UiBadge color="success" pill>{{ student?.status_label ?? '—' }}</UiBadge>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-x-3 gap-y-3 text-xs">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Ngày sinh</span>
                        <span class="font-medium text-on-surface">{{ student?.dob ? formatDate(student.dob) : '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Lớp đang học</span>
                        <span class="font-bold text-secondary">{{ studentClasses.length ? studentClasses.join(', ') : 'Chưa xếp lớp' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Giáo viên chính</span>
                        <span class="flex items-center gap-1 font-medium text-on-surface">{{ student?.teacher_name ?? '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Số điện thoại</span>
                        <span class="font-mono font-medium text-on-surface">{{ student?.phone ?? '—' }}</span>
                    </div>
                    <div class="col-span-2 flex flex-col gap-0.5 border-t border-surface-container-highest pt-2" data-field="study-started">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Ngày bắt đầu học</span>
                        <span class="font-medium text-on-surface">
                            {{ student?.study_started_on ?? 'Chưa có buổi học đầu tiên' }}
                            <span v-if="student?.tenure_label" class="font-normal text-on-surface-variant"> · đã học {{ student.tenure_label }}</span>
                        </span>
                    </div>
                    <div class="col-span-2 flex flex-col gap-0.5 border-t border-surface-container-highest pt-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Địa chỉ</span>
                        <span class="text-[12px] text-on-surface-variant">{{ student?.address ?? '—' }}</span>
                    </div>
                    <div v-if="student?.notes" class="col-span-2 flex flex-col gap-0.5 rounded-lg border border-warning/30 bg-warning-container p-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-warning">Ghi chú</span>
                        <span class="text-xs text-on-surface-variant">{{ student.notes }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lịch sử thu học phí -->
        <UiModal :show="historyOpen" title="Lịch sử thu học phí" max-width="md" @close="historyOpen = false">
            <div class="flex flex-col gap-3">
                <div class="mb-1 flex items-center gap-1 text-xs font-semibold text-on-surface-variant">
                    <span class="material-symbols-outlined text-[14px]">filter_list</span> Chỉ hiển thị phiếu "Đã duyệt"
                </div>

                <div v-for="rc in receipts" :key="rc.id" class="flex flex-col gap-2 rounded-xl border border-surface-container-highest/80 bg-surface-container-low p-3 shadow-2xs">
                    <div class="flex items-start justify-between">
                        <div class="flex flex-col">
                            <span class="font-mono text-xs font-bold text-primary">{{ rc.number }}</span>
                            <span class="text-xs font-semibold text-on-surface">{{ rc.title }}</span>
                        </div>
                        <UiBadge color="success" pill :dot="false"><span class="material-symbols-outlined text-[12px]">check_circle</span> Đã duyệt</UiBadge>
                    </div>
                    <div class="mt-1 flex items-end justify-between border-t border-surface-container-highest pt-2 text-xs">
                        <div class="flex flex-col gap-0.5 text-xs text-on-surface-variant">
                            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">calendar_today</span> {{ rc.payment_date }}</span>
                            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">payments</span> {{ rc.method_label }}</span>
                        </div>
                        <UiMoney :value="rc.amount" class="font-bold" />
                    </div>
                </div>
            </div>
        </UiModal>

        <!-- Cập nhật thông tin học viên -->
        <UiModal :show="profileOpen" title="Cập nhật thông tin học viên" max-width="sm" @close="profileOpen = false">
            <UiForm id="portal-edit-profile-form" :action="route('portal.student.profile.update', student?.id ?? 0)" method="post" class="space-y-3" @success="profileOpen = false">
                <UiInput name="phone" label="Số điện thoại liên hệ" :value="student?.phone" required />
                <UiInput name="address" label="Địa chỉ" :value="student?.address" />
                <UiTextarea name="notes" label="Ghi chú cho trung tâm / giáo viên" :rows="3" :value="student?.notes" placeholder="Ví dụ: Bé hay dị ứng phấn, xin phép vào muộn 5p..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="profileOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="portal-edit-profile-form">Lưu thay đổi</UiButton>
            </template>
        </UiModal>

        <!-- Báo đã nộp học phí -->
        <UiModal :show="requestOpen" title="Báo đóng học phí" max-width="sm" @close="requestOpen = false">
            <UiForm id="portal-tuition-request-form" :action="route('portal.student.tuition.request')" method="post" class="space-y-3" reset-on-success @success="requestOpen = false">
                <input type="hidden" name="student_id" :value="student?.id" />
                <UiInput type="number" name="amount" label="Số tiền đã chuyển (VNĐ)" :value="debtAmount > 0 ? Math.trunc(debtAmount) : ''" min="1000" required class="font-mono font-bold" />
                <UiTextarea name="content" label="Nội dung chuyển khoản / Ghi chú" :rows="3" required placeholder="Nhập mã giao dịch ngân hàng hoặc nội dung chuyển tiền..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="requestOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="portal-tuition-request-form">Gửi xác nhận</UiButton>
            </template>
        </UiModal>

        <PortalBottomNav active-tab="home" :student="student" :unread-count="unreadCount" />
    </div>
</template>
