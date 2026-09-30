<script setup>
/**
 * Sửa thông tin lớp: cùng 3 khối như form tạo lớp + trạng thái, ngày khai giảng / kết thúc, lịch học mô tả ngắn.
 * Trợ giảng cố định chỉ còn ở lớp cũ (dữ liệu cũ) → hiện ô để gỡ; lớp mới nhận trợ giảng theo ca qua "Giao việc trợ giảng".
 */
defineOptions({ layout: { title: 'Chỉnh sửa lớp học' } });

defineProps({
    klass: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    programs: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    foreignTeachers: { type: Array, default: () => [] },
    assistants: { type: Array, default: () => [] },
});

const statuses = [
    { value: 'pending_schedule', label: 'Chờ cấu hình lịch' },
    { value: 'active', label: 'Đang hoạt động' },
    { value: 'completed', label: 'Đã kết thúc' },
    { value: 'cancelled', label: 'Đã hủy' },
];
const section = 'space-y-6 p-6 md:p-8';
const dot = 'h-2.5 w-2.5 rounded-full';
const heading = 'text-base font-bold uppercase tracking-wide text-on-surface';
</script>

<template>
    <UiPageHeader title="Chỉnh sửa lớp học" icon="edit" :back="route('classes.show', klass.id)">
        <template #badges>
            <span class="rounded border border-primary-container/30 bg-primary-container/10 px-2 py-0.5 font-mono text-xs font-bold text-primary">{{ klass.code }}</span>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="list" :href="route('classes.index')">Danh sách lớp</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <UiForm :action="route('classes.update', klass.id)" method="put" class="divide-y divide-surface-container-highest overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <!-- Khối 1: Thông tin cơ bản & Phân loại lớp -->
            <div :class="section">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div :class="[dot, 'bg-primary-container']"></div>
                        <h2 :class="heading">1. Thông tin cơ bản &amp; Phân loại</h2>
                    </div>
                    <span class="text-xs font-medium italic text-on-surface-subtle">(<span class="font-bold text-error">*</span>) Trường bắt buộc</span>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-12">
                    <div class="md:col-span-8">
                        <UiInput id="ten_lop" name="ten_lop" label="Tên lớp học" required :value="klass.name" class="text-xs" />
                    </div>
                    <div class="md:col-span-4">
                        <UiInput id="ma_lop" name="ma_lop" label="Mã lớp (Tùy chọn)" :value="klass.code" class="font-mono text-xs uppercase" />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="chi_nhanh" name="chi_nhanh" label="Chi nhánh đào tạo" required :value="klass.branch_id" :options="branches" />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="chuong_trinh" name="chuong_trinh" label="Chương trình học" required :value="klass.program" :options="programs" />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="cap_do" name="cap_do" label="Cấp độ" required :value="klass.level" :options="levels" />
                    </div>
                    <div class="md:col-span-4">
                        <UiInput id="si_so_toi_da" type="number" name="si_so_toi_da" label="Sĩ số tối đa" required suffix="học viên" :value="klass.max_capacity" min="1" max="100" class="text-xs" />
                    </div>
                    <div class="md:col-span-4">
                        <UiInput id="min_students" type="number" name="min_students" label="Ngưỡng khai giảng" suffix="học viên" :value="klass.min_students" min="1" max="100" class="text-xs" hint="Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa." />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="status" name="status" label="Trạng thái lớp" :value="klass.status" :options="statuses" />
                    </div>
                    <div class="md:col-span-4">
                        <UiDate id="start_date" name="start_date" label="Ngày khai giảng" :value="klass.start_date" class="text-xs" />
                    </div>
                    <div class="md:col-span-4">
                        <UiDate id="end_date" name="end_date" label="Ngày kết thúc" :value="klass.end_date" class="text-xs" />
                    </div>
                    <div class="md:col-span-12">
                        <UiInput id="schedule_text" name="schedule_text" label="Lịch học (mô tả ngắn)" :value="klass.schedule_text" placeholder="VD: T2-T4-T6 18:00-20:00" class="text-xs" hint="Dùng để hiển thị trên danh sách lớp và đặt lịch học thử." />
                    </div>
                </div>
            </div>

            <!-- Khối 2: Phòng học & Đội ngũ -->
            <div :class="[section, 'bg-surface-container-low/40']">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div :class="[dot, 'bg-secondary']"></div>
                        <h2 :class="heading">2. Phòng học &amp; Đội ngũ phụ trách</h2>
                    </div>
                    <UiBadge :dot="false">Tùy chọn</UiBadge>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-12">
                    <div class="md:col-span-6">
                        <UiSelect id="phong_hoc" name="phong_hoc" label="Phòng học" placeholder="-- Chưa gán phòng --" :value="klass.room" :options="rooms" />
                    </div>
                    <div class="md:col-span-6">
                        <UiSelect id="giao_vien_chinh" name="giao_vien_chinh" label="Giáo viên chính" placeholder="-- Chưa gán giáo viên --" :value="klass.teacher_id" :options="teachers" />
                    </div>
                    <div class="md:col-span-6">
                        <UiSelect
                            id="giao_vien_nn"
                            name="giao_vien_nn"
                            label="GVNN mặc định"
                            placeholder="-- Không áp dụng --"
                            :value="klass.foreign_teacher_id"
                            hint="Đổi ở đây áp cho các buổi sắp tới đang theo GVNN mặc định; buổi đã gán GVNN riêng giữ nguyên. Gán theo từng buổi ở tab Lịch & buổi học."
                            :options="foreignTeachers"
                        />
                    </div>

                    <!-- Trợ giảng không cố định theo lớp. Lớp cũ còn trợ giảng cố định thì hiện ô này để gỡ. -->
                    <div v-if="klass.assistant_id" class="md:col-span-6">
                        <UiSelect
                            id="tro_giang"
                            name="tro_giang"
                            label="Trợ giảng cố định (dữ liệu cũ)"
                            placeholder="-- Gỡ trợ giảng cố định --"
                            :value="klass.assistant_id"
                            hint="Trợ giảng nay làm theo ca và giao việc; chọn “Gỡ” để bỏ gán cố định khỏi lớp và các buổi sắp tới."
                            :options="assistants"
                        />
                    </div>
                    <div v-else class="flex items-start gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 text-xs text-on-surface-variant md:col-span-6">
                        <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">support_agent</span>
                        <span>Trợ giảng không gán cố định cho lớp: làm theo ca và nhận việc qua <strong>Công việc → Giao việc cho Trợ giảng</strong>.</span>
                    </div>
                </div>
            </div>

            <!-- Khối 3: Học phí & Ghi chú -->
            <div :class="section">
                <div class="flex items-center gap-2">
                    <div :class="[dot, 'bg-tertiary']"></div>
                    <h2 :class="heading">3. Học phí &amp; Ghi chú nội bộ</h2>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-12">
                    <div class="md:col-span-6">
                        <UiInput id="hoc_phi" type="number" name="hoc_phi" label="Mức học phí niêm yết" suffix="VNĐ" :value="klass.tuition_fee" min="0" step="50000" class="font-mono text-xs font-bold" />
                    </div>
                    <div class="md:col-span-12">
                        <UiTextarea id="ghi_chu" name="ghi_chu" label="Ghi chú vận hành" :rows="3" :value="klass.notes" class="text-xs" />
                    </div>
                </div>
            </div>

            <div class="flex flex-col items-center justify-between gap-4 border-t border-surface-container-highest bg-surface-container-low p-6 sm:flex-row">
                <div class="text-xs text-on-surface-variant">
                    Cập nhật lần cuối: <strong class="text-on-surface">{{ klass.updated_at }}</strong>
                </div>
                <div class="flex items-center gap-3">
                    <UiButton variant="secondary" :href="route('classes.show', klass.id)">Hủy</UiButton>
                    <UiButton type="submit" variant="info" icon="save">Lưu thay đổi</UiButton>
                </div>
            </div>
        </UiForm>
    </div>
</template>
