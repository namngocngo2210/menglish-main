<script setup>
/**
 * Dạy thay (TK-2026-0057). Giáo viên: đề xuất tìm GV dạy thay cho buổi của mình, xem danh sách buổi cần cover và đăng ký.
 * Admin / Học vụ: xem người đăng ký, duyệt, mở lại (gán người khác) hoặc hủy. Gọi endpoint JSON của CoverRequestController.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { postJson, firstError } from '@/lib/http';
import { toast } from '@/lib/toast';
import { confirmDialog } from '@/lib/confirm';
import { COVER_STATUS, coverActions } from '@/lib/cover';

defineOptions({ layout: { title: 'Dạy thay' } });

const props = defineProps({
    covers: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    canApprove: { type: Boolean, default: false },
    userId: { type: Number, default: null },
    propose: { type: Number, default: null },
});

const busy = ref(false);
const reason = ref('');
// ?propose=<session id> (link from the weekly schedule) opens the dialog straight away.
const proposing = ref(props.sessions.find((s) => s.id === props.propose) ?? null);

async function act(url, payload, done) {
    busy.value = true;
    try {
        const { ok, data } = await postJson(url, payload);
        if (!ok) return toast(firstError(data), 'error');
        toast(done);
        proposing.value = null;
        reason.value = '';
        router.reload({ only: ['covers', 'sessions'] });
    } finally {
        busy.value = false;
    }
}

const propose = () => act(route('teacher.cover.store'), { class_session_id: proposing.value.id, reason: reason.value || null }, 'Đã gửi đề xuất tìm GV dạy thay');
const register = (c) => act(route('teacher.cover.register', c.id), null, 'Đã đăng ký dạy thay');
const approve = (c, teacherId) => act(route('cover-requests.approve', c.id), { teacher_id: teacherId }, 'Đã duyệt giáo viên dạy thay');
const reopen = async (c) => (await confirmDialog({ message: 'Mở lại đăng ký? Buổi học trả về giáo viên cũ.', confirmLabel: 'Mở lại' })) && act(route('cover-requests.reopen', c.id), null, 'Đã mở lại đăng ký');
const cancel = async (c) => (await confirmDialog({ message: 'Hủy yêu cầu dạy thay này?', confirmLabel: 'Hủy yêu cầu', danger: true })) && act(route('teacher.cover.cancel', c.id), null, 'Đã hủy yêu cầu');
const actions = (c) => coverActions(c, { canApprove: props.canApprove, userId: props.userId });
const slot = (s) => `${formatDate(s.date)} · ${s.start_time}–${s.end_time}`;
</script>

<template>
    <UiPageHeader title="Dạy thay" icon="swap_horiz" />

    <div class="space-y-md">
        <section v-if="sessions.length" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
            <h2 class="mb-sm font-h3 text-h3 text-on-surface">Buổi của tôi — tìm GV dạy thay</h2>
            <ul class="divide-y divide-outline-variant">
                <li v-for="s in sessions" :key="s.id" class="flex flex-wrap items-center justify-between gap-sm py-sm">
                    <span><strong>{{ slot(s) }}</strong> <span class="text-on-surface-variant">· {{ s.class_name }}</span></span>
                    <UiButton size="sm" variant="secondary" icon="person_search" @click="proposing = s">Tìm GV dạy thay</UiButton>
                </li>
            </ul>
        </section>

        <UiEmptyState v-if="!covers.length" icon="swap_horiz" title="Chưa có buổi nào cần dạy thay" />

        <section v-for="c in covers" :key="c.id" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
            <h2 class="mb-xs font-h3 text-h3 text-on-surface">{{ slot(c.session) }}</h2>
            <div class="flex flex-wrap items-center gap-sm">
                <UiBadge :color="COVER_STATUS[c.status]?.color">{{ COVER_STATUS[c.status]?.label }}</UiBadge>
                <span class="text-sm text-on-surface-variant">{{ c.session.shift_name }} · đề xuất bởi {{ c.requested_by.name }}</span>
                <span class="text-sm">{{ c.registrations_count }} người đăng ký</span>
            </div>
            <p v-if="c.reason" class="mt-xs text-sm">Lý do: {{ c.reason }}</p>
            <p v-if="c.class" class="mt-xs text-sm font-semibold">Lớp: {{ c.class.name }} ({{ c.students?.length ?? 0 }} học viên)</p>

            <ul v-if="canApprove && c.registrations?.length" class="mt-sm space-y-xs">
                <li v-for="r in c.registrations" :key="r.teacher_id" class="flex items-center justify-between gap-sm">
                    <span>{{ r.name }}</span>
                    <UiButton v-if="c.status === 'open'" size="sm" :disabled="busy" @click="approve(c, r.teacher_id)">Duyệt</UiButton>
                </li>
            </ul>

            <div class="mt-sm flex flex-wrap gap-sm">
                <UiButton v-if="actions(c).register" size="sm" icon="how_to_reg" :disabled="busy" @click="register(c)">Đăng ký dạy thay</UiButton>
                <UiButton v-if="actions(c).unregister" size="sm" variant="secondary" :disabled="busy" @click="act(route('teacher.cover.register', c.id), { _method: 'DELETE' }, 'Đã bỏ đăng ký')">Bỏ đăng ký</UiButton>
                <UiButton v-if="actions(c).reopen" size="sm" variant="secondary" :disabled="busy" @click="reopen(c)">Mở lại / chọn người khác</UiButton>
                <UiButton v-if="actions(c).cancel" size="sm" variant="danger-text" :disabled="busy" @click="cancel(c)">Hủy yêu cầu</UiButton>
            </div>
        </section>
    </div>

    <UiModal :show="!!proposing" title="Tìm giáo viên dạy thay" max-width="md" @close="proposing = null">
        <p v-if="proposing" class="mb-sm text-sm">{{ slot(proposing) }} · {{ proposing.class_name }}</p>
        <UiTextarea v-model="reason" label="Lý do (không bắt buộc)" :rows="3" />
        <template #footer>
            <UiButton variant="secondary" @click="proposing = null">Đóng</UiButton>
            <UiButton icon="send" :disabled="busy" @click="propose">Gửi đề xuất</UiButton>
        </template>
    </UiModal>
</template>
