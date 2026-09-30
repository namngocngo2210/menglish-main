<script setup>
/**
 * Trang lớp · Lịch & buổi học: lịch cố định + toàn bộ buổi học của lớp; cấu hình lịch mở màn TKB lọc sẵn lớp này.
 * GVNN không cố định: gán / đổi GVNN theo từng buổi sắp tới (modal "Gán GVNN").
 * Trợ giảng không cố định: cột Trợ giảng lấy từ việc giao cho trợ giảng (theo ca) gắn lớp trong ngày.
 */
import { computed, ref } from 'vue';
import { formatDate } from '@/lib/format';

const props = defineProps({
    klass: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    upcomingCount: { type: Number, default: 0 },
    canManage: { type: Boolean, default: false },
    foreignTeacherOptions: { type: Array, default: () => [] },
});

const weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
const editableSessions = computed(() => props.sessions.filter((s) => s.editable));
const canAssignForeign = computed(() => props.canManage && editableSessions.value.length > 0);
const activeCount = computed(() => props.sessions.filter((s) => !s.cancelled).length);
const gvnnOptions = computed(() => [{ value: 'none', label: 'Không có GVNN (gỡ khỏi các buổi đã chọn)' }, ...props.foreignTeacherOptions]);

const open = ref(false);
const ids = ref([]);
const foreignId = ref('');

/** Mở modal: không truyền buổi → chọn sẵn mọi buổi đổi được; bấm "Đổi / Gán" trên một dòng → chỉ buổi đó, chọn sẵn GVNN hiện tại. */
function assignForeign(sessionIds = [], current = '') {
    ids.value = sessionIds.length ? sessionIds : editableSessions.value.map((s) => s.id);
    foreignId.value = current ? String(current) : '';
    open.value = true;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-bold text-on-surface">Lịch học</h2>
                <p class="text-xs text-on-surface-variant">{{ klass.schedule_text || 'Lớp chưa có lịch học cố định.' }} · {{ activeCount }} buổi, còn {{ upcomingCount }} buổi sắp tới</p>
            </div>
            <div v-if="canManage" class="flex flex-wrap items-center gap-sm">
                <UiButton v-if="canAssignForeign" variant="secondary" icon="translate" @click="assignForeign()">Gán GVNN</UiButton>
                <UiButton v-if="can('work_task.assign')" variant="ghost" icon="support_agent" :href="route('tasks.ta-assign')" modal="4xl">Giao việc trợ giảng</UiButton>
                <UiButton v-if="can('work_task.view')" :variant="klass.status === 'pending_schedule' ? 'primary' : 'secondary'" icon="edit_calendar" :href="route('tasks.schedule-config', { class_id: klass.id })">{{
                    klass.has_schedule_config ? 'Sửa lịch' : 'Cấu hình lịch'
                }}</UiButton>
            </div>
        </div>

        <UiDataTable min-width="760px">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th>Giờ</th>
                        <th>Phòng</th>
                        <th>Giáo viên</th>
                        <th>GVNN</th>
                        <th>Trợ giảng</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sessions" :key="s.id" :class="[s.is_today ? 'bg-primary-container/5' : '', s.cancelled ? 'text-on-surface-subtle' : '']">
                        <td class="font-code font-bold">{{ formatDate(s.date) }}<span v-if="s.is_today" class="ml-1 text-xs font-semibold text-primary">Hôm nay</span></td>
                        <td class="font-code">{{ s.start }}–{{ s.end }}</td>
                        <td>{{ s.room }}</td>
                        <td>{{ s.teacher }}</td>
                        <td>
                            <div class="flex items-center gap-xs">
                                <span>{{ s.foreign_teacher ?? '—' }}</span>
                                <button
                                    v-if="canAssignForeign && s.editable"
                                    type="button"
                                    class="text-xs font-semibold text-primary hover:underline"
                                    :aria-label="`Đổi GVNN buổi ${formatDate(s.date)}`"
                                    @click="assignForeign([s.id], s.foreign_teacher_id)"
                                >
                                    {{ s.foreign_teacher_id ? 'Đổi' : 'Gán' }}
                                </button>
                            </div>
                        </td>
                        <td>{{ s.assistants }}</td>
                        <td>
                            <UiBadge v-if="s.cancelled" color="neutral">{{ s.holiday ? 'Nghỉ lễ' : 'Đã hủy' }}</UiBadge>
                            <UiBadge v-else-if="s.past" color="success">Đã diễn ra</UiBadge>
                            <UiBadge v-else color="info">Sắp diễn ra</UiBadge>
                        </td>
                    </tr>
                    <tr v-if="!sessions.length">
                        <td colspan="7"><UiEmptyState icon="event_busy" title="Lớp chưa có buổi học nào. Cấu hình lịch để sinh buổi học." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <UiModal v-if="canAssignForeign" :show="open" :title="`Gán GVNN cho lớp ${klass.code}`" max-width="2xl" @close="open = false">
        <UiForm id="assign-foreign-form" :action="route('classes.foreign-teacher', klass.id)" method="post" class="space-y-md" @success="open = false" #default="{ errors }">
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
            <UiButton variant="secondary" @click="open = false">Hủy</UiButton>
            <UiButton type="submit" form="assign-foreign-form" icon="save">Lưu GVNN</UiButton>
        </template>
    </UiModal>
</template>
