<script setup>
/**
 * Học bù & bổ trợ: học viên vắng học, điểm mini test / Big Test dưới 7 và học viên ghi trong báo cáo trực lớp tự vào danh
 * sách; Học vụ xếp buổi bổ trợ kèm nội dung + tài liệu buổi đã dạy, chuyển trợ giảng (kiểm tra trùng lịch người dạy / phòng).
 * Người bổ trợ bổ trợ xong thì tick hoàn thành (hoặc điểm danh buổi phụ đạo) kèm nhận xét → bảng công chờ duyệt.
 * Học sinh vắng buổi bổ trợ / Học vụ hủy buổi → dòng quay lại tab "Chờ xếp buổi" với nhãn "Cần xếp lại".
 * Hai tab (Chờ xếp buổi / Buổi bổ trợ) + bộ lọc: học viên, lớp, nguồn, trạng thái, người bổ trợ, khoảng ngày.
 * "Xếp buổi" ở một dòng (?support=) mở sẵn modal với học viên / lớp / nội dung buổi vắng của dòng đó.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import MissedLesson from '@/Components/Tasks/MissedLesson.vue';

defineOptions({ layout: { title: 'Học bù & bổ trợ' } });

const props = defineProps({
    tab: { type: String, default: 'pending' },
    stats: { type: Object, default: () => ({}) },
    pendingSupports: { type: Object, default: null },
    sessions: { type: Object, required: true },
    sources: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    filterClasses: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    studentGroups: { type: Array, default: () => [] },
    teacherGroups: { type: Array, default: () => [] },
    selectedSupport: { type: Object, default: null },
    defaultDate: { type: String, required: true },
    canAssign: { type: Boolean, default: false },
    mine: { type: Boolean, default: false },
});

const page = usePage();
const query = computed(() => new URL(page.url ?? '/', 'http://localhost').searchParams);
// Đổi tab giữ ô tìm + lớp + nguồn (lọc chung hai tab); trạng thái / người bổ trợ / khoảng ngày là lọc riêng từng tab.
const tabUrl = (tab, extra = {}) => {
    const params = { tab };
    for (const key of ['search', 'class_id', 'source', 'mine']) if (query.value.get(key)) params[key] = query.value.get(key);
    return route('tasks.support-sessions', { ...params, ...extra });
};
const sessionsUrl = (extra) => {
    const params = Object.fromEntries(query.value.entries());
    delete params.session_page;
    return route('tasks.support-sessions', { ...params, tab: 'sessions', ...extra });
};

const sourceColors = { attendance: 'warning', mini_test: 'info', big_test: 'error', class_report: 'neutral', teacher_report: 'secondary' };
const statusColors = { scheduled: 'warning', completed: 'success', cancelled: 'neutral' };
const teacherOptions = computed(() => props.teacherGroups.flatMap((g) => g.options));
const pill = (active) => ['rounded-full border px-3 py-1 text-xs font-semibold transition-colors', active ? 'border-primary-container bg-primary-container text-white' : 'border-surface-container-highest text-on-surface-variant hover:border-primary-container hover:text-primary'];

const open = ref(!!props.selectedSupport);
// Buổi đang tick hoàn thành (modal nhập nhận xét) / đang hủy (modal lý do).
const completing = ref(null);
const cancelling = ref(null);
const showPending = computed(() => props.canAssign && props.tab === 'pending');
const emptySessions = computed(() => (props.canAssign && !props.mine ? 'Chưa có buổi bổ trợ nào khớp bộ lọc.' : 'Chưa có buổi bổ trợ nào được giao cho bạn.'));
</script>

<template>
    <!-- Người được giao (giáo viên / trợ giảng, hoặc mở từ "Buổi bổ trợ của tôi") xem danh sách của mình. -->
    <UiPageHeader
        :title="canAssign && !mine ? 'Học bù & bổ trợ' : 'Buổi bổ trợ của tôi'"
        :description="canAssign
            ? 'Học sinh nghỉ học, điểm mini test / Big Test dưới 7 tự vào danh sách. Học vụ xếp buổi kèm nội dung và tài liệu buổi đã dạy; trợ giảng bổ trợ xong thì tick hoàn thành (hoặc điểm danh buổi phụ đạo) và nhận xét.'
            : 'Buổi bổ trợ được giao cho bạn: mở nội dung và tài liệu buổi học sinh vắng để chuẩn bị, bổ trợ xong thì tick hoàn thành và nhận xét.'"
    >
        <template v-if="canAssign" #actions>
            <UiButton icon="event" @click="open = true">Xếp buổi bổ trợ</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-lg">
        <!-- Số liệu nhanh: bấm để mở đúng danh sách. -->
        <div :class="['grid grid-cols-2 gap-sm md:gap-md', canAssign ? 'lg:grid-cols-4' : 'lg:grid-cols-3']">
            <template v-if="canAssign">
                <Link :href="tabUrl('pending')" class="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container">
                    <UiStatCard label="Chờ xếp buổi" :value="stats.pending" icon="pending_actions" tone="warning" />
                </Link>
                <Link :href="tabUrl('pending')" class="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container">
                    <UiStatCard label="Cần xếp lại" :value="stats.reschedule" icon="event_repeat" tone="error" hint="Buổi trước bị hủy / HS vắng" />
                </Link>
            </template>
            <Link :href="tabUrl('sessions', { status: 'scheduled' })" class="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container">
                <UiStatCard label="Đã xếp lịch" :value="stats.scheduled" icon="event_available" tone="primary" :hint="stats.today ? `${stats.today} buổi hôm nay` : 'Không có buổi hôm nay'" />
            </Link>
            <Link :href="tabUrl('sessions', { status: 'completed' })" class="block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container">
                <UiStatCard label="Hoàn thành tháng này" :value="stats.completed_month" icon="task_alt" tone="success" />
            </Link>
            <div v-if="!canAssign" class="col-span-2 flex items-center gap-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md text-xs text-on-surface-variant lg:col-span-1">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">info</span>
                Điểm danh buổi phụ đạo "Có mặt" kèm nhận xét cũng tính là đã bổ trợ xong.
            </div>
        </div>

        <UiTabs v-if="canAssign">
            <UiTab :href="tabUrl('pending')" :active="tab === 'pending'" icon="pending_actions" :count="pendingSupports?.total ?? 0">Chờ xếp buổi</UiTab>
            <UiTab :href="tabUrl('sessions')" :active="tab === 'sessions'" icon="event_note" :count="sessions.total">Buổi bổ trợ</UiTab>
        </UiTabs>

        <UiFilterBar :key="tab" search="search" placeholder="Tìm học viên (tên, mã)…" :keep="['tab', 'mine']" class="!mb-0">
            <template v-if="!showPending && canAssign" #quick>
                <div class="flex flex-wrap items-center gap-1.5">
                    <Link :href="sessionsUrl({ mine: undefined })" :class="pill(!mine)">Tất cả</Link>
                    <Link :href="sessionsUrl({ mine: 1 })" :class="pill(mine)">Giao cho tôi</Link>
                </div>
            </template>
            <UiSelect name="class_id" label="Lớp" :options="filterClasses" placeholder="Tất cả lớp" />
            <UiSelect name="source" label="Nguồn" :options="sources" placeholder="Tất cả nguồn" />
            <template v-if="!showPending">
                <UiSelect name="status" label="Trạng thái" :options="statuses" placeholder="Mọi trạng thái" />
                <UiSelect v-if="canAssign" name="teacher_id" label="Người bổ trợ" :options="teacherOptions" placeholder="Mọi người bổ trợ" />
            </template>
            <UiDateRange :label="showPending ? 'Ngày ghi nhận' : 'Ngày bổ trợ'" />
        </UiFilterBar>

        <!-- Tab 1: danh sách chờ xếp buổi (Học vụ). -->
        <UiDataTable v-if="showPending && pendingSupports">
            <ul class="divide-y divide-surface-container md:hidden">
                <li v-for="item in pendingSupports.data" :key="'m' + item.id" class="space-y-1.5 p-md text-sm">
                    <div class="flex items-start justify-between gap-sm">
                        <div class="min-w-0">
                            <p class="font-bold text-on-surface">{{ item.student }}</p>
                            <p class="text-xs text-on-surface-subtle">{{ item.class_name }} · {{ item.date }}</p>
                        </div>
                        <UiBadge :color="sourceColors[item.source] ?? 'neutral'">{{ item.source_label }}</UiBadge>
                    </div>
                    <p v-if="item.last_cancelled" class="rounded-lg bg-error-container px-2 py-1 text-xs text-on-error-container">
                        <strong>Cần xếp lại</strong> · buổi {{ item.last_cancelled.date }}: {{ item.last_cancelled.reason }}
                    </p>
                    <p class="text-on-surface-variant">{{ item.reason }}</p>
                    <p v-if="item.action_plan" class="text-xs text-on-surface-subtle">KH: {{ item.action_plan }}</p>
                    <MissedLesson v-if="item.missed" :missed="item.missed" />
                    <UiButton size="sm" icon="event" class="w-full" :href="route('tasks.support-sessions', { support: item.id })">{{ item.last_cancelled ? 'Xếp lại' : 'Xếp buổi' }}</UiButton>
                </li>
                <li v-if="!pendingSupports.data.length" class="p-md"><UiEmptyState title="Không có học viên nào đang chờ xếp buổi bổ trợ." /></li>
            </ul>
            <table class="hidden md:table">
                <thead>
                    <tr>
                        <th class="w-56">Học viên</th>
                        <th>Nguồn &amp; lý do</th>
                        <th class="w-32 text-center">Ghi nhận</th>
                        <th class="w-32"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in pendingSupports.data" :key="item.id" class="align-top">
                        <td class="font-bold">
                            {{ item.student }}<small class="block font-normal text-on-surface-subtle">{{ item.class_name }}</small>
                        </td>
                        <td class="text-on-surface-variant">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <UiBadge :color="sourceColors[item.source] ?? 'neutral'">{{ item.source_label }}</UiBadge>
                                <UiBadge v-if="item.last_cancelled" color="error">Cần xếp lại</UiBadge>
                            </div>
                            <span class="mt-1 block">{{ item.reason }}</span>
                            <small v-if="item.action_plan" class="block text-on-surface-subtle">KH: {{ item.action_plan }}</small>
                            <small v-if="item.last_cancelled" class="mt-0.5 block text-error">
                                Buổi {{ item.last_cancelled.date }}<template v-if="item.last_cancelled.teacher"> ({{ item.last_cancelled.teacher }})</template> đã hủy: {{ item.last_cancelled.reason }}
                            </small>
                            <MissedLesson v-if="item.missed" :missed="item.missed" />
                        </td>
                        <td class="text-center text-on-surface-variant">{{ item.date }}</td>
                        <td class="text-right">
                            <UiButton size="sm" icon="event" :href="route('tasks.support-sessions', { support: item.id })">{{ item.last_cancelled ? 'Xếp lại' : 'Xếp buổi' }}</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!pendingSupports.data.length">
                        <td colspan="4"><UiEmptyState title="Không có học viên nào đang chờ xếp buổi bổ trợ." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="pendingSupports" :options="[]" unit="học viên" page-name="list_page" /></template>
        </UiDataTable>

        <!-- Tab 2: buổi bổ trợ đã xếp (người bổ trợ chỉ có tab này). -->
        <UiDataTable v-else>
            <!-- Điện thoại: thẻ dọc để nút "Đã bổ trợ xong" luôn thấy, không phải cuộn ngang bảng. -->
            <ul class="divide-y divide-surface-container md:hidden">
                <li v-for="s in sessions.data" :key="'m' + s.id" class="space-y-1.5 p-md text-sm">
                    <div class="flex items-start justify-between gap-sm">
                        <div class="min-w-0">
                            <p class="font-bold text-on-surface">{{ s.student }}</p>
                            <p class="text-xs text-on-surface-subtle">{{ s.class_name }}</p>
                        </div>
                        <UiBadge :color="statusColors[s.status] ?? 'neutral'">{{ s.status_label }}</UiBadge>
                    </div>
                    <p class="flex flex-wrap items-center gap-1 text-xs text-on-surface-variant">
                        <UiBadge v-if="s.is_today && s.status === 'scheduled'" color="info">Hôm nay</UiBadge>
                        {{ s.date }} · {{ s.time }}<template v-if="s.room"> · P. {{ s.room }}</template> · {{ s.teacher }}
                    </p>
                    <p class="text-on-surface-variant">{{ s.reason }}</p>
                    <p v-if="s.lesson_content" class="whitespace-pre-line rounded-lg bg-primary-container/5 p-2 text-xs text-on-surface-variant"><strong class="text-on-surface">Học vụ dặn:</strong> {{ s.lesson_content }}</p>
                    <MissedLesson v-if="s.missed" :missed="s.missed" :open="s.is_mine && s.status === 'scheduled'" />
                    <p v-if="s.completion_note" class="whitespace-pre-line text-xs text-on-surface-variant">Nhận xét: {{ s.completion_note }}</p>
                    <p v-if="s.cancel_reason" class="text-xs text-error">Lý do hủy: {{ s.cancel_reason }}</p>
                    <div v-if="s.can_complete || s.can_cancel" class="flex gap-sm pt-1">
                        <UiButton v-if="s.can_complete" variant="success" size="sm" icon="check" class="flex-1" @click="completing = s">Đã bổ trợ xong</UiButton>
                        <UiButton v-if="s.can_cancel" variant="secondary" size="sm" icon="event_busy" @click="cancelling = s">Hủy</UiButton>
                    </div>
                </li>
                <li v-if="!sessions.data.length" class="p-md"><UiEmptyState :title="emptySessions" /></li>
            </ul>
            <table class="hidden md:table">
                <thead>
                    <tr>
                        <th class="w-52">Học viên</th>
                        <th>Nội dung bổ trợ</th>
                        <th class="w-36 text-center">Lịch</th>
                        <th class="w-36 text-center">Người bổ trợ</th>
                        <th class="w-64">Trạng thái / nhận xét</th>
                        <th class="w-40"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sessions.data" :key="s.id" class="align-top">
                        <td class="font-bold">
                            {{ s.student }}<small class="block font-normal text-on-surface-subtle">{{ s.class_name }}</small>
                        </td>
                        <td class="text-on-surface-variant">
                            <UiBadge v-if="s.source_label" :color="sourceColors[s.source] ?? 'neutral'">{{ s.source_label }}</UiBadge>
                            <span class="mt-1 block">{{ s.reason }}</span>
                            <small v-if="s.lesson_content" class="mt-1 block whitespace-pre-line text-xs"><strong class="text-on-surface">Học vụ dặn:</strong> {{ s.lesson_content }}</small>
                            <MissedLesson v-if="s.missed" :missed="s.missed" :open="s.is_mine && s.status === 'scheduled'" />
                        </td>
                        <td class="text-center">
                            <UiBadge v-if="s.is_today && s.status === 'scheduled'" color="info" class="mb-0.5">Hôm nay</UiBadge>
                            <span class="block">{{ s.date }}</span>{{ s.time }}<template v-if="s.room"><br /><small class="text-on-surface-subtle">P. {{ s.room }}</small></template>
                        </td>
                        <td class="text-center">{{ s.teacher }}<small v-if="s.is_mine" class="block text-on-surface-subtle">(bạn)</small></td>
                        <td class="text-on-surface-variant">
                            <UiBadge :color="statusColors[s.status] ?? 'neutral'">{{ s.status_label }}</UiBadge>
                            <small v-if="s.completed_at" class="ml-1 text-on-surface-subtle">{{ s.completed_at }}</small>
                            <small v-if="s.cancelled_at" class="ml-1 text-on-surface-subtle">{{ s.cancelled_at }}</small>
                            <span v-if="s.completion_note" class="mt-0.5 block whitespace-pre-line text-xs">Nhận xét: {{ s.completion_note }}</span>
                            <span v-if="s.cancel_reason" class="mt-0.5 block text-xs text-error">Lý do hủy: {{ s.cancel_reason }}</span>
                        </td>
                        <td>
                            <div class="flex flex-col items-end gap-1.5">
                                <UiButton v-if="s.can_complete" variant="success" size="sm" icon="check" @click="completing = s">Đã bổ trợ xong</UiButton>
                                <UiButton v-if="s.can_cancel" variant="ghost" size="sm" icon="event_busy" @click="cancelling = s">Hủy buổi</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!sessions.data.length">
                        <td colspan="6"><UiEmptyState :title="emptySessions" /></td>
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
                    <MissedLesson v-if="selectedSupport.missed" :missed="selectedSupport.missed" />
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
                label="Dặn người bổ trợ"
                :rows="4"
                :value="selectedSupport?.missed?.content"
                hint="Lấy sẵn từ giáo trình, báo cáo trực lớp và BTVN của buổi học sinh vắng; người bổ trợ xem được cả nội dung và tài liệu buổi đó."
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

    <!-- Hủy buổi: dòng gốc quay lại "Chờ xếp buổi" (nhãn Cần xếp lại), người bổ trợ được báo. -->
    <UiModal v-if="canAssign" :show="!!cancelling" title="Hủy buổi bổ trợ" max-width="lg" @close="cancelling = null">
        <UiForm v-if="cancelling" id="cancel-support-session-form" :action="route('tasks.support-sessions.cancel', cancelling.id)" method="post" class="space-y-md" @success="cancelling = null">
            <div class="rounded-xl border border-outline-variant bg-surface-container-low p-3 text-xs">
                <div class="font-bold text-on-surface">{{ cancelling.student }} · {{ cancelling.class_name }}</div>
                <div class="mt-0.5 text-on-surface-variant">{{ cancelling.date }} · {{ cancelling.time }} · {{ cancelling.teacher }}</div>
            </div>
            <UiTextarea id="support_cancel_reason" name="cancel_reason" label="Lý do hủy" required :rows="3" placeholder="Ví dụ: phụ huynh xin đổi lịch, trợ giảng bận đột xuất…" />
            <p class="text-xs text-on-surface-variant">Học viên quay lại danh sách chờ xếp buổi để xếp lại; người bổ trợ nhận thông báo.</p>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="cancelling = null">Đóng</UiButton>
            <UiButton type="submit" form="cancel-support-session-form" variant="danger" icon="event_busy">Hủy buổi</UiButton>
        </template>
    </UiModal>
</template>
