<script setup>
/**
 * Tạo lượt giao việc cho Trợ giảng — 3 ca: Trước / Trong / Sau giờ học; nhiều đầu việc một lần.
 * Luật riêng so với "Giao việc mới" (chỉ work_task.assign, TA trong phạm vi, giờ hạn theo buổi học, báo Admin khi gửi trễ).
 * Mở từ danh sách / form Giao việc → modal 4xl; mở thẳng URL → trang riêng.
 * Ô "Buổi học": buổi thật của lớp trong ngày giao; lớp không có buổi trong ngày → nhập tên buổi.
 */
import { computed, ref } from 'vue';
import AssignModeSwitch from './Partials/AssignModeSwitch.vue';

defineOptions({ layout: { title: 'Tạo lượt giao việc cho Trợ giảng' } });

const props = defineProps({
    assistants: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    classSessions: { type: [Object, Array], default: () => ({}) },
    slots: { type: Array, default: () => [] },
    today: { type: String, required: true },
    cutoff: { type: String, required: true },
    asModal: { type: Boolean, default: false },
});

const formId = computed(() => (props.asModal ? 'modal-ta-assign-form' : 'ta-assign-form'));
const assignDate = ref(props.today);
let seq = 1;
const tasks = ref([{ id: seq++, category: 'before', content: '', attach_class: false, class_id: '', class_session_id: '', session: '' }]);

const sessionsFor = (item) => (props.classSessions[item.class_id] || []).filter((s) => s.date === assignDate.value);
const addTask = () => tasks.value.push({ id: seq++, category: 'during', content: '', attach_class: false, class_id: '', class_session_id: '', session: '' });
const removeTask = (index) => tasks.value.length > 1 && tasks.value.splice(index, 1);

/** Lỗi của "tasks" và mọi "tasks.N.*" gom về một chỗ trên danh sách đầu việc. */
const taskErrors = (errors) => Object.entries(errors).filter(([key]) => key === 'tasks' || key.startsWith('tasks.')).map(([, msg]) => msg);

const control = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small';
const labelCls = 'mb-xs block font-label text-label uppercase text-on-surface-variant';
</script>

<template>
    <UiModalFrame title="Tạo lượt giao việc cho Trợ giảng" description="Phân công nhiệm vụ chi tiết theo ngày và ca học." :cancel="asModal ? 'Hủy bỏ' : false" :back="route('tasks.index')" size="4xl" page-width="max-w-4xl">
        <AssignModeSwitch current="assistant" />
        <UiForm :id="formId" :action="route('tasks.ta-assign.store')" method="post" class="space-y-lg" #default="{ errors }">
            <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                <UiSelect :id="asModal ? 'modal-ta-assistant_id' : 'f_assistant_id'" name="assistant_id" label="Chọn Trợ giảng" required placeholder="-- Chọn Trợ giảng --" :options="assistants" />
                <UiInput :id="(asModal ? 'modal-' : '') + 'assign_date'" v-model="assignDate" type="date" name="assign_date" label="Ngày giao việc" required />
                <UiSelect :id="asModal ? 'modal-ta-branch_id' : 'f_branch_id'" name="branch_id" label="Chi nhánh" placeholder="-- Chọn Chi nhánh --" :options="branches" />
            </div>
            <UiAlert v-if="!assistants.length" type="warning">Chưa có tài khoản trợ giảng nào đang hoạt động trong phạm vi bạn quản lý.</UiAlert>

            <div class="space-y-md border-t border-surface-container pt-md">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">checklist</span> Danh sách nhiệm vụ
                    </h2>
                    <span class="font-caption text-caption text-on-surface-variant">{{ tasks.length }} đầu việc</span>
                </div>
                <UiErrors :messages="taskErrors(errors)" />

                <div v-for="(item, index) in tasks" :key="item.id" class="relative space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-md">
                    <UiButton variant="ghost" icon="delete" title="Xóa đầu việc" aria-label="Xóa đầu việc" class="absolute right-sm top-sm p-xs hover:bg-error-container hover:text-error" @click="removeTask(index)" />
                    <div class="grid grid-cols-1 gap-sm pr-xl md:grid-cols-12">
                        <label class="md:col-span-3">
                            <span :class="labelCls">Nhóm đầu mục</span>
                            <select v-model="item.category" :name="`tasks[${index}][category]`" :class="control">
                                <option v-for="slot in slots" :key="slot.value" :value="slot.value">{{ slot.label }}</option>
                            </select>
                        </label>
                        <label class="md:col-span-7">
                            <span :class="labelCls">Nội dung</span>
                            <textarea
                                v-model="item.content"
                                :name="`tasks[${index}][content]`"
                                required
                                rows="2"
                                maxlength="500"
                                placeholder="Nhập nội dung công việc..."
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small"
                            ></textarea>
                        </label>
                        <label class="flex items-center gap-xs md:col-span-2 md:pt-lg">
                            <input type="hidden" :name="`tasks[${index}][attach_class]`" :value="item.attach_class ? '1' : '0'" />
                            <input v-model="item.attach_class" type="checkbox" class="h-4 w-4 rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                            <span class="font-body-small text-body-small font-medium">Gắn lớp?</span>
                        </label>
                    </div>
                    <div v-if="item.attach_class" class="grid grid-cols-1 gap-sm border-t border-dashed border-outline-variant pt-sm md:grid-cols-2">
                        <label>
                            <span :class="labelCls">Lớp học <span class="text-error">*</span></span>
                            <select v-model="item.class_id" :name="`tasks[${index}][class_id]`" required :class="control" @change="item.class_session_id = ''">
                                <option value="">Chọn lớp học</option>
                                <option v-for="c in classes" :key="c.value" :value="String(c.value)">{{ c.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span :class="labelCls">Buổi học <span class="text-error">*</span></span>
                            <select v-if="sessionsFor(item).length" v-model="item.class_session_id" :name="`tasks[${index}][class_session_id]`" :class="control">
                                <option value="">Chọn buổi học</option>
                                <option v-for="s in sessionsFor(item)" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                            </select>
                            <input
                                v-else
                                v-model="item.session"
                                type="text"
                                :name="`tasks[${index}][session]`"
                                placeholder="Lớp không có buổi học trong ngày — nhập tên buổi"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small"
                            />
                        </label>
                    </div>
                </div>

                <UiButton variant="secondary" icon="add" class="w-full border-dashed" @click="addTask()">Thêm đầu việc</UiButton>
            </div>

            <div class="flex flex-col items-center gap-xs border-t border-surface-container pt-md">
                <UiButton v-if="!asModal" type="submit" icon="send" class="w-full sm:w-auto sm:min-w-[220px]">Gửi nhiệm vụ</UiButton>
                <p class="font-body-small text-body-small text-on-surface-variant">Khuyến nghị gửi trước {{ cutoff.replace(':', 'h') }} — gửi trễ vẫn được, hệ thống sẽ báo Admin.</p>
                <p class="font-caption text-caption text-on-surface-variant">Hạn mỗi ca: gắn buổi học → Trước giờ học = giờ vào lớp, Trong giờ học = giờ tan lớp, Sau giờ học = tan lớp + 60 phút; không gắn buổi → 14:00 / 18:00 / 21:30.</p>
            </div>
        </UiForm>

        <template v-if="asModal" #footer>
            <UiButton type="submit" :form="formId" icon="send">Gửi nhiệm vụ</UiButton>
        </template>
    </UiModalFrame>
</template>
