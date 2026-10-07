<script setup>
/**
 * Cổng Giáo viên — Tổng quan hôm nay (mockup 03_Cong_Giao_Vien/01_app_shell): lịch dạy hôm nay + check-in nhiều ca, banner ca sắp
 * bắt đầu, thẻ học sinh cần chú ý / lương tạm tính / chấm công / vi phạm, buổi cần điểm danh bù, lịch tuần. Điện thoại: thanh điều hướng dưới.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';

defineOptions({ layout: { title: 'Cổng Giáo viên' } });

const props = defineProps({
    teacherName: { type: String, default: '' },
    todayLabel: { type: String, default: '' },
    stats: { type: Object, required: true },
    nextShift: { type: Object, default: null },
    shifts: { type: Array, default: () => [] },
    pendingSessions: { type: Array, default: () => [] },
    week: { type: Object, required: true },
    weekDays: { type: Array, default: () => [] },
    widgets: { type: Object, required: true },
});

const card = 'rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg';
const showAllPending = ref(false);
const WARNING_SECONDS = 15 * 60; // Cảnh báo khi còn dưới 15 phút để chấm công.

// Đếm ngược hạn check-in (giờ bắt đầu + 24h): tính từ số giây server gửi, cập nhật mỗi 30 giây.
const loadedAt = Date.now();
const tick = ref(0);
let timer = null;
onMounted(() => { timer = setInterval(() => { tick.value = Date.now() - loadedAt; }, 30000); });
onBeforeUnmount(() => clearInterval(timer));

function checkinState(shift) {
    if (shift.checked_in || !shift.checkin) return null;
    const left = shift.checkin.remaining_seconds - Math.floor(tick.value / 1000);
    if (shift.checkin.expired || left <= 0) return { expired: true, warning: false, left: 0 };
    return { expired: false, warning: left < WARNING_SECONDS, left };
}

function countdown(left) {
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    return h > 0 ? `${h} giờ ${m} phút` : `${m} phút`;
}

const hasUnchecked = computed(() => props.shifts.some((s) => !s.checked_in && !checkinState(s)?.expired));
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-lg pb-24 md:pb-0">
        <UiPageHeader title="Tổng quan hôm nay">
            <template #meta>
                Xin chào <span class="font-semibold text-on-surface">{{ teacherName }}</span> · Cổng Giáo viên ·
                <Link :href="route('teacher.trial-guests')" class="font-semibold text-primary hover:underline">Khách học thử</Link>
            </template>
            <template #actions>
                <UiAvatar :name="teacherName" />
            </template>
        </UiPageHeader>

        <!-- ─── Lịch dạy hôm nay ─── -->
        <section :class="card">
            <div class="mb-md flex flex-col justify-between gap-md md:flex-row md:items-center">
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">Lịch dạy hôm nay — {{ todayLabel }}</h2>
                    <p class="font-body-base text-body-base text-on-surface-variant">
                        <template v-if="stats.total > 0">Bạn có {{ stats.total }} ca dạy trong ngày hôm nay · đã check-in {{ stats.checked_in }} · đã điểm danh {{ stats.attendance_done }}.</template>
                        <template v-else>Hôm nay bạn không có buổi dạy nào trên lịch.</template>
                    </p>
                </div>
                <div v-if="nextShift" class="flex items-center gap-md rounded-lg border border-primary-container/20 bg-primary-container/10 p-md" data-testid="next-shift-banner">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">warning</span>
                    <div class="flex-1">
                        <p class="font-body-medium text-body-medium text-on-primary-container">Ca dạy lúc {{ nextShift.start_time }} {{ nextShift.upcoming ? 'sắp bắt đầu!' : 'đang diễn ra!' }}</p>
                        <p class="font-caption text-caption text-on-primary-container/80">Vui lòng hoàn thành thủ tục điểm danh tại lớp học.</p>
                    </div>
                    <UiButton size="sm" :href="route('teacher.attendance', { classId: nextShift.class_id, session: nextShift.session_id })">Điểm danh ngay</UiButton>
                </div>
            </div>

            <UiForm v-if="shifts.length" :action="route('teacher.checkin')" method="post" class="space-y-md">
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <div v-for="shift in shifts" :key="shift.session_id" :class="['rounded-lg border bg-surface-container-low p-md', shift.checked_in ? 'border-tertiary/40' : 'border-outline-variant']">
                        <div class="flex items-start gap-md">
                            <input
                                v-if="!shift.checked_in && !checkinState(shift)?.expired"
                                type="checkbox"
                                name="session_ids[]"
                                :value="shift.session_id"
                                :aria-label="`Chọn ca ${shift.class_name ?? ''} để check-in`"
                                class="mt-sm h-5 w-5 rounded border-outline-variant text-primary-container focus:ring-primary-container/40"
                            />
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-container/10 text-primary-container">
                                <span class="material-symbols-outlined" aria-hidden="true">{{ shift.checked_in ? 'check_circle' : 'schedule' }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                                    {{ shift.class_name }}
                                    <UiBadge v-if="shift.type_label" color="info">{{ shift.type_label }}</UiBadge>
                                    <UiBadge v-if="!shift.is_today" color="warning">Ca ngày {{ shift.date_label }}</UiBadge>
                                </p>
                                <p class="font-body-small text-body-small text-on-surface-variant">
                                    <template v-if="shift.shift_name">{{ shift.shift_name }} · </template>{{ shift.scheduled_time }} • {{ shift.room_label ?? 'Chưa có phòng' }}, {{ shift.branch_name ?? 'Chưa gán chi nhánh' }}
                                </p>
                                <p class="font-caption text-caption text-on-surface-variant">
                                    {{ shift.class_code }} · {{ shift.student_count }} HV
                                    <template v-if="shift.trial_count"> · <span class="font-semibold text-secondary">+{{ shift.trial_count }} khách học thử</span></template>
                                    <template v-if="shift.support_student"> · {{ shift.support_student }}</template>
                                    <template v-if="shift.checked_in"> · <span class="font-semibold text-tertiary">Đã check-in {{ shift.checkin_time }}</span></template>
                                </p>
                                <!-- Hạn check-in = giờ bắt đầu + 24h; còn dưới 15 phút → cảnh báo; quá hạn → liên hệ Học vụ. -->
                                <p v-if="checkinState(shift)?.expired" class="mt-xs font-caption text-caption font-semibold text-error" data-testid="checkin-expired">
                                    Quá hạn chấm công — liên hệ Học vụ
                                </p>
                                <p v-else-if="checkinState(shift)" :class="['mt-xs font-caption text-caption', checkinState(shift).warning ? 'font-semibold text-error' : 'text-on-surface-variant']" data-testid="checkin-deadline">
                                    <template v-if="checkinState(shift).warning">Còn dưới 15 phút để chấm công · </template>
                                    Hạn chấm công {{ shift.checkin.deadline }} (còn {{ countdown(checkinState(shift).left) }})
                                </p>
                            </div>
                        </div>
                        <div class="mt-md flex flex-wrap gap-xs border-t border-outline-variant/60 pt-sm">
                            <UiButton size="sm" :variant="shift.attendance_done ? 'ghost' : 'secondary'" icon="fact_check" :href="route('teacher.attendance', { classId: shift.class_id, session: shift.session_id })">{{ shift.attendance_done ? 'Đã điểm danh' : 'Điểm danh' }}</UiButton>
                            <template v-if="!shift.is_support">
                                <UiButton size="sm" variant="secondary" icon="rate_review" :href="route('teacher.remarks', { classId: shift.class_id, session: shift.session_id })">Nhận xét</UiButton>
                                <UiButton size="sm" variant="secondary" icon="assignment" :href="route('teacher.homework', { classId: shift.class_id, session: shift.session_id })">Giao bài</UiButton>
                                <UiButton size="sm" variant="secondary" icon="grading" :href="route('teacher.scores', shift.class_id)">Nhập điểm</UiButton>
                            </template>
                        </div>
                    </div>
                </div>
                <div v-if="hasUnchecked" class="flex justify-end">
                    <UiButton type="submit" icon="how_to_reg" class="w-full md:w-auto">Check-in các ca đã chọn</UiButton>
                </div>
            </UiForm>
            <UiEmptyState v-else compact icon="event_busy" title="Không có ca dạy hôm nay" description="Lịch dạy được sinh từ TKB của các lớp bạn phụ trách." />
        </section>

        <!-- Thứ tự: việc cần làm (hôm nay, điểm danh bù) lên trước; lương/vi phạm để cuối. -->
        <!-- ─── Buổi đã qua chưa điểm danh ─── -->
        <section v-if="pendingSessions.length" class="overflow-hidden rounded-xl border border-error/30 bg-surface-container-lowest shadow-sm" data-testid="pending-attendance">
            <div class="flex items-center gap-sm border-b border-error/20 bg-error-container/30 px-md py-sm">
                <span class="material-symbols-outlined text-error" aria-hidden="true">pending_actions</span>
                <h2 class="font-body-semibold text-body-semibold text-on-error-container">Buổi đã dạy chưa điểm danh (điểm danh bù) · {{ pendingSessions.length }}</h2>
            </div>
            <ul class="divide-y divide-surface-container">
                <li v-for="(s, index) in pendingSessions" v-show="index < 3 || showAllPending" :key="s.id" class="flex flex-col justify-between gap-sm px-md py-sm font-body-small text-body-small sm:flex-row sm:items-center">
                    <div>
                        <span class="font-semibold text-on-surface">{{ s.class_name }}</span>
                        <span class="text-on-surface-variant"> · {{ s.date }} · {{ s.time }}</span>
                        <UiBadge v-if="s.type_label" color="info">{{ s.type_label }}</UiBadge>
                    </div>
                    <UiButton size="sm" variant="secondary" icon="fact_check" :href="route('teacher.attendance', { classId: s.class_id, session: s.id })">Điểm danh bù</UiButton>
                </li>
            </ul>
            <button v-if="pendingSessions.length > 3 && !showAllPending" type="button" class="w-full border-t border-surface-container px-md py-sm text-left font-body-small text-body-small font-semibold text-primary hover:underline" @click="showAllPending = true">
                Xem tất cả {{ pendingSessions.length }} buổi
            </button>
        </section>

        <!-- ─── Lịch dạy tuần ─── -->
        <section class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
            <div class="flex flex-col justify-between gap-sm border-b border-surface-container px-md py-sm sm:flex-row sm:items-center">
                <h2 class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">calendar_view_week</span>
                    Lịch dạy tuần {{ week.label }}
                </h2>
                <div class="flex items-center gap-xs">
                    <UiButton size="sm" variant="secondary" icon="chevron_left" :href="route('teacher.home', { week: week.prev })">Tuần trước</UiButton>
                    <UiButton size="sm" variant="ghost" :href="route('teacher.home')">Tuần này</UiButton>
                    <UiButton size="sm" variant="secondary" :href="route('teacher.home', { week: week.next })">Tuần sau <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span></UiButton>
                </div>
            </div>
            <div class="grid grid-cols-1 divide-y divide-surface-container md:grid-cols-7 md:divide-x md:divide-y-0">
                <div v-for="day in weekDays" :key="day.label" :class="['min-h-[90px] p-sm', day.is_today ? 'bg-primary-container/5' : '']">
                    <div :class="['font-label-caps text-label-caps uppercase', day.is_today ? 'text-primary' : 'text-on-surface-variant']">{{ day.label }}</div>
                    <div class="mt-sm space-y-xs">
                        <div
                            v-for="s in day.sessions"
                            :key="s.id"
                            :class="['rounded-lg border px-sm py-xs text-xs', s.cancelled ? 'border-outline-variant bg-surface-container-low text-on-surface-variant line-through' : s.done ? 'border-tertiary/30 bg-tertiary-fixed/20' : 'border-outline-variant']"
                        >
                            <div class="font-semibold text-on-surface">{{ s.time }}</div>
                            <div class="truncate">{{ s.class_name }}</div>
                            <div class="text-on-surface-variant">
                                {{ s.room }}
                                <template v-if="s.type_label"> · {{ s.type_label }}</template>
                                <template v-if="s.cancelled"> · Đã hủy</template>
                            </div>
                            <div v-if="s.checkin_overdue" class="font-semibold text-error">Quá hạn chấm công — liên hệ Học vụ</div>
                            <Link v-if="s.can_take" :href="route('teacher.attendance', { classId: s.class_id, session: s.id })" :class="['mt-xs inline-block font-semibold hover:underline', s.done ? 'text-tertiary' : 'text-primary']">
                                {{ s.done ? 'Đã điểm danh' : 'Điểm danh' }}
                            </Link>
                        </div>
                        <div v-if="!day.sessions.length" class="text-xs text-outline-variant">—</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── Thẻ tổng quan ─── -->
        <div class="grid grid-cols-1 gap-lg md:grid-cols-2">
            <div :class="[card, 'flex flex-col']" data-testid="widget-attention">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Học sinh cần chú ý</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">priority_high</span>
                </div>
                <div class="flex-1 space-y-sm">
                    <div v-for="score in widgets.attention" :key="score.id" class="rounded border border-error-container/60 bg-error-container/20 p-sm">
                        <p class="font-body-medium text-body-medium text-on-surface">{{ score.student_name }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            {{ score.class_name }} • {{ score.name }} • Điểm: <span class="font-bold text-error">{{ score.score }}</span>
                        </p>
                    </div>
                    <p v-if="!widgets.attention.length" class="font-body-small text-body-small text-on-surface-variant">Không có học sinh nào dưới mục tiêu trong 30 ngày qua.</p>
                    <p class="font-caption text-caption italic text-on-surface-variant">* Danh sách học sinh có kết quả Mini Test dưới mục tiêu (dưới 7/10).</p>
                </div>
            </div>

            <div :class="[card, 'flex flex-col']" data-testid="widget-salary">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Lương tạm tính tháng {{ widgets.month }}</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">payments</span>
                </div>
                <div class="flex flex-1 flex-col justify-center">
                    <template v-if="widgets.estimate !== null">
                        <p class="text-[28px] font-bold leading-9 text-primary">{{ formatMoney(widgets.estimate) }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">Tính đến ngày {{ widgets.today_dm }} · {{ widgets.hours_label }} giờ dạy × đơn giá (chưa gồm phụ cấp, KPI, khấu trừ)</p>
                    </template>
                    <template v-else>
                        <p class="text-[28px] font-bold leading-9 text-primary">{{ widgets.hours_label }} giờ</p>
                        <p class="font-caption text-caption text-on-surface-variant">Giờ dạy đã chấm công tính đến ngày {{ widgets.today_dm }} — chưa có đơn giá riêng để tạm tính lương.</p>
                    </template>
                </div>
                <UiButton variant="secondary" class="mt-md w-full" :href="route('portal.my-salary')">Chi tiết</UiButton>
            </div>

            <div :class="[card, 'flex flex-col']" data-testid="widget-timesheet">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Báo cáo chấm công</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">assignment_turned_in</span>
                </div>
                <p class="flex-1 font-body-base text-body-base text-on-surface-variant">
                    Kỳ lương {{ widgets.period }}: {{ widgets.timesheets_total }} ca đã chấm công, {{ widgets.timesheets_pending }} ca chờ Học vụ duyệt.
                    Vui lòng kiểm tra giờ dạy trước 23:59 ngày {{ widgets.month_end_dm }}.
                </p>
                <UiButton class="mt-md w-full" :href="route('teacher.general-report')">Xem bảng công</UiButton>
            </div>

            <div :class="[card, 'flex flex-col']" data-testid="widget-violations">
                <div class="mb-md flex items-center justify-between">
                    <h3 class="font-h3 text-h3 text-on-surface">Vi phạm &amp; Khoản trừ</h3>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">gavel</span>
                </div>
                <div class="flex flex-1 items-center gap-md">
                    <div :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-full border-4', widgets.violations_count ? 'border-error text-error' : 'border-tertiary text-tertiary']">
                        <span class="font-bold">{{ widgets.violations_count }}</span>
                    </div>
                    <div>
                        <p v-if="!widgets.violations_count" class="font-body-medium text-body-medium text-on-surface">Không có vi phạm trong tháng</p>
                        <template v-else>
                            <p class="font-body-medium text-body-medium text-on-surface">{{ widgets.violations_count }} lỗi vi phạm trong tháng</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ widgets.latest_violation.violation_type }} — {{ widgets.latest_violation.date }} · {{ widgets.latest_violation.status_label }}</p>
                        </template>
                    </div>
                </div>
                <UiButton variant="secondary" class="mt-md w-full" :href="route('penalties.index')">Xem biên bản</UiButton>
            </div>
        </div>
    </div>

    <TeacherBottomNav />
</template>
