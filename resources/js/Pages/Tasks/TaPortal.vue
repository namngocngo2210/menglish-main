<script setup>
/**
 * Portal trợ giảng — "Nhiệm vụ hôm nay" (mockup TA checklist 3 mục, 14/10/2026): thẻ tiêu đề kèm tiến độ trong ngày,
 * 3 ca cố định 01 Trước / 02 Trong / 03 Sau giờ học, mỗi việc một dòng (chip lớp trực / công việc chung, giờ, người giao)
 * và nút bên phải. Quy tắc hoàn thành + bảng màu trạng thái nằm sau nút "i". Có thanh điều hướng dưới trên điện thoại.
 * Admin / quản lý / học vụ chọn xem trợ giảng bất kỳ trong phạm vi.
 * Hoàn thành: có ảnh → hoàn thành ngay; không ảnh → chờ Admin xác nhận (quyết định ở server).
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
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
const rowTone = (task) => (task.status === 'completed' ? 'bg-tertiary-container/5' : task.status === 'pending_confirmation' ? 'bg-warning-container/30' : task.status === 'overdue' ? 'bg-error-container/10' : '');
const open = reactive(Object.fromEntries(props.groups.map((g) => [g.key, g.key === props.firstOpen || g.tasks.length > 0])));
const isDone = (task) => ['completed', 'canceled'].includes(task.status);

/** Đổi ngày / trợ giảng → tải lại portal theo lựa chọn mới. */
const filter = (key, value) => router.get(urlWith({ [key]: value }), {}, { preserveScroll: true });
const todayUrl = () => route('portal.ta-tasks', props.canPickTa && props.taUser ? { ta_id: props.taUser.id } : {});

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
    <div class="mx-auto max-w-4xl space-y-lg pb-24 md:pb-0">
        <!-- Tiêu đề + tiến độ hôm nay -->
        <header class="flex flex-col gap-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-lg">
            <div class="flex items-start gap-md sm:items-center">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-primary-container/20 bg-primary-fixed text-primary" aria-hidden="true">
                    <span class="material-symbols-outlined text-[26px]">task_alt</span>
                </span>
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
                    <p class="mt-xs flex flex-wrap items-center gap-x-sm gap-y-xs font-body-small text-body-small text-on-surface-variant">
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">calendar_today</span>{{ isToday ? 'Hôm nay' : 'Ngày' }}: {{ formatDate(date, 'l, d/m/Y') }}</span>
                        <template v-if="taUser">
                            <span class="text-outline-variant" aria-hidden="true">•</span>
                            <span class="font-medium text-on-surface">{{ taUser.name }}<template v-if="taUser.branch"> ({{ taUser.branch }})</template></span>
                        </template>
                        <span v-else>Chưa có trợ giảng nào trong hệ thống.</span>
                    </p>
                </div>
            </div>
            <div v-if="taUser" class="flex shrink-0 items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-low px-md py-sm" data-ta-progress>
                <div class="text-right">
                    <p class="font-caption text-caption text-on-surface-variant">Tiến độ {{ isToday ? 'hôm nay' : 'trong ngày' }}</p>
                    <p class="font-body-medium text-body-medium font-bold text-on-surface"><span class="text-primary">{{ progress.done }}</span> / {{ progress.total }} hoàn thành</p>
                </div>
                <div class="relative flex h-11 w-11 items-center justify-center">
                    <svg class="h-11 w-11 -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
                        <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="3.5" class="stroke-surface-container-highest" />
                        <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="3.5" stroke-linecap="round" class="stroke-primary-container" :stroke-dasharray="`${percent}, 100`" />
                    </svg>
                    <span class="absolute text-[11px] font-bold text-on-surface">{{ percent }}%</span>
                </div>
            </div>
        </header>

        <!-- Bộ lọc: ngày (+ chọn trợ giảng cho admin / quản lý) -->
        <div class="flex flex-wrap items-end gap-sm">
            <div v-if="canPickTa" class="min-w-[180px] flex-1 sm:max-w-xs">
                <UiSelect
                    id="f_ta_id"
                    label="Trợ giảng"
                    aria-label="Chọn trợ giảng"
                    :model-value="taUser?.id ?? ''"
                    :options="assistants.length ? assistants : [{ value: '', label: 'Chưa có trợ giảng' }]"
                    @update:model-value="(v) => filter('ta_id', v)"
                />
            </div>
            <div class="min-w-[150px] sm:w-48">
                <UiInput id="f_date" type="date" label="Ngày" aria-label="Chọn ngày" :model-value="date" @change="(e) => filter('date', e.target.value)" />
            </div>
            <UiButton v-if="!isToday" size="sm" variant="ghost" icon="today" :href="todayUrl()">Hôm nay</UiButton>
        </div>

        <UiAlert v-if="overdueCount > 0" type="warning">Còn <strong>{{ overdueCount }}</strong> nhiệm vụ của các ngày trước chưa hoàn thành.</UiAlert>

        <!-- 3 ca cố định -->
        <section id="nhiem-vu" class="space-y-md" aria-label="Nhiệm vụ">
            <div v-for="group in groups" :key="group.key" class="overflow-hidden rounded-2xl border border-outline-variant bg-surface-container-lowest shadow-sm" :data-task-group="group.key">
                <button type="button" :aria-expanded="open[group.key] ? 'true' : 'false'" :class="['flex w-full items-center justify-between gap-sm bg-surface-container-low/60 px-md py-sm text-left sm:px-lg', open[group.key] ? 'border-b border-outline-variant' : '']" @click="open[group.key] = !open[group.key]">
                    <span class="flex items-center gap-sm">
                        <span :class="['flex h-7 w-7 shrink-0 items-center justify-center rounded-lg font-code text-caption font-bold', groupMeta[group.key]?.tone]">{{ groupMeta[group.key]?.no }}</span>
                        <span>
                            <span class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                                {{ group.title }}
                                <span class="rounded-full bg-surface-container-high px-sm py-[2px] font-caption text-caption font-semibold text-on-surface-variant">{{ group.tasks.length }} việc</span>
                            </span>
                            <span class="block font-caption text-caption text-on-surface-variant">{{ groupMeta[group.key]?.note }}</span>
                        </span>
                    </span>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">{{ open[group.key] ? 'expand_less' : 'expand_more' }}</span>
                </button>
                <div v-show="open[group.key]" class="divide-y divide-surface-container">
                    <article
                        v-for="task in group.tasks"
                        :key="task.id"
                        :class="['flex flex-col justify-between gap-sm p-md sm:flex-row sm:items-center sm:px-lg', rowTone(task)]"
                        :data-task-id="task.id"
                    >
                        <div class="min-w-0 flex-1 space-y-xs">
                            <div class="flex flex-wrap items-center gap-sm">
                                <h3 :class="['font-body-medium text-body-medium font-semibold', isDone(task) ? 'text-on-surface-variant line-through' : 'text-on-surface']">{{ task.title }}</h3>
                                <UiBadge :color="statusColors[task.status] ?? 'neutral'" pill>{{ task.status_label }}</UiBadge>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-sm gap-y-xs font-caption text-caption text-on-surface-variant">
                                <span v-if="task.class_label" class="inline-flex items-center gap-xs rounded border border-primary-container/20 bg-primary-fixed px-sm py-[2px] font-semibold text-primary">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">school</span>Trực lớp: {{ task.class_label }}<template v-if="task.room"> (Phòng {{ task.room }})</template>
                                </span>
                                <span v-else class="inline-flex items-center gap-xs rounded bg-surface-container-high px-sm py-[2px]">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">assignment</span>Công việc chung
                                </span>
                                <template v-if="task.status === 'completed'">
                                    <span v-if="task.has_proof" class="inline-flex items-center gap-xs font-medium text-tertiary"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">image</span>Đã đính kèm ảnh minh chứng</span>
                                    <span v-if="task.completed_at">Hoàn tất lúc {{ task.completed_at }}</span>
                                </template>
                                <span v-else-if="task.status === 'pending_confirmation'" class="font-medium text-warning">Đã gửi xác nhận (không đính ảnh)</span>
                                <span v-else-if="task.status === 'overdue'" class="inline-flex items-center gap-xs font-semibold text-error"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>Trễ {{ task.late_hours }} giờ</span>
                                <span v-else-if="task.status === 'blocked' && task.blocked_reason" class="inline-flex items-center gap-xs text-status-blocked"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ task.blocked_reason }}</span>
                                <span v-else-if="task.session_time" class="font-medium text-on-surface">Ca học: {{ task.session_time }}</span>
                                <span v-else-if="task.status !== 'canceled'" class="font-medium text-on-surface">Hạn: {{ task.due_label }}</span>
                                <span v-if="task.creator">Người giao: {{ task.creator }}</span>
                            </div>
                            <p v-if="task.rejection_reason" class="rounded bg-error-container/40 px-sm py-xs font-caption text-caption text-error">Bị trả về: {{ task.rejection_reason }}</p>
                        </div>

                        <div class="flex shrink-0 flex-col gap-xs sm:items-end">
                            <UiButton
                                v-if="task.class_label && ['new', 'in_progress', 'overdue', 'blocked'].includes(task.status) && canComplete"
                                icon="edit_document"
                                :href="route('tasks.class-reports.create', { task_id: task.id, class_id: task.class_id })"
                                modal="2xl"
                                >Nộp báo cáo trực lớp</UiButton
                            >
                            <UiButton v-else-if="canComplete && ['new', 'in_progress'].includes(task.status)" variant="success" icon="check_circle" @click="openComplete(task)">Hoàn thành</UiButton>
                            <UiButton v-else-if="canComplete && task.status === 'overdue'" variant="secondary" icon="check_circle" @click="openComplete(task)">Báo xong</UiButton>
                            <span v-else-if="task.status === 'completed'" class="inline-flex items-center gap-xs rounded-lg border border-tertiary/20 bg-tertiary-container/10 px-sm py-xs font-caption text-caption font-medium text-tertiary">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified</span>{{ task.has_proof ? 'Đã xác nhận tự động' : 'Đã xác nhận' }}
                            </span>
                            <span v-else-if="task.pending_label" class="inline-flex items-center gap-xs rounded-lg border border-warning/20 bg-warning-container px-sm py-xs font-caption text-caption font-medium text-on-warning-container">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>{{ task.pending_label }}
                            </span>
                        </div>
                    </article>
                    <p v-if="!group.tasks.length" class="px-md py-md font-body-small text-body-small italic text-on-surface-variant sm:px-lg">Không có nhiệm vụ {{ group.title.toLowerCase() }}.</p>
                </div>
            </div>

            <UiEmptyState v-if="taUser && !hasTasks" icon="task_alt" title="Không có nhiệm vụ trong ngày" :description="`${taUser.name} chưa được giao nhiệm vụ nào cho ngày ${formatDate(date)}.`" />
        </section>

        <!-- Lớp trực trong ngày (từ buổi học thật) -->
        <section id="lop-hoc" class="mt-lg space-y-sm" aria-label="Lớp trực">
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">school</span>
                Lớp trực {{ isToday ? 'hôm nay' : 'ngày ' + formatDate(date, 'd/m') }}
            </h2>
            <div v-for="s in sessions" :key="s.id" class="flex items-center justify-between gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <div class="min-w-0">
                    <p class="truncate font-body-medium text-body-medium font-semibold text-on-surface">{{ s.class_name }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">
                        <span class="font-code">{{ s.start }} - {{ s.end }}</span>
                        · {{ s.room || 'Chưa có phòng' }}{{ s.branch ? ' · ' + s.branch : '' }}
                    </p>
                </div>
                <UiBadge v-if="s.makeup" color="warning">Học bù</UiBadge>
            </div>
            <p v-if="!sessions.length" class="font-body-small text-body-small italic text-on-surface-variant">Không có buổi học nào trong ngày.</p>
        </section>

        <!-- Thanh điều hướng dưới (điện thoại) -->
        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-outline-variant bg-surface-container-lowest md:hidden" aria-label="Điều hướng portal trợ giảng">
            <ul class="mx-auto grid max-w-md grid-cols-4 gap-xs px-sm py-xs">
                <li>
                    <a href="#nhiem-vu" class="flex flex-col items-center gap-[2px] rounded-xl bg-primary-container px-xs py-xs text-white" aria-current="page">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">assignment</span>
                        <span class="text-xs font-semibold">Nhiệm vụ</span>
                    </a>
                </li>
                <li>
                    <a href="#lop-hoc" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">school</span>
                        <span class="text-xs font-semibold">Lớp học</span>
                    </a>
                </li>
                <li>
                    <a :href="route('tasks.class-reports.create')" data-modal-size="2xl" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant" @click="openReport">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">bar_chart</span>
                        <span class="text-xs font-semibold">Báo cáo</span>
                    </a>
                </li>
                <li>
                    <a :href="route('profile.edit')" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">person</span>
                        <span class="text-xs font-semibold">Cá nhân</span>
                    </a>
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
