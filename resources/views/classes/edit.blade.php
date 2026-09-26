<x-app-layout>
    <x-ui.page-header title="Chỉnh sửa lớp học" icon="edit" :back="route('classes.profile', $class->id)">
        <x-slot:badges>
            <span class="font-mono text-xs font-bold text-primary bg-orange-50 px-2 py-0.5 rounded border border-orange-200">{{ $class->code }}</span>
        </x-slot:badges>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="list" :href="route('classes.index')">Danh sách lớp</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($errors->any())
        <div class="max-w-4xl mx-auto mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-xs">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-4xl mx-auto space-y-6">
        <form action="{{ route('classes.update', $class->id) }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden divide-y divide-gray-100">
            @csrf
            @method('PUT')

            {{-- Khối 1: Thông tin cơ bản & Phân loại lớp --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-primary-container"></div>
                        <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">1. Thông tin cơ bản &amp; Phân loại</h2>
                    </div>
                    <span class="text-xs text-gray-400 font-medium italic">(<span class="text-rose-500 font-bold">*</span>) Trường bắt buộc</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Tên lớp --}}
                    <div class="md:col-span-8">
                        <label for="ten_lop" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Tên lớp học <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" id="ten_lop" name="ten_lop"
                               value="{{ old('ten_lop', $class->name) }}"
                               required
                               class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                        @error('ten_lop') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Mã lớp --}}
                    <div class="md:col-span-4">
                        <label for="ma_lop" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Mã lớp <span class="text-[11px] font-normal text-gray-400">(Tùy chọn)</span>
                        </label>
                        <input type="text" id="ma_lop" name="ma_lop"
                               value="{{ old('ma_lop', $class->code) }}"
                               class="w-full px-3.5 py-2.5 text-xs font-mono uppercase bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                    </div>

                    {{-- Chi nhánh --}}
                    <div class="md:col-span-4">
                        <label for="chi_nhanh" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Chi nhánh đào tạo <span class="text-rose-500">*</span>
                        </label>
                        <select id="chi_nhanh" name="chi_nhanh" required
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition cursor-pointer">
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('chi_nhanh', $class->branch_id) == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }} ({{ $b->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Chương trình --}}
                    <div class="md:col-span-4">
                        <label for="chuong_trinh" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Chương trình học <span class="text-rose-500">*</span>
                        </label>
                        <select id="chuong_trinh" name="chuong_trinh" required
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition cursor-pointer">
                            @foreach(['IELTS' => 'IELTS Học thuật (Academic)', 'TOEIC' => 'TOEIC 4 kỹ năng', 'COMMUNICATION' => 'Tiếng Anh Giao tiếp phản xạ', 'JUNIOR' => 'Tiếng Anh Thiếu niên (Junior)', 'BUSINESS' => 'Tiếng Anh Doanh nghiệp'] as $val => $label)
                                <option value="{{ $val }}" {{ old('chuong_trinh', $class->program) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                            @foreach($courses as $c)
                                <option value="{{ $c->name }}" {{ old('chuong_trinh', $class->program) === $c->name ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Cấp độ --}}
                    <div class="md:col-span-4">
                        <label for="cap_do" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Cấp độ <span class="text-rose-500">*</span>
                        </label>
                        <select id="cap_do" name="cap_do" required
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition cursor-pointer">
                            @foreach(['B1' => 'Cấp độ B1 (Mục tiêu 5.5 - 6.0)', 'FOUNDATION' => 'Foundation (Mục tiêu 4.0 - 5.0)', 'B2' => 'Cấp độ B2 (Mục tiêu 6.5 - 7.0)', 'ADVANCED' => 'Mastery (Mục tiêu 7.5+)'] as $val => $label)
                                <option value="{{ $val }}" {{ old('cap_do', $class->level) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                            @foreach($levels as $lvl)
                                <option value="{{ $lvl->code }}" {{ old('cap_do', $class->level) === $lvl->code ? 'selected' : '' }}>{{ $lvl->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Sĩ số tối đa --}}
                    <div class="md:col-span-4">
                        <label for="si_so_toi_da" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Sĩ số tối đa <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="number" id="si_so_toi_da" name="si_so_toi_da"
                                   min="1" max="100"
                                   value="{{ old('si_so_toi_da', $class->max_capacity) }}"
                                   required
                                   class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[11px] text-gray-400 font-medium">học viên</div>
                        </div>
                        @error('si_so_toi_da') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Ngưỡng khai giảng --}}
                    <div class="md:col-span-4">
                        <label for="min_students" class="block text-xs font-bold text-gray-700 mb-1.5">Ngưỡng khai giảng</label>
                        <div class="relative">
                            <input type="number" id="min_students" name="min_students"
                                   min="1" max="100"
                                   value="{{ old('min_students', $class->min_students ?? 6) }}"
                                   class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[11px] text-gray-400 font-medium">học viên</div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa.</p>
                        @error('min_students') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Trạng thái --}}
                    <div class="md:col-span-4">
                        <label for="status" class="block text-xs font-bold text-gray-700 mb-1.5">Trạng thái lớp</label>
                        <select id="status" name="status"
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition cursor-pointer">
                            <option value="pending_schedule" {{ old('status', $class->status) === 'pending_schedule' ? 'selected' : '' }}>Chờ cấu hình lịch</option>
                            <option value="active" {{ old('status', $class->status) === 'active' ? 'selected' : '' }}>Đang hoạt động</option>
                            <option value="completed" {{ old('status', $class->status) === 'completed' ? 'selected' : '' }}>Đã kết thúc</option>
                            <option value="cancelled" {{ old('status', $class->status) === 'cancelled' ? 'selected' : '' }}>Đã hủy</option>
                        </select>
                    </div>

                    {{-- Ngày khai giảng & kết thúc --}}
                    <div class="md:col-span-4">
                        <label for="start_date" class="block text-xs font-bold text-gray-700 mb-1.5">Ngày khai giảng</label>
                        <input type="date" id="start_date" name="start_date"
                               value="{{ old('start_date', $class->start_date?->format('Y-m-d')) }}"
                               class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                    </div>
                    <div class="md:col-span-4">
                        <label for="end_date" class="block text-xs font-bold text-gray-700 mb-1.5">Ngày kết thúc</label>
                        <input type="date" id="end_date" name="end_date"
                               value="{{ old('end_date', $class->end_date?->format('Y-m-d')) }}"
                               class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                    </div>

                    {{-- Lịch học text --}}
                    <div class="md:col-span-12">
                        <label for="schedule_text" class="block text-xs font-bold text-gray-700 mb-1.5">Lịch học (mô tả ngắn)</label>
                        <input type="text" id="schedule_text" name="schedule_text"
                               value="{{ old('schedule_text', $class->schedule_text) }}"
                               placeholder="VD: T2-T4-T6 18:00-20:00"
                               class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                        <p class="text-[11px] text-gray-400 mt-1">Dùng để hiển thị trên danh sách lớp và đặt lịch học thử.</p>
                    </div>
                </div>
            </div>

            {{-- Khối 2: Phòng học & Đội ngũ --}}
            <div class="p-6 md:p-8 space-y-6 bg-gray-50/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">2. Phòng học &amp; Đội ngũ phụ trách</h2>
                    </div>
                    <span class="text-xs px-2.5 py-1 bg-gray-100 text-gray-600 rounded-md font-semibold">Tùy chọn</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <div class="md:col-span-6">
                        <label for="phong_hoc" class="block text-xs font-bold text-gray-700 mb-1.5">Phòng học</label>
                        <select id="phong_hoc" name="phong_hoc"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition cursor-pointer">
                            <option value="">-- Chưa gán phòng --</option>
                            @foreach(['P101' => 'Phòng 101 (Sức chứa 20 - Tầng 1)', 'P202' => 'Phòng 202 (Sức chứa 16 - Tầng 2)', 'P302' => 'Phòng 302 (Sức chứa 18 - Tầng 3)', 'LAB_A' => 'Phòng Lab A (Sức chứa 24 - Tầng 4)', 'LAB_B' => 'Phòng Lab B (Sức chứa 24 - Tầng 4)'] as $val => $label)
                                <option value="{{ $val }}" {{ old('phong_hoc', $class->room) === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-6">
                        <label for="giao_vien_chinh" class="block text-xs font-bold text-gray-700 mb-1.5">Giáo viên chính</label>
                        <select id="giao_vien_chinh" name="giao_vien_chinh"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition cursor-pointer">
                            <option value="">-- Chưa gán giáo viên --</option>
                            @foreach($teachers as $t)
                                <option value="{{ $t->id }}" {{ old('giao_vien_chinh', $class->teacher_id) == $t->id ? 'selected' : '' }}>{{ $t->name }} ({{ $t->email }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-6">
                        <label for="tro_giang" class="block text-xs font-bold text-gray-700 mb-1.5">Trợ giảng</label>
                        <select id="tro_giang" name="tro_giang"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition cursor-pointer">
                            <option value="">-- Chưa gán trợ giảng --</option>
                            @foreach($assistants as $ta)
                                <option value="{{ $ta->id }}" {{ old('tro_giang', $class->assistant_id) == $ta->id ? 'selected' : '' }}>{{ $ta->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-6">
                        <label for="giao_vien_nn" class="block text-xs font-bold text-gray-700 mb-1.5">Giáo viên nước ngoài (GVNN)</label>
                        <select id="giao_vien_nn" name="giao_vien_nn"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition cursor-pointer">
                            <option value="">-- Không áp dụng --</option>
                            @foreach($teachers as $t)
                                <option value="{{ $t->id }}" {{ old('giao_vien_nn', $class->foreign_teacher_id) == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- Khối 3: Học phí & Ghi chú --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                    <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">3. Học phí &amp; Ghi chú nội bộ</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <div class="md:col-span-6">
                        <label for="hoc_phi" class="block text-xs font-bold text-gray-700 mb-1.5">Mức học phí niêm yết</label>
                        <div class="relative">
                            <input type="number" id="hoc_phi" name="hoc_phi"
                                   value="{{ old('hoc_phi', $class->tuition_fee) }}"
                                   min="0" step="50000"
                                   class="w-full pl-3.5 pr-14 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition font-mono font-bold">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-xs font-bold text-gray-400">VNĐ</div>
                        </div>
                    </div>

                    <div class="md:col-span-12">
                        <label for="ghi_chu" class="block text-xs font-bold text-gray-700 mb-1.5">Ghi chú vận hành</label>
                        <textarea id="ghi_chu" name="ghi_chu" rows="3"
                                  class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">{{ old('ghi_chu', $class->notes) }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Action Footer --}}
            <div class="p-6 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-gray-500">
                    Cập nhật lần cuối: <strong class="text-gray-800">{{ $class->updated_at->format('d/m/Y H:i') }}</strong>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('classes.profile', $class->id) }}" class="px-5 py-2.5 rounded-xl border border-gray-300 bg-white text-gray-700 text-xs font-semibold hover:bg-gray-100 transition">Hủy</a>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-secondary text-white text-xs font-bold shadow-sm hover:bg-secondary/90 transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        Lưu thay đổi
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
