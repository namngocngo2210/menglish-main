<x-app-layout>
    <x-ui.page-header title="Chỉnh sửa lớp học" icon="edit" :back="route('classes.show', $class->id)">
        <x-slot:badges>
            <span class="font-mono text-xs font-bold text-primary bg-primary-container/10 px-2 py-0.5 rounded border border-primary-container/30">{{ $class->code }}</span>
        </x-slot:badges>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="list" :href="route('classes.index')">Danh sách lớp</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($errors->any())
        <x-ui.alert type="error" class="max-w-4xl mx-auto mb-4 text-xs">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="max-w-4xl mx-auto space-y-6">
        <form action="{{ route('classes.update', $class->id) }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden divide-y divide-surface-container-highest">
            @csrf
            @method('PUT')

            {{-- Khối 1: Thông tin cơ bản & Phân loại lớp --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-primary-container"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">1. Thông tin cơ bản &amp; Phân loại</h2>
                    </div>
                    <span class="text-xs text-on-surface-variant/70 font-medium italic">(<span class="text-error font-bold">*</span>) Trường bắt buộc</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Tên lớp --}}
                    <div class="md:col-span-8">
                        <x-ui.input id="ten_lop" name="ten_lop" label="Tên lớp học" required :value="$class->name" class="text-xs" />
                    </div>

                    {{-- Mã lớp --}}
                    <div class="md:col-span-4">
                        <x-ui.input id="ma_lop" name="ma_lop" label="Mã lớp (Tùy chọn)" :value="$class->code" class="text-xs font-mono uppercase" />
                    </div>

                    {{-- Chi nhánh --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="chi_nhanh" name="chi_nhanh" label="Chi nhánh đào tạo" required class="text-xs cursor-pointer" :value="$class->branch_id"
                                     :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name . ' (' . $b->code . ')'])" />
                    </div>

                    {{-- Chương trình --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="chuong_trinh" name="chuong_trinh" label="Chương trình học" required class="text-xs cursor-pointer">
                            @foreach(['IELTS' => 'IELTS Học thuật (Academic)', 'TOEIC' => 'TOEIC 4 kỹ năng', 'COMMUNICATION' => 'Tiếng Anh Giao tiếp phản xạ', 'JUNIOR' => 'Tiếng Anh Thiếu niên (Junior)', 'BUSINESS' => 'Tiếng Anh Doanh nghiệp'] as $val => $label)
                                <option value="{{ $val }}" {{ old('chuong_trinh', $class->program) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                            @foreach($courses as $c)
                                <option value="{{ $c->name }}" {{ old('chuong_trinh', $class->program) === $c->name ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Cấp độ --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="cap_do" name="cap_do" label="Cấp độ" required class="text-xs cursor-pointer">
                            @foreach(['B1' => 'Cấp độ B1 (Mục tiêu 5.5 - 6.0)', 'FOUNDATION' => 'Foundation (Mục tiêu 4.0 - 5.0)', 'B2' => 'Cấp độ B2 (Mục tiêu 6.5 - 7.0)', 'ADVANCED' => 'Mastery (Mục tiêu 7.5+)'] as $val => $label)
                                <option value="{{ $val }}" {{ old('cap_do', $class->level) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                            @foreach($levels as $lvl)
                                <option value="{{ $lvl->code }}" {{ old('cap_do', $class->level) === $lvl->code ? 'selected' : '' }}>{{ $lvl->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Sĩ số tối đa --}}
                    <div class="md:col-span-4">
                        <x-ui.input type="number" id="si_so_toi_da" name="si_so_toi_da" label="Sĩ số tối đa" required suffix="học viên"
                                    :value="$class->max_capacity" min="1" max="100" class="text-xs" />
                    </div>

                    {{-- Ngưỡng khai giảng --}}
                    <div class="md:col-span-4">
                        <x-ui.input type="number" id="min_students" name="min_students" label="Ngưỡng khai giảng" suffix="học viên"
                                    :value="$class->min_students ?? 6" min="1" max="100" class="text-xs"
                                    hint="Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa." />
                    </div>

                    {{-- Trạng thái --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="status" name="status" label="Trạng thái lớp" class="text-xs cursor-pointer" :value="$class->status"
                                     :options="['pending_schedule' => 'Chờ cấu hình lịch', 'active' => 'Đang hoạt động', 'completed' => 'Đã kết thúc', 'cancelled' => 'Đã hủy']" />
                    </div>

                    {{-- Ngày khai giảng & kết thúc --}}
                    <div class="md:col-span-4">
                        <x-ui.date id="start_date" name="start_date" label="Ngày khai giảng" :value="$class->start_date?->format('Y-m-d')" class="text-xs" />
                    </div>
                    <div class="md:col-span-4">
                        <x-ui.date id="end_date" name="end_date" label="Ngày kết thúc" :value="$class->end_date?->format('Y-m-d')" class="text-xs" />
                    </div>

                    {{-- Lịch học text --}}
                    <div class="md:col-span-12">
                        <x-ui.input id="schedule_text" name="schedule_text" label="Lịch học (mô tả ngắn)" :value="$class->schedule_text" placeholder="VD: T2-T4-T6 18:00-20:00" class="text-xs"
                                    hint="Dùng để hiển thị trên danh sách lớp và đặt lịch học thử." />
                    </div>
                </div>
            </div>

            {{-- Khối 2: Phòng học & Đội ngũ --}}
            <div class="p-6 md:p-8 space-y-6 bg-surface-container-low/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">2. Phòng học &amp; Đội ngũ phụ trách</h2>
                    </div>
                    <x-ui.badge :dot="false">Tùy chọn</x-ui.badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <div class="md:col-span-6">
                        <x-ui.select id="phong_hoc" name="phong_hoc" label="Phòng học" class="text-xs cursor-pointer" placeholder="-- Chưa gán phòng --" :value="$class->room"
                                     :options="['P101' => 'Phòng 101 (Sức chứa 20 - Tầng 1)', 'P202' => 'Phòng 202 (Sức chứa 16 - Tầng 2)', 'P302' => 'Phòng 302 (Sức chứa 18 - Tầng 3)', 'LAB_A' => 'Phòng Lab A (Sức chứa 24 - Tầng 4)', 'LAB_B' => 'Phòng Lab B (Sức chứa 24 - Tầng 4)']" />
                    </div>

                    <div class="md:col-span-6">
                        <x-ui.select id="giao_vien_chinh" name="giao_vien_chinh" label="Giáo viên chính" class="text-xs cursor-pointer" placeholder="-- Chưa gán giáo viên --" :value="$class->teacher_id"
                                     :options="$teachers->mapWithKeys(fn ($t) => [$t->id => $t->name . ' (' . $t->email . ')'])" />
                    </div>

                    <div class="md:col-span-6">
                        <x-ui.select id="tro_giang" name="tro_giang" label="Trợ giảng" class="text-xs cursor-pointer" placeholder="-- Chưa gán trợ giảng --" :value="$class->assistant_id"
                                     :options="$assistants->pluck('name', 'id')" />
                    </div>

                    <div class="md:col-span-6">
                        <x-ui.select id="giao_vien_nn" name="giao_vien_nn" label="Giáo viên nước ngoài (GVNN)" class="text-xs cursor-pointer" placeholder="-- Không áp dụng --" :value="$class->foreign_teacher_id"
                                     :options="$teachers->pluck('name', 'id')" />
                    </div>
                </div>
            </div>

            {{-- Khối 3: Học phí & Ghi chú --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 rounded-full bg-tertiary"></div>
                    <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">3. Học phí &amp; Ghi chú nội bộ</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <div class="md:col-span-6">
                        <x-ui.input type="number" id="hoc_phi" name="hoc_phi" label="Mức học phí niêm yết" suffix="VNĐ"
                                    :value="$class->tuition_fee" min="0" step="50000" class="text-xs font-mono font-bold" />
                    </div>

                    <div class="md:col-span-12">
                        <x-ui.textarea id="ghi_chu" name="ghi_chu" label="Ghi chú vận hành" rows="3" :value="$class->notes" class="text-xs" />
                    </div>
                </div>
            </div>

            {{-- Action Footer --}}
            <div class="p-6 bg-surface-container-low border-t border-surface-container-highest flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-on-surface-variant">
                    Cập nhật lần cuối: <strong class="text-on-surface">{{ $class->updated_at->format('d/m/Y H:i') }}</strong>
                </div>
                <div class="flex items-center gap-3">
                    <x-ui.button variant="secondary" :href="route('classes.show', $class->id)">Hủy</x-ui.button>
                    <x-ui.button type="submit" variant="info" icon="save">Lưu thay đổi</x-ui.button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
