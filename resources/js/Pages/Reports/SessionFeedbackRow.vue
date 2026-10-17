<script setup>
/**
 * Một buổi học trong khối "Chất lượng buổi học" của báo cáo ngày Học vụ (chủ dự án 17/10/2026): trạng thái nhận xét GV, phụ huynh
 * chấm sao, góp ý của người viết (Tốt / Cần lưu ý / Có vấn đề + ghi chú, không cần dự giờ; gửi cùng form báo cáo theo
 * `feedback[id][…]`), "+ Tạo task" gắn lớp, và "Báo Admin gấp" tạo sự việc mức Khẩn trong Nhật ký ngay (StaffReportController::dailyEscalate).
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

const props = defineProps({
    row: { type: Object, required: true },
    verdicts: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['task']);

const VERDICT_TONE = {
    good: 'border-success bg-success/10 text-success',
    attention: 'border-warning bg-warning/10 text-warning',
    problem: 'border-error bg-error/10 text-error',
};
const REMARK = {
    sent: ['Đã gửi nhận xét', 'success'],
    missing: ['Chưa gửi nhận xét', 'warning'],
    pending: ['Chưa kết thúc', 'neutral'],
};
const JOURNAL_STATUS = { open: 'Chờ xử lý', following: 'Đang theo dõi', resolved: 'Đã xử lý' };

const verdict = ref(props.row.verdict ?? '');
const content = ref(props.row.content ?? '');
const noteOpen = ref(Boolean(props.row.content));
const parentsOpen = ref(false);
const escalating = ref(false);
const escalateText = ref('');
const escalateError = ref(null);
const sending = ref(false);

// Báo Admin xong, máy chủ đánh dấu buổi "Có vấn đề": đồng bộ lại ô đang chọn.
watch(
    () => props.row.escalation?.id,
    (id) => id && (verdict.value = 'problem'),
);

const pick = (key) => {
    verdict.value = verdict.value === key ? '' : key;
    if (key !== 'good' && verdict.value) noteOpen.value = true;
};
const staffLine = computed(() => [props.row.teacher && `GV ${props.row.teacher}`, props.row.foreign_teacher && `GVNN ${props.row.foreign_teacher}`, props.row.assistant && `TG ${props.row.assistant}`].filter(Boolean).join(' · '));
const lowParents = computed(() => (props.row.parents?.items ?? []).filter((p) => p.rating <= 2).length);

const openEscalate = () => {
    escalateText.value = content.value;
    escalateError.value = null;
    escalating.value = true;
};
const sendEscalate = () => {
    sending.value = true;
    router.post(
        route('reports.periodic.daily.escalate', props.row.id),
        { content: escalateText.value },
        {
            preserveScroll: true,
            preserveState: true,
            errorBag: `escalate${props.row.id}`,
            onSuccess: () => (escalating.value = false),
            onError: (errors) => (escalateError.value = errors.content ?? errors.escalate ?? 'Chưa gửi được, thử lại.'),
            onFinish: () => (sending.value = false),
        },
    );
};
</script>

<template>
    <li class="space-y-sm rounded-lg border border-surface-variant bg-surface-container-lowest p-md" :data-session-row="row.id">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div class="min-w-0">
                <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ row.class }} <span class="font-normal text-on-surface-variant">· {{ row.time }}</span></p>
                <p v-if="staffLine" class="font-body-small text-body-small text-on-surface-variant">{{ staffLine }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-xs">
                <UiBadge :color="REMARK[row.remark]?.[1] ?? 'neutral'">{{ REMARK[row.remark]?.[0] }}</UiBadge>
                <button
                    v-if="row.parents?.count"
                    type="button"
                    class="inline-flex items-center gap-0.5 rounded-full border border-surface-variant px-sm py-0.5 font-body-small text-body-small text-on-surface hover:border-outline-variant"
                    :aria-expanded="parentsOpen"
                    data-session-parents
                    @click="parentsOpen = !parentsOpen"
                >
                    <span class="material-symbols-outlined text-[16px] text-warning" style="font-variation-settings: 'FILL' 1" aria-hidden="true">star</span>
                    {{ row.parents.avg }} · {{ row.parents.count }} phụ huynh
                    <span v-if="lowParents" class="text-error">· {{ lowParents }} thấp</span>
                </button>
                <span v-else class="font-body-small text-body-small text-on-surface-subtle">Chưa có phụ huynh chấm</span>
            </div>
        </div>

        <ul v-if="parentsOpen" class="space-y-xs rounded-lg bg-surface-container-low p-sm" data-session-parent-list>
            <li v-for="(p, i) in row.parents.items" :key="i" class="font-body-small text-body-small text-on-surface">
                <strong>{{ p.rating }}★</strong> {{ p.student ?? 'Học viên' }}<template v-if="p.categories.length"> · {{ p.categories.join(', ') }}</template>
                <span v-if="p.content" class="block text-on-surface-variant">"{{ p.content }}"</span>
            </li>
        </ul>

        <input type="hidden" :name="`feedback[${row.id}][verdict]`" :value="verdict" />
        <div class="flex flex-wrap items-center gap-xs" role="group" :aria-label="`Góp ý buổi ${row.class} ${row.time}`">
            <span class="mr-xs font-label text-label uppercase text-on-surface-variant">Góp ý của bạn</span>
            <button
                v-for="(label, key) in verdicts"
                :key="key"
                type="button"
                :aria-pressed="verdict === key"
                :class="['min-h-[32px] rounded-full border px-md font-body-small text-body-small font-semibold transition', verdict === key ? VERDICT_TONE[key] : 'border-surface-variant text-on-surface-variant hover:border-outline-variant']"
                :data-verdict="key"
                @click="pick(key)"
            >
                {{ label }}
            </button>
            <button v-if="!noteOpen" type="button" class="ml-xs font-body-small text-body-small font-semibold text-primary hover:underline" @click="noteOpen = true">+ Ghi chú</button>
        </div>
        <UiTextarea v-if="noteOpen" v-model="content" :name="`feedback[${row.id}][content]`" :rows="2" placeholder="Bạn thấy gì ở buổi này? (không cần dự giờ)" />
        <input v-else type="hidden" :name="`feedback[${row.id}][content]`" :value="content" />

        <div class="flex flex-wrap items-center gap-md">
            <button type="button" class="inline-flex items-center gap-0.5 font-body-small text-body-small font-semibold text-primary hover:underline" data-session-task @click="emit('task', row)">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">add_task</span>Tạo task
            </button>
            <p v-if="row.escalation" class="inline-flex items-center gap-xs font-body-small text-body-small text-error" data-session-escalated>
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">campaign</span>
                Đã báo Admin {{ formatDate(row.escalation.at, 'H:i d/m') }}
                <UiBadge :color="row.escalation.status === 'resolved' ? 'success' : 'warning'">{{ JOURNAL_STATUS[row.escalation.status] ?? row.escalation.status }}</UiBadge>
            </p>
            <button
                v-else-if="!escalating"
                type="button"
                class="inline-flex items-center gap-0.5 font-body-small text-body-small font-semibold text-error hover:underline"
                data-session-escalate
                @click="openEscalate"
            >
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">campaign</span>Báo Admin gấp
            </button>
        </div>

        <div v-if="escalating" class="space-y-sm rounded-lg border border-error/40 bg-error/5 p-md" data-session-escalate-panel>
            <p class="font-body-small text-body-small text-on-surface">Admin và Quản lý cơ sở nhận thông báo ngay. Vấn đề được ghi vào Nhật ký sự vụ ở mức <strong>Khẩn</strong> để theo dõi xử lý.</p>
            <UiTextarea v-model="escalateText" :rows="3" placeholder="Chuyện gì xảy ra, cần Admin làm gì?" :error="escalateError" />
            <p v-if="escalateError" class="font-body-small text-body-small text-error" role="alert">{{ escalateError }}</p>
            <div class="flex items-center gap-sm">
                <UiButton type="button" size="sm" variant="danger" icon="campaign" :disabled="sending || !escalateText.trim()" @click="sendEscalate">Gửi Admin</UiButton>
                <button type="button" class="font-body-small text-body-small font-semibold text-on-surface-variant hover:underline" @click="escalating = false">Hủy</button>
            </div>
        </div>
    </li>
</template>
