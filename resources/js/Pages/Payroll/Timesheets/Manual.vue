<script setup>
/**
 * Chấm công thủ công (mockup epic-7/cham-cong-thu-cong): ghi 1 ca chấm công thay hệ thống khi gặp sự cố.
 * Tìm nhân viên không dấu, lọc lớp theo chi nhánh, ngày thuộc kỳ lương đã khoá → cảnh báo + khoá nút lưu.
 * Mở từ "Buổi thiếu chấm công" → điền sẵn nhân viên / lớp / ngày / giờ theo tham số URL.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { searchKey } from '../format';

defineOptions({ layout: { title: 'Chấm công thủ công' } });

const props = defineProps({
    teachers: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    lockedRanges: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
});

const page = usePage();
const q = ref('');
const userId = ref(props.defaults.user_id);
const branchId = ref(props.defaults.branch_id);
const classId = ref(props.defaults.class_id);
const date = ref(props.defaults.teaching_date);

const filteredTeachers = computed(() => {
    const key = searchKey(q.value);
    return key ? props.teachers.filter((t) => t.search.includes(key) || String(t.id) === userId.value) : props.teachers;
});
const teacherOptions = computed(() => filteredTeachers.value.map((t) => ({ value: String(t.id), label: t.label })));
const filteredClasses = computed(() => (branchId.value ? props.classes.filter((c) => String(c.branch) === branchId.value) : props.classes));
const classOptions = computed(() => filteredClasses.value.map((c) => ({ value: String(c.id), label: c.label })));
const lockedPeriod = computed(() => props.lockedRanges.find((p) => date.value && date.value >= p.from && date.value <= p.to) ?? null);
const extraOpen = computed(() => !!(page.props.errors?.type || page.props.errors?.hourly_rate));
const typeOptions = [
    { value: 'regular', label: 'Ca dạy chính khóa' },
    { value: 'sub', label: 'Dạy thay (Sub)' },
    { value: '1on1', label: 'Kèm phụ đạo 1-1' },
    { value: 'grading', label: 'Chấm bài thi Test' },
    { value: 'workshop', label: 'Workshop / Sự kiện' },
];

function onBranchChange() {
    if (classId.value && !filteredClasses.value.some((c) => String(c.id) === classId.value)) classId.value = '';
}
</script>

<template>
    <div class="max-w-3xl">
        <UiPageHeader title="Chấm công thủ công" description="Ghi nhận chấm công thay hệ thống khi gặp sự cố hạ tầng (mất mạng, mất điện...).">
            <template #breadcrumbs>
                <Link :href="route('payroll.timesheets.teachers')" class="hover:text-primary">Chấm công giáo viên</Link>
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span>
                <span>Chấm công thủ công</span>
            </template>
        </UiPageHeader>

        <UiForm :action="route('payroll.timesheets.manual.store')" method="post" class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
            <div class="flex items-center gap-sm border-b border-surface-container px-lg py-md">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">timer</span>
                <h2 class="font-h3 text-h3 text-on-surface">Thông tin chấm công</h2>
            </div>

            <div class="space-y-lg p-lg">
                <UiAlert type="info">
                    <strong>Lưu ý:</strong> Mỗi lần lưu hệ thống chỉ tạo đúng <strong>1 dòng chấm công</strong> (tương ứng 1 buổi công).
                    Nếu giáo viên làm nhiều ca liên tiếp, vui lòng thực hiện lưu nhiều lần.
                    Không chấm trùng ca đã check-in / đã chấm tay; số giờ tính từ giờ vào – giờ ra (tối thiểu 30 phút).
                </UiAlert>

                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <UiField label="Nhân viên" name="user_id" for="f_user_id" required class="md:col-span-2">
                        <div class="mb-xs">
                            <UiInput v-model="q" type="search" icon="search" placeholder="Nhập tên hoặc mã nhân viên" aria-label="Tìm nhân viên" />
                        </div>
                        <UiSelect id="f_user_id" v-model="userId" name="user_id" required placeholder="Chọn nhân viên..." :options="teacherOptions" />
                        <p v-show="q && filteredTeachers.length === 0" class="font-body-small text-body-small text-on-surface-variant">Không tìm thấy nhân viên phù hợp.</p>
                    </UiField>

                    <UiField label="Chi nhánh" name="branch_id" for="f_branch_id" required>
                        <UiSelect id="f_branch_id" v-model="branchId" name="branch_id" placeholder="Chọn chi nhánh..." :options="branches" @change="onBranchChange" />
                    </UiField>

                    <UiField label="Lớp học" name="class_id" for="f_class_id" required>
                        <UiSelect id="f_class_id" v-model="classId" name="class_id" required placeholder="Chọn lớp học..." :options="classOptions" />
                    </UiField>

                    <div class="md:col-span-2">
                        <UiInput v-model="date" type="date" name="teaching_date" label="Ngày làm việc" required />
                        <div v-show="lockedPeriod" role="alert" class="mt-xs flex items-center gap-xs rounded-lg bg-error-container px-md py-sm font-body-small text-body-small text-on-error-container">
                            <span class="material-symbols-outlined text-[18px] text-error" aria-hidden="true">lock</span>
                            <span>Kỳ lương của ngày này đã bị khóa ({{ lockedPeriod?.title }}). Không thể chấm công.</span>
                        </div>
                    </div>

                    <UiInput type="time" name="time_in" label="Giờ vào" required :value="defaults.time_in" />
                    <UiInput type="time" name="time_out" label="Giờ ra" required :value="defaults.time_out" />

                    <UiInput type="number" name="late_minutes" label="Đi muộn (phút)" min="0" max="600" step="1" value="0" />
                    <UiInput type="number" name="early_leave_minutes" label="Về sớm (phút)" min="0" max="600" step="1" value="0" />
                    <div class="md:col-span-2">
                        <UiCheckbox name="late_notified" value="1" label="Có báo trước (trả theo số phút thực dạy; không báo trước: dưới ngưỡng trừ theo phút, từ ngưỡng không tính buổi)" />
                    </div>

                    <div class="md:col-span-2">
                        <UiTextarea name="notes" label="Lý do điều chỉnh" required rows="3" placeholder="Ví dụ: Mất mạng chi nhánh, quên quẹt thẻ..." />
                    </div>
                </div>

                <details class="rounded-lg border border-surface-container bg-surface-container-low/40 px-md py-sm" :open="extraOpen || null">
                    <summary class="cursor-pointer font-body-medium text-body-medium text-on-surface-variant">Thông tin bổ sung (loại ca, đơn giá riêng ca này)</summary>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                        <UiSelect name="type" label="Loại ca dạy" required :options="typeOptions" />
                        <UiInput type="number" name="hourly_rate" label="Đơn giá giờ riêng cho ca này (đ/giờ)" min="1000" step="1000" placeholder="Bỏ trống = đơn giá của giáo viên" hint="Bỏ trống để dùng đơn giá riêng của GV theo ngày hiệu lực (theo buổi hoặc theo giờ)." />
                    </div>
                </details>
            </div>

            <div class="flex items-center justify-end gap-sm border-t border-surface-container bg-surface-container-low/40 px-lg py-md">
                <UiButton variant="secondary" :href="route('payroll.timesheets.teachers')">Hủy</UiButton>
                <UiButton type="submit" icon="save" v-bind="lockedPeriod ? { disabled: true } : {}">Lưu chấm công</UiButton>
            </div>
        </UiForm>
    </div>
</template>
