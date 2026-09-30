<script setup>
/**
 * Form Sửa thông tin khách — nằm trong tab "Thông tin khách hàng" của hồ sơ (Show.vue).
 * Trường hợp đồng khóa sau chốt (A3): Giá trị hợp đồng, Cơ sở, Khóa đăng ký.
 * options: CrmController::showProps → editForm (branches, salesUsers, leadSources, courseNames, lockedFields).
 */
import { computed } from 'vue';

const props = defineProps({
    customer: { type: Object, required: true },
    options: { type: Object, required: true },
});
const locked = computed(() => props.customer.contract_locked);
const genders = [{ value: 'Nam', label: 'Nam' }, { value: 'Nữ', label: 'Nữ' }, { value: 'Khác', label: 'Khác' }];
const lockedInput = 'w-full rounded-lg border border-outline-variant px-md py-sm focus:border-primary-container focus:ring-2 focus:ring-primary-container/50';
</script>

<template>
    <UiForm id="edit-lead-form" :action="route('crm.customers.update', customer.id)" method="put" class="space-y-md p-lg">
        <UiAlert v-if="locked" type="warning">Khách đã <strong>{{ customer.stage_label }}</strong>: {{ options.lockedFields }} đã khóa, không sửa được tại đây.</UiAlert>

        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
            <UiInput id="f_name" name="name" label="Họ tên" required placeholder="Nhập họ và tên" :value="customer.name" />
            <UiInput id="f_phone" name="phone" type="tel" label="Số điện thoại" required placeholder="0xxx xxx xxx" :value="customer.phone" hint="10 số, bắt đầu bằng 0 (hoặc +84)" />
        </div>

        <UiInput id="f_parent_name" name="parent_name" label="Tên phụ huynh" placeholder="Nhập tên phụ huynh (nếu có)" :value="customer.parent_name" />

        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
            <UiSelect id="f_source" name="source" label="Nguồn" required placeholder="Chọn nguồn" :value="customer.source" :options="options.leadSources" />
            <div class="flex flex-col gap-xs">
                <UiSelect id="f_branch_id" name="branch_id" label="Chi nhánh" required placeholder="Chọn chi nhánh" :value="customer.branch_id" :options="options.branches" :disabled="locked" />
                <p class="font-caption text-caption italic text-on-surface-variant">* Không thể thay đổi nếu học viên đã có lớp</p>
            </div>
        </div>

        <details class="group rounded-lg border border-surface-container-highest" open>
            <summary class="flex cursor-pointer select-none items-center justify-between px-md py-sm font-body-medium text-body-medium text-on-surface-variant">
                Thông tin bổ sung
                <span class="material-symbols-outlined transition-transform group-open:rotate-180">expand_more</span>
            </summary>
            <div class="grid grid-cols-1 gap-md border-t border-surface-container-highest p-md md:grid-cols-2">
                <UiInput id="f_parent_phone" name="parent_phone" type="tel" label="SĐT phụ huynh" placeholder="VD: 0912 345 678" :value="customer.parent_phone" />
                <UiInput id="next_follow_up_at" name="next_follow_up_at" type="datetime-local" label="Hạn liên hệ tiếp theo" :value="customer.next_follow_up_input" hint="Pipeline báo &quot;Sắp hết hạn&quot; trước 24 giờ và &quot;Quá hạn&quot; khi quá hạn." />
                <UiInput id="f_email" name="email" type="email" label="Email" :value="customer.email" />
                <UiDate id="f_dob" name="dob" label="Ngày sinh" :value="customer.dob" />
                <UiSelect id="f_gender" name="gender" label="Giới tính" placeholder="-- Chọn --" :value="customer.gender" :options="genders" />
                <UiField label="Giai đoạn">
                    <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-medium text-body-medium text-primary">{{ customer.stage_label }}</div>
                    <p class="font-caption text-caption text-on-surface-variant">Đổi giai đoạn tại Pipeline; "Đã chốt" chỉ tạo qua Chốt &amp; Xếp lớp.</p>
                </UiField>
                <UiSelect
                    v-if="can('lead.assign')"
                    id="f_assigned_user_id"
                    name="assigned_user_id"
                    label="Người phụ trách"
                    placeholder="-- Chưa có người phụ trách --"
                    :value="customer.assigned_user_id"
                    :options="options.salesUsers"
                    hint="Chọn Học vụ cơ sở khác = chuyển cơ sở cho khách, cần Admin duyệt."
                />
                <UiField label="Khóa học quan tâm" name="course_interest" for="f_course_interest">
                    <input
                        id="f_course_interest"
                        type="text"
                        name="course_interest"
                        :readonly="locked"
                        :value="customer.course_interest"
                        placeholder="Chọn hoặc nhập khóa học"
                        list="course-interest-options"
                        :class="[lockedInput, 'font-body-base text-body-base', locked ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-lowest']"
                    />
                    <datalist id="course-interest-options">
                        <option v-for="courseName in options.courseNames" :key="courseName" :value="courseName"></option>
                    </datalist>
                </UiField>
                <UiField label="Giá trị hợp đồng (VNĐ)" name="deal_value" for="f_deal_value">
                    <div class="relative">
                        <input
                            id="f_deal_value"
                            type="number"
                            name="deal_value"
                            :readonly="locked"
                            :value="customer.deal_value"
                            :class="[lockedInput, 'font-code text-code', locked ? 'bg-surface-container-low text-on-surface-variant' : 'bg-surface-container-lowest']"
                        />
                        <span v-if="locked" class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[18px] text-warning" title="Đã khóa">lock</span>
                    </div>
                </UiField>
                <div class="md:col-span-2"><UiInput id="f_address" name="address" label="Địa chỉ" :value="customer.address" /></div>
                <div class="md:col-span-2"><UiTextarea id="f_notes" name="notes" label="Ghi chú nhu cầu" :rows="3" :value="customer.notes" /></div>
            </div>
        </details>

        <div class="flex items-center justify-end gap-md pt-lg">
            <UiButton variant="secondary" type="reset">Hoàn tác</UiButton>
            <UiButton type="submit" icon="save">Lưu thay đổi</UiButton>
        </div>
    </UiForm>
</template>
