<script setup>
/**
 * Giao việc mới: mở từ Danh sách công việc → modal 2xl; mở thẳng URL → trang riêng.
 * "Giao cho: Trợ giảng" chuyển sang form giao việc theo ca (tasks.ta-assign).
 * Mọi nhân sự giao được việc cho Admin; người chỉ có quyền đề xuất (GV / TA / Sales) giao cho Admin và người duyệt công việc.
 */
import { ref } from 'vue';
import AssignModeSwitch from './Partials/AssignModeSwitch.vue';

defineOptions({ layout: (props) => ({ title: props.title }) });

defineProps({
    title: { type: String, required: true },
    users: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    defaultDueDate: { type: String, default: null },
    canTaAssign: { type: Boolean, default: false },
    asModal: { type: Boolean, default: false },
});

const isRecurring = ref(false);
const frequencies = [
    { value: 'daily', label: 'Hàng ngày' },
    { value: 'weekly', label: 'Hàng tuần' },
    { value: 'monthly', label: 'Hàng tháng' },
];
</script>

<template>
    <UiModalFrame
        :title="title"
        description="Giao một việc cho đồng nghiệp. Ai cũng có thể giao việc cho Admin."
        :action="route('tasks.store')"
        method="post"
        submit-label="Lưu và Giao việc"
        submit-icon="send"
        cancel="Hủy"
        :back="route('tasks.index')"
        size="2xl"
        page-width="max-w-2xl"
    >
        <AssignModeSwitch v-if="canTaAssign" current="staff" />
        <div class="space-y-md">
            <UiInput id="modal-task-taskTitle" name="taskTitle" label="Tiêu đề công việc" required placeholder="Nhập tiêu đề công việc..." maxlength="255" />
            <UiTextarea id="modal-task-taskDescription" name="taskDescription" label="Mô tả chi tiết" :rows="3" placeholder="Mô tả nội dung công việc chi tiết..." />
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect id="modal-task-assignee" name="assignee" label="Người nhận" required placeholder="Chọn nhân sự..." :options="users" />
                <UiDate id="modal-task-dueDate" name="dueDate" label="Hạn hoàn thành" required :value="defaultDueDate" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect id="modal-task-branch_id" name="branch_id" label="Chi nhánh" placeholder="-- Không chỉ định --" :options="branches" />
                <UiSelect id="modal-task-class_id" name="class_id" label="Gắn lớp (nếu có)" placeholder="-- Không gắn lớp --" :options="classes" />
            </div>
            <fieldset class="space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-md">
                <legend class="px-xs font-body-small text-body-small font-medium text-on-surface">Loại công việc</legend>
                <div class="flex flex-wrap items-center gap-lg">
                    <label class="inline-flex cursor-pointer items-center gap-xs">
                        <input type="radio" name="taskType" value="one-time" :checked="!isRecurring" class="text-primary-container focus:ring-primary-container" @change="isRecurring = false" />
                        <span class="font-body-base text-body-base">Phát sinh</span>
                    </label>
                    <label class="inline-flex cursor-pointer items-center gap-xs">
                        <input type="radio" name="taskType" value="recurring" :checked="isRecurring" class="text-primary-container focus:ring-primary-container" @change="isRecurring = true" />
                        <span class="font-body-base text-body-base">Lặp đi lặp lại</span>
                    </label>
                </div>
                <div v-show="isRecurring" class="border-t border-outline-variant pt-sm">
                    <UiSelect id="modal-task-frequency" name="frequency" label="Tần suất" :options="frequencies" value="weekly" hint="Mỗi lần việc này được xác nhận xong, hệ thống tự tạo lại việc cho lần sau." />
                </div>
            </fieldset>
        </div>
    </UiModalFrame>
</template>
