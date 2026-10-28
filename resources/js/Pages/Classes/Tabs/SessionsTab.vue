<script setup>
/**
 * Trang lớp · Buổi học & điểm danh: lịch học + điểm danh + báo cáo buổi trên cùng một dòng cho mỗi buổi.
 *  - Dải tóm tắt: lịch cố định, số buổi đã học, điểm danh / báo cáo còn thiếu; nút của người quản lý lớp (Gán GVNN, Sửa lịch).
 *  - Lọc nhanh: Tất cả · Cần xử lý · Đã diễn ra · Sắp tới · Nghỉ / hủy. "Tất cả" gọn quanh hôm nay, bấm để hiện thêm.
 *  - Nút trong ô theo vai trò (backend tính sẵn): Điểm danh mở ngăn bên phải; Nộp báo cáo khi đã điểm danh; Nộp trực lớp (trợ giảng).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/format';
import { groupSchedule } from '@/lib/schedule';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';

const props = defineProps({
    klass: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    sessionProgress: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    canAssignForeign: { type: Boolean, default: false },
    foreignTeacherOptions: { type: Array, default: () => [] },
    windowHours: { type: Number, default: 24 },
    recordAny: { type: Boolean, default: false },
});

const weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
const schedule = computed(() => groupSchedule(props.klass.schedule_text).map((g) => [g.days, g.time].filter(Boolean).join(' · ')).join('; '));
const progress = computed(() => (props.sessionProgress.total ? Math.round((props.sessionProgress.done * 100) / props.sessionProgress.total) : 0));
const missingAttendance = computed(() => props.stats.past - props.stats.attended);
const missingReports = computed(() => props.stats.past - props.stats.reported);

// Buổi "cần xử lý" với người xem: có nút việc chưa làm (điểm danh / nộp báo cáo / nộp trực lớp) hoặc đã qua mà chưa điểm danh.
const needsAction = (s) =>
    !s.cancelled && ((s.past && s.state.key === 'missing') || (s.can_submit_report && !s.teacher_report) || s.can_submit_duty || (s.attendance_action === 'open' && s.state.key !== 'done' && s.is_today));
const filters = computed(() => [
    { key: 'all', label: 'Tất cả', count: props.sessions.length },
    { key: 'todo', label: 'Cần xử lý', count: props.sessions.filter(needsAction).length, alert: true },
    { key: 'past', label: 'Đã diễn ra', count: props.sessions.filter((s) => s.past && !s.cancelled).length },
    { key: 'upcoming', label: 'Sắp tới', count: props.sessions.filter((s) => !s.past && !s.cancelled).length },
    { key: 'cancelled', label: 'Nghỉ / hủy', count: props.sessions.filter((s) => s.cancelled).length },
]);
const filter = ref('all');
const pick = (key) => {
    filter.value = key;
    olderShown.value = false;
    moreShown.value = false;
};

// "Tất cả": 4 buổi gần nhất đã qua + 3 buổi sắp tới, còn lại bấm để hiện.
const OLDER = 4;
const AHEAD = 3;
const olderShown = ref(false);
const moreShown = ref(false);
const firstUpcoming = computed(() => {
    const i = props.sessions.findIndex((s) => !s.past);
    return i === -1 ? props.sessions.length : i;
});
const hiddenOlder = computed(() => (filter.value === 'all' && !olderShown.value ? Math.max(0, firstUpcoming.value - OLDER) : 0));
const hiddenAhead = computed(() => (filter.value === 'all' && !moreShown.value ? Math.max(0, props.sessions.length - firstUpcoming.value - AHEAD) : 0));
const rows = computed(() => {
    const list = {
        all: props.sessions,
        todo: props.sessions.filter(needsAction),
        past: props.sessions.filter((s) => s.past && !s.cancelled),
        upcoming: props.sessions.filter((s) => !s.past && !s.cancelled),
        cancelled: props.sessions.filter((s) => s.cancelled),
    }[filter.value];
    return filter.value === 'all' ? list.slice(hiddenOlder.value, list.length - hiddenAhead.value) : list;
});
// Vạch "Hôm nay" trước buổi sắp tới đầu tiên (chỉ khi danh sách có cả buổi đã qua lẫn sắp tới).
const dividerAt = computed(() => {
    const i = rows.value.findIndex((s) => !s.past);
    return i > 0 ? rows.value[i].id : null;
});

const attendanceLabel = (s) => {
    if (s.state.key !== 'done') return s.state.label;
    return s.absent_count ? `${s.present_count}/${s.attendances_count} HV · ${s.absent_count} vắng` : `Đủ · ${s.attendances_count}/${s.attendances_count} HV`;
};
const openAttendance = (s) => openRemoteModal(route('teacher.attendance', { classId: props.klass.id, session: s.id }), { size: 'drawer' });
const reportUrl = (s) => route('classes.session-report', { id: props.klass.id, session: s.id });

// Gán / đổi GVNN theo buổi (giữ nguyên modal của tab Lịch cũ).
const editableSessions = computed(() => props.sessions.filter((s) => s.editable));
const gvnnOptions = computed(() => [{ value: 'none', label: 'Không có GVNN (gỡ khỏi các buổi đã chọn)' }, ...props.foreignTeacherOptions]);
const gvnnOpen = ref(false);
const ids = ref([]);
const foreignId = ref('');
function assignForeign(sessionIds = [], current = '') {
    ids.value = sessionIds.length ? sessionIds : editableSessions.value.map((s) => s.id);
    foreignId.value = current ? String(current) : '';
    gvnnOpen.value = true;
}

const segBtn = (active) =>
    ['inline-flex h-9 items-center gap-xs rounded-lg px-sm font-body-small text-body-small font-semibold transition-colors', active ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant hover:text-on-surface'];
const cellBtn = 'inline-flex h-9 items-center rounded-lg px-sm font-body-small text-body-small font-semibold';
</script>

<template>
    <div class="space-y-md">
        <!-- Tóm tắt lịch + việc tồn -->
        <div class="flex flex-wrap items-center gap-x-lg gap-y-sm rounded-xl border border-surface-container-highest bg-surface-container-lowest px-lg py-md">
            <div>
                <div class="text-xs text-on-surface-variant">Lịch cố định</div>
                <div class="font-semibold text-on-surface">{{ schedule || 'Chưa xếp lịch' }}{{ klass.room ? ` · Phòng ${klass.room}` : '' }}</div>
            </div>
            <div class="hidden h-10 w-px bg-surface-container sm:block"></div>
            <div class="min-w-[200px]">
                <div class="text-xs text-on-surface-variant">
                    Đã học <b class="text-on-surface">{{ sessionProgress.done }}</b> / {{ sessionProgress.total }} buổi · còn {{ sessionProgress.total - sessionProgress.done }}
                </div>
                <div class="mt-1 h-1.5 w-[200px] overflow-hidden rounded-full bg-surface-container"><div class="h-full rounded-full bg-secondary" :style="{ width: progress + '%' }"></div></div>
            </div>
            <div class="hidden h-10 w-px bg-surface-container sm:block"></div>
            <div>
                <div class="text-xs text-on-surface-variant">Điểm danh</div>
                <div class="font-semibold">
                    {{ stats.attended }}/{{ stats.past }} buổi<span v-if="missingAttendance > 0" class="text-error"> · {{ missingAttendance }} chưa</span>
                </div>
            </div>
            <div>
                <div class="text-xs text-on-surface-variant">Báo cáo buổi GV</div>
                <div class="font-semibold">
                    {{ stats.reported }}/{{ stats.past }} buổi<span v-if="missingReports > 0" class="text-warning"> · {{ missingReports }} chưa nộp</span>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap gap-xs">
                <UiButton v-if="can('homework.grade') && !canManage" variant="secondary" icon="grading" :href="route('teacher.submissions', { classId: klass.id })">Chấm bài nộp</UiButton>
                <UiButton v-if="canAssignForeign" variant="secondary" icon="translate" @click="assignForeign()">Gán GVNN</UiButton>
                <UiButton v-if="canManage && can('work_task.view')" :variant="klass.status === 'pending_schedule' ? 'primary' : 'secondary'" icon="edit_calendar" :href="route('tasks.schedule-config', { class_id: klass.id })">{{
                    klass.has_schedule_config ? 'Sửa lịch' : 'Cấu hình lịch'
                }}</UiButton>
            </div>
        </div>

        <!-- Bảng buổi học -->
        <div class="overflow-hidden rounded-xl border border-surface-container-highest bg-surface-container-lowest">
            <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container-highest px-md py-sm">
                <div role="tablist" aria-label="Lọc buổi" class="inline-flex flex-wrap gap-0.5 rounded-xl bg-surface-container-low p-[3px]">
                    <button v-for="f in filters" :key="f.key" type="button" role="tab" :aria-selected="filter === f.key ? 'true' : 'false'" :class="segBtn(filter === f.key)" @click="pick(f.key)">
                        {{ f.label }}
                        <span :class="['rounded-full px-1.5 font-code text-xs leading-[18px]', f.alert && f.count ? 'bg-error-container text-on-error-container' : 'bg-surface-container-high text-on-surface-variant']">{{ f.count }}</span>
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-sm">
                    <span v-if="!recordAny && !canManage" class="text-xs text-on-surface-variant">Bạn tự điểm danh trong {{ windowHours }}h từ giờ học. Quá hạn thì báo Học vụ điểm danh bù.</span>
                    <UiButton v-if="canManage && can('work_task.assign')" variant="ghost" size="sm" icon="support_agent" :href="route('tasks.ta-assign')" modal="4xl">Giao việc trợ giảng</UiButton>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse text-left">
                    <thead>
                        <tr class="bg-surface-container-low text-xs font-semibold text-on-surface-variant">
                            <th class="px-md py-sm">Buổi</th>
                            <th class="px-sm py-sm">Người dạy</th>
                            <th class="px-sm py-sm">Điểm danh</th>
                            <th class="px-sm py-sm">Báo cáo buổi (GV)</th>
                            <th class="px-sm py-sm">Báo cáo trực lớp</th>
                            <th v-if="canAssignForeign" class="px-md py-sm text-right">GVNN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="hiddenOlder">
                            <td colspan="6" class="border-t border-surface-container px-md py-xs">
                                <button type="button" class="font-body-small text-body-small font-semibold text-primary hover:underline" @click="olderShown = true">Hiện {{ hiddenOlder }} buổi cũ hơn</button>
                            </td>
                        </tr>
                        <template v-for="s in rows" :key="s.id">
                            <tr v-if="s.id === dividerAt">
                                <td colspan="6" class="border-y border-primary-fixed bg-primary-light px-md py-1 text-xs font-bold text-primary">Hôm nay · {{ formatDate(new Date(), 'l, d/m') }} — các buổi sắp tới bên dưới</td>
                            </tr>
                            <tr :class="['border-t border-surface-container align-middle', s.cancelled ? 'text-on-surface-subtle' : '', needsAction(s) ? 'bg-error/[0.03]' : '']" :data-session="s.id">
                                <td class="whitespace-nowrap px-md py-sm">
                                    <div class="flex items-center gap-sm">
                                        <div class="w-11 text-center">
                                            <div class="text-xs font-semibold text-on-surface-variant">{{ weekdays[s.weekday] }}</div>
                                            <div class="font-bold">{{ formatDate(s.date, 'd/m') }}</div>
                                        </div>
                                        <div>
                                            <div class="font-semibold">
                                                {{ s.no ? `Buổi ${s.no}` : formatDate(s.date) }}
                                                <UiBadge v-if="s.type === 'makeup'" color="warning" class="ml-xs">Học bù</UiBadge>
                                            </div>
                                            <div class="text-xs text-on-surface-variant">{{ s.start }}–{{ s.end }}{{ s.room ? ` · P.${s.room}` : '' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-sm py-sm">
                                    <div>{{ s.teacher ?? '—' }}</div>
                                    <div class="text-xs text-on-surface-variant">
                                        <template v-if="s.foreign_teacher">GVNN: {{ s.foreign_teacher }}</template>
                                        <template v-else-if="s.assistants">TG: {{ s.assistants }}</template>
                                    </div>
                                </td>
                                <!-- Điểm danh -->
                                <td class="whitespace-nowrap px-sm py-sm">
                                    <UiBadge v-if="s.cancelled" color="neutral">{{ s.holiday ? 'Nghỉ lễ' : 'Đã hủy' }}</UiBadge>
                                    <template v-else-if="s.state.key === 'done'">
                                        <button v-if="s.attendance_action === 'open'" type="button" class="rounded-full hover:opacity-80" :aria-label="`Sửa điểm danh buổi ${formatDate(s.date)}`" @click="openAttendance(s)">
                                            <UiBadge color="success">{{ attendanceLabel(s) }}</UiBadge>
                                        </button>
                                        <UiBadge v-else color="success">{{ attendanceLabel(s) }}</UiBadge>
                                    </template>
                                    <UiButton v-else-if="s.attendance_action === 'open'" size="sm" icon="fact_check" @click="openAttendance(s)">Điểm danh</UiButton>
                                    <UiBadge v-else-if="s.attendance_action === 'closed'" color="error">Quá {{ windowHours }}h · báo Học vụ</UiBadge>
                                    <span v-else-if="s.attendance_action === 'before'" class="text-xs text-on-surface-subtle">Mở từ {{ s.opens_at }}</span>
                                    <UiBadge v-else-if="s.past" color="error">Chưa điểm danh</UiBadge>
                                    <span v-else class="text-xs text-on-surface-subtle">—</span>
                                </td>
                                <!-- Báo cáo buổi của GV -->
                                <td class="whitespace-nowrap px-sm py-sm">
                                    <span v-if="s.cancelled || !s.past" class="text-xs text-on-surface-subtle">—</span>
                                    <Link v-else-if="s.teacher_report" :href="reportUrl(s)" class="hover:opacity-80">
                                        <UiBadge :color="s.teacher_report.late ? 'warning' : 'success'">{{ s.teacher_report.late ? 'Đã nộp · trễ' : 'Đã nộp' }}</UiBadge>
                                    </Link>
                                    <template v-else-if="s.can_submit_report">
                                        <Link v-if="s.state.key === 'done'" :href="reportUrl(s)" :class="[cellBtn, 'bg-primary-container text-white hover:bg-primary']">Nộp báo cáo</Link>
                                        <span v-else class="text-xs text-on-surface-subtle">Cần điểm danh trước</span>
                                    </template>
                                    <Link v-else :href="reportUrl(s)" class="text-xs text-on-surface-subtle hover:underline">Chưa nộp</Link>
                                </td>
                                <!-- Báo cáo trực lớp -->
                                <td class="whitespace-nowrap px-sm py-sm">
                                    <template v-if="s.duty_report">
                                        <UiBadge :color="s.duty_report.color">{{ s.duty_report.label }}</UiBadge>
                                        <span v-if="s.duty_report.reporter" class="ml-xs text-xs text-on-surface-variant">{{ s.duty_report.reporter }}</span>
                                    </template>
                                    <Link
                                        v-else-if="s.can_submit_duty && can('work_task.view')"
                                        :href="route('tasks.class-reports.create', { class_id: klass.id })"
                                        :class="[cellBtn, 'bg-primary-container text-white hover:bg-primary']"
                                        >Nộp trực lớp</Link
                                    >
                                    <span v-else-if="s.past && !s.cancelled" class="text-xs text-on-surface-subtle">Chưa nộp</span>
                                    <span v-else class="text-xs text-on-surface-subtle">—</span>
                                </td>
                                <td v-if="canAssignForeign" class="whitespace-nowrap px-md py-sm text-right">
                                    <button v-if="s.editable" type="button" class="font-body-small text-body-small font-semibold text-primary hover:underline" @click="assignForeign([s.id], s.foreign_teacher_id)">
                                        {{ s.foreign_teacher_id ? 'Đổi GVNN' : 'Gán GVNN' }}
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="hiddenAhead">
                            <td colspan="6" class="border-t border-surface-container px-md py-sm">
                                <button type="button" class="font-body-small text-body-small font-semibold text-primary hover:underline" @click="moreShown = true">Hiện thêm {{ hiddenAhead }} buổi sắp tới</button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="6">
                                <UiEmptyState
                                    :icon="filter === 'todo' ? 'task_alt' : 'event_busy'"
                                    :title="filter === 'todo' ? 'Không có buổi nào cần xử lý.' : sessions.length ? 'Không có buổi nào.' : 'Lớp chưa có buổi học nào. Cấu hình lịch để sinh buổi học.'"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <UiModal v-if="canAssignForeign" :show="gvnnOpen" :title="`Gán GVNN cho lớp ${klass.code}`" max-width="2xl" @close="gvnnOpen = false">
        <UiForm id="assign-foreign-form" :action="route('classes.foreign-teacher', klass.id)" method="post" class="space-y-md" @success="gvnnOpen = false" #default="{ errors }">
            <p class="text-xs text-on-surface-variant">GVNN không cố định theo lớp: chọn GVNN rồi tick các buổi áp dụng. Chỉ đổi được buổi sắp tới chưa điểm danh; giáo viên chính giữ nguyên.</p>
            <UiSelect id="assign-foreign-gvnn" v-model="foreignId" name="giao_vien_nn" label="Giáo viên nước ngoài" placeholder="-- Chọn GVNN --" required :options="gvnnOptions" />
            <div>
                <div class="mb-xs flex items-center justify-between">
                    <span class="text-xs font-semibold text-on-surface">Buổi áp dụng <span class="font-normal text-on-surface-variant">({{ ids.length }} buổi)</span></span>
                    <span class="flex gap-md text-xs font-semibold">
                        <button type="button" class="text-primary hover:underline" @click="ids = editableSessions.map((s) => s.id)">Chọn tất cả</button>
                        <button type="button" class="text-on-surface-variant hover:underline" @click="ids = []">Bỏ chọn</button>
                    </span>
                </div>
                <div class="max-h-72 space-y-1 overflow-y-auto rounded-lg border border-outline-variant p-sm">
                    <label v-for="s in editableSessions" :key="s.id" class="flex cursor-pointer items-center gap-sm rounded px-xs py-1 text-xs hover:bg-surface-container-low">
                        <input v-model="ids" type="checkbox" name="session_ids[]" :value="s.id" class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40" />
                        <span class="font-code font-bold">{{ weekdays[s.weekday] }} {{ formatDate(s.date) }}</span>
                        <span class="font-code text-on-surface-variant">{{ s.start }}–{{ s.end }}</span>
                        <span class="ml-auto text-on-surface-variant">{{ s.foreign_teacher ? 'GVNN: ' + s.foreign_teacher : 'Chưa có GVNN' }}</span>
                    </label>
                </div>
                <p v-if="errors.session_ids" class="mt-xs text-xs text-error">{{ errors.session_ids }}</p>
            </div>
            <label class="flex items-center gap-sm text-xs">
                <input type="checkbox" name="set_default" value="1" class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40" />
                Đặt làm GVNN mặc định của lớp (buổi sinh thêm sau này sẽ nhận GVNN này)
            </label>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="gvnnOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="assign-foreign-form" icon="save">Lưu GVNN</UiButton>
        </template>
    </UiModal>
</template>
