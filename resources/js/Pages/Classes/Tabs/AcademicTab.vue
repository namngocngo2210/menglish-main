<script setup>
/** Trang lớp · Học thuật: chương trình, chặng giáo trình, tiến độ và lịch Big Test (trước là "Chi tiết lớp học thuật"). */
import { Link } from '@inertiajs/vue3';

defineProps({
    klass: { type: Object, required: true },
    currentStage: { type: Object, default: null },
    sessionProgress: { type: Object, required: true },
    bigTests: { type: Array, default: () => [] },
});

const card = 'rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm';
const label = 'mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-subtle';
</script>

<template>
    <div class="space-y-5">
        <section :class="card">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">school</span>Chương trình &amp; Tiến độ
                </h2>
                <Link v-if="can('syllabus.manage')" :href="route('syllabus.assignments')" class="text-xs font-semibold text-primary hover:underline">Giao chặng</Link>
            </div>
            <dl class="grid grid-cols-1 gap-5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt :class="label">Tên chương trình</dt>
                    <dd class="font-bold text-on-surface">{{ klass.program ?? 'Chưa cập nhật' }}</dd>
                </div>
                <div>
                    <dt :class="label">Chặng hiện tại</dt>
                    <dd>
                        <UiBadge v-if="currentStage" color="primary" pill>{{ currentStage.stage_name }}</UiBadge>
                        <span v-else class="font-bold text-on-surface-subtle">Chưa giao chặng</span>
                    </dd>
                </div>
                <div>
                    <dt :class="label">Ngày mở chặng</dt>
                    <dd class="font-mono font-bold text-on-surface">{{ currentStage?.created_at ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="label">Buổi đã học</dt>
                    <dd>
                        <span v-if="sessionProgress.total > 0" class="font-mono text-sm font-bold text-primary">{{ sessionProgress.done }} / {{ sessionProgress.total }} buổi</span>
                        <span v-else class="font-bold text-on-surface-subtle">Chưa có lịch học</span>
                    </dd>
                </div>
            </dl>
        </section>

        <section :class="card">
            <div class="mb-4 flex items-center justify-between border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">event_available</span>Lịch Big Test
                </h2>
                <Link v-if="can('syllabus.manage')" :href="route('syllabus.big-tests.results')" class="text-xs font-semibold text-primary hover:underline">Bảng điểm & kết quả</Link>
            </div>
            <UiEmptyState v-if="!bigTests.length" icon="event_busy" title="Lớp chưa có đợt Big Test nào." />
            <ul v-else class="space-y-2">
                <li v-for="bt in bigTests" :key="bt.id" class="flex flex-col justify-between gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low/70 p-3 sm:flex-row sm:items-center">
                    <div>
                        <span class="block text-xs font-bold text-on-surface">{{ bt.title }}</span>
                        <span class="font-mono text-xs text-on-surface-variant">{{ bt.scheduled_at ?? 'Chưa xếp lịch' }}{{ bt.room ? ' • ' + bt.room : '' }}</span>
                    </div>
                    <UiBadge :color="bt.past ? 'success' : 'primary'">{{ bt.past ? 'Đã diễn ra' : 'Sắp diễn ra' }}</UiBadge>
                </li>
            </ul>
        </section>
    </div>
</template>
