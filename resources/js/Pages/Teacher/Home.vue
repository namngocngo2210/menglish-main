<script setup>
// Teacher dashboard: workflow logic stays here; ShiftBody renders each session.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import ShiftBody from '@/Components/Teacher/ShiftBody.vue';
import { expandedShiftIds } from '@/lib/teacherShifts';
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
const WARNING_SECONDS = 15 * 60; // Warn within 15 minutes of the check-in deadline.

// Update server-provided countdowns every 30 seconds.
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

/** Session time status: upcoming, live, or ended. */
function timeStatus(shift) {
    const startsIn = shift.starts_in - elapsed();
    if (startsIn > 0) return { label: `Bắt đầu sau ${countdown(startsIn)}`, tone: 'warning' };
    if (shift.ends_in - elapsed() > 0) return { label: 'Đang diễn ra', tone: 'live' };
    return { label: shift.is_today ? 'Đã kết thúc' : `Ca ngày ${shift.date_label}`, tone: 'muted' };
}

const pillTone = {
    warning: 'bg-warning-container text-on-warning-container',
    live: 'bg-tertiary/10 text-tertiary',
    muted: 'bg-surface-container text-on-surface-variant',
};

const attendanceUrl = (classId, sessionId) => route('teacher.attendance', { classId, session: sessionId });
// Keep real URLs while opening attendance and homework in the class drawer.
const openDrawer = (url) => openRemoteModal(url, { size: 'drawer' });

/** Check-in unlocks attendance and lessons; both unlock homework, then reports.
 * Assistants and support sessions only have check-in and attendance.
 */
function steps(shift) {
    const ck = checkinState(shift);
    const reportUrl = route('classes.session-report', { id: shift.class_id, session: shift.session_id });
    const homeworkUrl = route('teacher.homework', { classId: shift.class_id, session: shift.session_id });
    const notCheckedIn = !shift.checked_in;
    const list = [
        {
            key: 'checkin',
            label: 'Check-in',
            done: shift.checked_in,
            locked: false,
            warn: !!ck?.expired || !!ck?.warning,
            hint: shift.checked_in
                ? `Đã check-in ${shift.checkin_time ?? ''}`
                : ck?.expired ? 'Quá hạn chấm công — liên hệ Học vụ' : `Hạn ${shift.checkin?.deadline ?? ''}${ck ? ` (còn ${countdown(ck.left)})` : ''}`,
            actions: shift.checked_in || ck?.expired ? [] : [{ label: 'Check-in', href: route('teacher.checkin'), method: 'post', data: { session_ids: [shift.session_id] } }],
        },
        {
            key: 'attendance',
            label: 'Điểm danh',
            done: shift.attendance_done,
            locked: notCheckedIn || shift.attendance_state === 'not_yet',
            warn: shift.attendance_state === 'closed',
            hint: notCheckedIn
                ? 'Mở sau khi check-in'
                : {
                      done: 'Đã lưu điểm danh',
                      open: `${shift.student_count} HV${shift.trial_count ? ` · +${shift.trial_count} học thử` : ''}`,
                      not_yet: `Mở từ ${shift.attendance_opens_at}`,
                      closed: `Quá ${props.attendanceWindowHours}h — liên hệ Học vụ`,
                  }[shift.attendance_state],
            actions: [{ label: shift.attendance_done ? 'Sửa' : 'Điểm danh', href: attendanceUrl(shift.class_id, shift.session_id), drawer: true }],
        },
    ];
    if (shift.can_teach && !shift.is_support) {
        list.push(
            {
                key: 'lesson',
                label: 'Bài giảng',
                done: shift.lesson_done,
                locked: notCheckedIn,
                hint: notCheckedIn ? 'Mở sau khi check-in' : shift.lesson_done ? `Đã dạy xong ${shift.lesson_done_at ?? ''}` : 'Mở bài giảng, dạy xong bấm "Dạy xong"',
                actions: [
                    { label: 'Mở bài giảng', href: route('teacher.lesson-today', { class: shift.class_id }), secondary: true },
                    ...(shift.lesson_done ? [] : [{ label: 'Dạy xong', href: route('teacher.lesson-done', shift.session_id), method: 'post' }]),
                ],
            },
            {
                key: 'homework',
                label: 'Giao bài tập',
                done: shift.homework_done,
                locked: !shift.attendance_done || !shift.lesson_done,
                hint: shift.homework_done
                    ? 'Đã giao bài cho buổi này'
                    : !shift.attendance_done && !shift.lesson_done ? 'Cần điểm danh và dạy xong bài giảng' : !shift.attendance_done ? 'Cần điểm danh trước' : !shift.lesson_done ? 'Cần dạy xong bài giảng' : 'Giao bài về nhà cho lớp',
                actions: [{ label: shift.homework_done ? 'Sửa / giao thêm' : 'Giao bài', href: homeworkUrl, drawer: true }],
            },
            {
                key: 'report',
                label: 'Nộp báo cáo',
                done: shift.report_done,
                locked: !shift.homework_done && !shift.report_done,
                hint: shift.report_done ? 'Đã nộp báo cáo buổi' : shift.homework_done ? 'Nhận xét từng học sinh' : 'Cần giao bài tập trước',
                actions: [{ label: shift.report_done ? 'Xem báo cáo' : 'Nộp báo cáo', href: reportUrl }],
            },
        );
    }
    // Attendance and lessons can both be current at the same time.
    return list.map((s) => ({ ...s, current: !s.done && !s.locked }));
}

const shiftCards = computed(() => props.shifts.map((shift) => ({
    session_id: shift.session_id,
    shift,
    steps: steps(shift),
    status: timeStatus(shift),
})));
const expandedIds = computed(() => new Set(expandedShiftIds(shiftCards.value)));

const checkedInCount = computed(() => props.shifts.filter((s) => s.checked_in).length);
const attendanceCount = computed(() => props.shifts.filter((s) => s.attendance_done).length);
const visiblePending = computed(() => (showAllPending.value ? props.pendingSessions : props.pendingSessions.slice(0, PENDING_PREVIEW)));

/** Attendance status for a session in the weekly calendar. */
function weekState(s) {
    if (s.cancelled) return { label: 'Đã hủy', cls: 'text-on-surface-variant', border: 'border-outline-variant' };
    if (s.done) return { label: 'Đã điểm danh', cls: 'text-tertiary', border: 'border-tertiary/30' };
    if (s.is_today) return { label: 'Hôm nay', cls: 'text-primary', border: 'border-primary-border' };
    if (s.can_take) return { label: 'Chưa điểm danh', cls: 'text-error', border: 'border-error-container' };
    return { label: 'Sắp tới', cls: 'text-on-surface-variant', border: 'border-outline-variant' };
}

const chip = 'inline-flex min-h-11 items-center gap-xs rounded-full border px-sm text-sm font-semibold text-on-surface hover:bg-surface-container-low';
</script>

<template>
    <div class="min-w-0 space-y-md pb-40 md:pb-0">
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
            <div class="min-w-0 space-y-md">
                <section aria-labelledby="ca-hom-nay" :class="card" data-testid="today-shifts">
                    <div class="flex flex-wrap items-center justify-between gap-xs border-b border-surface-container px-md py-sm md:px-lg">
                        <h2 id="ca-hom-nay" class="text-base font-bold text-on-surface">Ca dạy hôm nay</h2>
                        <span v-if="shifts.length" class="text-sm text-on-surface-variant">Check-in {{ checkedInCount }}/{{ shifts.length }} · Điểm danh {{ attendanceCount }}/{{ shifts.length }}</span>
                    </div>

                    <article v-for="entry in shiftCards" :key="entry.session_id" class="min-w-0 border-t border-surface-container first-of-type:border-t-0" :data-shift="entry.session_id">
                        <component :is="expandedIds.has(entry.session_id) ? 'div' : 'details'" class="min-w-0">
                            <summary v-if="!expandedIds.has(entry.session_id)" class="flex min-h-16 cursor-pointer list-none flex-wrap items-center gap-sm px-md py-sm focus-visible:outline-primary md:px-lg">
                                <strong class="font-mono text-sm">{{ entry.shift.start_time }}</strong>
                                <span class="min-w-0 flex-1 break-words text-sm font-semibold">{{ entry.shift.class_name }}</span>
                                <span :class="['rounded-full px-sm py-xs text-xs font-semibold', pillTone[entry.status.tone]]">{{ entry.status.label }}</span>
                                <span class="material-symbols-outlined shrink-0" aria-hidden="true">expand_more</span>
                            </summary>
                            <ShiftBody :shift="entry.shift" :steps="entry.steps" :status="entry.status" :status-class="pillTone[entry.status.tone]" />
                        </component>
                    </article>

                    <UiEmptyState v-if="!shifts.length" compact icon="event_busy" title="Không có ca dạy hôm nay" description="Lịch dạy được sinh từ TKB của các lớp bạn phụ trách." />
                </section>
                <section v-if="pendingSessions.length" id="diem-danh-bu" aria-labelledby="diem-danh-bu-title" class="scroll-mt-md overflow-hidden rounded-xl border border-error-container bg-surface-container-lowest" data-testid="pending-attendance">
                    <div class="space-y-xs border-b border-error-container bg-[#fff8f7] px-md py-sm md:px-lg">
                        <h2 id="diem-danh-bu-title" class="text-base font-bold text-on-error-container">Buổi đã dạy chưa điểm danh · {{ pendingSessions.length }}</h2>
                        <p class="text-sm text-on-surface-variant">Trong {{ attendanceWindowHours }}h bạn tự điểm danh bù; quá hạn thì báo Học vụ.</p>
                    </div>
                    <ul class="divide-y divide-[#f3e4e1]">
                        <li v-for="s in visiblePending" :key="s.id" class="flex flex-wrap items-center gap-sm px-md py-sm md:px-lg">
                            <div class="w-12 shrink-0">
                                <div class="text-xs font-semibold text-on-surface-variant">{{ s.dow }}</div>
                                <div class="font-bold text-on-surface">{{ s.day }}</div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-on-surface">{{ s.class_name }}</div>
                                <div class="text-xs text-on-surface-variant">{{ s.time }}<template v-if="s.type_label"> · {{ s.type_label }}</template></div>
                            </div>
                            <div class="flex w-full flex-wrap items-center justify-end gap-sm md:w-auto">
                                <UiBadge :color="s.open ? 'warning' : 'error'" :dot="false" pill>
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                                    {{ s.open ? `Còn ${s.hours_left} giờ` : `Quá ${attendanceWindowHours}h` }}
                                </UiBadge>
                                <UiButton size="sm" class="min-h-11" :variant="s.open ? 'primary' : 'secondary'" :href="attendanceUrl(s.class_id, s.id)" modal="drawer">
                                    {{ s.open ? 'Điểm danh bù' : 'Xem buổi' }}
                                </UiButton>
                            </div>
                        </li>
                    </ul>
                    <button v-if="pendingSessions.length > PENDING_PREVIEW && !showAllPending" type="button" class="inline-flex min-h-11 w-full items-center border-t border-[#f3e4e1] px-md py-[10px] text-left text-sm font-semibold text-primary hover:underline md:px-lg" @click="showAllPending = true">
                        Xem tất cả {{ pendingSessions.length }} buổi
                    </button>
                </section>
                <section aria-labelledby="lich-tuan" :class="[card, 'overflow-hidden']">
                    <div class="flex flex-wrap items-center justify-between gap-xs border-b border-surface-container px-md py-sm md:px-lg">
                        <h2 id="lich-tuan" class="text-base font-bold text-on-surface">Lịch dạy tuần {{ week.label }}</h2>
                        <div class="inline-flex gap-[2px] rounded-[10px] bg-[#f1f3ff] p-[3px]">
                            <Link :href="route('teacher.home', { week: week.prev })" preserve-scroll aria-label="Tuần trước" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-lowest">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">chevron_left</span>
                            </Link>
                            <Link :href="route('teacher.home')" preserve-scroll :class="['inline-flex min-h-11 items-center rounded-lg px-sm text-sm font-semibold', week.is_current ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container-lowest']">Tuần này</Link>
                            <Link :href="route('teacher.home', { week: week.next })" preserve-scroll aria-label="Tuần sau" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-lowest">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">chevron_right</span>
                            </Link>
                        </div>
                    </div>
                    <div class="custom-scrollbar flex gap-2 overflow-x-auto p-sm snap-x snap-mandatory md:grid md:grid-cols-7 md:snap-none">
                        <div v-for="day in weekDays" :key="day.label" :class="['min-h-28 !min-w-[7.5rem] shrink-0 snap-start rounded-lg border border-outline-variant px-xs py-sm md:!min-w-0 md:flex-none', day.is_today ? 'bg-primary-light' : '']">
                            <div :class="['text-xs font-bold', day.is_today ? 'text-primary' : 'text-on-surface-variant']">{{ day.label }}</div>
                            <component
                                :is="s.can_take ? 'a' : 'div'"
                                v-for="s in day.sessions"
                                :key="s.id"
                                :href="s.can_take ? attendanceUrl(s.class_id, s.id) : undefined"
                                :class="['mt-[6px] flex min-h-11 min-w-0 flex-col justify-center rounded-lg border bg-surface-container-lowest px-xs py-[6px] text-xs', weekState(s).border, s.can_take ? 'hover:bg-surface-container-low' : '', s.cancelled ? 'line-through' : '']"
                                @click="s.can_take && ($event.preventDefault(), openDrawer(attendanceUrl(s.class_id, s.id)))"
                            >
                                <span class="block truncate font-bold text-on-surface" :title="s.time">{{ s.time }}</span>
                                <span class="block truncate text-on-surface-variant" :title="s.class_name">{{ s.class_name }}</span>
                                <span v-if="s.type_label" class="block truncate text-on-surface-variant" :title="s.type_label">{{ s.type_label }}</span>
                                <span :class="['mt-[2px] block truncate font-semibold', weekState(s).cls]">{{ weekState(s).label }}</span>
                                <span v-if="s.checkin_overdue" class="block text-xs font-semibold text-error">Quá hạn chấm công</span>
                            </component>
                        </div>
                    </div>
                </section>
            </div>
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
                            <UiBadge color="error" :dot="false" class="self-start">{{ score.score }}</UiBadge>
                        </li>
                    </ul>
                    <p v-if="!attention.length" class="border-t border-surface-container pt-sm font-body-small text-body-small text-on-surface-variant">Không có học sinh nào dưới mục tiêu trong 30 ngày qua.</p>
                </section>
            </aside>
        </div>
    </div>

    <TeacherBottomNav />
</template>
