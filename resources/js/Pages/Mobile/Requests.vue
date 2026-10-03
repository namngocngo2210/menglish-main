<script setup>
/**
 * Xin duyệt trên điện thoại: tạo đơn chấm công / nghỉ (Bổ sung công, Xin đi muộn / về sớm, Xin nghỉ), theo dõi
 * trạng thái, rút đơn đang chờ; và lối tắt tới các yêu cầu khác mà vai trò được gửi (giáo trình, hoàn tiền…).
 * ?type=correction|late_early|leave → mở sẵn form loại đơn đó (từ màn Chấm công).
 */
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MobileLayout from '@/Layouts/MobileLayout.vue';

defineOptions({ layout: MobileLayout });

const props = defineProps({
    types: { type: Array, required: true },
    today: { type: String, required: true },
    requests: { type: Array, default: () => [] },
    roleRequests: { type: Array, default: () => [] },
});

const ICONS = { correction: 'edit_calendar', late_early: 'schedule', leave: 'beach_access' };
const HINTS = {
    correction: 'Quên / không chấm được công, khai giờ vào – ra',
    late_early: 'Báo trước hoặc giải trình đi muộn, về sớm 1 ngày',
    leave: 'Nghỉ có phép từ ngày đến ngày',
};
const formType = ref(null);
const formTitle = computed(() => props.types.find((t) => t.value === formType.value)?.label ?? '');

onMounted(() => {
    const type = new URL(window.location.href).searchParams.get('type');
    if (props.types.some((t) => t.value === type)) formType.value = type;
});
</script>

<template>
    <Head title="Xin duyệt" />

    <div class="space-y-lg">
        <section>
            <h2 class="mb-sm font-body-semibold text-body-semibold text-on-surface">Tạo đơn</h2>
            <div class="grid gap-sm">
                <button
                    v-for="type in types"
                    :key="type.value"
                    type="button"
                    class="flex min-h-14 items-center gap-md rounded-2xl border border-outline-variant bg-surface-container-lowest px-md py-sm text-left shadow-sm transition-colors hover:bg-surface-container-low focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40"
                    :data-request-type="type.value"
                    @click="formType = type.value"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-fixed text-primary"><span class="material-symbols-outlined" aria-hidden="true">{{ ICONS[type.value] }}</span></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-body-semibold text-body-semibold text-on-surface">{{ type.label }}</span>
                        <span class="block font-caption text-caption text-on-surface-variant">{{ HINTS[type.value] }}</span>
                    </span>
                    <span class="material-symbols-outlined text-on-surface-subtle" aria-hidden="true">chevron_right</span>
                </button>
            </div>
        </section>

        <section>
            <h2 class="mb-sm font-body-semibold text-body-semibold text-on-surface">Đơn của tôi</h2>
            <ul v-if="requests.length" class="space-y-sm">
                <li v-for="r in requests" :key="r.id" class="rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" :data-request="r.id">
                    <div class="flex items-start justify-between gap-sm">
                        <div class="min-w-0">
                            <p class="font-body-semibold text-body-semibold text-on-surface">{{ r.type_label }}</p>
                            <p class="font-body-small text-body-small text-on-surface-variant">{{ r.period }}</p>
                        </div>
                        <UiBadge :color="r.status_tone">{{ r.status_label }}</UiBadge>
                    </div>
                    <p class="mt-xs whitespace-pre-line break-words font-body-small text-body-small text-on-surface">{{ r.reason }}</p>
                    <p v-if="r.reviewer" class="mt-xs font-caption text-caption text-on-surface-variant">
                        {{ r.status === 'rejected' ? 'Từ chối' : 'Duyệt' }} bởi {{ r.reviewer }}<template v-if="r.rejection_reason">: {{ r.rejection_reason }}</template>
                    </p>
                    <UiForm v-if="r.status === 'pending'" :action="route('mobile.requests.cancel', r.id)" method="post" :confirm="`Rút đơn ${r.type_label} ngày ${r.period}?`" confirm-label="Rút đơn" danger class="mt-sm">
                        <UiButton type="submit" variant="danger-text" size="sm" icon="undo">Rút đơn</UiButton>
                    </UiForm>
                </li>
            </ul>
            <UiEmptyState v-else compact icon="outgoing_mail" title="Bạn chưa gửi đơn nào" description="Đơn đã gửi và kết quả duyệt hiện ở đây." />
        </section>

        <section v-if="roleRequests.length">
            <h2 class="mb-sm font-body-semibold text-body-semibold text-on-surface">Yêu cầu khác theo vai trò</h2>
            <div class="divide-y divide-surface-container overflow-hidden rounded-2xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                <Link v-for="link in roleRequests" :key="link.url" :href="link.url" class="flex min-h-12 items-center gap-md px-md py-sm hover:bg-surface-container-low">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">{{ link.icon }}</span>
                    <span class="min-w-0 flex-1 font-body-medium text-body-medium text-on-surface">{{ link.label }}</span>
                    <span class="material-symbols-outlined text-on-surface-subtle" aria-hidden="true">chevron_right</span>
                </Link>
            </div>
            <p class="mt-xs font-caption text-caption text-on-surface-variant">Mở màn đầy đủ của hệ thống.</p>
        </section>
    </div>

    <UiModal :show="!!formType" :title="formTitle" max-width="2xl" @close="formType = null">
        <UiForm v-if="formType" id="attendance-request-form" :key="formType" :action="route('mobile.requests.store')" method="post" class="space-y-md" @success="formType = null">
            <input type="hidden" name="type" :value="formType" />
            <div class="grid grid-cols-1 gap-md" :class="formType === 'leave' ? 'sm:grid-cols-2' : ''">
                <UiInput name="date_from" type="date" :label="formType === 'leave' ? 'Từ ngày' : 'Ngày'" required :value="today" :max="formType === 'correction' ? today : null" />
                <UiInput v-if="formType === 'leave'" name="date_to" type="date" label="Đến ngày" required :value="today" />
            </div>
            <div v-if="formType === 'correction'" class="grid grid-cols-2 gap-md">
                <UiInput name="check_in_time" type="time" label="Giờ vào" hint="Bỏ trống nếu đã chấm" />
                <UiInput name="check_out_time" type="time" label="Giờ ra" hint="Bỏ trống nếu đã chấm" />
            </div>
            <UiTextarea name="reason" label="Lý do" required :rows="3" maxlength="1000" :placeholder="formType === 'leave' ? 'VD: Nghỉ ốm, việc gia đình…' : 'VD: Điện thoại hết pin, kẹt xe…'" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="formType = null">Hủy</UiButton>
            <UiButton type="submit" form="attendance-request-form" icon="send">Gửi đơn</UiButton>
        </template>
    </UiModal>
</template>
