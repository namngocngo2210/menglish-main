<script setup>
/**
 * Portal trợ giảng — "Nhiệm vụ hôm nay" (thiết kế lại 28/10/2026, canvas "Danh sách lớp – thiết kế lại"): full màn,
 * đầu trang gồm ngày + tiến độ dạng thanh + ‹ Hôm nay ›; "Lớp trực hôm nay" lên đầu (gộp buổi bổ trợ, thay tab Buổi bổ trợ
 * của tôi); việc tồn ngày trước hiện thành danh sách báo xong được ngay; 3 ca 01 Trước / 02 Trong / 03 Sau giờ học nằm thành
 * 3 cột trên máy tính, trên điện thoại chọn ca bằng thanh chọn nhanh. Quy tắc hoàn thành + bảng màu trạng thái nằm sau nút "i".
 * Admin / quản lý / học vụ chọn xem trợ giảng bất kỳ trong phạm vi.
 * Hoàn thành: có ảnh → hoàn thành ngay; không ảnh → chờ Admin xác nhận (quyết định ở server).
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { urlWith } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhiệm vụ hôm nay' } });

const props = defineProps({
    title: { type: String, required: true },
    taUser: { type: Object, default: null },
    progress: { type: Object, default: () => ({ done: 0, total: 0 }) },
    date: { type: String, required: true },
    isToday: { type: Boolean, default: true },
    canPickTa: { type: Boolean, default: false },
    assistants: { type: Array, default: () => [] },
    overdueCount: { type: Number, default: 0 },
    overdueTasks: { type: Array, default: () => [] },
    canComplete: { type: Boolean, default: false },
    hasTasks: { type: Boolean, default: false },
    firstOpen: { type: String, default: 'before' },
    groups: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
});

const statusColors = {
    new: 'status-new', in_progress: 'status-progress', pending_confirmation: 'status-pending',
    blocked: 'status-blocked', completed: 'status-done', overdue: 'status-overdue', canceled: 'status-canceled',
};
const groupMeta = {
    before: { no: '01', tone: 'bg-secondary-fixed text-secondary', note: 'Chuẩn bị trang thiết bị, tài liệu và đón học sinh trước ca học 15 phút' },
    during: { no: '02', tone: 'bg-warning-container text-warning', note: 'Hỗ trợ giáo viên điểm danh, quản lý nền nếp và kèm cặp học viên yếu' },
    after: { no: '03', tone: 'bg-tertiary-container/20 text-tertiary', note: 'Hoàn tất tổng hợp sĩ số, vệ sinh phòng học, tắt thiết bị điện và nộp báo cáo ca' },
};
const legend = [
    ['new', 'Mới'], ['in_progress', 'Đang thực hiện'], ['pending_confirmation', 'Chờ xác nhận'], ['blocked', 'Bị chặn'],
    ['completed', 'Hoàn thành'], ['overdue', 'Quá hạn'], ['canceled', 'Đã hủy'],
];
const percent = computed(() => (props.progress.total ? Math.round((props.progress.done / props.progress.total) * 100) : 0));
const cardTone = (task) =>
    task.status === 'completed' ? 'border-tertiary/20 bg-tertiary-container/5'
        : task.status === 'pending_confirmation' ? 'border-warning/30 bg-warning-container/30'
            : task.status === 'overdue' ? 'border-error/20 bg-error-container/10'
                : 'border-outline-variant bg-surface-container-lowest';
// Điện thoại: một ca một lúc, mở sẵn ca đầu tiên có việc (máy tính hiện cả 3 cột).
const activeGroup = ref(props.firstOpen);
const shortTitle = { before: 'Trước', during: 'Trong', after: 'Sau' };
const isDone = (task) => ['completed', 'canceled'].includes(task.status);

/** Đổi ngày / trợ giảng → tải lại portal theo lựa chọn mới. */
const filter = (key, value) => router.get(urlWith({ [key]: value }), {}, { preserveScroll: true });
const todayUrl = () => route('portal.ta-tasks', props.canPickTa && props.taUser ? { ta_id: props.taUser.id } : {});
/** ‹ / › : lùi / tiến một ngày (tính theo ngày lịch, không lệch múi giờ). */
function shiftDay(days) {
    const [y, m, d] = props.date.split('-').map(Number);
    const next = new Date(Date.UTC(y, m - 1, d + days));
    filter('date', next.toISOString().slice(0, 10));
}

// Modal "Cập nhật tiến độ"
const selected = ref(null);
const proofName = ref('');
function openComplete(task) {
    selected.value = task;
    proofName.value = '';
}

function openReport(event) {
    event.preventDefault();
    openRemoteModal(route('tasks.class-reports.create'), { size: '2xl' });
}
</script>

<template>
    <div class="space-y-lg pb-24 md:pb-0">
        <!-- Đầu trang: tiêu đề + ngày / người, bên phải tiến độ và đổi ngày -->
        <header class="flex flex-col gap-md lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-sm">
                    <Head :title="title" />
                    <h1 class="font-h2 text-h2 text-on-surface">{{ title }}</h1>
                    <span class="rounded-full bg-primary-container px-sm py-[2px] font-caption text-caption font-semibold text-white">Trợ giảng</span>
                    <UiInfo title="Quy tắc hoàn thành nhiệm vụ" width="96" label="Quy tắc hoàn thành nhiệm vụ">
                        <p><strong class="text-on-surface">Đầu việc không gắn lớp:</strong> đính kèm ảnh minh chứng sẽ <strong class="text-tertiary">hoàn thành ngay</strong>. Không có ảnh thì chuyển sang <strong class="text-warning">chờ xác nhận</strong> (Admin xác nhận ở màn duyệt).</p>
                        <p><strong class="text-on-surface">Đầu việc trực lớp:</strong> bấm <strong class="text-primary">Nộp báo cáo trực lớp</strong> để gửi thông tin chi tiết của buổi học.</p>
                        <p class="pt-xs font-semibold uppercase text-on-surface">Mã màu trạng thái</p>
                        <div class="flex flex-wrap gap-xs">
                            <UiBadge v-for="[key, label] in legend" :key="key" :color="statusColors[key]" pill :class="key === 'canceled' ? 'line-through' : ''">{{ label }}</UiBadge>
                        </div>
                    </UiInfo>
                </div>
                <p class="mt-xs font-body-small text-body-small text-on-surface-variant">
                    {{ formatDate(date, 'l, d/m/Y') }}
                    <template v-if="taUser"> · <span class="font-medium text-on-surface">{{ taUser.name }}</span><template v-if="taUser.branch"> · {{ taUser.branch }}</template></template>
                    <template v-else> · Chưa có trợ giảng nào trong hệ thống.</template>
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-sm">
                <div v-if="taUser" class="flex min-w-[220px] flex-1 flex-col gap-xs rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm sm:flex-none" data-ta-progress>
                    <p class="flex items-center justify-between gap-md font-caption text-caption">
                        <span class="text-on-surface-variant">Tiến độ {{ isToday ? 'hôm nay' : 'trong ngày' }}</span>
                        <span class="font-bold text-on-surface"><span class="text-primary">{{ progress.done }}</span> / {{ progress.total }} xong</span>
                    </p>
                    <div class="h-1.5 overflow-hidden rounded-full bg-surface-container-highest" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" :aria-label="`Hoàn thành ${percent}%`">
                        <div class="h-full rounded-full bg-primary-container" :style="{ width: `${percent}%` }" />
                    </div>
                </div>
                <div v-if="canPickTa" class="min-w-[180px] flex-1 sm:w-56 sm:flex-none">
                    <UiSelect
                        id="f_ta_id"
                        label="Trợ giảng"
                        aria-label="Chọn trợ giảng"
                        :model-value="taUser?.id ?? ''"
                        :options="assistants.length ? assistants : [{ value: '', label: 'Chưa có trợ giảng' }]"
                        @update:model-value="(v) => filter('ta_id', v)"
                    />
                </div>
                <div class="flex items-center gap-xs rounded-xl bg-surface-container-low p-[3px]">
                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-lowest" aria-label="Ngày trước" @click="shiftDay(-1)">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_left</span>
                    </button>
                    <Link v-if="!isToday" :href="todayUrl()" class="flex h-10 items-center rounded-lg px-sm font-caption text-caption font-semibold text-primary hover:bg-surface-container-lowest">Hôm nay</Link>
                    <label class="relative flex h-10 items-center rounded-lg bg-surface-container-lowest px-sm font-caption text-caption font-semibold text-primary shadow-sm">
                        <span class="sr-only">Chọn ngày</span>
                        <input id="f_date" type="date" :value="date" class="border-0 bg-transparent p-0 font-caption text-caption font-semibold text-primary focus:ring-0" @change="(e) => e.target.value && filter('date', e.target.value)" />
                    </label>
                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-lowest" aria-label="Ngày sau" @click="shiftDay(1)">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_right</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Lớp trực trong ngày (từ buổi học thật); buổi bổ trợ mở thẳng màn hoàn thành buổi -->
        <section v-if="taUser" id="lop-hoc" class="flex flex-col gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-sm lg:flex-row lg:items-stretch" aria-labelledby="lop-truc-title">
            <div class="flex items-center justify-between gap-sm px-sm lg:w-44 lg:flex-col lg:items-start lg:justify-center">
                <div>
                    <h2 id="lop-truc-title" class="font-body-medium text-body-medium font-bold text-on-surface">Lớp trực {{ isToday ? 'hôm nay' : 'ngày ' + formatDate(date, 'd/m') }}</h2>
                    <p class="font-caption text-caption text-on-surface-variant">{{ sessions.length }} buổi · từ lịch học</p>
                </div>
                <a v-if="canComplete" :href="route('tasks.class-reports.create')" data-modal-size="2xl" class="shrink-0 font-caption text-caption font-semibold text-primary hover:underline" @click="openReport">Nộp báo cáo trực lớp</a>
            </div>
            <div v-if="sessions.length" class="grid flex-1 gap-sm md:grid-cols-2">
                <article v-for="s in sessions" :key="s.id" class="flex flex-wrap items-center gap-sm rounded-xl border border-surface-container-high p-sm" :data-session-id="s.id">
                    <div :class="['w-[76px] shrink-0 rounded-lg py-xs text-center', s.support ? 'bg-secondary-fixed text-secondary' : 'bg-primary-fixed text-primary']">
                        <p class="font-code text-body-medium font-bold">{{ s.start }}</p>
                        <p class="font-caption text-[11px] text-on-surface-variant">đến {{ s.end }}</p>
                    </div>
                    <div class="min-w-[160px] flex-1">
                        <p class="flex flex-wrap items-center gap-xs">
                            <span class="truncate font-body-medium text-body-medium font-bold text-on-surface">{{ s.class_name }}</span>
                            <UiBadge v-if="s.support" color="info" pill>Buổi bổ trợ</UiBadge>
                            <UiBadge v-else-if="s.makeup" color="warning" pill>Học bù</UiBadge>
                        </p>
                        <p class="truncate font-caption text-caption text-on-surface-variant">{{ s.room || 'Chưa có phòng' }}{{ s.branch ? ' · ' + s.branch : '' }}</p>
                    </div>
                    <UiButton v-if="s.support" size="sm" icon="check_circle" :href="route('tasks.support-sessions', { mine: 1 })">Hoàn thành buổi</UiButton>
                </article>
            </div>
            <p v-else class="flex flex-1 items-center px-sm py-xs font-body-small text-body-small italic text-on-surface-variant">Không có buổi học nào trong ngày.</p>
        </section>

        <!-- Việc tồn của các ngày trước: xử lý ngay tại đây -->
        <section v-if="overdueCount > 0" class="overflow-hidden rounded-2xl border border-error/20 bg-surface-container-lowest" aria-labelledby="viec-ton-title" data-overdue>
            <div class="flex flex-wrap items-center justify-between gap-sm border-b border-error/20 bg-error-container/20 px-md py-sm sm:px-lg">
                <h2 id="viec-ton-title" class="font-body-medium text-body-medium font-bold text-error">Việc của ngày trước chưa xong · {{ overdueCount }}</h2>
                <span class="font-caption text-caption text-on-surface-variant">Báo xong kèm ảnh để được tính hoàn thành.</span>
            </div>
            <div class="divide-y divide-surface-container">
                <article v-for="task in overdueTasks" :key="task.id" class="flex flex-col gap-sm px-md py-sm sm:flex-row sm:items-center sm:px-lg" :data-task-id="task.id">
                    <div class="min-w-0 flex-1">
                        <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ task.title }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            {{ task.class_label ? 'Trực lớp: ' + task.class_label : 'Công việc chung' }} · Hạn {{ task.due_label }}<template v-if="task.creator"> · Người giao: {{ task.creator }}</template>
                        </p>
                    </div>
                    <UiBadge :color="statusColors[task.status] ?? 'neutral'" pill>{{ task.status === 'overdue' ? `Quá hạn · trễ ${task.late_hours} giờ` : task.status_label }}</UiBadge>
                    <UiButton
                        v-if="task.class_label && canComplete"
                        size="sm"
                        variant="secondary"
                        icon="edit_document"
                        :href="route('tasks.class-reports.create', { task_id: task.id, class_id: task.class_id })"
                        modal="2xl"
                        >Nộp báo cáo trực lớp</UiButton
                    >
                    <UiButton v-else-if="canComplete" size="sm" variant="secondary" icon="check_circle" @click="openComplete(task)">Báo xong</UiButton>
                </article>
                <p v-if="overdueCount > overdueTasks.length" class="px-md py-sm font-caption text-caption text-on-surface-variant sm:px-lg">Còn {{ overdueCount - overdueTasks.length }} việc cũ hơn — lùi ngày bằng nút ‹ để xem.</p>
            </div>
        </section>

        <!-- 3 ca cố định: 3 cột trên máy tính; điện thoại chọn ca bằng thanh chọn nhanh -->
        <section id="nhiem-vu" class="space-y-sm" aria-label="Nhiệm vụ">
            <div class="grid grid-cols-3 gap-xs rounded-xl bg-surface-container-low p-[3px] lg:hidden" role="tablist" aria-label="Chọn ca">
                <button
                    v-for="group in groups"
                    :key="group.key"
                    type="button"
                    role="tab"
                    :aria-selected="activeGroup === group.key ? 'true' : 'false'"
                    :class="['h-10 rounded-lg font-caption text-caption font-semibold', activeGroup === group.key ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant']"
                    @click="activeGroup = group.key"
                >
                    {{ shortTitle[group.key] ?? group.title }} · {{ group.tasks.length }}
                </button>
            </div>

            <div class="grid items-start gap-md lg:grid-cols-3">
                <div
                    v-for="group in groups"
                    :key="group.key"
                    :class="['overflow-hidden rounded-2xl border border-outline-variant bg-surface-container-lowest shadow-sm', activeGroup === group.key ? '' : 'hidden lg:block']"
                    :data-task-group="group.key"
                >
                    <div class="flex items-start gap-sm border-b border-outline-variant bg-surface-container-low/60 px-md py-sm">
                        <span :class="['flex h-7 w-7 shrink-0 items-center justify-center rounded-lg font-code text-caption font-bold', groupMeta[group.key]?.tone]">{{ groupMeta[group.key]?.no }}</span>
                        <div class="min-w-0 flex-1">
                            <h2 class="flex items-center justify-between gap-sm font-h3 text-h3 text-on-surface">
                                {{ group.title }}
                                <span class="shrink-0 font-caption text-caption font-semibold text-on-surface-variant">{{ group.tasks.length }} việc</span>
                            </h2>
                            <p class="font-caption text-caption text-on-surface-variant">{{ groupMeta[group.key]?.note }}</p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-sm p-sm">
                        <article v-for="task in group.tasks" :key="task.id" :class="['flex flex-col gap-sm rounded-xl border p-sm', cardTone(task)]" :data-task-id="task.id">
                            <div class="flex items-start justify-between gap-sm">
                                <h3 :class="['font-body-medium text-body-medium font-semibold', isDone(task) ? 'text-on-surface-variant line-through' : 'text-on-surface']">{{ task.title }}</h3>
                                <UiBadge :color="statusColors[task.status] ?? 'neutral'" pill class="shrink-0">{{ task.status_label }}</UiBadge>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-sm gap-y-xs font-caption text-caption text-on-surface-variant">
                                <span v-if="task.class_label" class="inline-flex items-center gap-xs rounded border border-primary-container/20 bg-primary-fixed px-sm py-[2px] font-semibold text-primary">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">school</span>Trực lớp: {{ task.class_label }}<template v-if="task.room"> (Phòng {{ task.room }})</template>
                                </span>
                                <span v-else class="inline-flex items-center gap-xs rounded bg-surface-container-high px-sm py-[2px]">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">assignment</span>Công việc chung
                                </span>
                                <template v-if="task.status === 'completed'">
                                    <span v-if="task.has_proof" class="inline-flex items-center gap-xs font-medium text-tertiary"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">image</span>Có ảnh minh chứng</span>
                                    <span v-if="task.completed_at">Xong lúc {{ task.completed_at }}</span>
                                </template>
                                <span v-else-if="task.status === 'pending_confirmation'" class="font-medium text-warning">Đã gửi xác nhận (không đính ảnh)</span>
                                <span v-else-if="task.status === 'overdue'" class="inline-flex items-center gap-xs font-semibold text-error"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>Trễ {{ task.late_hours }} giờ</span>
                                <span v-else-if="task.status === 'blocked' && task.blocked_reason" class="inline-flex items-center gap-xs text-status-blocked"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ task.blocked_reason }}</span>
                                <span v-else-if="task.session_time" class="font-medium text-on-surface">Ca học: {{ task.session_time }}</span>
                                <span v-else-if="task.status !== 'canceled'" class="font-medium text-on-surface">Hạn: {{ task.due_label }}</span>
                                <span v-if="task.creator">Người giao: {{ task.creator }}</span>
                            </div>
                            <p v-if="task.rejection_reason" class="rounded bg-error-container/40 px-sm py-xs font-caption text-caption text-error">Bị trả về: {{ task.rejection_reason }}</p>

                            <div class="flex">
                                <UiButton
                                    v-if="task.class_label && ['new', 'in_progress', 'overdue', 'blocked'].includes(task.status) && canComplete"
                                    icon="edit_document"
                                    :href="route('tasks.class-reports.create', { task_id: task.id, class_id: task.class_id })"
                                    modal="2xl"
                                    >Nộp báo cáo trực lớp</UiButton
                                >
                                <UiButton v-else-if="canComplete && ['new', 'in_progress'].includes(task.status)" variant="success" icon="check_circle" @click="openComplete(task)">Hoàn thành</UiButton>
                                <UiButton v-else-if="canComplete && task.status === 'overdue'" variant="secondary" icon="check_circle" @click="openComplete(task)">Báo xong</UiButton>
                                <span v-else-if="task.status === 'completed'" class="inline-flex items-center gap-xs font-caption text-caption font-semibold text-tertiary">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified</span>{{ task.has_proof ? 'Đã xác nhận tự động' : 'Đã xác nhận' }}
                                </span>
                                <span v-else-if="task.pending_label" class="inline-flex items-center gap-xs font-caption text-caption font-semibold text-on-warning-container">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>{{ task.pending_label }}
                                </span>
                            </div>
                        </article>
                        <p v-if="!group.tasks.length" class="px-xs py-sm font-body-small text-body-small italic text-on-surface-variant">Không có nhiệm vụ {{ group.title.toLowerCase() }}.</p>
                    </div>
                </div>
            </div>

            <UiEmptyState v-if="taUser && !hasTasks" icon="task_alt" title="Không có nhiệm vụ trong ngày" :description="`${taUser.name} chưa được giao nhiệm vụ nào cho ngày ${formatDate(date)}.`" />
        </section>

        <!-- Thanh điều hướng dưới (điện thoại): cùng 3 mục của cổng trợ giảng + Cá nhân -->
        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-outline-variant bg-surface-container-lowest md:hidden" aria-label="Điều hướng portal trợ giảng">
            <ul :class="['mx-auto grid max-w-md gap-xs px-sm py-xs', can('attendance_student.record') ? 'grid-cols-4' : 'grid-cols-3']">
                <li>
                    <a href="#nhiem-vu" class="flex flex-col items-center gap-[2px] rounded-xl bg-primary-container px-xs py-xs text-white" aria-current="page">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">assignment</span>
                        <span class="text-xs font-semibold">Nhiệm vụ</span>
                    </a>
                </li>
                <li v-if="can('attendance_student.record')">
                    <Link :href="route('teacher.home')" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">calendar_month</span>
                        <span class="text-xs font-semibold">Lịch dạy</span>
                    </Link>
                </li>
                <li>
                    <Link :href="route('classes.index', { mine: 1 })" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">school</span>
                        <span class="text-xs font-semibold">Lớp của tôi</span>
                    </Link>
                </li>
                <li>
                    <Link :href="route('profile.edit')" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">person</span>
                        <span class="text-xs font-semibold">Cá nhân</span>
                    </Link>
                </li>
            </ul>
        </nav>

        <!-- Modal hoàn thành nhiệm vụ -->
        <UiModal :show="!!selected" title="Cập nhật tiến độ" max-width="md" @close="selected = null">
            <UiForm v-if="selected" id="ta-complete-form" :action="route('tasks.complete', selected.id)" method="post" class="space-y-md" @success="selected = null" #default="{ errors }">
                <div class="rounded-lg bg-surface-container-low p-sm">
                    <p class="font-body-medium text-body-medium font-semibold">Nhiệm vụ: {{ selected.title }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">Hạn chót: {{ selected.due_label }}</p>
                </div>
                <div class="space-y-xs">
                    <span class="block font-label text-label uppercase text-on-surface-variant">Bằng chứng hình ảnh</span>
                    <label for="ta_proof" class="flex cursor-pointer flex-col items-center justify-center gap-xs rounded-lg border-2 border-dashed border-outline-variant p-md text-center text-on-surface-variant hover:border-primary-container">
                        <span class="material-symbols-outlined text-[32px]" aria-hidden="true">cloud_upload</span>
                        <span class="font-body-small text-body-small font-semibold">{{ proofName || 'Nhấn để tải ảnh lên' }}</span>
                        <span class="font-caption text-caption">PNG, JPG tối đa 10MB</span>
                    </label>
                    <input id="ta_proof" type="file" name="proof_image" accept="image/*" class="sr-only" @change="proofName = $event.target.files[0]?.name || ''" />
                    <UiErrors :messages="[errors.proof_image, errors.proof_image_url]" />
                </div>
                <UiAlert type="info"><strong>Lưu ý:</strong> Có ảnh đính kèm, nhiệm vụ sẽ được <strong>hoàn thành ngay</strong>. Nếu không có ảnh, trạng thái sẽ chuyển sang <strong>chờ Admin xác nhận</strong>.</UiAlert>
                <UiTextarea id="ta_note" name="note" label="Ghi chú (tùy chọn)" :rows="3" placeholder="Kết quả hoặc vấn đề phát sinh..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="selected = null">Hủy</UiButton>
                <UiButton type="submit" form="ta-complete-form" icon="send">Xác nhận</UiButton>
            </template>
        </UiModal>
    </div>
</template>
