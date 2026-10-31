<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';

const props = defineProps({
    shift: { type: Object, required: true },
    steps: { type: Array, required: true },
    status: { type: Object, required: true },
    statusClass: { type: String, required: true },
});

const currentSteps = computed(() => props.steps.filter((step) => step.current));
const doneSteps = computed(() => props.steps.filter((step) => step.done));
const checkedIn = computed(() => props.steps.find((step) => step.key === 'checkin' && step.done));
const chipTone = (step) => step.done ? 'bg-tertiary/10 text-tertiary' : step.current ? 'bg-primary-light text-primary' : 'bg-surface-container-low text-on-surface-variant';
const textLink = 'inline-flex min-h-11 items-center rounded-lg text-sm font-semibold text-primary hover:underline focus-visible:outline-primary';
</script>

<template>
    <div class="min-w-0 p-md md:flex md:gap-md md:p-lg">
        <div class="mb-md flex flex-wrap items-center gap-sm rounded-xl bg-primary-light p-sm text-primary md:mb-0 md:w-24 md:shrink-0 md:flex-col md:self-start md:text-center">
            <strong class="font-mono text-2xl">{{ shift.start_time }}</strong>
            <span class="text-xs">đến {{ shift.end_time }}</span>
            <span v-if="shift.shift_name" class="text-xs font-semibold">{{ shift.shift_name }}</span>
        </div>

        <div class="min-w-0 flex-1 space-y-sm">
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div class="min-w-0 flex-1 basis-48">
                    <h3>
                        <Link :href="route('classes.show', shift.class_id)" class="inline-flex min-h-11 items-center break-words text-xl font-bold text-on-surface hover:underline">{{ shift.class_name }}</Link>
                    </h3>
                    <UiBadge v-if="shift.type_label" color="info">{{ shift.type_label }}</UiBadge>
                    <p class="mt-xs break-words text-sm text-on-surface-variant">
                        {{ shift.room_label ?? 'Chưa có phòng' }}, {{ shift.branch_name ?? 'Chưa gán chi nhánh' }} · {{ shift.student_count }} HV
                        <template v-if="shift.support_student"> · {{ shift.support_student }}</template>
                    </p>
                    <Link v-if="shift.trial_count && can('portal.teacher')" :href="route('teacher.trial-guests')" :class="textLink">+{{ shift.trial_count }} khách học thử</Link>
                </div>
                <span :class="['inline-flex max-w-full items-center gap-xs rounded-full px-sm py-xs text-xs font-semibold', statusClass]">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>{{ status.label }}
                </span>
            </div>

            <ol aria-label="Tiến độ buổi dạy" class="relative flex min-w-0 gap-2 overflow-x-auto pb-sm">
                <li v-for="step in steps" :key="step.key" :data-step="step.key" :data-state="step.done ? 'done' : step.locked ? 'locked' : 'current'" :title="step.locked ? step.hint : undefined" :aria-current="step.current ? 'step' : undefined" :class="['flex shrink-0 items-center gap-2 rounded-full px-sm py-xs text-xs font-medium', chipTone(step)]">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">{{ step.done ? 'check_circle' : step.current ? 'radio_button_checked' : 'lock' }}</span>
                    <span>{{ step.label }}</span>
                    <span v-if="step.locked" class="sr-only">{{ step.hint }}</span>
                    <!-- Preserve locked action URLs without exposing an enabled action. -->
                    <span v-for="action in step.locked ? step.actions : []" :key="action.label" class="sr-only" :data-action-url="action.href"></span>
                </li>
            </ol>

            <div v-if="currentSteps.length" class="rounded-xl border border-primary-border bg-primary-light p-md" data-testid="shift-current-step">
                <div v-for="step in currentSteps" :key="step.key" class="flex flex-wrap items-center justify-between gap-sm border-t border-primary-border py-sm first:border-t-0 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        <h4 class="text-sm font-semibold text-primary">Bước {{ steps.indexOf(step) + 1 }}/{{ steps.length }} · {{ step.label }}</h4>
                        <p :class="['mt-xs text-sm', step.warn ? 'font-semibold text-error' : 'text-on-surface-variant']">{{ step.hint }}</p>
                    </div>
                    <div v-if="step.actions.length" class="flex w-full flex-wrap gap-2 md:w-auto">
                        <template v-for="act in step.actions" :key="act.label">
                            <UiButton v-if="act.drawer" size="sm" class="min-h-11 w-full md:w-auto" :variant="step.current && !act.secondary ? 'primary' : 'secondary'" :href="act.href" modal="drawer">{{ act.label }}</UiButton>
                            <UiButton v-else size="sm" class="min-h-11 w-full md:w-auto" :variant="step.current && !act.secondary ? 'primary' : 'secondary'" :href="act.href" :method="act.method" :data="act.data" :preserve-scroll="!!act.method">{{ act.label }}</UiButton>
                        </template>
                    </div>
                </div>
                <p v-if="checkedIn" class="mt-sm flex items-center gap-xs text-xs text-tertiary">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>{{ checkedIn.hint }}
                </p>
            </div>
            <p v-else-if="doneSteps.length === steps.length" class="flex items-center gap-xs text-sm font-semibold text-tertiary">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>Buổi dạy đã hoàn tất
            </p>
            <p v-if="checkedIn && !currentSteps.length" class="text-xs text-tertiary">{{ checkedIn.hint }}</p>

            <div class="flex flex-wrap items-center gap-x-md text-sm">
                <span class="text-xs text-on-surface-variant">Bước {{ doneSteps.length }}/{{ steps.length }} đã xong</span>
                <a v-if="shift.syllabus_url" :href="shift.syllabus_url" :class="textLink">Mở giáo trình</a>
                <Link v-if="shift.can_teach && !shift.is_support" :href="route('teacher.scores', shift.class_id)" :class="textLink">Nhập điểm</Link>
                <Link :href="route('classes.show', shift.class_id)" :class="textLink">Trang lớp</Link>
            </div>

            <!-- Keep editing and review actions available without expanding completed steps by default. -->
            <details v-if="doneSteps.some((step) => step.actions.length)" class="text-sm">
                <summary class="inline-flex min-h-11 cursor-pointer items-center font-semibold text-primary focus-visible:outline-primary">Xem bước đã xong</summary>
                <div v-for="step in doneSteps.filter((item) => item.actions.length)" :key="step.key" class="flex flex-wrap items-center gap-sm border-t border-surface-container py-sm">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ step.label }}</p>
                        <p class="text-xs text-tertiary">{{ step.hint }}</p>
                    </div>
                    <template v-for="act in step.actions" :key="act.label">
                        <UiButton v-if="step.locked" size="sm" class="min-h-11 w-full md:w-auto" variant="secondary" disabled>{{ act.label }}</UiButton>
                        <UiButton v-else-if="act.drawer" size="sm" class="min-h-11 w-full md:w-auto" variant="secondary" :href="act.href" modal="drawer">{{ act.label }}</UiButton>
                        <UiButton v-else size="sm" class="min-h-11 w-full md:w-auto" variant="secondary" :href="act.href" :method="act.method" :data="act.data" :preserve-scroll="!!act.method">{{ act.label }}</UiButton>
                    </template>
                </div>
            </details>
        </div>
    </div>
</template>
