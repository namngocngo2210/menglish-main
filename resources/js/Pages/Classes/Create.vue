<script setup>
/**
 * Tạo lớp mới (mockup "Tạo lớp mới"): form 4 khối — định danh & chương trình · sĩ số & phòng học dự kiến ·
 * nhân sự phụ trách · học phí & ghi chú; cột phải nhắc lịch định kỳ xếp sau ở TKB và cho xem phòng đang có lớp nào.
 * - Phòng học lọc theo chi nhánh (chưa chọn chi nhánh → khoá ô phòng); sĩ số tối đa vượt sức chứa phòng → cảnh báo vàng, vẫn lưu được.
 * - Lớp mới chưa có ca học nên không thể trùng giờ phòng: kiểm tra trùng phòng / chuyển phòng làm ở Lịch & TKB lớp khi xếp ca.
 * - Trợ giảng không gán cố định cho lớp (làm theo ca, nhận việc qua Giao việc cho Trợ giảng) nên form không có ô TA.
 * - Chọn chương trình → điền sẵn học phí niêm yết của khóa (số thô, server validate 'numeric').
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Tạo lớp mới' } });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    courses: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
});

const branch = ref('');
const program = ref('');
const tuition = ref('');
const capacity = ref('');
const roomId = ref('');

/** Đổi chương trình → học phí = học phí niêm yết của khóa (trống nếu khóa chưa có học phí). */
function onProgramChange(value) {
    program.value = value;
    const fee = parseFloat(props.courses.find((c) => c.value === value)?.fee ?? '');
    tuition.value = isNaN(fee) ? '' : String(fee);
}

function onBranchChange(value) {
    branch.value = value;
    if (!branchRooms.value.some((r) => String(r.value) === String(roomId.value))) roomId.value = '';
}

const branchName = computed(() => props.branches.find((b) => String(b.value) === String(branch.value))?.label ?? '');
const branchRooms = computed(() => props.rooms.filter((r) => String(r.branch_id) === String(branch.value)));
const room = computed(() => branchRooms.value.find((r) => String(r.value) === String(roomId.value)) ?? null);
const overCapacity = computed(() => !!room.value?.capacity && Number(capacity.value) > room.value.capacity);
const roomPlaceholder = computed(() => (!branch.value ? '-- Vui lòng chọn Chi nhánh trước để tải danh sách phòng --' : branchRooms.value.length ? '-- Chưa gán phòng học --' : '-- Chi nhánh chưa có phòng học --'));

/** Lỗi validate → tóm tắt đầu form, mỗi dòng dẫn tới ô lỗi (ô vẫn hiện lỗi riêng). */
const fieldIds = { ten_lop: 'ten_lop', ma_lop: 'ma_lop', chi_nhanh: 'chi_nhanh', chuong_trinh: 'chuong_trinh', cap_do: 'cap_do', si_so_toi_da: 'si_so_toi_da', min_students: 'min_students', room_id: 'room_id', giao_vien_chinh: 'giao_vien_chinh', giao_vien_nn: 'giao_vien_nn', hoc_phi: 'hoc_phi', ghi_chu: 'ghi_chu' };
const errorList = (errors) => Object.entries(errors ?? {}).map(([field, message]) => ({ field, message, href: fieldIds[field] ? `#${fieldIds[field]}` : null }));

const section = 'space-y-md p-md md:p-lg';
const heading = 'flex items-center gap-sm border-b border-surface-container pb-sm font-label text-label uppercase tracking-wider text-on-surface';
const bar = 'h-4 w-1 shrink-0 rounded-full bg-primary-container';
const optional = 'font-body-small text-body-small normal-case tracking-normal text-on-surface-subtle';
</script>

<template>
    <UiPageHeader
        title="Tạo lớp mới"
        icon="group_add"
        :back="route('classes.index')"
        back-label="Danh sách lớp"
        description="Nhập thông tin nền tảng ban đầu của lớp học. Lịch học chi tiết theo tuần, ca dạy và xếp phòng định kỳ được thiết lập sau ở Lịch & TKB lớp."
    >
        <template #badges><UiBadge color="primary" :dot="false">Học vụ • Quản lý lớp</UiBadge></template>
        <template #actions>
            <UiButton variant="secondary" :href="route('classes.index')">Hủy bỏ</UiButton>
            <UiButton type="submit" form="class-create-form" icon="save">Lưu lớp mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
        <UiForm id="class-create-form" v-slot="{ errors }" :action="route('classes.store')" method="post" class="divide-y divide-surface-container overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm lg:col-span-8">
            <!-- Tóm tắt lỗi khi bấm Lưu -->
            <div v-if="errorList(errors).length" class="p-md md:px-lg" role="alert" tabindex="-1" data-testid="class-create-errors">
                <UiAlert type="error" title="Không thể lưu lớp học do thiếu thông tin:">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li v-for="e in errorList(errors)" :key="e.field">
                            <a v-if="e.href" :href="e.href" class="underline-offset-2 hover:underline">{{ e.message }}</a>
                            <span v-else>{{ e.message }}</span>
                        </li>
                    </ul>
                </UiAlert>
            </div>

            <!-- 1. Định danh & phân loại chương trình -->
            <section :class="section" aria-labelledby="class-sec-1">
                <h2 id="class-sec-1" :class="heading"><span :class="bar" aria-hidden="true"></span>1. Định danh &amp; phân loại chương trình</h2>
                <UiInput id="ten_lop" name="ten_lop" label="Tên lớp" required maxlength="255" placeholder="VD: IELTS Foundation K28" hint="Tên hiển thị công khai trên phiếu thu, báo cáo điểm và ứng dụng học viên." />
                <UiInput id="ma_lop" name="ma_lop" label="Mã lớp (Tùy chọn - Tự động tạo nếu để trống)" maxlength="50" placeholder="VD: IF-28-CG" class="font-code uppercase" />
                <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                    <UiSelect id="chi_nhanh" :model-value="branch" name="chi_nhanh" label="Chi nhánh" required placeholder="-- Chọn chi nhánh --" :options="branches" searchable @update:model-value="onBranchChange" />
                    <UiSelect id="chuong_trinh" :model-value="program" name="chuong_trinh" label="Chương trình" required placeholder="-- Chọn chương trình --" :options="courses" searchable @update:model-value="onProgramChange" />
                    <UiSelect id="cap_do" name="cap_do" label="Cấp độ" required placeholder="-- Chọn cấp độ --" :options="levels" searchable />
                </div>
            </section>

            <!-- 2. Sĩ số & phòng học dự kiến -->
            <section :class="section" aria-labelledby="class-sec-2">
                <h2 id="class-sec-2" :class="heading"><span :class="bar" aria-hidden="true"></span>2. Sĩ số &amp; phòng học dự kiến</h2>
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <div class="space-y-md">
                        <UiInput id="si_so_toi_da" v-model="capacity" type="number" name="si_so_toi_da" label="Sĩ số tối đa" required min="1" max="100" placeholder="VD: 15" suffix="học viên" hint="Giới hạn để chặn ghi danh vượt mức khi chốt xếp lớp." />
                        <UiInput id="min_students" type="number" name="min_students" label="Ngưỡng khai giảng" :value="6" min="1" max="100" suffix="học viên" hint="Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa." />
                    </div>
                    <div class="space-y-sm">
                        <UiSelect
                            id="room_id"
                            v-model="roomId"
                            name="room_id"
                            label="Phòng học (Tùy chọn)"
                            :placeholder="roomPlaceholder"
                            :options="branchRooms"
                            :disabled="!branch"
                            :hint="branch ? `Theo ${branchName}` : 'Mỗi phòng gắn với cơ sở vật chất của một chi nhánh, danh sách phòng lọc theo chi nhánh đã chọn.'"
                        />
                        <div v-if="overCapacity" class="flex gap-sm rounded-lg border border-warning/30 bg-warning-container p-sm font-body-small text-body-small text-on-warning-container" role="status" data-testid="room-capacity-warning">
                            <span class="material-symbols-outlined text-[18px] text-warning" aria-hidden="true">warning</span>
                            <p><strong>Cảnh báo sức chứa:</strong> Sĩ số tối đa ({{ capacity }}) vượt sức chứa phòng ({{ room.capacity }}). Vẫn được phép lưu nhưng cần lưu ý kê thêm ghế phụ hoặc tách nhóm.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. Nhân sự phụ trách -->
            <section :class="section" aria-labelledby="class-sec-3">
                <h2 id="class-sec-3" :class="heading"><span :class="bar" aria-hidden="true"></span>3. Nhân sự phụ trách <span :class="optional">(Tùy chọn)</span></h2>
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <UiSelect id="giao_vien_chinh" name="giao_vien_chinh" label="Giáo viên chính" placeholder="-- Chưa chỉ định --" :options="teachers" searchable />
                    <!-- GVNN không cố định: đổi theo từng buổi ở tab Lịch & buổi học. -->
                    <UiSelect id="giao_vien_nn" name="giao_vien_nn" label="Giáo viên nước ngoài" placeholder="-- Chưa chỉ định --" :options="teachers" searchable hint="GVNN không cố định: sau khi tạo lớp có thể gán / đổi GVNN cho từng buổi ở tab Lịch & buổi học." />
                </div>
                <!-- Trợ giảng không cố định theo lớp: làm theo ca, nhận việc qua "Giao việc cho Trợ giảng". -->
                <p class="flex items-start gap-sm rounded-lg border border-outline-variant bg-surface-container-low p-sm font-body-small text-body-small text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">support_agent</span>
                    <span>Trợ giảng không gán cố định cho lớp: trợ giảng làm theo ca và nhận việc của lớp qua <strong>Công việc → Giao việc cho Trợ giảng</strong>.</span>
                </p>
            </section>

            <!-- 4. Học phí & ghi chú vận hành -->
            <section :class="section" aria-labelledby="class-sec-4">
                <h2 id="class-sec-4" :class="heading"><span :class="bar" aria-hidden="true"></span>4. Mức học phí &amp; ghi chú vận hành</h2>
                <UiInput
                    id="hoc_phi"
                    v-model="tuition"
                    type="number"
                    name="hoc_phi"
                    label="Mức học phí niêm yết (Tùy chọn - Có thể cập nhật theo đợt thu)"
                    suffix="VNĐ/khóa"
                    min="0"
                    step="50000"
                    placeholder="0"
                    class="font-code font-semibold"
                    :hint="tuition !== '' && !isNaN(Number(tuition)) ? `= ${formatMoney(Number(tuition))}` : 'Đơn giá trọn khóa trước khi áp dụng ưu đãi / học bổng.'"
                />
                <UiTextarea id="ghi_chu" name="ghi_chu" label="Ghi chú nội bộ (Tùy chọn)" :rows="3" placeholder="Ghi chú về yêu cầu đầu vào, lớp liên kết doanh nghiệp hoặc lưu ý cho giáo viên phụ trách..." />
            </section>

            <div class="flex flex-col-reverse gap-sm bg-surface-container-low p-md sm:flex-row sm:items-center sm:justify-between md:px-lg">
                <p class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                    Dấu (<span class="font-bold text-error">*</span>) là thông tin bắt buộc phải nhập.
                </p>
                <div class="flex gap-sm">
                    <UiButton variant="secondary" :href="route('classes.index')" class="flex-1 sm:flex-none">Hủy</UiButton>
                    <UiButton type="submit" icon="check_circle" class="flex-1 sm:flex-none">Lưu lớp học</UiButton>
                </div>
            </div>
        </UiForm>

        <!-- Cột phải: lịch định kỳ xếp sau + phòng đang có lớp nào -->
        <aside class="space-y-md lg:sticky lg:top-md lg:col-span-4">
            <div class="flex gap-sm rounded-xl border border-secondary/20 bg-secondary-fixed/40 p-md">
                <span class="material-symbols-outlined text-secondary" aria-hidden="true">event_busy</span>
                <div>
                    <h2 class="font-body-medium text-body-medium font-semibold text-on-surface">Tách bạch lịch định kỳ</h2>
                    <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Màn hình này chỉ nhận thông tin cốt lõi của lớp, không có ngày khai giảng, thứ trong tuần hay khung giờ học. Lịch định kỳ được xếp ở <strong>Lịch &amp; TKB lớp</strong> sau khi lớp được tạo.</p>
                </div>
            </div>

            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md" data-testid="room-usage-panel">
                <h2 class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">meeting_room</span>
                    Lịch dùng phòng
                </h2>
                <p v-if="!branch" class="mt-xs font-body-small text-body-small text-on-surface-variant">Chọn chi nhánh để tải danh sách phòng học của cơ sở.</p>
                <p v-else-if="!room" class="mt-xs font-body-small text-body-small text-on-surface-variant">Chọn phòng để xem các lớp đang dùng phòng. Có thể để trống và gán phòng sau.</p>
                <template v-else>
                    <p class="mt-xs font-body-small text-body-small text-on-surface">
                        <strong>{{ room.name }}</strong><template v-if="room.capacity"> · {{ room.capacity }} chỗ</template>
                    </p>
                    <ul v-if="room.classes.length" class="mt-sm space-y-xs">
                        <li v-for="c in room.classes" :key="c.id" class="font-body-small text-body-small">
                            <UiBadge :color="c.studying ? 'success' : 'warning'" :dot="false">{{ c.studying ? 'Đang học' : 'Chưa bắt đầu' }}</UiBadge>
                            Lớp <strong>{{ c.name }}</strong>
                            <span class="text-on-surface-variant">{{ c.slots.length ? c.slots.join(' · ') : '— chưa có lịch' }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-sm font-body-small text-body-small text-on-surface-variant">Chưa có lớp nào dùng phòng này.</p>
                    <p v-if="room.classes.length" class="mt-sm font-caption text-caption text-on-surface-variant">Khi xếp ca ở Lịch &amp; TKB lớp: trùng giờ với lớp đang học thì bị chặn; trùng lớp chưa bắt đầu học thì được hỏi chuyển phòng.</p>
                </template>
            </div>

            <div class="flex gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">lightbulb</span>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Sau khi bấm <strong class="text-on-surface">“Lưu lớp mới”</strong>, lớp ở trạng thái <em>Chờ lịch</em> và mở tab <strong class="text-on-surface">Lịch &amp; buổi học</strong> để xếp lịch định kỳ; học viên đã chốt được xếp vào lớp từ <strong class="text-on-surface">Chờ xếp lớp</strong>.
                </p>
            </div>
        </aside>
    </div>
</template>
