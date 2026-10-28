<script setup>
/**
 * Giáo viên / trợ giảng: ca tiếp theo hôm nay (nút Check-in / Điểm danh to ngay đầu màn) + lịch dạy hôm nay (Overview::shifts).
 * Check-in vẫn làm ở Cổng Giáo viên (chọn nhiều ca, kiểm tra cửa sổ check-in), nút ở đây mở thẳng màn đó.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { tone } from './tones';

const props = defineProps({ shifts: { type: Object, required: true } });
const next = computed(() => props.shifts.next);
const startsIn = computed(() => {
    const m = next.value?.minutesToStart;
    if (m === null || m === undefined) return 'Đang diễn ra';
    if (m < 60) return `Bắt đầu sau ${m} phút`;
    return `Bắt đầu lúc ${next.value.start}`;
});
</script>

<template>
    <div class="grid grid-cols-1 gap-md lg:grid-cols-3" data-overview-shifts>
        <section v-if="next" class="flex flex-col gap-md rounded-2xl bg-sidebar p-lg text-white lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div class="min-w-0">
                    <p class="font-caption text-caption font-bold uppercase tracking-wide text-primary-fixed-dim">Ca tiếp theo · {{ startsIn }}</p>
                    <p class="mt-xs font-h1 text-h1 font-bold">{{ next.time }} · {{ next.class }}</p>
                    <p class="mt-xs font-body-small text-body-small text-white/75">
                        {{ [next.room, next.students + ' học sinh', next.trials ? next.trials + ' khách học thử' : null].filter(Boolean).join(' · ') }}
                    </p>
                </div>
                <span v-if="next.checkedIn" class="rounded-full bg-white/15 px-sm py-0.5 font-caption text-caption font-semibold">Đã check-in {{ next.checkinTime }}</span>
            </div>
            <div class="flex flex-wrap gap-sm">
                <Link v-if="!next.checkedIn" :href="shifts.homeUrl" class="inline-flex min-h-12 items-center rounded-lg bg-primary-container px-lg font-body-medium text-body-medium font-semibold text-white hover:bg-primary">Check-in ca dạy</Link>
                <Link :href="next.attendanceUrl" :class="['inline-flex min-h-12 items-center rounded-lg px-lg font-body-medium text-body-medium font-semibold', next.checkedIn ? 'bg-primary-container text-white hover:bg-primary' : 'border border-white/30 bg-white/10 text-white hover:bg-white/20']">Điểm danh</Link>
                <Link v-if="shifts.lessonUrl" :href="shifts.lessonUrl" class="inline-flex min-h-12 items-center rounded-lg border border-white/30 bg-white/10 px-lg font-body-medium text-body-medium font-semibold text-white hover:bg-white/20">Bài giảng hôm nay</Link>
                <Link v-if="shifts.taTasks" :href="shifts.taTasks.url" class="inline-flex min-h-12 items-center rounded-lg border border-white/30 bg-white/10 px-lg font-body-medium text-body-medium font-semibold text-white hover:bg-white/20">
                    Nhiệm vụ trực lớp {{ shifts.taTasks.done }}/{{ shifts.taTasks.total }}
                </Link>
            </div>
        </section>
        <section v-else class="flex flex-col justify-center gap-xs rounded-2xl border border-surface-variant bg-surface-container-lowest p-lg lg:col-span-2">
            <p class="font-h3 text-h3 text-on-surface">{{ shifts.today.length ? 'Đã hết ca dạy hôm nay' : 'Hôm nay bạn không có ca dạy' }}</p>
            <Link :href="shifts.homeUrl" class="font-body-small text-body-small font-semibold text-primary hover:underline">Xem lịch cả tuần ở Cổng Giáo viên</Link>
        </section>

        <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest">
            <div class="flex items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
                <h3 class="font-h3 text-h3 text-on-surface">Lịch dạy hôm nay</h3>
                <span v-if="shifts.today.length" class="rounded-full bg-surface-container-high px-sm py-0.5 font-caption text-caption font-semibold text-on-surface-variant">{{ shifts.today.length }} ca · {{ String(shifts.hours).replace('.', ',') }} giờ</span>
            </div>
            <Link v-for="s in shifts.today" :key="s.id" :href="s.attendanceUrl" :class="['flex items-center gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0 hover:bg-surface-container-low', next && s.id === next.id ? 'bg-primary-light' : '']">
                <span class="w-24 shrink-0 font-code text-code font-semibold text-on-surface">{{ s.time }}</span>
                <span class="min-w-0 flex-1 truncate font-body-small text-body-small text-on-surface">{{ s.class }}<template v-if="s.room"> · {{ s.room }}</template></span>
                <span :class="['shrink-0 whitespace-nowrap rounded-full px-sm py-0.5 font-caption text-caption font-semibold', tone(s.tone).chip]">{{ s.status }}</span>
            </Link>
            <div class="px-md py-sm">
                <Link :href="shifts.homeUrl" class="font-body-small text-body-small font-semibold text-primary hover:underline">Lịch cả tuần</Link>
            </div>
        </section>
    </div>
</template>
