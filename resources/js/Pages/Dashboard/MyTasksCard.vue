<script setup>
/** Việc được giao cho tôi chưa xong, hạn trong tuần này hoặc đã quá hạn (MyWorkBoard::myTasks). */
import { Link } from '@inertiajs/vue3';

defineProps({ tasks: { type: Object, required: true } });
</script>

<template>
    <section v-if="tasks.url" class="rounded-xl border border-surface-variant bg-surface-container-lowest" data-my-tasks>
        <div class="flex items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Việc của tôi trong tuần</h3>
            <Link :href="tasks.url" class="font-body-small text-body-small text-primary hover:underline">Xem tất cả ({{ formatNumber(tasks.total) }})</Link>
        </div>
        <Link v-for="task in tasks.items" :key="task.id" :href="tasks.url" class="flex items-center justify-between gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0 hover:bg-surface-container-low">
            <span class="min-w-0">
                <span class="block truncate font-body-medium text-body-medium text-on-surface" :title="task.title">{{ task.title }}</span>
                <span class="block font-caption text-caption text-on-surface-variant">{{ task.status_label }}</span>
            </span>
            <span :class="['shrink-0 rounded-full px-sm py-xs font-caption text-caption', task.overdue ? 'bg-error-container text-error' : 'bg-surface-container-low text-on-surface-variant']">{{ task.due ? 'Hạn ' + task.due : 'Không hạn' }}</span>
        </Link>
        <UiEmptyState v-if="!tasks.items.length" icon="task_alt" title="Không có việc đến hạn tuần này" />
    </section>
</template>
