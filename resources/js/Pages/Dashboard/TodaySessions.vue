<script setup>
/** Lớp học hôm nay của cơ sở (Overview::todaySessions): giờ, lớp, phòng, giáo viên và trạng thái check-in / điểm danh. */
import { Link } from '@inertiajs/vue3';
import { tone } from './tones';

defineProps({ sessions: { type: Object, required: true } });
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-today-sessions>
        <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Lớp học hôm nay · {{ formatNumber(sessions.total) }} buổi</h3>
            <div class="flex flex-wrap gap-xs">
                <span v-if="sessions.notCheckedIn" class="rounded-full bg-error-container px-sm py-0.5 font-caption text-caption font-semibold text-error">{{ sessions.notCheckedIn }} GV chưa check-in</span>
                <span v-if="sessions.notAttended" class="rounded-full bg-primary-fixed px-sm py-0.5 font-caption text-caption font-semibold text-primary">{{ sessions.notAttended }} buổi chưa điểm danh</span>
            </div>
        </div>
        <Link v-for="s in sessions.items" :key="s.id" :href="s.href" class="flex items-center gap-md border-b border-surface-variant/60 px-md py-sm last:border-0 hover:bg-surface-container-low">
            <span class="w-24 shrink-0 font-code text-code font-semibold text-on-surface">{{ s.time }}</span>
            <span :class="['h-2.5 w-2.5 shrink-0 rounded-full', tone(s.tone).dot]" aria-hidden="true" />
            <span class="min-w-0 flex-1">
                <span class="block truncate font-body-medium text-body-medium font-semibold text-on-surface">{{ s.class }}</span>
                <span v-if="s.meta" class="block truncate font-caption text-caption text-on-surface-variant">{{ s.meta }}</span>
            </span>
            <span :class="['shrink-0 whitespace-nowrap rounded-full px-sm py-0.5 font-caption text-caption font-semibold', tone(s.tone).chip]">{{ s.status }}</span>
        </Link>
        <p v-if="sessions.total > sessions.items.length" class="px-md py-sm font-caption text-caption text-on-surface-variant">+{{ sessions.total - sessions.items.length }} buổi nữa</p>
        <UiEmptyState v-if="!sessions.items.length" icon="event_available" title="Hôm nay không có buổi học" />
    </section>
</template>
