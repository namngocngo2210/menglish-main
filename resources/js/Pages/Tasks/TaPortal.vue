<script setup>
/**
 * Portal trợ giảng — "Nhiệm vụ hằng ngày" dạng điện thoại, có thanh điều hướng dưới.
 * Nhiệm vụ chia 3 ca (Trước / Trong / Sau giờ học); Admin / quản lý / học vụ chọn xem trợ giảng bất kỳ trong phạm vi.
 * Hoàn thành: có ảnh → hoàn thành ngay; không ảnh → chờ Admin xác nhận (quyết định ở server).
 */
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { urlWith } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhiệm vụ hôm nay' } });

const props = defineProps({
    title: { type: String, required: true },
    taUser: { type: Object, default: null },
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
    <div class="mx-auto max-w-md pb-24 md:max-w-6xl md:pb-0">
        <!-- Tiêu đề + người được xem -->
        <UiPageHeader :title="title">
            <template #meta>
                <template v-if="taUser">
                    Trợ giảng: <span class="font-semibold text-on-surface">{{ taUser.name }}</span> · {{ formatDate(date) }}
                </template>
                <template v-else>Chưa có trợ giảng nào trong hệ thống.</template>
            </template>
            <template v-if="taUser" #actions><UiAvatar :name="taUser.name" /></template>
        </UiPageHeader>

        <!-- Bộ lọc: ngày (+ chọn trợ giảng cho admin / quản lý) -->
        <div class="mb-md flex flex-wrap items-end gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-sm">
            <div v-if="canPickTa" class="min-w-[160px] flex-1">
                <UiSelect
                    id="f_ta_id"
                    label="Trợ giảng"
                    aria-label="Chọn trợ giảng"
                    :model-value="taUser?.id ?? ''"
                    :options="assistants.length ? assistants : [{ value: '', label: 'Chưa có trợ giảng' }]"
                    @update:model-value="(v) => filter('ta_id', v)"
                />
            </div>
            <div class="min-w-[140px] flex-1">
                <UiInput id="f_date" type="date" label="Ngày" aria-label="Chọn ngày" :model-value="date" @change="(e) => filter('date', e.target.value)" />
            </div>
            <UiButton v-if="!isToday" size="sm" variant="ghost" icon="today" :href="todayUrl()">Hôm nay</UiButton>
        </div>

        <UiAlert v-if="overdueCount > 0" type="warning" class="mb-md">Còn <strong>{{ overdueCount }}</strong> nhiệm vụ của các ngày trước chưa hoàn thành.</UiAlert>

        <!-- Nhiệm vụ theo ca -->
        <section id="nhiem-vu" class="space-y-md lg:grid lg:grid-cols-3 lg:items-start lg:gap-md lg:space-y-0" aria-label="Nhiệm vụ">
            <div v-for="group in groups" :key="group.key" class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest">
                <button type="button" :aria-expanded="open[group.key] ? 'true' : 'false'" class="flex w-full items-center justify-between gap-sm px-md py-md text-left" @click="open[group.key] = !open[group.key]">
                    <span class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">{{ group.icon }}</span>
                        <span class="font-h3 text-h3 text-on-surface">{{ group.title }}</span>
                        <span class="rounded-full bg-surface-container-high px-sm font-code text-caption text-on-surface-variant">{{ group.tasks.length }}</span>
                    </span>
                    <span :class="['material-symbols-outlined text-on-surface-variant transition-transform', open[group.key] ? 'rotate-180' : '']" aria-hidden="true">expand_more</span>
                </button>
                <div v-show="open[group.key]" class="space-y-sm px-md pb-md">
                    <article
                        v-for="task in group.tasks"
                        :key="task.id"
                        :class="['space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm', isDone(task) ? 'opacity-70' : '']"
                        :data-task-id="task.id"
                    >
                        <div class="flex items-start justify-between gap-sm">
                            <h3 :class="['font-body-medium text-body-medium font-semibold text-on-surface', isDone(task) ? 'line-through' : '']">{{ task.title }}</h3>
                            <UiBadge :color="statusColors[task.status] ?? 'neutral'" pill class="uppercase">{{ task.status_label }}</UiBadge>
                        </div>
                        <span v-if="task.class_label" class="inline-flex items-center gap-xs rounded bg-secondary-fixed/60 px-sm py-[2px] font-caption text-caption text-secondary">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">school</span>
                            Trực lớp: {{ task.class_label }}
                        </span>

                        <p v-if="task.status === 'blocked' && task.blocked_reason" class="flex items-center gap-xs font-caption text-caption text-status-blocked">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ task.blocked_reason }}
                        </p>
                        <p v-else-if="task.status === 'overdue'" class="flex items-center gap-xs font-caption text-caption font-semibold text-error">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>Trễ {{ task.late_hours }} giờ
                        </p>
                        <p v-else-if="task.status === 'completed'" class="flex items-center gap-xs font-caption text-caption text-tertiary">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">check</span>
                            Hoàn thành{{ task.completed_at ? ' lúc ' + task.completed_at : '' }}
                        </p>
                        <p v-else-if="task.status !== 'canceled'" class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">calendar_today</span>
                            Hạn: {{ task.due_label }}
                        </p>
                        <p v-if="task.rejection_reason" class="rounded bg-error-container/40 px-sm py-xs font-caption text-caption text-error">Bị trả về: {{ task.rejection_reason }}</p>

                        <div class="flex flex-col gap-sm">
                            <UiButton v-if="canComplete && ['new', 'in_progress', 'overdue'].includes(task.status)" :variant="task.status === 'overdue' ? 'danger' : 'secondary'" icon="check_circle" class="w-full" @click="openComplete(task)">
                                {{ task.status === 'overdue' ? 'Hoàn thành gấp' : 'Hoàn thành' }}
                            </UiButton>
                            <p v-else-if="task.pending_label" class="flex items-center gap-xs font-body-small text-body-small text-warning">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">hourglass_empty</span>{{ task.pending_label }}
                            </p>
                            <UiButton
                                v-if="task.class_label && ['new', 'in_progress', 'overdue', 'blocked'].includes(task.status) && canComplete"
                                variant="secondary"
                                icon="assignment"
                                class="w-full"
                                :href="route('tasks.class-reports.create', { task_id: task.id, class_id: task.class_id })"
                                modal="2xl"
                                >Nộp báo cáo trực lớp</UiButton
                            >
                        </div>
                    </article>
                    <p v-if="!group.tasks.length" class="py-sm font-body-small text-body-small italic text-on-surface-variant">Không có nhiệm vụ {{ group.title.toLowerCase() }}.</p>
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
