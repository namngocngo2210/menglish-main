<script setup>
/**
 * Chọn lịch học cố định của lớp: tối đa 2 ca/tuần (Slot 1 bắt buộc, Slot 2 tùy chọn), mỗi ca = thứ + khung giờ ca dạy.
 * Ghi thẳng vào form[slot{n}_day|start|end] của trang cha và gửi kèm ô ẩn slot{n}_start/end.
 * Dùng chung cho Lịch & TKB lớp và Sửa lớp. Trang cha đổi lịch từ ngoài (chọn lớp khác, hủy thay đổi) thì gọi sync().
 */
import { reactive } from 'vue';

const props = defineProps({
    form: { type: Object, required: true },
    shiftOptions: { type: Object, default: () => ({}) },
    shiftDuration: { type: Number, default: 90 },
    weekdayRule: { type: String, default: '' },
    idPrefix: { type: String, default: 'tkb' },
});

const days = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'].map((d) => ({ value: d, label: d }));

// ─── Khung giờ ca dạy: Thứ 2–6 chọn Ca 1 / Ca 2 (giờ chuẩn hoặc giờ lệch); cuối tuần chọn khung hoặc "Giờ khác theo lớp"; mỗi ca 90 phút.
const dayTypeOf = (day) => (day === 'Thứ 7' ? 'saturday' : day === 'Chủ nhật' ? 'sunday' : 'weekday');
const plus = (time, minutes) => {
    if (!/^\d{2}:\d{2}$/.test(time ?? '')) return '';
    const [h, m] = time.split(':').map(Number);
    const total = Math.min(23 * 60 + 59, h * 60 + m + minutes);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
};
/** Giờ khác (không theo khung) chỉ cho cuối tuần, hoặc loại ngày chưa có khung nào. */
function shiftChoices(n) {
    const day = props.form[`slot${n}_day`];
    if (!day) return [];
    const type = dayTypeOf(day);
    const choices = (props.shiftOptions[type] ?? []).map((o) => ({ value: o.value, label: o.label }));
    if (type !== 'weekday' || !choices.length) choices.push({ value: 'custom', label: type === 'weekday' ? 'Giờ khác' : 'Giờ khác theo lớp' });
    return choices;
}
const slotRange = (n) => `${props.form[`slot${n}_start`]}-${props.form[`slot${n}_end`]}`;
const matchesFrame = (n) => shiftChoices(n).some((c) => c.value === slotRange(n));
const allowsCustom = (n) => shiftChoices(n).some((c) => c.value === 'custom');
const custom = reactive({ 1: false, 2: false });
const sync = () => [1, 2].forEach((n) => (custom[n] = !!props.form[`slot${n}_day`] && allowsCustom(n) && !matchesFrame(n)));
sync();
const shiftValue = (n) => (custom[n] ? 'custom' : matchesFrame(n) ? slotRange(n) : '');
/** Ngày thường đang mang giờ cũ lệch khung (TKB xếp trước khi có khung giờ) → nhắc chọn lại ca. */
const offFrame = (n) => !!props.form[`slot${n}_day`] && !custom[n] && !matchesFrame(n);
function pickShift(n, value) {
    if (value === 'custom') {
        custom[n] = true;
        props.form[`slot${n}_end`] = plus(props.form[`slot${n}_start`], props.shiftDuration);
        return;
    }
    custom[n] = false;
    if (!value) return;
    const [start, end] = value.split('-');
    props.form[`slot${n}_start`] = start;
    props.form[`slot${n}_end`] = end;
}
function setCustomStart(n, value) {
    props.form[`slot${n}_start`] = value;
    props.form[`slot${n}_end`] = plus(value, props.shiftDuration);
}
/** Đổi ngày: giờ đang chọn không còn hợp lệ với loại ngày mới → ngày thường về khung đầu tiên, cuối tuần giữ giờ (Giờ khác). */
function pickDay(n, day) {
    props.form[`slot${n}_day`] = day;
    if (!day || matchesFrame(n)) {
        custom[n] = false;
        return;
    }
    if (allowsCustom(n)) {
        custom[n] = true;
        props.form[`slot${n}_start`] ||= '18:00';
        props.form[`slot${n}_end`] = plus(props.form[`slot${n}_start`], props.shiftDuration);
        return;
    }
    pickShift(n, shiftChoices(n)[0]?.value ?? '');
}

defineExpose({ sync });
</script>

<template>
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <div v-for="n in [1, 2]" :key="n" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-low p-md">
            <h3 class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">{{ n === 1 ? 'looks_one' : 'looks_two' }}</span>
                Slot {{ n }}
            </h3>
            <UiSelect
                :id="`${idPrefix}_slot${n}_day`"
                :model-value="form[`slot${n}_day`]"
                label="Ngày trong tuần"
                :name="`slot${n}_day`"
                :placeholder="n === 2 ? '-- Không học ca 2 --' : form.slot1_day ? null : '-- Chọn ngày học --'"
                :options="days"
                @update:model-value="(v) => pickDay(n, v)"
            />
            <template v-if="form[`slot${n}_day`]">
                <UiSelect
                    :id="`${idPrefix}_slot${n}_shift`"
                    :model-value="shiftValue(n)"
                    label="Ca dạy"
                    placeholder="-- Chọn ca --"
                    :options="shiftChoices(n)"
                    :hint="dayTypeOf(form[`slot${n}_day`]) === 'weekday' ? `Thứ 2 – Thứ 6 chỉ có ${weekdayRule}.` : 'Cuối tuần lấy giờ thực tế của lớp: chọn khung thường dùng hoặc Giờ khác theo lớp.'"
                    @update:model-value="(v) => pickShift(n, v)"
                />
                <UiInput
                    v-if="custom[n]"
                    :id="`${idPrefix}_slot${n}_start`"
                    :model-value="form[`slot${n}_start`]"
                    type="time"
                    label="Giờ bắt đầu"
                    class="font-code"
                    :hint="`Kết thúc ${form[`slot${n}_end`] || '—'} (ca ${shiftDuration} phút)`"
                    @update:model-value="(v) => setCustomStart(n, v)"
                />
                <p v-else-if="matchesFrame(n)" class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" :data-testid="`slot${n}-time`">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">schedule</span>
                    Giờ học <span class="font-code text-on-surface">{{ form[`slot${n}_start`] }} – {{ form[`slot${n}_end`] }}</span> ({{ shiftDuration }} phút)
                </p>
                <p v-if="offFrame(n)" class="font-body-small text-body-small text-error" :data-testid="`slot${n}-off-frame`">
                    Lịch hiện tại {{ form[`slot${n}_start`] }} – {{ form[`slot${n}_end`] }} lệch khung giờ ca dạy, hãy chọn lại ca.
                </p>
            </template>
            <input type="hidden" :name="`slot${n}_start`" :value="form[`slot${n}_start`]" />
            <input type="hidden" :name="`slot${n}_end`" :value="form[`slot${n}_end`]" />
        </div>
    </div>
</template>
