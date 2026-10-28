<script setup>
/**
 * Cổng Giáo viên — Lịch dạy hôm nay (thiết kế lại 28/10/2026): đầu trang 1 dòng chào + chip việc tồn; mỗi ca dạy là một thẻ
 * với 3 bước Check-in → Điểm danh → Báo cáo buổi; buổi chưa điểm danh (điểm danh bù); lịch tuần; cột phải học sinh cần chú ý.
 * Điểm danh / điểm danh bù / giao bài mở ngăn kéo bên phải (cùng ngăn với Trang lớp), không sang trang cũ.
 * Bỏ thẻ lương, chấm công, vi phạm (chủ dự án 28/10/2026). Điện thoại: thanh điều hướng dưới.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import TeacherBottomNav from '@/Components/Teacher/TeacherBottomNav.vue';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Lịch dạy hôm nay' } });

const props = defineProps({
    teacherName: { type: String, default: '' },
    todayLabel: { type: String, default: '' },
    stats: { type: Object, required: true },
    todo: { type: Object, default: () => ({ reports_pending: 0, report_url: null }) },
    shifts: { type: Array, default: () => [] },
    pendingSessions: { type: Array, default: () => [] },
    attendanceWindowHours: { type: Number, default: 24 },
    week: { type: Object, required: true },
    weekDays: { type: Array, default: () => [] },
    attention: { type: Array, default: () => [] },
});

const card = 'rounded-xl border border-outline-variant bg-surface-container-lowest';
const PENDING_PREVIEW = 3;
const showAllPending = ref(false);
const WARNING_SECONDS = 15 * 60; // Cảnh báo khi còn dưới 15 phút để chấm công.

// Đếm ngược (giờ vào lớp, hạn check-in): tính từ số giây server gửi, cập nhật mỗi 30 giây.
const loadedAt = Date.now();
const tick = ref(0);
let timer = null;
onMounted(() => { timer = setInterval(() => { tick.value = Date.now() - loadedAt; }, 30000); });
onBeforeUnmount(() => clearInterval(timer));
const elapsed = () => Math.floor(tick.value / 1000);

function countdown(left) {
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    return h > 0 ? `${h} giờ ${m} phút` : `${m} phút`;
}

function checkinState(shift) {
    if (shift.checked_in || !shift.checkin) return null;
    const left = shift.checkin.remaining_seconds - elapsed();
    if (shift.checkin.expired || left <= 0) return { expired: true, warning: false, left: 0 };
    return { expired: false, warning: left < WARNING_SECONDS, left };
}

/** Trạng thái giờ học của ca: sắp bắt đầu / đang diễn ra / đã kết thúc. */
function timeStatus(shift) {
    const startsIn = shift.starts_in - elapsed();
    if (startsIn > 0) return { label: `Bắt đầu sau ${countdown(startsIn)}`, tone: 'warning' };
    if (shift.ends_in - elapsed() > 0) return { label: 'Đang diễn ra', tone: 'live' };
    return { label: shift.is_today ? 'Đã kết thúc' : `Ca ngày ${shift.date_label}`, tone: 'muted' };
}

const pillTone = {
    warning: 'bg-[#fef3c7] text-[#78350f]',
    live: 'bg-[#e4f5da] text-[#0e3500]',
    muted: 'bg-surface-container text-on-surface-variant',
};

const attendanceUrl = (classId, sessionId) => route('teacher.attendance', { classId, session: sessionId });
// Điểm danh / giao bài mở ngăn kéo bên phải (cùng ngăn với Trang lớp); link vẫn là URL thật để mở tab mới được.
const openDrawer = (url) => openRemoteModal(url, { size: 'drawer' });

/**
 * 3 bước của ca theo thứ tự làm; bước đầu tiên chưa xong là nút chính. Trợ giảng / buổi phụ đạo không có Báo cáo buổi.
 * Mỗi bước: { key, label, hint, done, action: 'checkin' | 'attendance' | 'report' | null, button, disabled }.
 */
function steps(shift) {
    const ck = checkinState(shift);
    const list = [
        {
            key: 'checkin',
            label: 'Check-in',
            done: shift.checked_in,
            hint: shift.checked_in ? `Đã check-in ${shift.checkin_time ?? ''}` : ck?.expired ? 'Quá hạn chấm công — liên hệ Học vụ' : `Hạn ${shift.checkin?.deadline ?? ''}`,
            button: shift.checked_in ? 'Đã check-in' : 'Check-in',
            disabled: shift.checked_in || !!ck?.expired,
        },
        {
            key: 'attendance',
            label: 'Điểm danh',
            done: shift.attendance_done,
            hint: {
                done: 'Đã lưu điểm danh',
                open: `${shift.student_count} HV${shift.trial_count ? ` · +${shift.trial_count} học thử` : ''}`,
                not_yet: `Mở từ ${shift.attendance_opens_at}`,
                closed: `Quá ${props.attendanceWindowHours}h — liên hệ Học vụ`,
            }[shift.attendance_state],
            button: shift.attendance_done ? 'Xem điểm danh' : 'Điểm danh',
            disabled: shift.attendance_state === 'not_yet',
        },
    ];
    if (shift.can_teach && !shift.is_support) {
        list.push({
            key: 'report',
            label: 'Báo cáo buổi',
            done: shift.report_done,
            hint: shift.report_done ? 'Đã nộp' : shift.attendance_done ? 'Nhận xét từng học sinh' : 'Cần điểm danh trước',
            button: shift.report_done ? 'Xem báo cáo' : shift.attendance_done ? 'Báo cáo buổi' : 'Cần điểm danh trước',
            disabled: !shift.report_done && !shift.attendance_done,
        });
    }
    const next = list.find((s) => !s.done && !s.disabled);
    return list.map((s) => ({ ...s, primary: s === next }));
}

const stepBox = (s) => (s.primary ? 'border-[#ffdbcd] bg-[#fffaf7]' : 'border-outline-variant bg-surface-container-lowest');
const stepDot = (s) => (s.done ? 'bg-tertiary text-on-tertiary' : s.primary ? 'bg-primary-container text-on-primary' : 'bg-surface-container-high text-on-surface-variant');

const checkedInCount = computed(() => props.shifts.filter((s) => s.checked_in).length);
const attendanceCount = computed(() => props.shifts.filter((s) => s.attendance_done).length);
const visiblePending = computed(() => (showAllPending.value ? props.pendingSessions : props.pendingSessions.slice(0, PENDING_PREVIEW)));

/** Trạng thái một buổi trong lịch tuần. */
function weekState(s) {
    if (s.cancelled) return { label: 'Đã hủy', cls: 'text-on-surface-variant', border: 'border-outline-variant' };
    if (s.done) return { label: 'Đã điểm danh', cls: 'text-tertiary', border: 'border-tertiary/30' };
    if (s.is_today) return { label: 'Hôm nay', cls: 'text-primary', border: 'border-[#ffdbcd]' };
    if (s.can_take) return { label: 'Chưa điểm danh', cls: 'text-error', border: 'border-error-container' };
    return { label: 'Sắp tới', cls: 'text-on-surface-variant', border: 'border-outline-variant' };
}

const chip = 'inline-flex h-9 items-center gap-xs rounded-full border px-sm text-[13px] font-semibold text-on-surface hover:bg-surface-container-low';
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-md pb-24 md:pb-0">
        <!-- Đầu trang: chào + việc tồn dạng chip -->
        <header class="flex flex-wrap items-end justify-between gap-sm">
            <div>
                <h1 class="text-2xl font-bold leading-8 text-on-surface">{{ todayLabel }}</h1>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Chào <span class="font-semibold text-on-surface">{{ teacherName }}</span> ·
                    <template v-if="stats.total > 0">hôm nay bạn có {{ stats.total }} ca dạy</template>
                    <template v-else>hôm nay bạn không có ca dạy nào</template>
                </p>
            </div>
            <nav aria-label="Việc cần làm" class="flex flex-wrap gap-xs" data-testid="teacher-todo">
                <a v-if="pendingSessions.length" href="#diem-danh-bu" :class="[chip, 'border-error-container bg-[#fff8f7]']">
                    Chưa điểm danh <b class="rounded-full bg-error-container px-[7px] text-xs leading-5 text-on-error-container">{{ pendingSessions.length }}</b>
                </a>
                <Link v-if="todo.reports_pending && todo.report_url" :href="todo.report_url" :class="[chip, 'border-error-container bg-[#fff8f7]']">
                    Báo cáo buổi chưa nộp <b class="rounded-full bg-error-container px-[7px] text-xs leading-5 text-on-error-container">{{ todo.reports_pending }}</b>
                </Link>
                <Link v-if="can('homework.grade')" :href="route('portal.teacher.submissions')" :class="[chip, 'border-outline-variant bg-surface-container-lowest']">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">grading</span>Chấm bài nộp
                </Link>
            </nav>
        </header>

        <div class="grid grid-cols-1 items-start gap-md lg:grid-cols-[minmax(0,2fr)_minmax(300px,1fr)]">
            <!-- Cột trái: việc của ngày -->
            <div class="min-w-0 space-y-md">
                <!-- Ca dạy hôm nay: mỗi ca một thẻ, 3 bước làm ngay trên thẻ -->
                <section aria-labelledby="ca-hom-nay" :class="card" data-testid="today-shifts">
                    <div class="flex flex-wrap items-center justify-between gap-xs border-b border-surface-container px-md py-sm md:px-lg">
                        <h2 id="ca-hom-nay" class="text-base font-bold text-on-surface">Ca dạy hôm nay</h2>
                        <span v-if="shifts.length" class="text-[13px] text-on-surface-variant">Check-in {{ checkedInCount }}/{{ shifts.length }} · Điểm danh {{ attendanceCount }}/{{ shifts.length }}</span>
                    </div>

                    <article v-for="shift in shifts" :key="shift.session_id" class="flex flex-col gap-md border-t border-surface-container px-md py-md first-of-type:border-t-0 sm:flex-row md:px-lg" :data-shift="shift.session_id">
                        <!-- Khối giờ -->
                        <div class="flex shrink-0 items-center gap-sm self-start rounded-[10px] bg-[#fff3eb] px-sm py-xs text-center sm:w-[92px] sm:flex-col sm:gap-0 sm:py-sm">
                            <div class="text-xl font-bold text-primary">{{ shift.start_time }}</div>
                            <div class="text-xs text-on-surface-variant">đến {{ shift.end_time }}</div>
                            <div v-if="shift.shift_name" class="text-[11px] font-semibold text-primary sm:mt-[6px]">{{ shift.shift_name }}</div>
                        </div>

                        <div class="flex min-w-0 flex-1 flex-col gap-sm">
                            <div class="flex flex-wrap items-start justify-between gap-xs">
                                <div class="min-w-0">
                                    <Link :href="route('classes.show', shift.class_id)" class="text-base font-bold text-on-surface hover:underline">{{ shift.class_name }}</Link>
                                    <UiBadge v-if="shift.type_label" color="info" class="ml-xs">{{ shift.type_label }}</UiBadge>
                                    <p class="mt-[2px] font-body-small text-body-small text-on-surface-variant">
                                        {{ shift.room_label ?? 'Chưa có phòng' }}, {{ shift.branch_name ?? 'Chưa gán chi nhánh' }} · {{ shift.student_count }} HV
                                        <template v-if="shift.support_student"> · {{ shift.support_student }}</template>
                                        <template v-if="shift.trial_count && can('portal.teacher')"> · <Link :href="route('teacher.trial-guests')" class="font-semibold text-primary hover:underline">+{{ shift.trial_count }} khách học thử</Link></template>
                                    </p>
                                </div>
                                <span :class="['inline-flex items-center gap-[6px] whitespace-nowrap rounded-full px-[10px] py-[3px] text-xs font-semibold', pillTone[timeStatus(shift).tone]]">
                                    <span class="h-[6px] w-[6px] rounded-full bg-current" aria-hidden="true"></span>{{ timeStatus(shift).label }}
                                </span>
                            </div>

                            <!-- 3 bước của ca -->
                            <ol class="grid grid-cols-1 gap-xs sm:grid-cols-3">
                                <li v-for="(step, i) in steps(shift)" :key="step.key" :class="['flex flex-col gap-[2px] rounded-[10px] border px-sm py-[10px]', stepBox(step)]" :data-step="step.key">
                                    <div class="flex items-center gap-[6px] font-semibold text-on-surface">
                                        <span :class="['inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold', stepDot(step)]">
                                            <span v-if="step.done" class="material-symbols-outlined text-[14px]" aria-hidden="true">check</span>
                                            <template v-else>{{ i + 1 }}</template>
                                        </span>
                                        {{ step.label }}
                                    </div>
                                    <div :class="['text-xs', step.hint?.includes('Học vụ') ? 'font-semibold text-error' : checkinState(shift)?.warning && step.key === 'checkin' ? 'font-semibold text-error' : 'text-on-surface-variant']">
                                        {{ step.hint }}<template v-if="step.key === 'checkin' && checkinState(shift) && !checkinState(shift).expired"> (còn {{ countdown(checkinState(shift).left) }})</template>
                                    </div>
                                    <UiButton
                                        v-if="step.key === 'checkin'"
                                        size="sm"
                                        class="mt-[6px]"
                                        :variant="step.primary ? 'primary' : 'secondary'"
                                        :href="step.disabled ? null : route('teacher.checkin')"
                                        :method="step.disabled ? null : 'post'"
                                        :data="{ session_ids: [shift.session_id] }"
                                        preserve-scroll
                                        :disabled="step.disabled"
                                    >{{ step.button }}</UiButton>
                                    <UiButton
                                        v-else-if="step.key === 'attendance'"
                                        size="sm"
                                        class="mt-[6px]"
                                        :variant="step.primary ? 'primary' : 'secondary'"
                                        :href="step.disabled ? null : attendanceUrl(shift.class_id, shift.session_id)"
                                        :modal="step.disabled ? false : 'drawer'"
                                        :disabled="step.disabled"
                                    >{{ step.button }}</UiButton>
                                    <UiButton
                                        v-else
                                        size="sm"
                                        class="mt-[6px]"
                                        :variant="step.primary ? 'primary' : 'secondary'"
                                        :href="step.disabled ? null : route('classes.session-report', { id: shift.class_id, session: shift.session_id })"
                                        :disabled="step.disabled"
                                    >{{ step.button }}</UiButton>
                                </li>
                            </ol>

                            <!-- Lối tắt phụ -->
                            <div class="flex flex-wrap items-center gap-x-md gap-y-[4px] text-[13px]">
                                <template v-if="!shift.is_support">
                                    <Link :href="route('teacher.lesson-today', { class: shift.class_id })" class="font-semibold text-primary hover:underline">Bài giảng</Link>
                                </template>
                                <a v-if="shift.syllabus_url" :href="shift.syllabus_url" class="font-semibold text-primary hover:underline">Mở giáo trình</a>
                                <template v-if="shift.can_teach && !shift.is_support">
                                    <a :href="route('teacher.homework', { classId: shift.class_id, session: shift.session_id })" class="font-semibold text-primary hover:underline" @click.prevent="openDrawer(route('teacher.homework', { classId: shift.class_id, session: shift.session_id }))">Giao bài</a>
                                    <Link :href="route('teacher.scores', shift.class_id)" class="font-semibold text-primary hover:underline">Nhập điểm</Link>
                                </template>
                            </div>
                        </div>
                    </article>

                    <UiEmptyState v-if="!shifts.length" compact icon="event_busy" title="Không có ca dạy hôm nay" description="Lịch dạy được sinh từ TKB của các lớp bạn phụ trách." />
                </section>

                <!-- Buổi đã dạy chưa điểm danh: trong khung tự điểm danh bù, quá khung liên hệ Học vụ -->
                <section v-if="pendingSessions.length" id="diem-danh-bu" aria-labelledby="diem-danh-bu-title" class="scroll-mt-md overflow-hidden rounded-xl border border-error-container bg-surface-container-lowest" data-testid="pending-attendance">
                    <div class="flex flex-wrap items-center justify-between gap-xs border-b border-error-container bg-[#fff8f7] px-md py-sm md:px-lg">
                        <h2 id="diem-danh-bu-title" class="text-base font-bold text-on-error-container">Buổi đã dạy chưa điểm danh · {{ pendingSessions.length }}</h2>
                        <span class="text-[13px] text-on-surface-variant">Trong {{ attendanceWindowHours }}h bạn tự điểm danh bù; quá hạn thì báo Học vụ.</span>
                    </div>
                    <ul class="divide-y divide-[#f3e4e1]">
                        <li v-for="s in visiblePending" :key="s.id" class="flex items-center gap-sm px-md py-[10px] md:px-lg">
                            <div class="w-12 shrink-0">
                                <div class="text-[11px] font-semibold text-on-surface-variant">{{ s.dow }}</div>
                                <div class="font-bold text-on-surface">{{ s.day }}</div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-on-surface">{{ s.class_name }}</div>
                                <div class="text-xs text-on-surface-variant">{{ s.time }}<template v-if="s.type_label"> · {{ s.type_label }}</template></div>
                            </div>
                            <span :class="['hidden whitespace-nowrap rounded-full px-[10px] py-[2px] text-xs font-semibold sm:inline-block', s.open ? 'bg-[#fef3c7] text-[#78350f]' : 'bg-error-container text-on-error-container']">
                                {{ s.open ? `Còn ${s.hours_left} giờ` : `Quá ${attendanceWindowHours}h` }}
                            </span>
                            <UiButton size="sm" :variant="s.open ? 'primary' : 'secondary'" :href="attendanceUrl(s.class_id, s.id)" modal="drawer">
                                {{ s.open ? 'Điểm danh bù' : 'Xem buổi' }}
                            </UiButton>
                        </li>
                    </ul>
                    <button v-if="pendingSessions.length > PENDING_PREVIEW && !showAllPending" type="button" class="w-full border-t border-[#f3e4e1] px-md py-[10px] text-left text-[13px] font-semibold text-primary hover:underline md:px-lg" @click="showAllPending = true">
                        Xem tất cả {{ pendingSessions.length }} buổi
                    </button>
                </section>

                <!-- Lịch dạy tuần -->
                <section aria-labelledby="lich-tuan" :class="[card, 'overflow-hidden']">
                    <div class="flex flex-wrap items-center justify-between gap-xs border-b border-surface-container px-md py-sm md:px-lg">
                        <h2 id="lich-tuan" class="text-base font-bold text-on-surface">Lịch dạy tuần {{ week.label }}</h2>
                        <div class="inline-flex gap-[2px] rounded-[10px] bg-[#f1f3ff] p-[3px]">
                            <Link :href="route('teacher.home', { week: week.prev })" preserve-scroll aria-label="Tuần trước" class="inline-flex h-8 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-lowest">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">chevron_left</span>
                            </Link>
                            <Link :href="route('teacher.home')" preserve-scroll :class="['inline-flex h-8 items-center rounded-lg px-sm text-[13px] font-semibold', week.is_current ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container-lowest']">Tuần này</Link>
                            <Link :href="route('teacher.home', { week: week.next })" preserve-scroll aria-label="Tuần sau" class="inline-flex h-8 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-lowest">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">chevron_right</span>
                            </Link>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 divide-y divide-surface-container md:grid-cols-7 md:divide-x md:divide-y-0">
                        <div v-for="day in weekDays" :key="day.label" :class="['min-h-[60px] px-xs py-[10px] md:min-h-[120px]', day.is_today ? 'bg-[#fffaf7]' : '']">
                            <div :class="['text-xs font-bold', day.is_today ? 'text-primary' : 'text-on-surface-variant']">{{ day.label }}</div>
                            <component
                                :is="s.can_take ? 'a' : 'div'"
                                v-for="s in day.sessions"
                                :key="s.id"
                                :href="s.can_take ? attendanceUrl(s.class_id, s.id) : undefined"
                                :class="['mt-[6px] block rounded-lg border bg-surface-container-lowest px-xs py-[6px] text-xs', weekState(s).border, s.can_take ? 'hover:bg-surface-container-low' : '', s.cancelled ? 'line-through' : '']"
                                @click="s.can_take && ($event.preventDefault(), openDrawer(attendanceUrl(s.class_id, s.id)))"
                            >
                                <span class="block font-bold text-on-surface">{{ s.time }}</span>
                                <span class="block truncate text-on-surface-variant">{{ s.class_name }}</span>
                                <span v-if="s.type_label" class="block text-on-surface-variant">{{ s.type_label }}</span>
                                <span :class="['mt-[2px] block font-semibold', weekState(s).cls]">{{ weekState(s).label }}</span>
                                <span v-if="s.checkin_overdue" class="block font-semibold text-error">Quá hạn chấm công</span>
                            </component>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Cột phải: học sinh cần chú ý -->
            <aside class="min-w-0">
                <section aria-labelledby="chu-y" :class="[card, 'px-md py-md md:px-lg']" data-testid="widget-attention">
                    <h2 id="chu-y" class="text-base font-bold text-on-surface">Học sinh cần chú ý</h2>
                    <p class="mb-[6px] mt-[2px] text-xs text-on-surface-variant">Mini Test dưới 7/10 trong 30 ngày</p>
                    <ul>
                        <li v-for="score in attention" :key="score.id" class="flex justify-between gap-sm border-t border-surface-container py-[9px]">
                            <span class="min-w-0">
                                <span class="block font-semibold text-on-surface">{{ score.student_name }}</span>
                                <span class="block text-xs text-on-surface-variant">{{ score.class_name }} · {{ score.name }}</span>
                            </span>
                            <b class="whitespace-nowrap text-error">{{ score.score }}</b>
                        </li>
                    </ul>
                    <p v-if="!attention.length" class="border-t border-surface-container pt-sm font-body-small text-body-small text-on-surface-variant">Không có học sinh nào dưới mục tiêu trong 30 ngày qua.</p>
                </section>
            </aside>
        </div>
    </div>

    <TeacherBottomNav />
</template>
