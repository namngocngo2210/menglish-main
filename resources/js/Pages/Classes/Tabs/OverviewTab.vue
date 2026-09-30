<script setup>
/** Trang lớp · Tổng quan: thông tin chung + sĩ số + buổi kế tiếp + học thuật + sự vụ (gộp Hồ sơ lớp & Chi tiết học thuật). */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';

const props = defineProps({
    klass: { type: Object, required: true },
    seat: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    nextSession: { type: Object, default: null },
    sessionProgress: { type: Object, required: true },
    currentStage: { type: Object, default: null },
    nextBigTest: { type: Object, default: null },
    openIncidents: { type: Number, default: 0 },
    upcomingForeignTeachers: { type: Array, default: () => [] },
    upcomingAssistants: { type: Array, default: () => [] },
});

const card = 'rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm';
const label = 'mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-subtle';
const tabUrl = (t) => route('classes.show', { id: props.klass.id, tab: t });

const info = computed(() => [
    ['Chi nhánh', props.klass.branch ?? 'Chưa cập nhật'],
    ['Chương trình', props.klass.program ?? 'Chưa cập nhật'],
    ['Cấp độ', props.klass.level ?? 'Chưa cập nhật'],
    ['Phòng học', props.klass.room ?? 'Chưa cập nhật'],
    ['Giáo viên chính', props.klass.teacher ?? 'Chưa phân công'],
    // GVNN & trợ giảng không cố định theo lớp: lấy theo các buổi sắp tới / việc giao 7 ngày tới.
    ['GVNN (buổi sắp tới)', props.upcomingForeignTeachers.join(', ') || (props.klass.foreign_teacher ?? 'Chưa gán')],
    ['Trợ giảng (7 ngày tới)', props.upcomingAssistants.join(', ') || 'Theo ca & giao việc'],
    ['Lịch học', props.klass.schedule_text ?? 'Chưa cập nhật'],
    ['Khai giảng → Kết thúc', (formatDate(props.klass.start_date) || '—') + ' → ' + (formatDate(props.klass.end_date) || '—')],
]);
const seatText = computed(() =>
    props.seat.left === null ? 'Không giới hạn sĩ số' : props.seat.left === 0 ? 'Đã đủ sĩ số' : `Còn ${props.seat.left} chỗ`,
);
</script>

<template>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <section :class="[card, 'lg:col-span-2']">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">info</span>Thông tin chung
                </h2>
                <Link v-if="canManage" :href="route('classes.edit', klass.id)" class="text-xs font-semibold text-primary hover:underline">Sửa thông tin lớp</Link>
            </div>
            <dl class="grid grid-cols-1 gap-5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="[name, value] in info" :key="name">
                    <dt :class="label">{{ name }}</dt>
                    <dd class="font-bold text-on-surface">{{ value }}</dd>
                </div>
            </dl>
        </section>

        <section :class="card">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">group</span>Sĩ số
                </h2>
                <Link :href="tabUrl('students')" class="text-xs font-semibold text-primary hover:underline">Xem học viên</Link>
            </div>
            <p class="text-xl font-bold text-primary" :data-seats="klass.id">{{ seat.occupied }} / {{ seat.capacity || '∞' }} <span class="text-xs font-semibold text-on-surface-variant">học viên</span></p>
            <p :class="['mt-1 text-xs', seat.left === 0 ? 'font-bold text-error' : 'text-on-surface-variant']">{{ seatText }} · Ngưỡng khai giảng {{ seat.min }}</p>
            <p v-if="seat.needed > 0" class="mt-1 text-xs font-semibold text-warning">Cần thêm {{ seat.needed }} học viên để khai giảng</p>
        </section>

        <section :class="card">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">event</span>Buổi kế tiếp
                </h2>
                <Link :href="tabUrl('schedule')" class="text-xs font-semibold text-primary hover:underline">Lịch & buổi học</Link>
            </div>
            <template v-if="nextSession">
                <p class="text-sm font-bold text-on-surface">{{ formatDate(nextSession.date) }} · {{ nextSession.start }}–{{ nextSession.end }}</p>
                <p class="mt-1 text-xs text-on-surface-variant">{{ nextSession.room || klass.room || 'Chưa có phòng' }} · {{ nextSession.teacher ?? klass.teacher ?? 'Chưa phân công GV' }}</p>
            </template>
            <p v-else class="text-xs text-on-surface-variant">{{ klass.status === 'pending_schedule' ? 'Lớp chưa có lịch học.' : 'Không còn buổi học nào sắp tới.' }}</p>
            <p class="mt-3 text-xs text-on-surface-variant">Đã học <span class="font-code font-bold text-on-surface">{{ sessionProgress.done }}/{{ sessionProgress.total }}</span> buổi</p>
        </section>

        <section :class="card">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">school</span>Học thuật
                </h2>
                <Link :href="tabUrl('academic')" class="text-xs font-semibold text-primary hover:underline">Chi tiết</Link>
            </div>
            <dl class="space-y-3 text-xs">
                <div>
                    <dt :class="label">Chặng hiện tại</dt>
                    <dd class="font-bold text-on-surface">{{ currentStage?.stage_name ?? 'Chưa giao chặng' }}</dd>
                </div>
                <div>
                    <dt :class="label">Big Test sắp tới</dt>
                    <dd class="font-bold text-on-surface">{{ nextBigTest ? nextBigTest.title + ' · ' + nextBigTest.date : 'Chưa có' }}</dd>
                </div>
            </dl>
        </section>

        <section :class="card">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">report</span>Sự vụ
                </h2>
                <Link :href="tabUrl('incidents')" class="text-xs font-semibold text-primary hover:underline">Xem sự vụ</Link>
            </div>
            <p :class="['text-xl font-bold', openIncidents ? 'text-warning' : 'text-on-surface']">{{ openIncidents }}</p>
            <p class="text-xs text-on-surface-variant">sự vụ đang mở của lớp</p>
        </section>
    </div>
</template>
