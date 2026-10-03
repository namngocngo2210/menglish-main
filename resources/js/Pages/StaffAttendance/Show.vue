<script setup>
/** Chi tiết 1 ngày công: ảnh khuôn mặt lúc vào / ra, giờ máy chủ, vị trí GPS (mở bản đồ), khoảng cách tới cơ sở. */
defineOptions({ layout: (props) => ({ title: `Chấm công ${props.attendance?.user ?? ''}` }) });

defineProps({
    asModal: { type: Boolean, default: false },
    attendance: { type: Object, required: true },
});
</script>

<template>
    <UiModalFrame :title="`${attendance.user} · ${formatDate(attendance.date, 'l, d/m/Y')}`" :description="[attendance.code, attendance.branch].filter(Boolean).join(' · ')" :submit-label="false" cancel="Đóng" :back="route('staff-attendance.index', { date: attendance.date })">
        <div class="space-y-md">
            <div class="flex flex-wrap items-center gap-sm">
                <UiBadge :color="attendance.status_tone">{{ attendance.status_label }}</UiBadge>
                <span v-if="attendance.expected_start" class="font-body-small text-body-small text-on-surface-variant">Giờ phải có mặt {{ attendance.expected_start }}<template v-if="attendance.expected_end"> – {{ attendance.expected_end }}</template></span>
                <a v-if="attendance.penalty" :href="attendance.penalty.url" class="font-body-small text-body-small text-error underline">Biên bản {{ attendance.penalty.code }}</a>
            </div>

            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <div v-for="punch in [{ label: 'Vào', data: attendance.in }, { label: 'Ra', data: attendance.out }]" :key="punch.label" class="rounded-xl border border-outline-variant p-sm">
                    <p class="mb-xs font-body-semibold text-body-semibold text-on-surface">{{ punch.label }}: <span class="font-mono">{{ punch.data?.time ?? '—' }}</span></p>
                    <a v-if="punch.data?.photo" :href="punch.data.photo" target="_blank" rel="noopener">
                        <img :src="punch.data.photo" :alt="`Ảnh khuôn mặt lúc chấm ${punch.label.toLowerCase()}`" class="aspect-[3/4] w-full rounded-lg bg-surface-container object-cover" />
                    </a>
                    <div v-else class="flex aspect-[3/4] w-full items-center justify-center rounded-lg bg-surface-container-low text-on-surface-subtle">
                        <span class="font-body-small text-body-small">{{ punch.data ? 'Không có ảnh (bổ sung công)' : 'Chưa chấm' }}</span>
                    </div>
                    <p v-if="punch.data?.distance !== null && punch.data?.distance !== undefined" class="mt-xs font-body-small text-body-small text-on-surface-variant">
                        Cách cơ sở {{ formatNumber(punch.data.distance) }} m (cho phép {{ attendance.radius }} m)<template v-if="punch.data.accuracy"> · sai số GPS {{ punch.data.accuracy }} m</template>
                    </p>
                    <a v-if="punch.data?.map" :href="punch.data.map" target="_blank" rel="noopener" class="mt-xs inline-flex items-center gap-xs font-body-small text-body-small text-primary hover:underline">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">map</span>Xem vị trí trên bản đồ
                    </a>
                </div>
            </div>

            <p v-if="attendance.note" class="whitespace-pre-line rounded-lg bg-surface-container-low p-sm font-body-small text-body-small">{{ attendance.note }}</p>
        </div>
    </UiModalFrame>
</template>
