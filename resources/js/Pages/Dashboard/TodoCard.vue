<script setup>
/**
 * "Cần bạn xử lý" (Overview::todo): mọi việc đang chờ mình gộp một danh sách, quá hạn lên đầu, mỗi dòng có nút làm ngay.
 */
import { Link } from '@inertiajs/vue3';
import { shortenCodesIn } from '@/lib/format';
import { tone } from './tones';

defineProps({ todo: { type: Object, required: true } });
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-todo>
        <div class="flex items-center gap-sm border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Cần bạn xử lý</h3>
            <span v-if="todo.total" class="rounded-full bg-error-container px-sm py-0.5 font-caption text-caption font-semibold text-error">{{ formatNumber(todo.total) }}</span>
        </div>
        <div v-for="(item, i) in todo.items" :key="i" class="flex flex-wrap items-center gap-x-md gap-y-xs border-b border-surface-variant/60 px-md py-sm last:border-0 sm:flex-nowrap">
            <span :class="['h-2.5 w-2.5 shrink-0 rounded-full', tone(item.tone).dot]" aria-hidden="true" />
            <div class="min-w-0 flex-1 basis-[calc(100%-2rem)] sm:basis-auto">
                <p class="truncate font-body-medium text-body-medium font-semibold text-on-surface" :title="item.title">
                    <span v-if="item.count" class="mr-xs tabular-nums text-primary">{{ formatNumber(item.count) }}</span>{{ shortenCodesIn(item.title) }}
                </p>
                <p v-if="item.meta" class="truncate font-caption text-caption text-on-surface-variant" :title="item.meta">{{ item.meta }}</p>
            </div>
            <span :class="['ml-[1.625rem] shrink-0 whitespace-nowrap rounded-full px-sm sm:ml-0 py-0.5 font-caption text-caption font-semibold', tone(item.tone).chip]">{{ item.when }}</span>
            <Link v-if="item.href" :href="item.href" :class="['ml-auto inline-flex min-h-9 shrink-0 sm:ml-0 items-center rounded-lg px-md font-body-small text-body-small font-semibold', item.tone === 'overdue' || item.tone === 'today' ? 'bg-primary-container text-white hover:bg-primary' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low']">
                {{ item.action }}
            </Link>
        </div>
        <UiEmptyState v-if="!todo.items.length" icon="task_alt" title="Không có việc nào đang chờ bạn" />
    </section>
</template>
