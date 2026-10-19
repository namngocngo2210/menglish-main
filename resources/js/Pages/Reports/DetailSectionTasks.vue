<script setup>
/**
 * Task follow-up của một khối trong chi tiết báo cáo (Reports/Detail). Admin (`canAdd`) tạo task ngay trong khối (chủ dự án 17/10/2026):
 * mặc định giao người viết, gửi riêng từng khối (StaffReportController::reportTaskStore), lưu xong tải lại chi tiết. Người xem khác chỉ xem.
 */
import { ref } from 'vue';
import { route } from '@/lib/route';
import SectionTasks from './SectionTasks.vue';

const props = defineProps({
    reportId: { type: Number, required: true },
    section: { type: String, required: true },
    title: { type: String, required: true },
    tasks: { type: Array, default: () => [] },
    canAdd: { type: Boolean, default: false },
    assignees: { type: Array, default: () => [] },
    today: { type: String, default: null },
});

let uid = 0;
const rows = ref([]);
const add = () => rows.value.push({ uid: ++uid, title: null });
const remove = (t) => (rows.value = rows.value.filter((x) => x.uid !== t.uid));
const indexOf = (t) => rows.value.indexOf(t);
const messages = (errors) => Object.entries(errors).filter(([key]) => key === 'tasks' || key.startsWith('tasks.')).map(([, msg]) => msg);
</script>

<template>
    <UiForm v-if="canAdd" :action="route('reports.tasks.store', reportId)" method="post" stay class="space-y-sm" :data-review-tasks="section" #default="{ errors, processing }" @success="rows = []">
        <SectionTasks
            :section="section"
            :title="title"
            :tasks="tasks"
            :rows="rows"
            :index-of="indexOf"
            :assignees="assignees"
            :today="today"
            add-label="Admin tạo task"
            @add="add"
            @remove="remove"
        />
        <UiErrors :messages="messages(errors)" />
        <div v-if="rows.length" class="flex justify-end gap-sm">
            <UiButton type="button" variant="ghost" size="sm" @click="rows = []">Thôi</UiButton>
            <UiButton type="submit" size="sm" icon="send" :disabled="processing" data-review-tasks-submit>Giao {{ rows.length }} task</UiButton>
        </div>
    </UiForm>
    <SectionTasks v-else-if="tasks.length" readonly :section="section" :title="title" :tasks="tasks" />
</template>
