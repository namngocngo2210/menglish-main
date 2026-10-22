<script setup>
/**
 * Trường form Thêm / Sửa nhân sự — dùng chung trang đầy đủ và modal 3xl (idPrefix "modal-user-").
 * 3 tab trong CÙNG 1 form (Tài khoản / Hồ sơ / Hợp đồng & Lương): mọi trường vẫn gửi đi dù tab đang ẩn (v-show).
 * Validate lỗi → chọn tab đầu tiên có lỗi; ô required nằm ở tab ẩn mà trình duyệt chặn gửi → tự chuyển sang tab chứa ô đó (sự kiện invalid).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useFormContext, usePage } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, default: null },
    currentRole: { type: String, default: null },
    currentConcurrent: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
    concurrentOptions: { type: Array, default: () => [] },
    initialTab: { type: String, default: 'account' },
    idPrefix: { type: String, default: '' },
    canEditSensitive: { type: Boolean, default: true },
});

const form = useFormContext();
const page = usePage();
const fid = (field) => props.idPrefix + field;

const tabs = {
    account: ['Tài khoản', 'manage_accounts', ['name', 'email', 'phone', 'branch_id', 'extra_branch_ids', 'role', 'academic_teaching', 'concurrent_roles', 'password']],
    profile: ['Hồ sơ', 'badge', ['date_of_birth', 'personal_email', 'facebook', 'contact_channel', 'id_card_number', 'id_card_issued_on', 'emergency_contact', 'hometown', 'current_address', 'graduation_school', 'major', 'certificates', 'teaching_level', 'staff_note']],
    salary: ['Hợp đồng & Lương', 'payments', ['contract_type', 'base_salary', 'hourly_rate', 'joined_on', 'bank_account', 'contract_start_date', 'contract_end_date', 'contract_file']],
};
const contractTypes = [
    { value: 'Toàn thời gian', label: 'Toàn thời gian (Fulltime)' },
    { value: 'Bán thời gian', label: 'Bán thời gian (Parttime)' },
    { value: 'Thử việc', label: 'Thử việc' },
    { value: 'Cộng tác viên', label: 'Cộng tác viên / Trợ giảng' },
];

const errors = computed(() => {
    const own = form?.errors ?? {};
    return Object.keys(own).length ? own : (page.props.errors ?? {});
});
const tabHasError = (key) => Object.keys(errors.value).some((e) => tabs[key][2].some((f) => e === f || e.startsWith(f + '.')));
const firstErrorTab = () => Object.keys(tabs).find((key) => tabHasError(key)) ?? null;

// Học thuật: option "Kiêm nhiệm giảng dạy" hiện ngay dưới ô vai trò khi chọn vai trò chính Học thuật.
const role = ref(props.currentRole ?? '');

// Nhân sự làm việc ở nhiều cơ sở: 1 cơ sở chính (chấm công mặc định, lương, báo cáo) + các cơ sở làm việc thêm.
const primaryBranch = ref(props.user?.branch_id ?? '');
const extraBranches = ref((props.user?.extra_branch_ids ?? []).map(Number));
const extraBranchOptions = computed(() => props.branches.filter((b) => String(b.value) !== String(primaryBranch.value)));
const extraBranchErrors = computed(() => Object.entries(errors.value)
    .filter(([key]) => key === 'extra_branch_ids' || key.startsWith('extra_branch_ids.'))
    .map(([, message]) => message));
const teaching = ref(!!props.user?.academic_teaching);

const tab = ref(firstErrorTab() ?? (tabs[props.initialTab] ? props.initialTab : 'account'));
watch(errors, () => {
    const withError = firstErrorTab();
    if (withError) tab.value = withError;
});

const concurrentErrors = computed(() => Object.entries(errors.value)
    .filter(([key]) => key === 'concurrent_roles' || key.startsWith('concurrent_roles.'))
    .map(([, message]) => message));

function onInvalid(event) {
    const panel = event.target.closest?.('[data-tab-panel]');
    if (panel) tab.value = panel.dataset.tabPanel;
}

onMounted(() => {
    const target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
    const panel = target?.closest('[data-tab-panel]');
    if (panel) tab.value = panel.dataset.tabPanel;
});
</script>

<template>
    <div class="space-y-md" @invalid.capture="onInvalid">
        <div role="tablist" aria-label="Nhóm thông tin người dùng" class="no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest">
            <button
                v-for="([label, icon], key) in tabs"
                :key="key"
                type="button"
                role="tab"
                :id="fid('tab-' + key)"
                :aria-controls="fid('panel-' + key)"
                :data-tab="key"
                :aria-selected="(tab === key).toString()"
                :class="['-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-sm font-body-medium text-body-medium transition-colors', tab === key ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary']"
                @click="tab = key"
            >
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ icon }}</span>{{ label }}
                <span v-if="tabHasError(key)" class="h-2 w-2 rounded-full bg-error" title="Tab có lỗi cần sửa"></span>
            </button>
        </div>

        <!-- Tab 1: Tài khoản -->
        <div v-show="tab === 'account'" role="tabpanel" :id="fid('panel-account')" :aria-labelledby="fid('tab-account')" data-tab-panel="account" class="space-y-md">
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiInput name="name" :id="fid('name')" label="Họ và tên" required :value="user?.name" placeholder="Họ và tên đầy đủ" />
                <!-- Mã nhân viên do hệ thống tự cấp (MESxxx) — chỉ hiển thị, không có name nên không gửi đi. -->
                <UiInput :id="fid('employee_code')" label="Mã nhân viên (dùng để đăng nhập)" :value="user?.employee_code ?? 'Tự cấp khi lưu'" hint="Hệ thống tự cấp theo dạng MESxxx" disabled class="font-code" />
                <UiInput name="email" :id="fid('email')" type="email" label="Email công việc" required :value="user?.email" placeholder="VD: nva@menglish.edu.vn" />
                <UiInput name="phone" :id="fid('phone')" label="Số điện thoại" :value="user?.phone" placeholder="10 số, bắt đầu bằng 0" class="font-code" />
                <UiSelect v-model="primaryBranch" name="branch_id" :id="fid('branch_id')" label="Cơ sở chính" :required="role !== 'assistant'" :hint="role === 'assistant' ? 'Trợ giảng không bắt buộc cơ sở' : null" placeholder="-- Chọn cơ sở --" :options="branches" />
                <UiSelect v-model="role" name="role" :id="fid('role')" label="Vai trò & Chức vụ" required placeholder="-- Chọn vai trò --" :options="roleOptions" />
            </div>

            <!-- Cơ sở làm việc thêm (user_branches): nhân sự dạy / làm việc ở nhiều cơ sở. -->
            <fieldset v-if="branches.length > 1" class="rounded-lg border border-outline-variant p-md" data-extra-branches>
                <input type="hidden" name="extra_branch_ids_present" value="1" />
                <legend class="px-xs font-label text-label uppercase text-on-surface-variant">Cơ sở làm việc thêm</legend>
                <p class="mb-sm font-caption text-caption text-on-surface-variant">Nhân sự còn làm việc ở các cơ sở này ngoài cơ sở chính: được chọn làm giáo viên / thành viên, chấm công tại cơ sở gần nhất và được quản lý cơ sở đó nhìn thấy.</p>
                <div class="grid grid-cols-1 gap-xs sm:grid-cols-2">
                    <label v-for="option in extraBranchOptions" :key="option.value" class="flex items-center gap-sm font-body-small text-body-small">
                        <input v-model="extraBranches" type="checkbox" name="extra_branch_ids[]" :value="Number(option.value)" :id="fid('extra_branch_' + option.value)" class="rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                        <span>{{ option.label }}</span>
                    </label>
                </div>
                <UiErrors class="mt-1" :messages="extraBranchErrors" />
            </fieldset>

            <!-- Học thuật kiêm nhiệm giảng dạy: phiếu lương mở thêm lương đứng lớp (% học phí theo buổi) + KPI kiêm nhiệm. -->
            <div v-if="role === 'academic_lead'" class="rounded-lg border border-outline-variant p-md" data-academic-teaching>
                <input type="hidden" name="academic_teaching" value="0" />
                <label class="flex cursor-pointer items-start gap-sm">
                    <input v-model="teaching" type="checkbox" name="academic_teaching" value="1" :id="fid('academic_teaching')" class="mt-0.5 rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                    <span>
                        <span class="block font-body-medium text-body-medium font-semibold text-on-surface">Kiêm nhiệm giảng dạy</span>
                        <span class="block font-caption text-caption text-on-surface-variant">
                            {{ teaching
                                ? 'Phiếu lương có thêm lương đứng lớp: mỗi buổi dạy được chấm công như GV part-time × % học phí theo buổi của lớp (cấu hình ở Đơn giá giáo viên, mặc định 40%), và KPI kiêm nhiệm giữ học sinh.'
                                : 'Không kiêm nhiệm: phiếu lương Học thuật giữ nguyên lương cơ bản như hiện tại.' }}
                        </span>
                    </span>
                </label>
            </div>

            <!-- Kiêm nhiệm: vai trò phụ ngoài vai trò chính (chỉ các vai trò người thao tác được phép gán). -->
            <fieldset v-if="can('user.assign_role')" class="rounded-lg border border-outline-variant p-md">
                <input type="hidden" name="concurrent_roles_present" value="1" />
                <legend class="px-xs font-label text-label uppercase text-on-surface-variant">Vai trò kiêm nhiệm</legend>
                <p class="mb-sm font-caption text-caption text-on-surface-variant">Người dùng giữ thêm các vai trò này ngoài vai trò chính (ví dụ Học vụ kiêm Trợ giảng). Quyền được cộng dồn.</p>
                <div class="grid grid-cols-1 gap-xs sm:grid-cols-2">
                    <label v-for="option in concurrentOptions" :key="option.value" class="flex items-center gap-sm font-body-small text-body-small">
                        <input type="checkbox" name="concurrent_roles[]" :value="option.value" :checked="currentConcurrent.includes(option.value)" class="rounded border-outline-variant text-primary-container focus:ring-primary-container" />
                        <span>{{ option.label }}</span>
                    </label>
                </div>
                <UiErrors class="mt-1" :messages="concurrentErrors" />
            </fieldset>

            <UiInput name="password" :id="fid('password')" type="password" :required="!user" placeholder="Tối thiểu 8 ký tự" :label="user ? 'Mật khẩu mới (để trống nếu không đổi)' : 'Mật khẩu khởi tạo'" autocomplete="new-password" />
        </div>

        <!-- Tab 2: Hồ sơ nhân sự & chuyên môn (có thể bổ sung sau khi tạo) -->
        <div v-show="tab === 'profile'" role="tabpanel" :id="fid('panel-profile')" :aria-labelledby="fid('tab-profile')" data-tab-panel="profile" class="space-y-md">
            <p class="font-caption text-caption text-on-surface-variant">Có thể để trống khi tạo mới và bổ sung khi chỉnh sửa.</p>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiDate name="date_of_birth" :id="fid('date_of_birth')" label="Ngày sinh" :value="user?.date_of_birth" />
                <UiInput name="personal_email" :id="fid('personal_email')" type="email" label="Email cá nhân" :value="user?.personal_email" placeholder="Email riêng, khác email công việc" />
                <UiInput name="facebook" :id="fid('facebook')" label="Facebook" :value="user?.facebook" placeholder="Link trang cá nhân" />
                <UiInput name="contact_channel" :id="fid('contact_channel')" label="Kênh liên lạc với quản lý nhân sự" :value="user?.contact_channel" placeholder="VD: Zalo" />
                <UiInput v-if="canEditSensitive" name="id_card_number" :id="fid('id_card_number')" label="Số CCCD (12 số)" :value="user?.id_card_number" placeholder="VD: 001201004567" class="font-code" />
                <UiDate v-if="canEditSensitive" name="id_card_issued_on" :id="fid('id_card_issued_on')" label="Ngày cấp CCCD" :value="user?.id_card_issued_on" />
                <UiInput name="emergency_contact" :id="fid('emergency_contact')" label="Liên lạc khẩn cấp (Tên & SĐT)" :value="user?.emergency_contact" placeholder="Tên người thân - SĐT" />
                <UiInput name="hometown" :id="fid('hometown')" label="Quê quán" :value="user?.hometown" placeholder="VD: Hà Nội" />
                <UiInput name="current_address" :id="fid('current_address')" label="Nơi ở hiện tại" :value="user?.current_address" placeholder="Quận/huyện, tỉnh/thành" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiInput name="graduation_school" :id="fid('graduation_school')" label="Tốt nghiệp" :value="user?.graduation_school" placeholder="VD: ĐH Sư Phạm Hà Nội" />
                <UiInput name="major" :id="fid('major')" label="Chuyên ngành" :value="user?.major" placeholder="VD: Ngôn ngữ Anh" />
                <UiInput name="certificates" :id="fid('certificates')" label="Chứng chỉ" :value="user?.certificates" placeholder="VD: IELTS 8.0, TESOL" />
            </div>
            <UiInput name="teaching_level" :id="fid('teaching_level')" label="Level giảng dạy" :value="user?.teaching_level" placeholder="VD: IELTS Intensive, Pre-G1" />
            <UiTextarea name="staff_note" :id="fid('staff_note')" label="Ghi chú" rows="2" :value="user?.staff_note" placeholder="VD: Nghỉ sinh đến 03/2027" />
        </div>

        <!-- Tab 3: Hợp đồng lao động & Lương -->
        <div v-show="tab === 'salary'" role="tabpanel" :id="fid('panel-salary')" :aria-labelledby="fid('tab-salary')" data-tab-panel="salary" class="space-y-md">
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiSelect name="contract_type" :id="fid('contract_type')" label="Loại hợp đồng" placeholder="-- Chọn loại HĐ --" :value="user?.contract_type" :options="contractTypes" />
                <UiInput v-if="canEditSensitive" name="base_salary" :id="fid('base_salary')" type="number" min="0" step="1000" label="Lương cơ bản (VNĐ)" :value="user?.base_salary" placeholder="VD: 15000000" class="font-code" />
                <UiInput v-if="canEditSensitive" name="hourly_rate" :id="fid('hourly_rate')" type="number" min="0" step="1000" label="Thù lao giờ dạy (VNĐ)" :value="user?.hourly_rate" placeholder="VD: 250000" class="font-code" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiDate name="joined_on" :id="fid('joined_on')" label="Ngày bắt đầu làm việc" :value="user?.joined_on" />
                <UiInput v-if="canEditSensitive" name="bank_account" :id="fid('bank_account')" label="TK ngân hàng nhận lương" :value="user?.bank_account" placeholder="Số TK - Ngân hàng" class="font-code" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiDate name="contract_start_date" :id="fid('contract_start_date')" label="Ngày bắt đầu hợp đồng" :value="user?.contract_start_date" />
                <UiDate name="contract_end_date" :id="fid('contract_end_date')" label="Ngày kết thúc hợp đồng" :value="user?.contract_end_date" />
            </div>
            <UiField label="File hợp đồng lao động (PDF, Word, ảnh — tối đa 10MB)" name="contract_file" :for="fid('contract_file')">
                <input type="file" :id="fid('contract_file')" name="contract_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" class="w-full font-body-small text-body-small text-on-surface file:mr-sm file:rounded-lg file:border-0 file:bg-primary-container/10 file:px-md file:py-sm file:font-semibold file:text-primary" />
                <p v-if="user?.has_contract_file" class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">description</span>
                    Đã có file hợp đồng —
                    <a :href="route('users.contract.download', user.id)" class="font-semibold text-primary hover:underline">Tải xuống</a>
                    (tải file mới sẽ thay thế file cũ)
                </p>
            </UiField>
        </div>
    </div>
</template>
