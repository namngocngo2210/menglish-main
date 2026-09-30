<script setup>
/**
 * Lịch & TKB lớp: cấu hình lịch lặp của lớp (tạo mới hoặc sửa lịch đã có — buổi quá khứ / đã có dữ liệu được giữ nguyên)
 * + màn riêng "Báo cáo phòng / nhân sự" 7 ngày theo buổi học thật (?view=report).
 * Chọn lớp đã có TKB → điền sẵn năm học, ngày, 2 ca học của lớp đó.
 */
import { computed, reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { can } from '@/lib/can';

defineOptions({ layout: (props) => ({ title: props.view === 'report' ? 'Báo cáo phòng / nhân sự' : 'Lịch & TKB lớp' }) });

const props = defineProps({
    view: { type: String, default: 'config' },
    canSchedule: { type: Boolean, default: false },
    newClasses: { type: Array, default: () => [] },
    scheduledClasses: { type: Array, default: () => [] },
    academicYears: { type: Array, default: () => [] },
    scheduleData: { type: [Object, Array], default: () => ({}) },
    initial: { type: Object, required: true },
    holidaySessions: { type: Array, default: () => [] },
    classSearch: { type: String, default: '' },
    listClasses: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    reportBranchId: { type: Number, default: null },
    reportStart: { type: String, default: null },
    report: { type: Array, default: () => [] },
    classCountChange: { type: Object, default: () => ({}) },
});

const days = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'].map((d) => ({ value: d, label: d }));
const statusBadges = {
    active: ['success', 'Đang học'],
    upcoming: ['info', 'Sắp khai giảng'],
    pending_schedule: ['warning', 'Chưa xếp lịch'],
    cancelled: ['error', 'Đã hủy'],
};

const form = reactive({ ...props.initial });
const selectedData = computed(() => props.scheduleData[form.class_id] ?? null);
const hasSchedule = computed(() => !!(form.class_id && selectedData.value?.slot1_day));
const status = computed(() => selectedData.value?.status ?? null);

/** Chọn lớp đã có TKB → điền sẵn lịch hiện tại của lớp. */
function pick(value) {
    form.class_id = value;
    const data = props.scheduleData[value];
    if (!data || !data.slot1_day) return;
    for (const key of Object.keys(form)) {
        if (key !== 'class_id' && data[key] !== undefined) form[key] = data[key] ?? (key === 'slot2_day' ? '' : form[key]);
    }
}
const reset = () => Object.assign(form, props.initial);

// Lỗi xếp lịch (xung đột, không sinh được buổi…) hiện nổi bật trên form.
const page = usePage();
const conflictError = computed(() => {
    const e = page.props.errors ?? {};
    return e.class_id || e.slot2_start || e.slot1_day || e.start_date || e.end_date || null;
});
const conflictTitle = computed(() => (conflictError.value && (conflictError.value.includes('Xung đột') || conflictError.value.includes('trùng')) ? 'Cảnh báo xung đột lịch' : 'Không lưu được lịch lớp'));

const canToggle = (c) => ['active', 'completed'].includes(c.status) && can('class.update');
const editable = (c) => !['cancelled', 'completed'].includes(c.status);
const toggleConfirm = (c) => (c.status === 'active' ? `Kết thúc lớp ${c.name}? Lớp chuyển sang "Đã kết thúc".` : `Mở lại lớp ${c.name}?`);

/** Tìm lớp trong bảng "Danh sách lớp hiện tại" (giữ các tham số khác, cuộn về bảng). */
function searchClasses(event) {
    const q = new FormData(event.target).get('class_q');
    router.get(urlWith({ class_q: q, page: null }) + '#danh-sach-lop');
}
</script>

<template>
    <UiPageHeader v-if="view === 'report'" title="Báo cáo phòng / nhân sự" description="Số ca, phòng và trợ giảng cần bố trí 7 ngày theo buổi học thực tế." :back="route('tasks.schedule-config')" back-label="Lịch & TKB lớp">
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="urlWith({ export: 1 })" native title="Xuất báo cáo phòng / nhân sự 7 ngày">Xuất Excel</UiButton>
        </template>
    </UiPageHeader>
    <UiPageHeader v-else title="Lịch & TKB lớp" description="Cấu hình thời khóa biểu lớp học.">
        <template #actions>
            <UiButton variant="secondary" icon="groups" :href="route('tasks.schedule-config', { view: 'report' })">Báo cáo phòng / nhân sự</UiButton>
            <UiButton v-if="can('class.create')" variant="secondary" icon="add" :href="route('classes.create')">Tạo lớp mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 items-start gap-lg">
        <!-- ─── Cấu hình lịch lớp ─── -->
        <section v-if="view === 'config'" class="w-full space-y-lg">
            <UiAlert v-if="conflictError" type="error" :title="conflictTitle" data-testid="schedule-conflict">Lỗi: {{ conflictError }}</UiAlert>

            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <div class="mb-md flex items-center gap-sm border-b border-surface-container pb-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">calendar_month</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Cấu hình lịch lớp</h2>
                </div>

                <UiForm v-if="canSchedule" :action="route('tasks.schedule-config.update')" method="post" class="space-y-md">
                    <UiSelect
                        id="tkb_class_id"
                        label="Lớp học"
                        name="class_id"
                        required
                        placeholder="-- Chọn lớp học --"
                        hint="Lớp đã có TKB có thể sửa: buổi đã qua, đã điểm danh hoặc đã chấm công được giữ nguyên, chỉ các buổi sắp tới được xếp lại."
                        :model-value="form.class_id"
                        @update:model-value="pick"
                    >
                        <optgroup label="Chưa có TKB">
                            <option v-for="c in newClasses" :key="c.value" :value="String(c.value)" :selected="String(c.value) === form.class_id">{{ c.label }}</option>
                        </optgroup>
                        <optgroup label="Đã có TKB — sửa lịch">
                            <option v-for="c in scheduledClasses" :key="c.value" :value="String(c.value)" :selected="String(c.value) === form.class_id">{{ c.label }}</option>
                        </optgroup>
                    </UiSelect>

                    <UiAlert v-if="hasSchedule" type="info">Lớp này đã có thời khóa biểu — lưu lại sẽ xếp lại các buổi <strong>từ hôm nay</strong>; buổi quá khứ và buổi đã có dữ liệu thực tế không bị thay đổi.</UiAlert>

                    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                        <UiSelect id="tkb_year" v-model="form.academic_year" label="Năm học áp dụng" name="academic_year" :options="academicYears" />
                        <UiInput id="tkb_start" v-model="form.start_date" type="date" label="Khai giảng" name="start_date" required />
                        <UiInput id="tkb_end" v-model="form.end_date" type="date" label="Kết thúc" name="end_date" required />
                    </div>

                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        <div v-for="n in [1, 2]" :key="n" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-low p-md">
                            <h3 class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                                <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">{{ n === 1 ? 'looks_one' : 'looks_two' }}</span>
                                Slot {{ n }}
                            </h3>
                            <UiSelect :id="`tkb_slot${n}_day`" v-model="form[`slot${n}_day`]" label="Ngày trong tuần" :name="`slot${n}_day`" :placeholder="n === 2 ? '-- Không học ca 2 --' : null" :options="days" />
                            <div class="grid grid-cols-2 gap-sm">
                                <UiInput :id="`tkb_slot${n}_start`" v-model="form[`slot${n}_start`]" type="time" label="Giờ bắt đầu" :name="`slot${n}_start`" class="font-code" />
                                <UiInput :id="`tkb_slot${n}_end`" v-model="form[`slot${n}_end`]" type="time" label="Giờ kết thúc" :name="`slot${n}_end`" class="font-code" />
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-sm border-t border-surface-container pt-md">
                        <label v-if="status === 'upcoming'" class="mr-auto inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                            <input type="checkbox" name="activate" value="1" class="rounded border-outline-variant" />
                            Kích hoạt lớp (đang "Sắp khai giảng")
                        </label>
                        <UiButton variant="secondary" @click="reset()">Hủy thay đổi</UiButton>
                        <UiButton type="submit">{{ hasSchedule ? 'Cập nhật lịch' : 'Tạo lịch' }}</UiButton>
                    </div>
                </UiForm>
                <p v-else class="font-body-small text-body-small text-on-surface-variant">Bạn chỉ có quyền xem thời khóa biểu. Liên hệ Học vụ để thay đổi lịch lớp.</p>
            </div>

            <!-- Buổi bị hủy do nghỉ lễ thêm sau -->
            <UiDataTable v-if="holidaySessions.length">
                <template #header>
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-warning" aria-hidden="true">event_busy</span>
                        Buổi học bị hủy do ngày nghỉ
                    </h3>
                    <span class="font-body-small text-body-small text-on-surface-variant">{{ holidaySessions.length }} buổi sắp tới</span>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Lớp</th>
                            <th>Buổi bị hủy</th>
                            <th>Ngày nghỉ</th>
                            <th>Học bù</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in holidaySessions" :key="s.id">
                            <td>{{ s.class_name }} <span class="block font-code text-caption text-on-surface-variant">{{ s.class_code }}</span></td>
                            <td class="whitespace-nowrap font-code">{{ s.when }}</td>
                            <td>{{ s.holiday }}</td>
                            <td class="whitespace-nowrap">
                                <UiBadge v-if="s.makeup" color="warning">{{ s.makeup }}</UiBadge>
                                <UiBadge v-else color="error">Chưa xếp bù</UiBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>

            <!-- Danh sách lớp -->
            <div id="danh-sach-lop">
                <UiDataTable>
                    <template #header>
                        <h3 class="font-h3 text-h3 text-on-surface">Danh sách lớp hiện tại</h3>
                        <form method="GET" :action="route('tasks.schedule-config')" role="search" class="relative" @submit.prevent="searchClasses">
                            <span class="material-symbols-outlined pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant" aria-hidden="true">search</span>
                            <input
                                type="search"
                                name="class_q"
                                :value="classSearch"
                                placeholder="Tìm lớp..."
                                aria-label="Tìm lớp"
                                class="w-52 rounded-lg border border-outline-variant py-xs pl-8 pr-md font-body-small text-body-small focus:border-primary-container focus:ring-2 focus:ring-primary-container/50"
                            />
                        </form>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Tên lớp</th>
                                <th>Giảng viên</th>
                                <th>Trạng thái</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in listClasses" :key="c.id">
                                <td>
                                    <span class="font-semibold">{{ c.name }}</span>
                                    <span class="block font-code text-caption text-on-surface-variant">{{ c.code }} · {{ c.schedule_text || 'Chưa có TKB' }}</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <template v-if="c.teacher">GV: {{ c.teacher }}</template>
                                    <span v-else class="text-on-surface-variant">Chưa phân công</span>
                                </td>
                                <td>
                                    <UiBadge v-if="statusBadges[c.status]" :color="statusBadges[c.status][0]">{{ statusBadges[c.status][1] }}</UiBadge>
                                    <UiBadge v-else>Đã kết thúc</UiBadge>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <UiButton v-if="canSchedule && editable(c)" size="sm" variant="ghost" icon="edit_calendar" :href="route('tasks.schedule-config', { class_id: c.id })">{{ c.has_config ? 'Sửa lịch' : 'Xếp lịch' }}</UiButton>
                                    <!-- Kết thúc / mở lại lớp là thao tác hiếm: để trong menu "⋯" và hỏi xác nhận. -->
                                    <div v-if="canToggle(c)" class="inline-block text-left">
                                        <UiDropdown align="right" width="48">
                                            <template #trigger>
                                                <UiButton size="sm" variant="ghost" icon="more_horiz" :aria-label="`Thao tác khác với lớp ${c.code}`" aria-haspopup="menu" />
                                            </template>
                                            <template #content>
                                                <UiForm
                                                    :action="route('tasks.schedule-config.update')"
                                                    method="post"
                                                    role="menu"
                                                    :confirm="toggleConfirm(c)"
                                                    :danger="c.status === 'active'"
                                                >
                                                    <input type="hidden" name="toggle_class_id" :value="c.id" />
                                                    <button
                                                        type="submit"
                                                        role="menuitem"
                                                        :class="[
                                                            'flex w-full items-center gap-sm px-md py-sm text-left font-body-medium text-body-medium transition-colors',
                                                            c.status === 'active' ? 'text-error hover:bg-error/5' : 'text-on-surface hover:bg-surface-container-low',
                                                        ]"
                                                    >
                                                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ c.status === 'active' ? 'event_available' : 'restart_alt' }}</span>
                                                        {{ c.status === 'active' ? 'Kết thúc lớp' : 'Mở lại lớp' }}
                                                    </button>
                                                </UiForm>
                                            </template>
                                        </UiDropdown>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!listClasses.length">
                                <td colspan="4">
                                    <UiEmptyState v-if="classSearch !== ''" icon="search_off" title="Không tìm thấy lớp" :description="`Không có lớp nào khớp “${classSearch}”.`" />
                                    <UiEmptyState v-else icon="school" title="Chưa có lớp nào" description="Bạn chưa phụ trách lớp học nào." />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </div>
        </section>

        <!-- ─── Báo cáo phòng / nhân sự ─── -->
        <section v-else class="w-full max-w-3xl">
            <div class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <UiFilterBar :action="route('tasks.schedule-config')" :search="false" submit-label="Xem" class="!mb-0 !border-0 !p-0 !shadow-none">
                    <input type="hidden" name="view" value="report" />
                    <UiSelect name="report_branch_id" label="Chi nhánh" :options="branches" :value="reportBranchId" />
                    <UiDate name="report_date" label="Từ ngày (7 ngày)" :value="reportStart" />
                </UiFilterBar>

                <UiAlert v-if="classCountChange.previous !== classCountChange.current" type="warning">
                    Số lớp có lịch đã thay đổi <strong>{{ classCountChange.previous }} → {{ classCountChange.current }}</strong> so với 7 ngày trước, kiểm tra lại số nhân sự trợ giảng cần bố trí.
                </UiAlert>

                <UiForm :action="route('tasks.hr-demand.save')" method="post" class="space-y-md">
                    <input type="hidden" name="branch_id" :value="reportBranchId" />
                    <UiDataTable>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th class="text-center">Số ca</th>
                                    <th class="text-center">Phòng</th>
                                    <th class="text-center">TA có ca</th>
                                    <th class="text-center">Nhân sự cần</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in report" :key="row.date">
                                    <td class="whitespace-nowrap">
                                        <span class="font-semibold">{{ row.label }}</span>
                                        <span class="block font-code text-caption text-on-surface-variant">{{ row.day }}</span>
                                    </td>
                                    <td class="text-center font-code">{{ row.shifts }}</td>
                                    <td class="text-center font-code">{{ row.rooms }}</td>
                                    <td class="text-center font-code">{{ row.assistants }}</td>
                                    <td class="text-center">
                                        <div class="inline-flex items-center gap-xs">
                                            <input
                                                type="number"
                                                :name="`demands[${row.date}]`"
                                                :value="row.staff_needed"
                                                min="0"
                                                max="50"
                                                :disabled="!canSchedule"
                                                :aria-label="`Nhân sự cần ${row.day}`"
                                                :class="['w-16 rounded-lg border px-xs py-xs text-center font-code', row.saved ? 'border-outline-variant' : 'border-dashed border-outline-variant text-on-surface-variant']"
                                            />
                                            <span v-if="!row.saved" class="material-symbols-outlined cursor-help text-[16px] text-on-surface-variant" title="Giá trị tự động tính toán từ số ca">info</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>
                    <p class="font-caption text-caption text-on-surface-variant">Số ca, phòng và TA tính từ buổi học thực tế của chi nhánh. Ô viền nét đứt là gợi ý (chưa lưu).</p>
                    <UiButton v-if="canSchedule" type="submit" variant="secondary" icon="save" class="w-full">Lưu báo cáo nhân sự</UiButton>
                </UiForm>
            </div>
        </section>
    </div>
</template>
