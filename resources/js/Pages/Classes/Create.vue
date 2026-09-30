<script setup>
/**
 * Tạo lớp mới: 3 khối (thông tin cơ bản · phòng học & đội ngũ · học phí & ghi chú).
 * Chọn chương trình → điền sẵn học phí niêm yết của khóa (số thô, server validate 'numeric').
 * Lưu xong mở Trang lớp; lịch học xếp sau ở màn Lịch & TKB lớp.
 */
import { ref } from 'vue';

defineOptions({ layout: { title: 'Tạo lớp mới' } });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    courses: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
});

const program = ref('');
const tuition = ref('');

/** Đổi chương trình → học phí = học phí niêm yết của khóa (trống nếu khóa chưa có học phí). */
function onProgramChange(value) {
    program.value = value;
    const fee = parseFloat(props.courses.find((c) => c.value === value)?.fee ?? '');
    tuition.value = isNaN(fee) ? '' : String(fee);
}

const section = 'space-y-6 p-6 md:p-8';
const dot = 'h-2.5 w-2.5 rounded-full';
const heading = 'text-base font-bold uppercase tracking-wide text-on-surface';
</script>

<template>
    <UiPageHeader title="Tạo lớp mới" icon="group_add" :back="route('classes.index')" back-label="Danh sách lớp" />

    <div class="space-y-6">
        <UiForm :action="route('classes.store')" method="post" class="divide-y divide-surface-container-highest overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <!-- Khối 1: Thông tin cơ bản & Phân loại lớp (Bắt buộc) -->
            <div :class="section">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div :class="[dot, 'bg-primary-container']"></div>
                        <h2 :class="heading">1. Thông tin cơ bản &amp; Phân loại</h2>
                    </div>
                    <span class="text-xs font-medium italic text-on-surface-subtle">(<span class="font-bold text-error">*</span>) Trường bắt buộc nhập</span>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-12">
                    <div class="md:col-span-8">
                        <UiInput id="ten_lop" name="ten_lop" label="Tên lớp học" required placeholder="VD: ENG-B1 · IELTS Căn Bản K26" class="text-xs" hint="Tên hiển thị rõ ràng trên sổ điểm danh và cổng giáo viên." />
                    </div>
                    <div class="md:col-span-4">
                        <UiInput id="ma_lop" name="ma_lop" label="Mã lớp (Tùy chọn)" placeholder="VD: ENG-B1-K26" class="font-mono text-xs uppercase" hint="Để trống hệ thống sẽ tự sinh theo quy tắc." />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="chi_nhanh" name="chi_nhanh" label="Chi nhánh đào tạo" required placeholder="-- Chọn chi nhánh --" :options="branches" />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="chuong_trinh" :model-value="program" name="chuong_trinh" label="Chương trình học" required placeholder="-- Chọn chương trình học --" :options="courses" @update:model-value="onProgramChange" />
                    </div>
                    <div class="md:col-span-4">
                        <UiSelect id="cap_do" name="cap_do" label="Cấp độ" required placeholder="-- Chọn cấp độ --" :options="levels" />
                    </div>
                    <div class="md:col-span-4">
                        <UiInput id="si_so_toi_da" type="number" name="si_so_toi_da" label="Sĩ số tối đa" required hint="Giới hạn số học viên xếp lớp tối đa." suffix="học viên" min="1" max="100" placeholder="VD: 16" class="text-xs" />
                    </div>
                    <div class="md:col-span-4">
                        <UiInput id="min_students" type="number" name="min_students" label="Ngưỡng khai giảng" :value="6" hint="Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa." suffix="học viên" min="1" max="100" class="text-xs" />
                    </div>
                    <div class="flex items-center md:col-span-12">
                        <div class="flex w-full items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Trạng thái lớp ban đầu:</span>
                                <UiBadge color="warning">Chưa cấu hình lịch (Khởi tạo)</UiBadge>
                            </div>
                            <span class="text-xs italic text-on-surface-subtle">Tự động kích hoạt khi xếp ca ở TKB</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Khối 2: Phòng học & Nhân sự giảng dạy (Tùy chọn - có thể để trống gán sau) -->
            <div :class="[section, 'bg-surface-container-low/40']">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div :class="[dot, 'bg-secondary']"></div>
                        <h2 :class="heading">2. Phòng học &amp; Đội ngũ phụ trách</h2>
                    </div>
                    <UiBadge :dot="false">Tùy chọn • Để trống gán sau</UiBadge>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-12">
                    <div class="md:col-span-6">
                        <UiSelect id="phong_hoc" name="phong_hoc" label="Phòng học dự kiến" placeholder="-- Chưa gán phòng (để trống) --" hint="Danh sách phòng hiện có của trung tâm (dùng chung các chi nhánh)." :options="rooms" />
                    </div>
                    <div class="md:col-span-6">
                        <UiSelect id="giao_vien_chinh" name="giao_vien_chinh" label="Giáo viên chính" placeholder="-- Chưa gán giáo viên chính (để trống) --" :options="teachers" />
                    </div>
                    <!-- GVNN không cố định: đổi theo từng buổi ở tab Lịch & buổi học. -->
                    <div class="md:col-span-6">
                        <UiSelect
                            id="giao_vien_nn"
                            name="giao_vien_nn"
                            label="GVNN mặc định"
                            placeholder="-- Không áp dụng hoặc gán sau (để trống) --"
                            hint="GVNN không cố định: sau khi tạo lớp có thể gán / đổi GVNN cho từng buổi ở tab Lịch & buổi học."
                            :options="teachers"
                        />
                    </div>
                    <!-- Trợ giảng không cố định theo lớp: làm theo ca, nhận việc qua "Giao việc trợ giảng". -->
                    <div class="flex items-start gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 text-xs text-on-surface-variant md:col-span-12">
                        <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">support_agent</span>
                        <span>Trợ giảng không gán cố định cho lớp: trợ giảng làm theo ca và nhận việc của lớp qua <strong>Công việc → Giao việc cho Trợ giảng</strong> (chọn ngày, ca, gắn lớp/buổi).</span>
                    </div>
                </div>
            </div>

            <!-- Khối 3: Học phí & Ghi chú quản lý (Tùy chọn) -->
            <div :class="section">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div :class="[dot, 'bg-tertiary']"></div>
                        <h2 :class="heading">3. Học phí &amp; Ghi chú nội bộ</h2>
                    </div>
                    <UiBadge :dot="false">Tùy chọn</UiBadge>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-12">
                    <div class="md:col-span-6">
                        <UiInput id="hoc_phi" v-model="tuition" type="number" name="hoc_phi" label="Mức học phí niêm yết" suffix="VNĐ" hint="Đơn giá trọn khóa trước khi áp dụng ưu đãi/học bổng." placeholder="VD: 8500000" min="0" step="50000" class="font-mono text-xs font-bold" />
                    </div>
                    <div class="md:col-span-12">
                        <UiTextarea id="ghi_chu" name="ghi_chu" label="Ghi chú vận hành (Tùy chọn)" :rows="3" class="text-xs" placeholder="Ghi chú thêm về yêu cầu đầu vào, lớp liên kết doanh nghiệp hoặc lưu ý đặc biệt cho giáo viên phụ trách..." />
                    </div>
                </div>
            </div>

            <div class="flex flex-col items-center justify-between gap-4 border-t border-surface-container-highest bg-surface-container-low p-6 sm:flex-row">
                <div class="text-center text-xs text-on-surface-variant sm:text-left">
                    Sau khi bấm <strong class="text-on-surface">"Lưu &amp; mở Trang lớp"</strong>, lớp ở trạng thái chờ cấu hình lịch; lịch học xếp ở màn <strong class="text-on-surface">Lịch &amp; TKB lớp</strong> (nút "Cấu hình lịch" trên Trang lớp).
                </div>
                <div class="flex w-full items-center gap-3 sm:w-auto">
                    <UiButton variant="secondary" :href="route('classes.index')" class="w-full sm:w-auto">Hủy bỏ</UiButton>
                    <UiButton type="submit" icon="save" class="w-full sm:w-auto">Lưu &amp; mở Trang lớp</UiButton>
                </div>
            </div>
        </UiForm>
    </div>
</template>
