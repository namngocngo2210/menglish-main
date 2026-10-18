<script setup>
/**
 * Học bù & bổ trợ: học viên vắng học, điểm mini test / Big Test dưới 7 và học viên ghi trong báo cáo trực lớp tự vào danh
 * sách; Học vụ xếp buổi bổ trợ kèm nội dung buổi đã dạy, chuyển trợ giảng (kiểm tra trùng lịch người dạy / phòng).
 * Người bổ trợ tick hoàn thành kèm nhận xét buổi → bảng công chờ duyệt.
 * "Xếp buổi" ở một dòng (?support=) mở sẵn modal với học viên / lớp / nội dung buổi vắng của dòng đó.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Học bù & bổ trợ' } });

const props = defineProps({
    pendingSupports: { type: Object, default: null },
    sessions: { type: Object, required: true },
    sources: { type: Array, default: () => [] },
    source: { type: String, default: null },
    classes: { type: Array, default: () => [] },
    studentGroups: { type: Array, default: () => [] },
    teacherGroups: { type: Array, default: () => [] },
    selectedSupport: { type: Object, default: null },
    defaultDate: { type: String, required: true },
    canAssign: { type: Boolean, default: false },
    mine: { type: Boolean, default: false },
});

const sourceColors = { attendance: 'warning', mini_test: 'info', big_test: 'error', class_report: 'neutral' };
const chip = (active) => ['rounded-full border px-2.5 py-1', active ? 'border-primary-container bg-primary-container text-white' : 'border-surface-container-highest text-on-surface-variant'];
const open = ref(!!props.selectedSupport);
// Buổi đang tick hoàn thành (modal nhập nhận xét).
const completing = ref(null);
</script>

<template>
    <UiPageHeader
        title="Học bù & bổ trợ"
        :description="canAssign
            ? 'Học sinh nghỉ học, điểm mini test / Big Test dưới 7 tự vào danh sách. Học vụ xếp buổi bổ trợ kèm nội dung buổi đã dạy và chuyển trợ giảng; trợ giảng bổ trợ xong thì tick hoàn thành và nhận xét.'
            : 'Buổi bổ trợ được giao cho bạn: xem nội dung cần dạy lại, bổ trợ xong thì tick hoàn thành và nhận xét buổi.'"
    >
        <template v-if="canAssign" #actions>
            <UiButton icon="event" @click="open = true">Xếp buổi bổ trợ</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <UiDataTable v-if="canAssign && pendingSupports">
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
                            <div v-if="item.missed" class="mt-1 rounded-lg bg-surface-container-low p-2 text-xs">
                                <span class="font-semibold text-on-surface">Buổi vắng: {{ item.missed.label }}</span>
                                <span v-if="item.missed.content" class="mt-0.5 block whitespace-pre-line">{{ item.missed.content }}</span>
                                <span v-else class="mt-0.5 block italic text-on-surface-subtle">Chưa có nội dung bài / báo cáo trực lớp của buổi này.</span>
                            </div>
                        </td>
                        <td class="text-center text-on-surface-variant">{{ item.date }}</td>
                        <td class="text-right">
                            <Link :href="route('tasks.support-sessions', { support: item.id })" class="font-bold text-primary hover:underline">Xếp buổi</Link>
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
            <template #header>
                <h2 class="text-sm font-bold">{{ canAssign ? 'Buổi bổ trợ đã xếp' : 'Buổi bổ trợ của tôi' }}</h2>
                <div v-if="canAssign" class="flex flex-wrap gap-1.5 text-xs font-semibold">
                    <Link :href="route('tasks.support-sessions', { source: source || undefined })" :class="chip(!mine)">Tất cả</Link>
                    <Link :href="route('tasks.support-sessions', { mine: 1, source: source || undefined })" :class="chip(mine)">Giao cho tôi</Link>
                </div>
            </template>
            <!-- Điện thoại: thẻ dọc để nút "Đã bổ trợ xong" luôn thấy, không phải cuộn ngang bảng. -->
            <ul class="divide-y divide-surface-container md:hidden">
                <li v-for="s in sessions.data" :key="'m' + s.id" class="space-y-1.5 p-md text-sm">
                    <div class="flex items-start justify-between gap-sm">
                        <div class="min-w-0">
                            <p class="font-bold text-on-surface">{{ s.student }}</p>
                            <p class="text-xs text-on-surface-subtle">{{ s.class_name }}</p>
                        </div>
                        <UiBadge :color="s.status === 'completed' ? 'success' : s.status === 'cancelled' ? 'neutral' : 'warning'">{{ s.status_label }}</UiBadge>
                    </div>
                    <p class="text-xs text-on-surface-variant">
                        {{ s.date }} · {{ s.time }}<template v-if="s.room"> · P. {{ s.room }}</template> · {{ s.teacher }}
                    </p>
                    <p class="text-on-surface-variant">{{ s.reason }}</p>
                    <p v-if="s.missed_label" class="text-xs font-semibold text-on-surface">Dạy lại: {{ s.missed_label }}</p>
                    <p v-if="s.lesson_content" class="whitespace-pre-line rounded-lg bg-surface-container-low p-2 text-xs text-on-surface-variant">{{ s.lesson_content }}</p>
                    <p v-if="s.completion_note" class="whitespace-pre-line text-xs text-on-surface-variant">Nhận xét: {{ s.completion_note }}</p>
                    <UiButton v-if="s.can_complete" variant="success" size="sm" icon="check" class="w-full" @click="completing = s">Đã bổ trợ xong</UiButton>
                </li>
                <li v-if="!sessions.data.length" class="p-md">
                    <UiEmptyState :title="canAssign && !mine ? 'Chưa có buổi bổ trợ nào.' : 'Chưa có buổi bổ trợ nào được giao cho bạn.'" />
                </li>
            </ul>
            <table class="hidden md:table">
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Nội dung bổ trợ</th>
                        <th class="text-center">Lịch</th>
                        <th class="text-center">Người bổ trợ</th>
                        <th>Trạng thái / nhận xét</th>
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
                            <small v-if="s.missed_label" class="mt-0.5 block font-semibold text-on-surface">Dạy lại: {{ s.missed_label }}</small>
                            <small v-if="s.lesson_content" class="mt-0.5 block whitespace-pre-line text-xs">{{ s.lesson_content }}</small>
                        </td>
                        <td class="text-center">
                            {{ s.date }}<br />{{ s.time }}<template v-if="s.room"><br />P. {{ s.room }}</template>
                        </td>
                        <td class="text-center">{{ s.teacher }}<small v-if="s.is_mine" class="block text-on-surface-subtle">(bạn)</small></td>
                        <td class="text-on-surface-variant">
                            <UiBadge :color="s.status === 'completed' ? 'success' : s.status === 'cancelled' ? 'neutral' : 'warning'">{{ s.status_label }}</UiBadge>
                            <small v-if="s.completed_at" class="ml-1 text-on-surface-subtle">{{ s.completed_at }}</small>
                            <span v-if="s.completion_note" class="mt-0.5 block whitespace-pre-line text-xs">Nhận xét: {{ s.completion_note }}</span>
                        </td>
                        <td class="text-right">
                            <UiButton v-if="s.can_complete" variant="success" size="sm" icon="check" @click="completing = s">Đã bổ trợ xong</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!sessions.data.length">
                        <td colspan="6"><UiEmptyState :title="canAssign && !mine ? 'Chưa có buổi bổ trợ nào.' : 'Chưa có buổi bổ trợ nào được giao cho bạn.'" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="sessions" :options="[]" unit="buổi" page-name="session_page" /></template>
        </UiDataTable>
    </div>

    <!-- Mở sẵn khi chọn "Xếp buổi" ở một dòng (?support=); lỗi validate / trùng lịch hiện ngay trong modal. -->
    <UiModal v-if="canAssign" :show="open" title="Xếp buổi bổ trợ" max-width="xl" @close="open = false">
        <UiForm id="new-support-session-form" :action="route('tasks.support-sessions.store')" method="post" class="space-y-md" @success="open = false">
            <template v-if="selectedSupport">
                <input type="hidden" name="class_report_student_support_id" :value="selectedSupport.id" />
                <div class="rounded-xl border border-primary-container/30 bg-primary-container/10 p-3 text-xs">
                    <div class="font-bold text-on-surface">{{ selectedSupport.student }} · {{ selectedSupport.source_label }}</div>
                    <div class="mt-0.5 text-on-surface-variant">{{ selectedSupport.reason }}</div>
                    <div v-if="selectedSupport.missed" class="mt-0.5 font-semibold text-on-surface">Buổi vắng: {{ selectedSupport.missed.label }}</div>
                    <Link :href="route('tasks.support-sessions')" class="mt-1 inline-block font-semibold text-primary hover:underline">Bỏ chọn</Link>
                </div>
            </template>
            <UiSelect id="support_class_id" name="class_id" label="Lớp" required placeholder="Chọn lớp" :options="classes" :value="selectedSupport?.class_id" />
            <UiSelect id="support_student_id" name="student_id" label="Học viên" required placeholder="Chọn học viên" :value="selectedSupport?.student_id">
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
            <UiSelect id="support_teacher_id" name="teacher_id" label="Trợ giảng bổ trợ" required placeholder="Chọn trợ giảng (hoặc GV / Học vụ)">
                <optgroup v-for="group in teacherGroups" :key="group.label" :label="group.label">
                    <option v-for="t in group.options" :key="t.value" :value="t.value">{{ t.label }}</option>
                </optgroup>
            </UiSelect>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiDate id="support_session_date" name="session_date" label="Ngày" :value="defaultDate" required />
                <UiInput id="support_start_time" type="time" name="start_time" label="Giờ bắt đầu" required />
                <UiInput id="support_end_time" type="time" name="end_time" label="Giờ kết thúc" required />
            </div>
            <UiInput id="support_room" name="room" label="Phòng" />
            <UiTextarea id="support_reason" name="reason" label="Mục tiêu bổ trợ" :rows="2" placeholder="Để trống sẽ dùng lý do trong danh sách bổ trợ" />
            <UiTextarea
                id="support_lesson_content"
                name="lesson_content"
                label="Nội dung buổi đã dạy (chuyển cho trợ giảng)"
                :rows="5"
                :value="selectedSupport?.missed?.content"
                hint="Lấy sẵn từ giáo trình, báo cáo trực lớp và BTVN của buổi học sinh vắng; sửa lại nếu cần."
            />
            <p class="text-xs text-on-surface-variant">Hệ thống kiểm tra trùng lịch người dạy (kể cả vai trò trợ giảng/GVNN) và phòng, bỏ qua buổi đã hủy. Người bổ trợ nhận thông báo khi được giao.</p>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">Hủy</UiButton>
            <UiButton type="submit" form="new-support-session-form" icon="event_available">Xếp lịch</UiButton>
        </template>
    </UiModal>

    <!-- Tick hoàn thành: bắt buộc nhận xét buổi bổ trợ. -->
    <UiModal :show="!!completing" title="Hoàn thành buổi bổ trợ" max-width="lg" @close="completing = null">
        <UiForm v-if="completing" id="complete-support-session-form" :action="route('tasks.support-sessions.complete', completing.id)" method="post" class="space-y-md" @success="completing = null">
            <div class="rounded-xl border border-outline-variant bg-surface-container-low p-3 text-xs">
                <div class="font-bold text-on-surface">{{ completing.student }} · {{ completing.class_name }}</div>
                <div class="mt-0.5 text-on-surface-variant">{{ completing.date }} · {{ completing.time }}</div>
                <div v-if="completing.lesson_content" class="mt-1 whitespace-pre-line text-on-surface-variant">{{ completing.lesson_content }}</div>
            </div>
            <UiTextarea
                id="support_completion_note"
                name="completion_note"
                label="Nhận xét buổi bổ trợ"
                required
                :rows="4"
                placeholder="Học sinh đã nắm được gì, còn yếu phần nào, cần lưu ý gì cho buổi sau"
            />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="completing = null">Hủy</UiButton>
            <UiButton type="submit" form="complete-support-session-form" variant="success" icon="task_alt">Hoàn thành</UiButton>
        </template>
    </UiModal>
</template>
