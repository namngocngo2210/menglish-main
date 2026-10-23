<script setup>
/** Trang lớp — tab Homework: tỉ lệ nộp homework 3 giỏ theo học sinh và cả lớp, 30 ngày gần nhất (docs/kho-syllabus.md §10.2). */
import HomeworkRateTable from '@/Components/Homework/HomeworkRateTable.vue';

defineProps({ homework: { type: Object, required: true } });
</script>

<template>
    <div class="space-y-md">
        <p class="text-body-small text-on-surface-variant">Bài tập giao từ {{ formatDate(homework.from) }} đến {{ formatDate(homework.to) }} — số bài nộp / số bài được giao theo giỏ.</p>
        <HomeworkRateTable :stats="homework" />
    </div>
</template>
