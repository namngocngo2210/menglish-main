<script setup>
/**
 * Trang lớp: đầu trang gọn (đường dẫn, tên + trạng thái, 1 dòng thông tin, Sửa + ⋯) và 4 tab:
 * Tổng quan · Học viên · Buổi học & điểm danh · Học thuật (dữ liệu riêng của tab chỉ nạp khi mở tab đó —
 * xem ClassManagementController::show). Nút trong từng tab ẩn hẳn khi vai trò không có quyền.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import OverviewTab from './Tabs/OverviewTab.vue';
import StudentsTab from './Tabs/StudentsTab.vue';
import SessionsTab from './Tabs/SessionsTab.vue';
import AcademicTab from './Tabs/AcademicTab.vue';

defineOptions({ layout: (props) => ({ title: props.klass?.name }) });

const props = defineProps({
    klass: { type: Object, required: true },
    tab: { type: String, required: true },
    tabs: { type: Array, required: true },
    seat: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    sessionProgress: { type: Object, required: true },
    needAction: { type: Number, default: 0 },
    // Tổng quan
    upcomingSessions: { type: Array, default: () => [] },
    currentStage: { type: Object, default: null },
    nextBigTest: { type: Object, default: null },
    openIncidents: { type: Number, default: 0 },
    upcomingForeignTeachers: { type: Array, default: () => [] },
    upcomingAssistants: { type: Array, default: () => [] },
    // Học viên
    students: { type: Array, default: () => [] },
    // Buổi học & điểm danh
    sessions: { type: Array, default: () => [] },
    sessionStats: { type: Object, default: () => ({}) },
    canAssignForeign: { type: Boolean, default: false },
    foreignTeacherOptions: { type: Array, default: () => [] },
    attendanceWindowHours: { type: Number, default: 24 },
    recordAny: { type: Boolean, default: false },
    // Học thuật
    syllabus: { type: Object, default: null },
    bigTests: { type: Array, default: () => [] },
    adjustments: { type: Array, default: () => [] },
    adjustmentSlaDays: { type: Number, default: 3 },
    adjustmentMax: { type: Number, default: 10 },
    canRequestAdjustment: { type: Boolean, default: false },
    canApproveAdjustment: { type: Boolean, default: false },
});

const tabUrl = (key) => route('classes.show', { id: props.klass.id, tab: key });
const tabCount = (key) => (key === 'students' ? props.seat.occupied : key === 'sessions' ? props.needAction || null : null);
// Một dòng thông tin dưới tên lớp.
const meta = computed(() => [props.klass.program, props.klass.level, props.klass.branch, props.klass.room ? `Phòng ${props.klass.room}` : null, props.klass.teacher ? `GV ${props.klass.teacher}` : null].filter(Boolean).join(' · '));
</script>

<template>
    <div class="space-y-md">
        <!-- Đầu trang -->
        <header class="flex flex-wrap items-start justify-between gap-sm rounded-xl border border-surface-container-highest bg-surface-container-lowest px-lg py-md">
            <div class="min-w-0 space-y-1">
                <nav aria-label="Đường dẫn" class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    <Link :href="route('classes.index')" class="font-semibold text-primary hover:underline">Danh sách lớp</Link>
                    <span aria-hidden="true">/</span>
                    <span class="font-code">{{ klass.code }}</span>
                </nav>
                <div class="flex flex-wrap items-center gap-sm">
                    <h1 class="font-h2 text-h2 text-on-surface">{{ klass.name }}</h1>
                    <UiBadge :color="klass.status_meta.color" pill>{{ klass.status_meta.label }}</UiBadge>
                </div>
                <p v-if="meta" class="font-body-base text-body-base text-on-surface-variant">{{ meta }}</p>
            </div>
            <div v-if="canManage || canDelete" class="flex items-center gap-xs">
                <UiButton v-if="canManage" variant="secondary" icon="edit" :href="route('classes.edit', klass.id)">Sửa thông tin</UiButton>
                <!-- Xóa lớp là thao tác hiếm và nguy hiểm: để trong menu "⋯", có hộp xác nhận. -->
                <UiDropdown v-if="canDelete" align="right" width="56">
                    <template #trigger>
                        <UiButton variant="secondary" icon="more_horiz" aria-label="Thao tác khác" aria-haspopup="menu" />
                    </template>
                    <template #content>
                        <UiForm :action="route('classes.destroy', klass.id)" method="delete" :confirm="`Xóa lớp ${klass.name}? Lớp sẽ bị ẩn khỏi danh sách.`" confirm-label="Xóa" danger role="menu">
                            <button type="submit" role="menuitem" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-medium text-body-medium text-error transition-colors hover:bg-error/5">
                                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">delete</span>Xóa lớp
                            </button>
                        </UiForm>
                    </template>
                </UiDropdown>
            </div>
        </header>

        <UiAlert v-if="klass.status === 'cancelled'" type="error">Lớp đã hủy. Thông tin bên dưới chỉ để tra cứu.</UiAlert>

        <UiTabs aria-label="Mục của lớp">
            <UiTab v-for="t in tabs" :key="t.key" :href="tabUrl(t.key)" :active="tab === t.key" :count="tabCount(t.key)">{{ t.label }}</UiTab>
        </UiTabs>

        <OverviewTab
            v-if="tab === 'overview'"
            :klass="klass"
            :seat="seat"
            :can-manage="canManage"
            :upcoming-sessions="upcomingSessions"
            :session-progress="sessionProgress"
            :current-stage="currentStage"
            :next-big-test="nextBigTest"
            :open-incidents="openIncidents"
            :upcoming-foreign-teachers="upcomingForeignTeachers"
            :upcoming-assistants="upcomingAssistants"
        />
        <StudentsTab v-else-if="tab === 'students'" :klass="klass" :students="students" :can-manage="canManage" />
        <SessionsTab
            v-else-if="tab === 'sessions'"
            :klass="klass"
            :sessions="sessions"
            :stats="sessionStats"
            :session-progress="sessionProgress"
            :can-manage="canManage"
            :can-assign-foreign="canAssignForeign"
            :foreign-teacher-options="foreignTeacherOptions"
            :window-hours="attendanceWindowHours"
            :record-any="recordAny"
        />
        <AcademicTab
            v-else-if="tab === 'academic' && syllabus"
            :klass="klass"
            :syllabus="syllabus"
            :big-tests="bigTests"
            :adjustments="adjustments"
            :sla-days="adjustmentSlaDays"
            :max-extra="adjustmentMax"
            :can-request="canRequestAdjustment"
            :can-approve="canApproveAdjustment"
        />
    </div>
</template>
