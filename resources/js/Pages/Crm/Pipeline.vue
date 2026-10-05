<script setup>
/**
 * Kanban tuyển sinh (mockup pipeline-tong-quan-giai-doan): 8 cột theo giai đoạn, kéo thả thẻ để chuyển giai đoạn.
 * - Học vụ / Quản lý cơ sở: tiến đúng 1 bước (không kéo vào Chờ xếp lớp / Đã chốt — chốt bằng "Chốt & Xếp lớp").
 * - Admin: được lùi bước (bắt buộc lý do, hỏi trong modal "Sửa giai đoạn"). Sales: chỉ đánh Thất bại (quyền lead.mark_lost).
 * Chuyển giai đoạn gửi JSON tới crm.customers.stage / crm.customers.next-stage rồi tải lại dữ liệu bảng (giữ bộ lọc).
 * Bấm thẻ → hồ sơ khách đầy đủ; "Thêm khách mới" (cột Mới) mở modal.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from '@/lib/toast';
import { postJson } from '@/lib/http';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import { openRemoteModal } from '@/lib/remoteModal';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import ListFilters from '@/Components/Crm/ListFilters.vue';
import SlaCountdown from '@/Components/Crm/SlaCountdown.vue';

defineOptions({ layout: { title: 'Kanban tuyển sinh', workspaceTabs: false } });

const props = defineProps({
    stages: { type: Array, required: true },
    stagePermissions: { type: Object, required: true },
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
});
const perm = computed(() => props.stagePermissions);
const closed = (stage) => perm.value.closed.includes(stage);
const indexOf = (stage) => perm.value.order.indexOf(stage);
const canEditAny = computed(() => perm.value.canForward || perm.value.canBackward || perm.value.canMarkLost);
const canAssignClass = computed(() => can('student.assign_class'));
// Giai đoạn được bấm "Chốt & Xếp lớp" (CrmCustomer::CLOSABLE_STAGES).
const closable = (stage) => (perm.value.closable ?? []).includes(stage);

// ── Thẻ ────────────────────────────────────────────────────────────────────────────────────
function forwardTarget(stage) {
    return perm.value.canForward && stage.next && !closed(stage.next) ? stage.next : null;
}
function draggable(stage, index) {
    return !!forwardTarget(stage) || (perm.value.canBackward && index > 0 && !closed(stage.id));
}
function accent(stage, lead) {
    if (stage.id === 'won') return 'border-l-tertiary';
    return { overdue: 'border-l-error', due_soon: 'border-l-warning', on_time: 'border-l-tertiary' }[lead.follow_up_state] ?? 'border-l-outline-variant';
}
function canEditStage(stage) {
    return canEditAny.value && !closed(stage.id);
}
function hasFooter(stage, lead) {
    return stage.id === 'won'
        || !!lead.follow_up_state
        || (stage.id === 'waiting_class' && canAssignClass.value)
        || (stage.id !== 'waiting_class' && !!forwardTarget(stage))
        || (closable(stage.id) && perm.value.canConvert);
}
function openCard(event, lead) {
    if (event.target.closest('button, a, form')) return;
    router.visit(route('crm.customers.show', lead.id));
}

// ── Chuyển giai đoạn ───────────────────────────────────────────────────────────────────────
function transitionType(sourceStage, targetStage) {
    const from = indexOf(sourceStage);
    const to = indexOf(targetStage);
    if (from < 0 || to < 0 || from === to) return null;
    if (to === from + 1 && perm.value.canForward && !closed(targetStage)) return 'forward';
    if (to < from && perm.value.canBackward && !closed(sourceStage)) return 'backward';
    return null;
}

async function postStageRequest(url, payload = null) {
    const { ok, data } = await postJson(url, payload);
    return { ok: ok && data.success, data };
}

function refresh() {
    router.reload({ preserveScroll: true });
}

async function postStage(customerId, payload) {
    try {
        const { ok, data } = await postStageRequest(route('crm.customers.stage', customerId), payload);
        if (ok) {
            toast(data.message || 'Đã chuyển giai đoạn thành công!');
            refresh();
            return true;
        }
        toast(data.message || 'Không thể chuyển giai đoạn!', 'error');
    } catch {
        toast('Đã xảy ra lỗi kết nối khi chuyển giai đoạn!', 'error');
    }
    return false;
}

async function moveToNextStage(lead) {
    try {
        const { ok, data } = await postStageRequest(route('crm.customers.next-stage', lead.id));
        if (ok) {
            toast(`Đã chuyển ${lead.name} sang giai đoạn: ${data.stage_label}!`);
            refresh();
        } else {
            toast(data.message || 'Không thể chuyển tiếp giai đoạn!', 'error');
        }
    } catch {
        toast('Đã xảy ra lỗi khi bấm chuyển giai đoạn!', 'error');
    }
}

// ── Modal "Sửa giai đoạn" (A6): tiến 1 bước; chỉ Admin lùi bước (bắt buộc lý do); Thất bại bắt buộc lý do ──────
const stageEdit = reactive({ open: false, customerId: null, name: '', from: '', target: '', reason: '', saving: false });
const stageEditOptions = computed(() => {
    const from = stageEdit.from;
    const options = [];
    const next = perm.value.order[indexOf(from) + 1];
    if (perm.value.canForward && next && !closed(next)) {
        options.push({ value: next, label: 'Tiến 1 bước → ' + perm.value.labels[next] });
    }
    if (perm.value.canBackward && !closed(from)) {
        perm.value.order.slice(0, Math.max(0, indexOf(from))).reverse()
            .forEach((stage) => options.push({ value: stage, label: 'Lùi về ← ' + perm.value.labels[stage] }));
    }
    if (perm.value.canMarkLost && !closed(from)) {
        options.push({ value: 'lost', label: 'Chuyển sang Thất bại' });
    }
    return options;
});
const needsReason = computed(() => stageEdit.target === 'lost' || indexOf(stageEdit.target) < indexOf(stageEdit.from));
const canSubmitStage = computed(() => !!stageEdit.target && !(needsReason.value && !stageEdit.reason.trim()) && !stageEdit.saving);

function openStageEdit(customerId, name, fromStage, target = null) {
    Object.assign(stageEdit, { open: true, customerId, name, from: fromStage, target: '', reason: '', saving: false });
    stageEdit.target = target || stageEditOptions.value[0]?.value || '';
}

async function submitStageEdit() {
    if (!canSubmitStage.value) return;
    const { customerId, target, reason } = stageEdit;
    const payload = target === 'lost' ? { stage: 'lost', lost_reason: reason } : { stage: target, reason };
    stageEdit.saving = true;
    const ok = await postStage(customerId, payload);
    stageEdit.saving = false;
    if (ok) stageEdit.open = false;
}

// ── Kéo thả ────────────────────────────────────────────────────────────────────────────────
const dragged = ref(null);
const dropTarget = ref(null);

function onDragStart(event, lead, stage) {
    dragged.value = { customerId: lead.id, stageId: stage.id, name: lead.name };
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(lead.id));
}
function onDragEnd() {
    dragged.value = null;
    dropTarget.value = null;
}
function onDragOver(event, stageId) {
    if (!dragged.value) return;
    if (!transitionType(dragged.value.stageId, stageId)) {
        event.dataTransfer.dropEffect = 'none';
        return;
    }
    event.dataTransfer.dropEffect = 'move';
    dropTarget.value = stageId;
}
function onDragLeave(event, stageId) {
    if (!event.currentTarget.contains(event.relatedTarget) && dropTarget.value === stageId) dropTarget.value = null;
}
async function onDrop(targetStageId) {
    dropTarget.value = null;
    if (!dragged.value) return;
    const { customerId, stageId: sourceStageId, name } = dragged.value;
    dragged.value = null;
    if (sourceStageId === targetStageId) return;

    if (['waiting_class', 'won'].includes(targetStageId)) {
        toast('Hãy dùng Chốt & Xếp lớp (hoặc Xếp lớp ở màn Chờ xếp lớp) để chốt Lead.', 'error');
        return;
    }
    const type = transitionType(sourceStageId, targetStageId);
    if (type === 'backward') {
        openStageEdit(customerId, name, sourceStageId, targetStageId);
        return;
    }
    if (type !== 'forward') {
        toast(perm.value.canForward ? 'Chỉ được chuyển tiến 1 bước sang giai đoạn kế tiếp.' : 'Bạn không có quyền chuyển giai đoạn Lead.', 'error');
        return;
    }
    await postStage(customerId, { stage: targetStageId });
}

// ── Mép trái / phải: vùng mờ + mũi tên cuộn khi còn cột bị che ─────────────────────────────
const board = ref(null);
const canLeft = ref(false);
const canRight = ref(false);
function sync() {
    const b = board.value;
    if (!b) return;
    canLeft.value = b.scrollLeft > 4;
    canRight.value = b.scrollLeft + b.clientWidth < b.scrollWidth - 4;
}
let resizeTimer = null;
function onResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(sync, 100);
}
onMounted(async () => {
    await nextTick();
    sync();
    window.addEventListener('resize', onResize, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('resize', onResize));
</script>

<template>
    <CrmHeader title="Kanban tuyển sinh" />

    <!-- Màu cột theo mockup (CrmCustomer::stageStyle, chuỗi đầy đủ để Tailwind quét):
         bg-secondary text-secondary border-secondary/20 bg-tertiary text-tertiary border-tertiary/20 bg-primary text-primary border-primary/20
         bg-info text-info border-info/20 bg-accent text-accent border-accent/20 bg-warning text-warning border-warning/20
         border-l-error border-l-warning border-l-tertiary border-l-outline-variant -->
    <div class="space-y-md">
        <UiAlert v-if="!stagePermissions.canForward" type="info">
            Giai đoạn khách do Học vụ / Quản lý cơ sở chuyển. Bạn vẫn cập nhật thông tin, ghi nhật ký chăm sóc trong hồ sơ khách{{ stagePermissions.canMarkLost ? ' và đánh dấu khách Thất bại (nút "Sửa giai đoạn" trên thẻ)' : '' }}.
        </UiAlert>

        <ListFilters :filter-branches="filterBranches" :filter-sales="filterSales" :filter-sources="filterSources" date-label="Ngày tạo" />

        <!-- Kanban 8 cột (mockup: tiêu đề cột = chấm màu + TÊN (số lượng)). Bảng rộng hơn khung: vùng mờ & mũi tên ở mép khi còn cột bị che. -->
        <div id="crm-kanban" class="space-y-sm">
            <div class="relative">
                <div ref="board" class="custom-scrollbar overflow-x-auto pb-md" @scroll.passive="sync">
                    <div class="flex min-h-[calc(100vh-320px)] min-w-max items-start gap-md">
                        <div
                            v-for="(stage, index) in stages"
                            :key="stage.id"
                            :class="['kanban-column flex w-[240px] shrink-0 flex-col gap-md rounded-xl transition-colors duration-200', dropTarget === stage.id ? 'bg-primary-container/10 ring-2 ring-primary-container' : '']"
                            :data-stage-id="stage.id"
                            :data-stage-index="index"
                            @dragover.prevent="onDragOver($event, stage.id)"
                            @dragleave="onDragLeave($event, stage.id)"
                            @drop.prevent="onDrop(stage.id)"
                        >
                            <!-- Tiêu đề cột -->
                            <div :class="['flex items-center justify-between gap-xs border-b px-xs py-xs', stage.header_border]">
                                <h3 :class="['flex min-w-0 items-center gap-sm font-label text-label uppercase', stage.text]">
                                    <span :class="['h-2 w-2 shrink-0 rounded-full', stage.dot]"></span>
                                    <span>{{ stage.name }} (<span :id="'badge-count-' + stage.id">{{ stage.count }}</span>)</span>
                                </h3>
                                <span v-if="Number(stage.amount_raw) > 0" class="shrink-0 whitespace-nowrap font-code text-caption text-on-surface-variant" title="Tổng giá trị hợp đồng">{{ stage.amount }}</span>
                            </div>

                            <div :id="'column-cards-' + stage.id" class="cards-container min-h-[120px] space-y-md py-xs">
                                <div
                                    v-for="lead in stage.leads"
                                    :key="lead.id"
                                    :class="[
                                        'kanban-card group relative space-y-md rounded-lg border border-l-4 border-outline-variant/30 bg-surface-container-lowest p-md shadow-level-2 transition-all hover:shadow-level-3',
                                        accent(stage, lead),
                                        draggable(stage, index) ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                                        dragged && dragged.customerId === lead.id ? 'scale-95 opacity-40' : '',
                                    ]"
                                    :draggable="draggable(stage, index) ? 'true' : 'false'"
                                    :data-customer-id="lead.id"
                                    :data-stage-id="stage.id"
                                    :data-stage-index="index"
                                    @dragstart="onDragStart($event, lead, stage)"
                                    @dragend="onDragEnd"
                                    @click="openCard($event, lead)"
                                    role="button"
                                    tabindex="0"
                                    @keydown.enter.self.prevent="openCard($event, lead)"
                                    @keydown.space.self.prevent="openCard($event, lead)"
                                >
                                    <button
                                        v-if="canEditStage(stage)"
                                        type="button"
                                        class="absolute right-2 top-2 rounded bg-surface-container-low px-1 font-caption text-xs text-on-surface-variant opacity-100 transition-opacity hover:text-primary md:opacity-0 md:group-hover:opacity-100"
                                        title="Sửa giai đoạn"
                                        @click.stop="openStageEdit(lead.id, lead.name, stage.id)"
                                    >Sửa giai đoạn</button>

                                    <div class="flex items-start justify-between gap-sm pr-md">
                                        <div class="min-w-0">
                                            <h4 class="line-clamp-2 font-h3 text-[14px] font-bold uppercase leading-tight text-on-surface">{{ lead.name }}</h4>
                                            <p class="mt-1 font-code text-body-small text-on-surface-variant">{{ lead.phone }}</p>
                                        </div>
                                        <span :class="['max-w-[96px] shrink-0 truncate rounded px-sm py-[2px] font-caption text-xs font-bold', stage.source_badge]" :title="'Nguồn: ' + lead.source">{{ lead.source }}</span>
                                    </div>

                                    <div class="space-y-1.5 text-body-small">
                                        <div class="flex items-center gap-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px]">person</span>
                                            <span class="truncate">Phụ huynh: {{ lead.parent_name || '—' }}</span>
                                        </div>
                                        <div class="flex items-center gap-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px]">account_circle</span>
                                            <span class="truncate">Phụ trách: <span class="font-medium text-on-surface">{{ lead.agent }}</span></span>
                                        </div>
                                        <div v-if="lead.has_test_result" class="flex items-center gap-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px]">quiz</span>
                                            <span class="truncate">Điểm test: {{ lead.score }}</span>
                                        </div>
                                        <div v-if="lead.status" class="flex items-center gap-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[16px]">payments</span>
                                            <span class="truncate">Học phí: {{ lead.status }}</span>
                                        </div>
                                    </div>

                                    <div v-if="hasFooter(stage, lead)" class="space-y-md border-t border-surface-container-highest pt-sm">
                                        <div v-if="stage.id === 'won'" class="flex items-center gap-xs font-body-small text-body-small font-medium text-tertiary">
                                            <span class="material-symbols-outlined text-[18px]">verified</span>
                                            {{ lead.confirmed ? 'Đã hoàn tất hồ sơ' : 'Đã chốt — chờ xác nhận chính thức' }}
                                        </div>
                                        <template v-else>
                                            <div v-if="lead.sla" class="space-y-xs">
                                                <SlaCountdown :sla="lead.sla" compact />
                                                <p class="font-caption text-caption text-on-surface-variant" :title="lead.follow_up_at">{{ lead.sla.label }}: {{ lead.follow_up_label }}</p>
                                            </div>

                                            <template v-if="stage.id === 'waiting_class'">
                                                <UiButton v-if="canAssignClass" variant="secondary" size="sm" :href="route('crm.waiting-list')" icon="assignment_turned_in" class="w-full">Xếp lớp</UiButton>
                                            </template>
                                            <UiButton
                                                v-else-if="forwardTarget(stage)"
                                                size="sm"
                                                :variant="lead.follow_up_state === 'overdue' ? 'primary' : 'secondary'"
                                                icon="arrow_forward"
                                                class="w-full"
                                                :title="'Sang bước tiếp theo: ' + stagePermissions.labels[stage.next]"
                                                @click.stop="moveToNextStage(lead)"
                                            >Sang bước: {{ stagePermissions.labels[stage.next] }}</UiButton>

                                            <UiButton v-if="closable(stage.id) && stagePermissions.canConvert" variant="secondary" size="sm" :href="route('crm.closing-wizard', { customer_id: lead.id })" icon="how_to_reg" class="w-full">Chốt &amp; Xếp lớp</UiButton>
                                        </template>
                                    </div>
                                </div>
                                <p v-if="!stage.leads.length" class="px-xs py-md text-center font-caption text-caption text-on-surface-subtle">Chưa có khách</p>
                            </div>

                            <a
                                v-if="stage.id === 'new' && can('lead.create')"
                                :href="route('crm.customers.create')"
                                class="flex w-full items-center justify-center gap-xs rounded-lg border-2 border-dashed border-outline-variant py-sm font-body-small text-body-small font-bold text-on-surface-variant transition hover:border-primary-container/50 hover:text-primary"
                                @click.prevent="openRemoteModal(route('crm.customers.create'), { size: '2xl' })"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                <span>Thêm khách mới</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div v-show="canLeft" class="pointer-events-none absolute inset-y-0 left-0 w-12 bg-gradient-to-r from-surface to-transparent"></div>
                <div v-show="canRight" class="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-l from-surface to-transparent"></div>
                <button
                    v-show="canLeft"
                    type="button"
                    aria-label="Xem các cột bên trái"
                    title="Xem các cột bên trái"
                    class="absolute left-2 top-28 flex h-9 w-9 items-center justify-center rounded-full border border-outline-variant bg-surface-container-lowest text-on-surface shadow-level-2 hover:text-primary"
                    @click="board.scrollBy({ left: -500, behavior: 'smooth' })"
                >
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_left</span>
                </button>
                <button
                    v-show="canRight"
                    type="button"
                    aria-label="Xem các cột bên phải"
                    title="Xem các cột bên phải"
                    class="absolute right-2 top-28 flex h-9 w-9 items-center justify-center rounded-full border border-outline-variant bg-surface-container-lowest text-on-surface shadow-level-2 hover:text-primary"
                    @click="board.scrollBy({ left: 500, behavior: 'smooth' })"
                >
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_right</span>
                </button>
            </div>
        </div>

        <!-- Sửa giai đoạn (A6): CM tiến 1 bước; chỉ Admin lùi bước (bắt buộc lý do); Thất bại bắt buộc lý do -->
        <UiModal v-if="canEditAny" :show="stageEdit.open" :title="'Sửa giai đoạn: ' + stageEdit.name" max-width="md" @close="stageEdit.open = false">
            <div class="space-y-sm font-body-small text-body-small">
                <p class="text-on-surface-variant">Hiện tại: <strong>{{ stagePermissions.labels[stageEdit.from] }}</strong>. Học vụ / Quản lý cơ sở chỉ chuyển tiến 1 bước; chỉ Admin được lùi bước. Mọi thay đổi được lưu vào lịch sử khách.</p>
                <p v-if="stagePermissions.canBackward" class="font-semibold text-error">Lùi giai đoạn: bắt buộc nhập lý do (không áp dụng cho khách đã chốt).</p>
                <UiSelect v-model="stageEdit.target" :options="stageEditOptions" :searchable="false" aria-label="Giai đoạn mới" />
                <UiTextarea
                    v-show="needsReason"
                    v-model="stageEdit.reason"
                    :rows="3"
                    :placeholder="stageEdit.target === 'lost' ? 'Lý do thất bại (bắt buộc)' : 'Lý do lùi giai đoạn (bắt buộc)'"
                    :maxlength="stageEdit.target === 'lost' ? 255 : 1000"
                />
            </div>
            <template #footer>
                <UiButton variant="secondary" @click="stageEdit.open = false">Hủy</UiButton>
                <UiButton :disabled="!canSubmitStage" @click="submitStageEdit">Lưu giai đoạn</UiButton>
            </template>
        </UiModal>
    </div>
</template>
