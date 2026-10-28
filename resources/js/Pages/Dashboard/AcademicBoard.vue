<script setup>
/** Học thuật: lớp cần chú ý trong tháng (TeachingQuality::teachersTable.attention) + Big Test 14 ngày tới. */
import { Link } from '@inertiajs/vue3';

defineProps({
    attention: { type: Array, default: () => [] },
    bigTests: { type: Array, default: () => [] },
    monthLabel: { type: String, default: '' },
});
const pct = (v) => (v === null || v === undefined ? '—' : String(v).replace('.', ',') + '%');
const num = (v) => (v === null || v === undefined ? '—' : String(v).replace('.', ','));
</script>

<template>
    <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
        <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest lg:col-span-2" data-overview-attention-classes>
            <div class="flex items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Lớp cần chú ý<template v-if="monthLabel"> · {{ monthLabel }}</template></h3>
                <Link :href="route('classes.index')" class="font-body-small text-body-small text-primary hover:underline">Tất cả lớp</Link>
            </div>
            <div class="overflow-x-auto">
                <table v-if="attention.length" class="w-full text-left font-body-small text-body-small">
                    <thead class="text-on-surface-variant">
                        <tr class="border-b border-surface-variant">
                            <th class="whitespace-nowrap px-md py-sm font-semibold">Lớp</th>
                            <th class="whitespace-nowrap px-md py-sm font-semibold">Giáo viên</th>
                            <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Chuyên cần</th>
                            <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Bài về nhà</th>
                            <th class="whitespace-nowrap px-md py-sm text-right font-semibold">Điểm TB</th>
                            <th class="whitespace-nowrap px-md py-sm font-semibold">Vì sao</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in attention" :key="c.id" class="border-b border-surface-variant/60 last:border-0">
                            <td class="whitespace-nowrap px-md py-sm font-semibold"><Link :href="route('classes.show', c.id)" class="text-on-surface hover:text-primary">{{ c.name }}</Link></td>
                            <td class="whitespace-nowrap px-md py-sm">{{ c.teacher ?? '—' }}</td>
                            <td :class="['px-md py-sm text-right tabular-nums', c.attendance !== null && c.attendance < 75 ? 'font-semibold text-error' : '']">{{ pct(c.attendance) }}</td>
                            <td :class="['px-md py-sm text-right tabular-nums', c.homework !== null && c.homework < 50 ? 'font-semibold text-error' : '']">{{ pct(c.homework) }}</td>
                            <td :class="['px-md py-sm text-right tabular-nums', c.score !== null && c.score < 6.5 ? 'font-semibold text-error' : '']">{{ num(c.score) }}</td>
                            <td class="min-w-44 px-md py-sm">
                                <span class="flex flex-wrap gap-xs">
                                    <span v-for="r in c.reasons" :key="r" class="whitespace-nowrap rounded-full bg-primary-fixed px-sm py-0.5 font-caption text-caption font-semibold text-primary">{{ r }}</span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <UiEmptyState v-if="!attention.length" icon="verified" title="Chưa có lớp nào dưới ngưỡng trong tháng" />
        </section>
        <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-bigtests>
            <div class="flex items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Big Test 14 ngày tới</h3>
                <Link :href="route('syllabus.big-tests.schedules')" class="font-body-small text-body-small text-primary hover:underline">Lịch</Link>
            </div>
            <div v-for="t in bigTests" :key="t.id" class="flex items-center gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0">
                <span class="flex w-12 shrink-0 flex-col items-center rounded-lg bg-surface-container-low py-xs">
                    <span class="font-caption text-caption text-on-surface-variant">{{ formatDate(t.scheduled_at, 'H:i') }}</span>
                    <span class="font-h3 text-h3 font-bold text-on-surface">{{ formatDate(t.scheduled_at, 'd/m') }}</span>
                </span>
                <span class="min-w-0">
                    <span class="block truncate font-body-medium text-body-medium font-semibold text-on-surface">{{ t.title }}</span>
                    <span class="block truncate font-caption text-caption text-on-surface-variant">{{ t.class ?? '—' }}</span>
                </span>
            </div>
            <UiEmptyState v-if="!bigTests.length" icon="event_available" title="Không có Big Test trong 14 ngày tới" />
        </section>
    </div>
</template>
