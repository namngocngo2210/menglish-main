<x-app-layout>
    <x-ui.page-header title="Tạo lớp mới" icon="group_add" :back="route('classes.index')" back-label="Danh sách lớp" />


    <div class="space-y-6">

        {{-- Main Form Card --}}
        <form action="{{ route('classes.store') }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden divide-y divide-surface-container-highest">
            @csrf

            {{-- Khối 1: Thông tin cơ bản & Phân loại lớp (Bắt buộc) --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-primary-container"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            1. Thông tin cơ bản &amp; Phân loại
                        </h2>
                    </div>
                    <span class="text-xs text-on-surface-variant/70 font-medium italic">
                        (<span class="text-error font-bold">*</span>) Trường bắt buộc nhập
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Tên lớp (Bắt buộc) --}}
                    <div class="md:col-span-8">
                        <x-ui.input id="ten_lop" name="ten_lop" label="Tên lớp học" required placeholder="VD: ENG-B1 · IELTS Căn Bản K26" class="text-xs"
                                    hint="Tên hiển thị rõ ràng trên sổ điểm danh và cổng giáo viên." />
                    </div>

                    {{-- Mã lớp (Tùy chọn) --}}
                    <div class="md:col-span-4">
                        <x-ui.input id="ma_lop" name="ma_lop" label="Mã lớp (Tùy chọn)" placeholder="VD: ENG-B1-K26" class="text-xs font-mono uppercase"
                                    hint="Để trống hệ thống sẽ tự sinh theo quy tắc." />
                    </div>

                    {{-- Chi nhánh (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="chi_nhanh" name="chi_nhanh" label="Chi nhánh đào tạo" required class="text-xs cursor-pointer">
                            <option value="" disabled {{ old('chi_nhanh') ? '' : 'selected' }}>-- Chọn chi nhánh --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('chi_nhanh') == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }} ({{ $b->code }})
                                </option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Chương trình (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="chuong_trinh" name="chuong_trinh" label="Chương trình học" required class="text-xs cursor-pointer">
                            <option value="" disabled {{ old('chuong_trinh') ? '' : 'selected' }}>-- Chọn chương trình học --</option>
                            @foreach($courses as $c)
                                <option value="{{ $c->name }}" data-fee="{{ $c->tuition_fee }}" {{ old('chuong_trinh') === $c->name ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Cấp độ (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="cap_do" name="cap_do" label="Cấp độ" required class="text-xs cursor-pointer">
                            <option value="" disabled {{ old('cap_do') ? '' : 'selected' }}>-- Chọn cấp độ --</option>
                            @foreach($levels as $lvl)
                                <option value="{{ $lvl->code }}" {{ old('cap_do') === $lvl->code ? 'selected' : '' }}>{{ $lvl->name }} ({{ $lvl->target }})</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Sĩ số tối đa (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.input type="number" id="si_so_toi_da" name="si_so_toi_da" label="Sĩ số tối đa" required
                                    hint="Giới hạn số học viên xếp lớp tối đa." suffix="học viên"
                                    min="1" max="100" placeholder="VD: 16" class="text-xs" />
                    </div>

                    {{-- Ngưỡng khai giảng (số học viên tối thiểu để mở lớp) --}}
                    <div class="md:col-span-4">
                        <x-ui.input type="number" id="min_students" name="min_students" label="Ngưỡng khai giảng" :value="6"
                                    hint="Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa." suffix="học viên"
                                    min="1" max="100" class="text-xs" />
                    </div>

                    {{-- Trạng thái khởi tạo (Readonly indicator) --}}
                    <div class="md:col-span-12 flex items-center">
                        <div class="w-full bg-surface-container-low border border-surface-container-highest rounded-xl px-4 py-2.5 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Trạng thái lớp ban đầu:</span>
                                <x-ui.badge color="warning">Chưa cấu hình lịch (Khởi tạo)</x-ui.badge>
                            </div>
                            <span class="text-[11px] text-on-surface-variant/70 italic">Tự động kích hoạt khi xếp ca ở TKB</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Khối 2: Phòng học & Nhân sự giảng dạy (Tùy chọn - có thể để trống gán sau) --}}
            <div class="p-6 md:p-8 space-y-6 bg-surface-container-low/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            2. Phòng học &amp; Đội ngũ phụ trách
                        </h2>
                    </div>
                    <x-ui.badge :dot="false">Tùy chọn • Để trống gán sau</x-ui.badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Phòng học (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="phong_hoc" name="phong_hoc" label="Phòng học dự kiến" class="text-xs cursor-pointer" placeholder="-- Chưa gán phòng (để trống) --"
                                     hint="Danh sách phòng hiện có của trung tâm (dùng chung các chi nhánh)."
                                     :options="['P101' => 'Phòng 101 (Sức chứa 20 - Tầng 1)', 'P202' => 'Phòng 202 (Sức chứa 16 - Tầng 2)', 'P302' => 'Phòng 302 (Sức chứa 18 - Tầng 3)', 'LAB_A' => 'Phòng Lab A (Sức chứa 24 - Tầng 4)', 'LAB_B' => 'Phòng Lab B (Sức chứa 24 - Tầng 4)']" />
                    </div>

                    {{-- Giáo viên chính (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="giao_vien_chinh" name="giao_vien_chinh" label="Giáo viên chính" class="text-xs cursor-pointer" placeholder="-- Chưa gán giáo viên chính (để trống) --"
                                     :options="$teachers->mapWithKeys(fn ($t) => [$t->id => $t->name . ' (' . $t->email . ')'])" />
                    </div>

                    {{-- Giáo viên nước ngoài (GVNN) (Tùy chọn) — không cố định: đổi theo từng buổi ở tab Lịch & buổi học. --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="giao_vien_nn" name="giao_vien_nn" label="GVNN mặc định" class="text-xs cursor-pointer" placeholder="-- Không áp dụng hoặc gán sau (để trống) --"
                                     hint="GVNN không cố định: sau khi tạo lớp có thể gán / đổi GVNN cho từng buổi ở tab Lịch & buổi học."
                                     :options="$foreignTeachers->mapWithKeys(fn ($teacher) => [$teacher->id => $teacher->name . ' (' . $teacher->email . ')'])" />
                    </div>

                    {{-- Trợ giảng không cố định theo lớp: làm theo ca, nhận việc qua "Giao việc trợ giảng". --}}
                    <div class="md:col-span-12 flex items-start gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest p-3 text-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">support_agent</span>
                        <span>Trợ giảng không gán cố định cho lớp: trợ giảng làm theo ca và nhận việc của lớp qua <strong>Công việc → Giao việc cho Trợ giảng</strong> (chọn ngày, ca, gắn lớp/buổi).</span>
                    </div>
                </div>
            </div>

            {{-- Khối 3: Học phí & Ghi chú quản lý (Tùy chọn) --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-tertiary"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            3. Học phí &amp; Ghi chú nội bộ
                        </h2>
                    </div>
                    <x-ui.badge :dot="false">Tùy chọn</x-ui.badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Học phí (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.input type="number" id="hoc_phi" name="hoc_phi" label="Mức học phí niêm yết" suffix="VNĐ"
                                    hint="Đơn giá trọn khóa trước khi áp dụng ưu đãi/học bổng."
                                    placeholder="VD: 8500000" min="0" step="50000" class="text-xs font-mono font-bold" />
                    </div>

                    {{-- Ghi chú (Tùy chọn) --}}
                    <div class="md:col-span-12">
                        <x-ui.textarea id="ghi_chu" name="ghi_chu" label="Ghi chú vận hành (Tùy chọn)" rows="3" class="text-xs"
                                       placeholder="Ghi chú thêm về yêu cầu đầu vào, lớp liên kết doanh nghiệp hoặc lưu ý đặc biệt cho giáo viên phụ trách..." />
                    </div>
                </div>
            </div>

            {{-- Action Footer (Buttons) --}}
            <div class="p-6 bg-surface-container-low border-t border-surface-container-highest flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-on-surface-variant text-center sm:text-left">
                    Sau khi bấm <strong class="text-on-surface">"Lưu &amp; mở Trang lớp"</strong>, lớp ở trạng thái chờ cấu hình lịch; lịch học xếp ở màn <strong class="text-on-surface">Lịch &amp; TKB lớp</strong> (nút "Cấu hình lịch" trên Trang lớp).
                </div>

                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <x-ui.button variant="secondary" :href="route('classes.index')" class="w-full sm:w-auto">Hủy bỏ</x-ui.button>
                    <x-ui.button type="submit" icon="save" class="w-full sm:w-auto">Lưu &amp; mở Trang lớp</x-ui.button>
                </div>
            </div>
        </form>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const courseSelect = document.getElementById('chuong_trinh');
        const tuitionInput = document.getElementById('hoc_phi');

        if (courseSelect && tuitionInput) {
            courseSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const fee = selectedOption.getAttribute('data-fee');
                if (fee) {
                    // Điền số thô (VD: 8500000) — server validate 'numeric', không nhận dấu chấm ngăn cách hàng nghìn.
                    const parsed = parseFloat(fee);
                    tuitionInput.value = isNaN(parsed) ? '' : String(parsed);
                } else {
                    tuitionInput.value = '';
                }
            });
        }
    });
</script>

</x-app-layout>
