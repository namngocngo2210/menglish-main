<script setup>
/** Trang lớp · Tổng quan: trái "Thông tin lớp" + buổi học sắp tới; phải sĩ số (vạch ngưỡng khai giảng) + tóm tắt. */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/format';
import { groupSchedule } from '@/lib/schedule';
import { route } from '@/lib/route';

const props = defineProps({
    klass: { type: Object, required: true },
    seat: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    upcomingSessions: { type: Array, default: () => [] },
    sessionProgress: { type: Object, required: true },
    currentStage: { type: Object, default: null },
    nextBigTest: { type: Object, default: null },
    openIncidents: { type: Number, default: 0 },
    upcomingForeignTeachers: { type: Array, default: () => [] },
    upcomingAssistants: { type: Array, default: () => [] },
});

const card = 'rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg';
const weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
const tabUrl = (t) => route('classes.show', { id: props.klass.id, tab: t });

const info = computed(() => [
    ['Chương trình', props.klass.program ?? 'Chưa cập nhật'],
    ['Cấp độ', props.klass.level ?? 'Chưa cập nhật'],
    ['Chi nhánh', props.klass.branch ?? 'Chưa cập nhật'],
    ['Phòng học', props.klass.room ?? 'Chưa cập nhật'],
    ['Lịch học', groupSchedule(props.klass.schedule_text).map((g) => [g.days, g.time].filter(Boolean).join(' · ')).join('; ') || 'Chưa xếp lịch'],
    ['Khai giảng → Kết thúc', (formatDate(props.klass.start_date) || '—') + ' → ' + (formatDate(props.klass.end_date) || '—')],
    ['Giáo viên chính', props.klass.teacher ?? 'Chưa phân công'],
    // GVNN & trợ giảng không cố định theo lớp: lấy theo các buổi sắp tới / việc giao 7 ngày tới.
    ['GVNN (buổi sắp tới)', props.upcomingForeignTeachers.join(', ') || (props.klass.foreign_teacher ?? 'Chưa gán')],
    ['Trợ giảng (7 ngày tới)', props.upcomingAssistants.join(', ') || props.klass.assistant || 'Theo ca & giao việc'],
]);

// Thanh sĩ số + vạch ngưỡng khai giảng (lớp không giới hạn chỗ: thanh theo ngưỡng).
const scale = computed(() => props.seat.capacity || Math.max(props.seat.min, props.seat.occupied, 1));
const fill = computed(() => Math.min(100, Math.round((props.seat.occupied * 100) / scale.value)));
const marker = computed(() => (props.seat.min ? Math.min(100, Math.round((props.seat.min * 100) / scale.value)) : null));
const seatText = computed(() => (props.seat.left === null ? 'Không giới hạn' : props.seat.left === 0 ? 'Đã đủ chỗ' : `Còn ${props.seat.left} chỗ`));
</script>

<template>
    <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
        <div class="space-y-md lg:col-span-2">
            <section :class="card">
                <h2 class="mb-sm font-h3 text-h3 text-on-surface">Thông tin lớp</h2>
                <dl class="grid grid-cols-1 gap-x-lg sm:grid-cols-2">
                    <div v-for="[name, value] in info" :key="name" class="flex justify-between gap-md border-t border-surface-container py-sm">
                        <dt class="text-on-surface-variant">{{ name }}</dt>
                        <dd class="text-right font-semibold text-on-surface">{{ value }}</dd>
                    </div>
                </dl>
            </section>

            <section :class="card">
                <div class="mb-sm flex items-center justify-between gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Buổi học sắp tới</h2>
                    <Link :href="tabUrl('sessions')" class="font-body-small text-body-small font-semibold text-primary hover:underline">Xem tất cả {{ sessionProgress.total }} buổi</Link>
                </div>
                <p v-if="!upcomingSessions.length" class="border-t border-surface-container py-sm text-on-surface-variant">
                    {{ klass.status === 'pending_schedule' ? 'Lớp chưa có lịch học.' : 'Không còn buổi học nào sắp tới.' }}
                </p>
                <div v-for="s in upcomingSessions" :key="s.id" class="flex items-center gap-md border-t border-surface-container py-sm">
                    <div class="w-11 shrink-0 text-center">
                        <div class="text-xs font-semibold text-on-surface-variant">{{ weekdays[s.weekday] }}</div>
                        <div class="font-bold text-on-surface">{{ formatDate(s.date, 'd/m') }}</div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-on-surface">{{ s.no ? `Buổi ${s.no} · ` : '' }}{{ s.start }}–{{ s.end }}</div>
                        <div class="truncate text-xs text-on-surface-variant">{{ [s.room ? `Phòng ${s.room}` : null, s.teacher, s.foreign_teacher ? `GVNN ${s.foreign_teacher}` : null].filter(Boolean).join(' · ') }}</div>
                    </div>
                    <UiBadge v-if="s.is_today" color="primary">Hôm nay</UiBadge>
                </div>
            </section>
        </div>

        <aside class="space-y-md">
            <section :class="card">
                <div class="mb-sm flex items-center justify-between gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Sĩ số</h2>
                    <Link v-if="canManage && can('student.view')" :href="route('students.enrollments')" class="font-body-small text-body-small font-semibold text-primary hover:underline">Thêm học viên</Link>
                </div>
                <p class="text-2xl font-bold text-on-surface" :data-seats="klass.id">
                    {{ seat.occupied }}<span class="text-sm font-normal text-on-surface-variant"> / {{ seat.capacity || '∞' }} học viên</span>
                </p>
                <div class="relative mt-sm h-2 rounded-full bg-surface-container">
                    <div class="h-full rounded-full" :class="seat.needed > 0 ? 'bg-warning' : 'bg-tertiary'" :style="{ width: fill + '%' }"></div>
                    <div v-if="marker !== null" class="absolute -top-1 h-4 w-0.5 bg-on-surface" :style="{ left: marker + '%' }" title="Ngưỡng khai giảng"></div>
                </div>
                <div class="mt-xs flex justify-between text-xs text-on-surface-variant">
                    <span>{{ seatText }}</span><span>Ngưỡng khai giảng: {{ seat.min }}</span>
                </div>
                <p v-if="seat.needed > 0" class="mt-sm text-xs font-semibold text-warning">Cần thêm {{ seat.needed }} học viên để khai giảng{{ klass.start_date ? ` ${formatDate(klass.start_date)}` : '' }}</p>
            </section>

            <section :class="card">
                <h2 class="mb-sm font-h3 text-h3 text-on-surface">Tóm tắt</h2>
                <dl>
                    <div class="flex justify-between gap-md border-t border-surface-container py-sm">
                        <dt class="text-on-surface-variant">Đã học</dt>
                        <dd class="font-semibold">{{ sessionProgress.done }}/{{ sessionProgress.total }} buổi</dd>
                    </div>
                    <div class="flex justify-between gap-md border-t border-surface-container py-sm">
                        <dt class="text-on-surface-variant">Chặng hiện tại</dt>
                        <dd class="text-right font-semibold">{{ currentStage?.stage_name ?? 'Chưa giao chặng' }}</dd>
                    </div>
                    <div class="flex justify-between gap-md border-t border-surface-container py-sm">
                        <dt class="text-on-surface-variant">Big Test sắp tới</dt>
                        <dd class="text-right font-semibold">{{ nextBigTest ? `${nextBigTest.title} · ${nextBigTest.date}` : 'Chưa có' }}</dd>
                    </div>
                    <div class="flex justify-between gap-md border-t border-surface-container py-sm">
                        <dt class="text-on-surface-variant">Sự vụ đang mở</dt>
                        <dd class="font-semibold" :class="openIncidents ? 'text-warning' : ''">{{ openIncidents }}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</template>
