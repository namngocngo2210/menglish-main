<script setup>
/**
 * Quản lý sửa giờ công 1 ngày của nhân sự (quên chấm, chấm sai, đã nghỉ việc…) — bắt buộc ghi lý do, ô trống giữ giờ
 * đang có — và thu hồi đơn xin nghỉ / xin đi muộn đã duyệt nhầm của ngày đó. Ngày thuộc kỳ lương đã khóa thì chỉ xem.
 */
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';

defineOptions({ layout: (props) => ({ title: `Sửa công ${props.staff?.name ?? ''}` }) });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    staff: { type: Object, required: true },
    date: { type: String, required: true },
    locked: { type: String, default: null },
    attendance: { type: Object, default: null },
    requests: { type: Array, default: () => [] },
});

const revokeReasons = reactive(Object.fromEntries(props.requests.map((r) => [r.id, ''])));
const revoking = ref(null);

function revoke(request) {
    revoking.value = request.id;
    router.post(
        route('staff-attendance.requests.revoke', request.id),
        { reason: revokeReasons[request.id] ?? '' },
        { preserveScroll: true, onFinish: () => (revoking.value = null) },
    );
}
</script>

<template>
    <UiModalFrame
        :title="`Sửa công · ${staff.name}`"
        :description="[staff.code, formatDate(date, 'l, d/m/Y')].filter(Boolean).join(' · ')"
        :action="locked ? null : route('staff-attendance.adjust')"
        :submit-label="locked ? false : 'Lưu giờ công'"
        submit-icon="save"
        :back="route('staff-attendance.index', { date })"
    >
        <UiAlert v-if="locked" type="warning">{{ locked }}</UiAlert>

        <div v-if="attendance" class="flex flex-wrap items-center gap-sm font-body-small text-body-small text-on-surface-variant">
            <UiBadge :color="attendance.status_tone">{{ attendance.status_label }}</UiBadge>
            <span>Hiện tại: vào <span class="font-mono">{{ attendance.check_in ?? '—' }}</span>, ra <span class="font-mono">{{ attendance.check_out ?? '—' }}</span></span>
        </div>
        <p v-else class="font-body-small text-body-small text-on-surface-variant">Ngày này chưa có lượt chấm công.</p>

        <template v-if="!locked">
            <input type="hidden" name="user_id" :value="staff.id" />
            <input type="hidden" name="work_date" :value="date" />
            <div class="grid grid-cols-2 gap-sm">
                <UiInput name="check_in_time" type="time" label="Giờ vào" :value="attendance?.check_in ?? ''" />
                <UiInput name="check_out_time" type="time" label="Giờ ra" :value="attendance?.check_out ?? ''" />
            </div>
            <UiTextarea name="reason" label="Lý do sửa" required rows="2" placeholder="VD: Quên chấm ra, đã đối chiếu camera" />
            <p class="font-caption text-caption text-on-surface-variant">Ô để trống giữ giờ đang có. Đi muộn / biên bản tự tính lại theo giờ mới.</p>
        </template>

        <p v-if="attendance?.note" class="whitespace-pre-line rounded-lg bg-surface-container-low p-sm font-body-small text-body-small">{{ attendance.note }}</p>

        <section v-if="requests.length" class="space-y-sm border-t border-outline-variant pt-md">
            <h3 class="font-body-semibold text-body-semibold text-on-surface">Đơn đã duyệt của ngày này</h3>
            <div v-for="request in requests" :key="request.id" class="space-y-xs rounded-lg border border-outline-variant p-sm" data-no-row-link>
                <p class="font-body-medium text-body-medium text-on-surface">{{ request.type_label }} · {{ request.period }}</p>
                <p class="font-body-small text-body-small text-on-surface-variant">{{ request.reason }}</p>
                <div v-if="!locked" class="flex flex-wrap items-end gap-sm">
                    <UiInput v-model="revokeReasons[request.id]" :name="`revoke_reason_${request.id}`" label="Lý do thu hồi" class="min-w-0 flex-1" />
                    <UiButton variant="secondary" icon="undo" :disabled="!revokeReasons[request.id] || revoking === request.id" @click="revoke(request)">Thu hồi đơn</UiButton>
                </div>
            </div>
        </section>
    </UiModalFrame>
</template>
