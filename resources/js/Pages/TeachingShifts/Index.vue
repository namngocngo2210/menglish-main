<script setup>
/**
 * Khung giờ ca dạy (Cài đặt → Tổ chức & Đào tạo): danh mục khung giờ theo loại ngày, mỗi khung có giờ chuẩn và giờ lệch.
 * Lịch & TKB lớp chỉ cho chọn theo khung này (Thứ 2–6), cuối tuần theo giờ lớp; mỗi ca 90 phút.
 * Thêm / sửa trong modal, Ngừng dùng / Dùng lại, Xóa (xóa mềm) — chỉ Admin (teaching_shift.manage).
 */
import { computed, reactive, ref } from 'vue';

defineOptions({ layout: { title: 'Khung giờ ca dạy' } });

const props = defineProps({
    dayTypes: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    weekdayRule: { type: String, default: '' },
    duration: { type: Number, default: 90 },
    offScheduleClasses: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canSchedule: { type: Boolean, default: false },
});

const groups = computed(() => props.dayTypes.map((d) => ({ ...d, shifts: props.shifts.filter((s) => s.day_type === d.value) })));

// Modal Thêm / Sửa: giờ kết thúc tự tính = giờ bắt đầu + 90 phút (vẫn sửa được, máy chủ kiểm lại).
const editing = ref(null);
const open = ref(false);
const form = reactive({ day_type: 'weekday', name: '', start_time: '', end_time: '', alt_start_time: '', alt_end_time: '', note: '' });

function plus(time, minutes) {
    if (!/^\d{2}:\d{2}$/.test(time ?? '')) return '';
    const [h, m] = time.split(':').map(Number);
    const total = Math.min(23 * 60 + 59, h * 60 + m + minutes);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
function startEdit(shift = null) {
    editing.value = shift;
    Object.assign(form, {
        day_type: shift?.day_type ?? 'weekday',
        name: shift?.name ?? '',
        start_time: shift?.start_time ?? '',
        end_time: shift?.end_time ?? '',
        alt_start_time: shift?.alt_start_time ?? '',
        alt_end_time: shift?.alt_end_time ?? '',
        note: shift?.note ?? '',
    });
    open.value = true;
}
const setStart = (v) => {
    form.start_time = v;
    form.end_time = plus(v, props.duration);
};
const setAltStart = (v) => {
    form.alt_start_time = v;
    form.alt_end_time = v ? plus(v, props.duration) : '';
};
</script>

<template>
    <UiPageHeader title="Khung giờ ca dạy" description="Khung giờ chuẩn và giờ lệch của từng ca, dùng khi xếp Lịch & TKB lớp và đối chiếu chấm công giáo viên.">
        <template #actions>
            <UiButton variant="secondary" icon="edit_calendar" :href="route('tasks.schedule-config')">Lịch & TKB lớp</UiButton>
            <UiButton v-if="canManage" icon="add" @click="startEdit()">Thêm khung giờ</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-lg">
        <!-- Quy tắc -->
        <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md" data-testid="shift-rules">
            <h2 class="mb-sm flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">rule</span>
                Quy tắc áp dụng
            </h2>
            <ul class="grid grid-cols-1 gap-sm font-body-small text-body-small text-on-surface md:grid-cols-2">
                <li class="flex gap-xs"><span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">event</span><span><strong>Thứ 2 – Thứ 6</strong> chỉ có {{ weekdayRule }}.</span></li>
                <li class="flex gap-xs"><span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">weekend</span><span><strong>Thứ 7, Chủ nhật</strong> không chia cứng ca: lấy giờ bắt đầu – kết thúc thực tế của từng lớp trên TKB (khung bên dưới là giờ thường dùng).</span></li>
                <li class="flex gap-xs"><span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">timer</span><span>Mỗi ca dạy <strong>{{ duration }} phút</strong>.</span></li>
                <li class="flex gap-xs"><span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">how_to_reg</span><span>Chấm công giáo viên đối chiếu theo <strong>giờ của lớp được phân công</strong>, không theo một giờ chung cố định.</span></li>
            </ul>
        </section>

        <!-- Lớp lệch khung -->
        <UiAlert v-if="offScheduleClasses.length" type="warning" title="Lớp có buổi sắp tới lệch khung giờ" data-testid="off-schedule">
            <p class="mb-xs">{{ offScheduleClasses.length }} lớp có buổi trong 60 ngày tới không theo khung giờ ca dạy. Xếp lại TKB để chấm công đối chiếu đúng ca.</p>
            <ul class="space-y-xs">
                <li v-for="c in offScheduleClasses" :key="c.class_id" class="flex flex-wrap items-center gap-x-sm gap-y-xs">
                    <span class="font-semibold">{{ c.class_name }}</span>
                    <span class="font-code text-caption">{{ c.class_code }}<template v-if="c.branch"> · {{ c.branch }}</template></span>
                    <span class="font-code text-caption">{{ c.slots.join(', ') }} ({{ c.sessions }} buổi)</span>
                    <UiButton v-if="canSchedule" size="sm" variant="ghost" icon="edit_calendar" :href="route('tasks.schedule-config', { class_id: c.class_id })">Sửa lịch</UiButton>
                </li>
            </ul>
        </UiAlert>

        <!-- Danh mục theo loại ngày -->
        <UiDataTable v-for="g in groups" :key="g.value" min-width="640px">
            <template #header>
                <h2 class="font-h3 text-h3 text-on-surface">{{ g.label }}</h2>
                <span class="font-body-small text-body-small text-on-surface-variant">{{ g.shifts.length }} khung</span>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Ca</th>
                        <th>Khung giờ chuẩn</th>
                        <th>Khung giờ lệch trên TKB</th>
                        <th>Ghi chú</th>
                        <th v-if="canManage" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in g.shifts" :key="s.id">
                        <td :class="['whitespace-nowrap font-semibold', s.is_active ? 'text-on-surface' : 'text-on-surface-variant']">
                            {{ s.name }}
                            <UiBadge v-if="!s.is_active" color="neutral" class="ml-xs">Ngừng dùng</UiBadge>
                        </td>
                        <td class="whitespace-nowrap font-code">{{ s.standard }}</td>
                        <td class="whitespace-nowrap font-code">{{ s.alt ?? '—' }}</td>
                        <td class="min-w-[10rem] text-on-surface-variant">{{ s.note ?? '' }}</td>
                        <td v-if="canManage" class="whitespace-nowrap text-right">
                            <div class="inline-flex items-center gap-xs">
                                <UiButton variant="ghost" size="sm" icon="edit" :aria-label="`Sửa ${s.name} ${s.standard}`" title="Sửa" @click="startEdit(s)" />
                                <UiForm :action="route('teaching-shifts.toggle', s.id)" method="post" back>
                                    <UiButton
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                        :icon="s.is_active ? 'block' : 'restart_alt'"
                                        :aria-label="`${s.is_active ? 'Ngừng dùng' : 'Dùng lại'} ${s.name} ${s.standard}`"
                                        :title="s.is_active ? 'Ngừng dùng' : 'Dùng lại'"
                                    />
                                </UiForm>
                                <UiForm
                                    :action="route('teaching-shifts.destroy', s.id)"
                                    method="delete"
                                    back
                                    :confirm="`Xóa khung ${s.name} ${s.standard} (${g.label})? Lịch đã xếp không đổi.`"
                                    confirm-title="Xóa khung giờ"
                                    confirm-label="Xóa"
                                    danger
                                >
                                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa ${s.name} ${s.standard}`" title="Xóa" />
                                </UiForm>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!g.shifts.length">
                        <td :colspan="canManage ? 5 : 4">
                            <UiEmptyState icon="schedule" title="Chưa có khung giờ" :description="g.value === 'weekday' ? 'Chưa có khung nào: ngày thường được xếp giờ bất kỳ (vẫn 90 phút).' : 'Cuối tuần lấy giờ thực tế của lớp.'" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <UiModal :show="open" :title="editing ? 'Sửa khung giờ ca dạy' : 'Thêm khung giờ ca dạy'" max-width="lg" @close="open = false">
        <UiForm
            :action="editing ? route('teaching-shifts.update', editing.id) : route('teaching-shifts.store')"
            :method="editing ? 'put' : 'post'"
            back
            class="space-y-md"
            data-testid="shift-form"
            @success="open = false"
        >
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect id="shift_day_type" v-model="form.day_type" name="day_type" label="Loại ngày" required :options="dayTypes" />
                <UiInput id="shift_name" v-model="form.name" name="name" label="Tên ca" required maxlength="50" placeholder="VD: Ca 1" />
            </div>
            <fieldset class="grid grid-cols-2 gap-sm">
                <legend class="mb-xs font-label text-label text-on-surface-variant">Khung giờ chuẩn</legend>
                <UiInput id="shift_start" :model-value="form.start_time" type="time" name="start_time" label="Bắt đầu" required class="font-code" @update:model-value="setStart" />
                <UiInput id="shift_end" v-model="form.end_time" type="time" name="end_time" label="Kết thúc" required class="font-code" :hint="`Tự tính +${duration} phút`" />
            </fieldset>
            <fieldset class="grid grid-cols-2 gap-sm">
                <legend class="mb-xs font-label text-label text-on-surface-variant">Khung giờ lệch trên TKB (không bắt buộc)</legend>
                <UiInput id="shift_alt_start" :model-value="form.alt_start_time" type="time" name="alt_start_time" label="Bắt đầu" class="font-code" @update:model-value="setAltStart" />
                <UiInput id="shift_alt_end" v-model="form.alt_end_time" type="time" name="alt_end_time" label="Kết thúc" class="font-code" />
            </fieldset>
            <UiInput id="shift_note" v-model="form.note" name="note" label="Ghi chú" maxlength="255" placeholder="VD: Một số lớp lệch 10 phút" />
            <p class="font-caption text-caption text-on-surface-variant">Sửa khung không đổi lịch đã xếp — chỉ áp khi xếp hoặc sửa TKB lớp.</p>
            <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                <UiButton variant="secondary" @click="open = false">Hủy</UiButton>
                <UiButton type="submit">{{ editing ? 'Lưu' : 'Thêm' }}</UiButton>
            </div>
        </UiForm>
    </UiModal>
</template>
