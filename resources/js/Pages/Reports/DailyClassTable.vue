<script setup>
/**
 * Bảng lớp trong ngày của khối Vận hành (thiết kế 27/10/2026): mỗi buổi một dòng, gom "Danh sách lớp", việc của giáo viên
 * (chưa check-in, đi muộn, dạy thay…) và của trợ giảng (chưa báo cáo trực lớp…) vào đúng buổi. Điện thoại hiện dạng thẻ.
 */
import { Link } from '@inertiajs/vue3';

defineProps({ rows: { type: Array, required: true } });

const COLOR = { success: 'success', warning: 'warning', error: 'error', muted: 'neutral' };
const room = (r) => (r ? (/^phòng/i.test(r) ? r : `Phòng ${r}`) : null);
</script>

<template>
    <div data-daily-class-table>
        <div class="hidden overflow-x-auto rounded-lg border border-surface-variant md:block">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-surface-variant bg-surface-container-low">
                        <th scope="col" class="px-md py-sm font-label text-label uppercase text-on-surface-variant">Lớp</th>
                        <th scope="col" class="px-md py-sm font-label text-label uppercase text-on-surface-variant">Giờ · phòng</th>
                        <th scope="col" class="px-md py-sm font-label text-label uppercase text-on-surface-variant">Buổi học</th>
                        <th scope="col" class="px-md py-sm font-label text-label uppercase text-on-surface-variant">Giáo viên</th>
                        <th scope="col" class="px-md py-sm font-label text-label uppercase text-on-surface-variant">Trợ giảng</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.ref" class="border-b border-surface-container-high align-top last:border-b-0" :data-class-row="r.ref">
                        <td class="px-md py-sm font-body-semibold text-body-semibold text-on-surface">
                            <Link v-if="r.url" :href="r.url" class="hover:text-primary hover:underline">{{ r.class }}</Link>
                            <template v-else>{{ r.class }}</template>
                        </td>
                        <td class="px-md py-sm font-body-small text-body-small text-on-surface-variant">{{ [r.time, room(r.room)].filter(Boolean).join(' · ') }}</td>
                        <td class="px-md py-sm"><UiBadge :color="COLOR[r.tone] ?? 'neutral'">{{ r.tag }}</UiBadge></td>
                        <td class="px-md py-sm font-body-small text-body-small text-on-surface">
                            {{ r.teacher ?? '—' }}
                            <span v-for="(d, i) in r.teacherIssues" :key="i" class="mt-0.5 block"><UiBadge :color="COLOR[d.tone] ?? 'neutral'" :dot="false" :title="d.text">{{ d.tag }}</UiBadge></span>
                        </td>
                        <td class="px-md py-sm font-body-small text-body-small text-on-surface">
                            {{ r.assistant ?? '—' }}
                            <span v-for="(d, i) in r.assistantIssues" :key="i" class="mt-0.5 block"><UiBadge :color="COLOR[d.tone] ?? 'neutral'" :dot="false" :title="d.text">{{ d.tag }}</UiBadge></span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ul class="space-y-sm md:hidden">
            <li v-for="r in rows" :key="r.ref" class="space-y-xs rounded-lg border border-surface-variant p-md">
                <div class="flex items-start justify-between gap-sm">
                    <Link v-if="r.url" :href="r.url" class="font-body-semibold text-body-semibold text-on-surface hover:text-primary">{{ r.class }}</Link>
                    <span v-else class="font-body-semibold text-body-semibold text-on-surface">{{ r.class }}</span>
                    <UiBadge :color="COLOR[r.tone] ?? 'neutral'">{{ r.tag }}</UiBadge>
                </div>
                <p class="font-body-small text-body-small text-on-surface-variant">{{ [r.time, room(r.room)].filter(Boolean).join(' · ') }}</p>
                <p class="font-body-small text-body-small text-on-surface-variant">GV {{ r.teacher ?? '—' }} · TG {{ r.assistant ?? '—' }}</p>
                <div v-if="r.teacherIssues.length || r.assistantIssues.length" class="flex flex-wrap gap-xs">
                    <UiBadge v-for="(d, i) in [...r.teacherIssues, ...r.assistantIssues]" :key="i" :color="COLOR[d.tone] ?? 'neutral'" :dot="false">{{ d.who ? `${d.who} · ` : '' }}{{ d.tag }}</UiBadge>
                </div>
            </li>
        </ul>
    </div>
</template>
