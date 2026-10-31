<script setup>
import { Link } from '@inertiajs/vue3';
import { shortenCodesIn } from '@/lib/format';
import { tone } from './tones';

defineProps({ item: { type: Object, required: true } });
</script>

<template>
    <div class="flex min-w-0 items-center gap-sm border-b border-surface-variant/60 px-md py-sm last:border-0">
        <div class="min-w-0 flex-1">
            <p class="break-words font-body-medium text-body-medium font-semibold text-on-surface">
                <span v-if="item.count != null" class="mr-xs tabular-nums text-primary">{{ formatNumber(item.count) }}</span>{{ shortenCodesIn(item.title) }}
            </p>
            <p v-if="item.meta" class="mt-xs break-words font-caption text-caption text-on-surface-variant">{{ item.meta }}</p>
            <span :class="['mt-xs inline-flex max-w-full items-center gap-xs rounded-full px-sm py-0.5 font-caption text-caption font-semibold', tone(item.tone).chip]">
                <span class="material-symbols-outlined shrink-0 text-[16px]" aria-hidden="true">schedule</span>
                <span class="min-w-0 break-words">{{ item.when }}</span>
            </span>
        </div>
        <Link v-if="item.href" :href="item.href" :class="['inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg px-sm font-body-small text-body-small font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container focus-visible:ring-offset-2 sm:px-md', item.tone === 'overdue' || item.tone === 'today' ? 'bg-primary-container text-white hover:bg-primary' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low']">
            {{ item.action }}
        </Link>
    </div>
</template>
