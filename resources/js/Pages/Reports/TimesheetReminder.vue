<script setup>
// Placement pending BA - move/replace this single component
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage();
</script>

<template>
    <section v-if="page.props.timesheetReminder?.length" class="mb-lg w-full rounded-2xl border border-warning/40 bg-warning-container/40 p-5 text-sm" data-timesheet-reminder>
        <p class="font-bold text-on-surface">Báo cáo chấm công tháng chưa nộp</p>
        <p class="mt-1 text-on-surface">
            <template v-for="(item, index) in page.props.timesheetReminder" :key="item.month">
                <Link :href="item.href" class="font-semibold text-primary underline">{{ item.label }}</Link><template v-if="index < page.props.timesheetReminder.length - 1">, </template>
            </template>
        </p>
        <p class="mt-1 text-on-surface-variant">Có thể nộp bất cứ lúc nào, không có hạn nộp. Bấm vào tháng để nộp hoặc cập nhật.</p>
    </section>
</template>
