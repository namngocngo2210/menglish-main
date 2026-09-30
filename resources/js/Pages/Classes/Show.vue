<script setup>
/**
 * Trang lớp: một lớp, một trang. Thanh vòng đời cho biết lớp đang ở bước nào; tab con gom mọi việc của lớp
 * (dữ liệu riêng của tab chỉ nạp khi mở tab đó — xem ClassManagementController::show).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import OverviewTab from './Tabs/OverviewTab.vue';
import StudentsTab from './Tabs/StudentsTab.vue';
import ScheduleTab from './Tabs/ScheduleTab.vue';
import AttendanceTab from './Tabs/AttendanceTab.vue';
import AcademicTab from './Tabs/AcademicTab.vue';
import IncidentsTab from './Tabs/IncidentsTab.vue';
import { route } from '@/lib/route';

defineOptions({ layout: (props) => ({ title: props.klass?.name }) });

const props = defineProps({
    klass: { type: Object, required: true },
    tab: { type: String, required: true },
    tabs: { type: Array, required: true },
    seat: { type: Object, required: true },
    steps: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    sessionProgress: { type: Object, required: true },
    nextSession: { type: Object, default: null },
    bigTests: { type: Array, default: () => [] },
    nextBigTest: { type: Object, default: null },
    nextAction: { type: Object, default: null },
    currentStage: { type: Object, default: null },
    openIncidents: { type: Number, default: 0 },
    // Theo tab
    upcomingForeignTeachers: { type: Array, default: () => [] },
    upcomingAssistants: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    upcomingCount: { type: Number, default: 0 },
    foreignTeacherOptions: { type: Array, default: () => [] },
    attendanceRows: { type: Array, default: () => [] },
    attendanceStats: { type: Object, default: () => ({}) },
    canRecordAttendance: { type: Boolean, default: false },
    incidents: { type: Array, default: () => [] },
});

const tabUrl = (key) => route('classes.show', { id: props.klass.id, tab: key });
const tabCount = (key) => (key === 'students' ? props.seat.occupied : key === 'incidents' ? props.openIncidents || null : null);
const stepClass = (state) => ({ done: 'text-tertiary', current: 'bg-primary-container/10 text-primary font-semibold' })[state] ?? 'text-on-surface-subtle';
const stepIcon = (state) => ({ done: 'check_circle', current: 'radio_button_checked' })[state] ?? 'radio_button_unchecked';
// Lớp chờ lịch: nút chính mở thẳng màn Lịch & TKB lớp (lọc sẵn lớp), không qua tab Lịch & buổi học rồi bấm lần nữa.
const scheduleShortcut = computed(() => props.nextAction && props.klass.status === 'pending_schedule' && props.tab !== 'schedule' && props.canManage && can('work_task.view'));
</script>

<template>
    <UiPageHeader :title="klass.name" :back="route('classes.index')" back-label="Danh sách lớp">
        <template #badges>
            <UiBadge :color="klass.status_meta.color" pill>{{ klass.status_meta.label }}</UiBadge>
            <span class="font-code text-body-small text-on-surface-variant">{{ klass.code }}{{ klass.branch ? ' · ' + klass.branch : '' }}</span>
        </template>
        <template #actions>
            <UiButton v-if="canManage" variant="secondary" icon="edit" :href="route('classes.edit', klass.id)">Sửa thông tin</UiButton>
            <UiButton v-if="scheduleShortcut" icon="edit_calendar" :href="route('tasks.schedule-config', { class_id: klass.id })">{{ nextAction.label }}</UiButton>
            <!-- Tình trạng tiếp theo của lớp là nhãn (bấm để mở tab liên quan), không phải nút hành động chính. -->
            <Link
                v-else-if="nextAction && nextAction.tab !== tab"
                :href="tabUrl(nextAction.tab)"
                class="inline-flex items-center gap-xs rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 max-md:min-h-11"
            >
                <UiBadge :color="nextAction.tone" pill>{{ nextAction.label }}</UiBadge>
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">arrow_forward</span>
            </Link>
            <!-- Xóa lớp là thao tác hiếm và nguy hiểm: để trong menu "⋯", có hộp xác nhận, không đặt cạnh nút chính. -->
            <UiDropdown v-if="canDelete" align="right" width="56">
                <template #trigger>
                    <UiButton variant="ghost" icon="more_horiz" aria-label="Thao tác khác" aria-haspopup="menu" />
                </template>
                <template #content>
                    <UiForm :action="route('classes.destroy', klass.id)" method="delete" :confirm="`Xóa lớp ${klass.name}? Lớp sẽ bị ẩn khỏi danh sách.`" confirm-label="Xóa" danger role="menu">
                        <button type="submit" role="menuitem" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-medium text-body-medium text-error transition-colors hover:bg-error/5">
                            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">delete</span>Xóa lớp
                        </button>
                    </UiForm>
                </template>
            </UiDropdown>
        </template>
    </UiPageHeader>

    <div class="space-y-5">
        <!-- Vòng đời lớp -->
        <UiAlert v-if="klass.status === 'cancelled'" type="error">Lớp đã hủy. Thông tin bên dưới chỉ để tra cứu.</UiAlert>
        <ol class="no-scrollbar flex overflow-x-auto rounded-xl border border-surface-container-highest bg-surface-container-lowest" aria-label="Vòng đời lớp" data-lifecycle>
            <li v-for="step in steps" :key="step.key" class="min-w-[140px] flex-1 border-r border-surface-container-highest last:border-r-0">
                <Link
                    :href="tabUrl(step.tab)"
                    :class="['flex h-full flex-col gap-0.5 px-md py-sm transition-colors hover:bg-surface-container-low', stepClass(step.state)]"
                    :aria-current="step.state === 'current' ? 'step' : null"
                    :data-step="step.key"
                    :data-state="step.state"
                >
                    <span class="flex items-center gap-xs text-body-small">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ stepIcon(step.state) }}</span>
                        {{ step.label }}
                    </span>
                    <span v-if="step.hint" class="pl-[22px] font-code text-xs opacity-80">{{ step.hint }}</span>
                </Link>
            </li>
        </ol>

        <!-- Tab con -->
        <UiTabs>
            <UiTab v-for="t in tabs" :key="t.key" :href="tabUrl(t.key)" :active="tab === t.key" :icon="t.icon" :count="tabCount(t.key)">{{ t.label }}</UiTab>
        </UiTabs>

        <OverviewTab
            v-if="tab === 'overview'"
            :klass="klass"
            :seat="seat"
            :can-manage="canManage"
            :next-session="nextSession"
            :session-progress="sessionProgress"
            :current-stage="currentStage"
            :next-big-test="nextBigTest"
            :open-incidents="openIncidents"
            :upcoming-foreign-teachers="upcomingForeignTeachers"
            :upcoming-assistants="upcomingAssistants"
        />
        <StudentsTab v-else-if="tab === 'students'" :klass="klass" :students="students" :can-manage="canManage" />
        <ScheduleTab v-else-if="tab === 'schedule'" :klass="klass" :sessions="sessions" :upcoming-count="upcomingCount" :can-manage="canManage" :foreign-teacher-options="foreignTeacherOptions" />
        <AttendanceTab v-else-if="tab === 'attendance'" :klass="klass" :rows="attendanceRows" :stats="attendanceStats" :can-record-attendance="canRecordAttendance" />
        <AcademicTab v-else-if="tab === 'academic'" :klass="klass" :current-stage="currentStage" :session-progress="sessionProgress" :big-tests="bigTests" />
        <IncidentsTab v-else-if="tab === 'incidents'" :klass="klass" :incidents="incidents" />
    </div>
</template>
