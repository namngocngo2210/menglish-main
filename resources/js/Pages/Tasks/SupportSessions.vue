<script setup>
/**
 * Danh sách bổ trợ & xếp lịch phụ đạo: học viên vắng học, điểm mini test / Big Test dưới 7 và học viên ghi trong báo cáo
 * trực lớp tự vào danh sách; Học vụ xếp buổi bổ trợ (kiểm tra trùng lịch người dạy / phòng), buổi hoàn thành → bảng công chờ duyệt.
 * "Xếp buổi" ở một dòng (?support=) mở sẵn modal với học viên / lớp của dòng đó.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Danh sách bổ trợ & xếp lịch phụ đạo' } });

const props = defineProps({
    pendingSupports: { type: Object, required: true },
    sessions: { type: Object, required: true },
    sources: { type: Array, default: () => [] },
    source: { type: String, default: null },
    classes: { type: Array, default: () => [] },
    studentGroups: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    selectedSupport: { type: Object, default: null },
    defaultDate: { type: String, required: true },
});

const sourceColors = { attendance: 'warning', mini_test: 'info', big_test: 'error', class_report: 'neutral' };
const chip = (active) => ['rounded-full border px-2.5 py-1', active ? 'border-primary-container bg-primary-container text-white' : 'border-surface-container-highest text-on-surface-variant'];
const open = ref(!!props.selectedSupport);
</script>

<template>
    <UiPageHeader title="Danh sách bổ trợ & xếp lịch phụ đạo" description="Học viên vắng học, điểm mini test / Big Test dưới 7 được tự đưa vào danh sách; Học vụ xếp buổi bổ trợ, buổi hoàn thành chuyển bảng công chờ duyệt.">
        <template v-if="can('work_task.assign')" #actions>
            <UiButton icon="event" @click="open = true">Xếp buổi phụ đạo</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <UiDataTable>
            <template #header>
                <h2 class="text-sm font-bold">Danh sách cần bổ trợ (chưa xếp buổi)</h2>
                <div class="flex flex-wrap gap-1.5 text-xs font-semibold">
                    <Link :href="route('tasks.support-sessions')" :class="chip(!source)">Tất cả</Link>
                    <Link v-for="s in sources" :key="s.key" :href="route('tasks.support-sessions', { source: s.key })" :class="chip(source === s.key)">{{ s.label }}</Link>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Nguồn</th>
                        <th>Lý do</th>
                        <th class="text-center">Ngày ghi nhận</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in pendingSupports.data" :key="item.id">
                        <td class="font-bold">
                            {{ item.student }}<small class="block font-normal text-on-surface-subtle">{{ item.class_name }}</small>
                        </td>
                        <td><UiBadge :color="sourceColors[item.source] ?? 'neutral'">{{ item.source_label }}</UiBadge></td>
                        <td class="text-on-surface-variant">
                            {{ item.reason }}<small v-if="item.action_plan" class="block text-on-surface-subtle">KH: {{ item.action_plan }}</small>
                        </td>
                        <td class="text-center text-on-surface-variant">{{ item.date }}</td>
                        <td class="text-right">
                            <Link v-if="can('work_task.assign')" :href="route('tasks.support-sessions', { support: item.id })" class="font-bold text-primary hover:underline">Xếp buổi</Link>
                        </td>
                    </tr>
                    <tr v-if="!pendingSupports.data.length">
                        <td colspan="5"><UiEmptyState title="Không có học viên nào đang chờ xếp buổi bổ trợ." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="pendingSupports" :options="[]" unit="học viên" page-name="list_page" /></template>
        </UiDataTable>

        <UiDataTable>
            <template #header><h2 class="text-sm font-bold">Buổi phụ đạo đã xếp</h2></template>
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Nguồn / lý do</th>
                        <th class="text-center">Lịch</th>
                        <th class="text-center">Người dạy</th>
                        <th class="text-center">Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sessions.data" :key="s.id">
                        <td class="font-bold">
                            {{ s.student }}<small class="block font-normal text-on-surface-subtle">{{ s.class_name }}</small>
                        </td>
                        <td class="text-on-surface-variant">
                            <UiBadge v-if="s.source_label" :color="sourceColors[s.source] ?? 'neutral'">{{ s.source_label }}</UiBadge>
                            <span class="mt-0.5 block">{{ s.reason }}</span>
                        </td>
                        <td class="text-center">
                            {{ s.date }}<br />{{ s.time }}<template v-if="s.room"><br />P. {{ s.room }}</template>
                        </td>
                        <td class="text-center">{{ s.teacher }}</td>
                        <td class="text-center">
                            <UiBadge :color="s.status === 'completed' ? 'success' : 'warning'">{{ s.status_label }}</UiBadge>
                        </td>
                        <td class="text-right">
                            <UiForm v-if="s.can_complete" :action="route('tasks.support-sessions.complete', s.id)" method="post">
                                <UiButton type="submit" variant="success" size="sm">Hoàn thành</UiButton>
                            </UiForm>
                        </td>
                    </tr>
                    <tr v-if="!sessions.data.length">
                        <td colspan="6"><UiEmptyState title="Chưa có lịch phụ đạo." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="sessions" :options="[]" unit="buổi" page-name="session_page" /></template>
        </UiDataTable>
    </div>

    <!-- Mở sẵn khi chọn "Xếp buổi" ở một dòng (?support=); lỗi validate / trùng lịch hiện ngay trong modal. -->
    <UiModal v-if="can('work_task.assign')" :show="open" title="Xếp buổi phụ đạo" max-width="xl" @close="open = false">
        <UiForm id="new-support-session-form" :action="route('tasks.support-sessions.store')" method="post" class="space-y-md" @success="open = false">
            <template v-if="selectedSupport">
                <input type="hidden" name="class_report_student_support_id" :value="selectedSupport.id" />
                <div class="rounded-xl border border-primary-container/30 bg-primary-container/10 p-3 text-xs">
                    <div class="font-bold text-on-surface">{{ selectedSupport.student }} · {{ selectedSupport.source_label }}</div>
                    <div class="mt-0.5 text-on-surface-variant">{{ selectedSupport.reason }}</div>
                    <Link :href="route('tasks.support-sessions')" class="mt-1 inline-block font-semibold text-primary hover:underline">Bỏ chọn</Link>
                </div>
            </template>
            <UiSelect id="support_class_id" name="class_id" label="Lớp" required placeholder="Chọn lớp" :options="classes" :value="selectedSupport?.class_id" />
            <UiSelect id="support_student_id" name="student_id" label="Học viên" required placeholder="Chọn học viên">
                <optgroup v-for="group in studentGroups" :key="group.class_id" :label="group.label">
                    <option
                        v-for="st in group.options"
                        :key="st.value"
                        :value="st.value"
                        :selected="selectedSupport && selectedSupport.student_id === st.value && selectedSupport.class_id === group.class_id"
                    >
                        {{ st.label }}
                    </option>
                </optgroup>
            </UiSelect>
            <UiSelect id="support_teacher_id" name="teacher_id" label="Người dạy" required placeholder="Chọn giáo viên / trợ giảng / học vụ" :options="teachers" />
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiDate id="support_session_date" name="session_date" label="Ngày" :value="defaultDate" required />
                <UiInput id="support_start_time" type="time" name="start_time" label="Giờ bắt đầu" required />
                <UiInput id="support_end_time" type="time" name="end_time" label="Giờ kết thúc" required />
            </div>
            <UiInput id="support_room" name="room" label="Phòng" />
            <UiTextarea id="support_reason" name="reason" label="Mục tiêu phụ đạo" :rows="2" placeholder="Để trống sẽ dùng lý do trong danh sách bổ trợ" />
            <p class="text-xs text-on-surface-variant">Hệ thống kiểm tra trùng lịch người dạy (kể cả vai trò trợ giảng/GVNN) và phòng, bỏ qua buổi đã hủy.</p>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">Hủy</UiButton>
            <UiButton type="submit" form="new-support-session-form" icon="event_available">Xếp lịch</UiButton>
        </template>
    </UiModal>
</template>
