<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('classes.trial-booking') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">group_add</span>
                            Tạo lớp mới (Flow 1 — Bước #2)
                        </h1>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-secondary border border-secondary/20">
                            Học vụ / Quản trị
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">Khởi tạo hồ sơ lớp học với thông tin giảng dạy cốt lõi. Lịch học và thời khóa biểu chi tiết sẽ được cấu hình sau ở bước Thời khóa biểu.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('classes.academic-list') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[16px]">list</span>
                    <span>Quay lại danh sách lớp</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('classes.partials.flow-header', ['activeStep' => 2])

    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Thông báo hướng dẫn nghiệp vụ (Exact Match BA) -->
        <div class="bg-blue-50/70 border border-blue-200/80 rounded-2xl p-4 flex items-start gap-3 shadow-2xs">
            <div class="text-secondary shrink-0 mt-0.5">
                <span class="material-symbols-outlined text-[20px]">info</span>
            </div>
            <div class="text-xs text-gray-700 leading-relaxed">
                <strong class="text-gray-900 font-semibold">Lưu ý nghiệp vụ:</strong> Chỉ cần nhập đủ <strong>5 trường thông tin bắt buộc</strong> để tạo lớp. Các mục như Giáo viên, Phòng học, Học phí có thể để trống và bổ sung sau tại màn <a href="{{ route('classes.profile') }}" class="font-bold text-primary hover:underline">Hồ sơ lớp học</a>. Lớp vừa tạo sẽ ở trạng thái <span class="inline-block px-1.5 py-0.5 rounded text-[11px] font-mono bg-amber-50 text-amber-800 border border-amber-200 font-semibold">Chưa cấu hình lịch (Khởi tạo)</span>.
            </div>
        </div>

        <!-- Main Form Card (1 cột theo chiến lược UI chuẩn BA) -->
        <form action="{{ route('classes.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden divide-y divide-gray-100">
            @csrf

            <!-- Khối 1: Thông tin cơ bản & Phân loại lớp (Bắt buộc) -->
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-primary-container"></div>
                        <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">
                            1. Thông tin cơ bản &amp; Phân loại
                        </h2>
                    </div>
                    <span class="text-xs text-gray-400 font-medium italic">
                        (<span class="text-rose-500 font-bold">*</span>) Trường bắt buộc nhập
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <!-- Tên lớp (Bắt buộc) -->
                    <div class="md:col-span-8">
                        <label for="ten_lop" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Tên lớp học <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <input type="text"
                               id="ten_lop"
                               name="ten_lop"
                               value="{{ old('ten_lop') }}"
                               placeholder="VD: ENG-B1 · IELTS Căn Bản K26"
                               required
                               class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition placeholder:text-gray-400">
                        <p class="text-[11px] text-gray-400 mt-1">Tên hiển thị rõ ràng trên sổ điểm danh và cổng giáo viên.</p>
                        @error('ten_lop') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Mã lớp (Tùy chọn) -->
                    <div class="md:col-span-4">
                        <label for="ma_lop" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Mã lớp <span class="text-[11px] font-normal text-gray-400">(Tùy chọn)</span>
                        </label>
                        <input type="text"
                               id="ma_lop"
                               name="ma_lop"
                               value="{{ old('ma_lop') }}"
                               placeholder="VD: ENG-B1-K26"
                               class="w-full px-3.5 py-2.5 text-xs font-mono uppercase bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition placeholder:text-gray-400">
                        <p class="text-[11px] text-gray-400 mt-1">Để trống hệ thống sẽ tự sinh theo quy tắc.</p>
                        @error('ma_lop') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Chi nhánh (Bắt buộc) -->
                    <div class="md:col-span-4">
                        <label for="chi_nhanh" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Chi nhánh đào tạo <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <select id="chi_nhanh"
                                name="chi_nhanh"
                                required
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition text-gray-900 cursor-pointer">
                            <option value="" disabled {{ old('chi_nhanh') ? '' : 'selected' }}>-- Chọn chi nhánh --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('chi_nhanh') == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }} ({{ $b->code }})
                                </option>
                            @endforeach
                        </select>
                        @error('chi_nhanh') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Chương trình (Bắt buộc) -->
                    <div class="md:col-span-4">
                        <label for="chuong_trinh" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Chương trình học <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <select id="chuong_trinh"
                                name="chuong_trinh"
                                required
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition text-gray-900 cursor-pointer">
                            <option value="" disabled {{ old('chuong_trinh') ? '' : 'selected' }}>-- Chọn chương trình học --</option>
                            
                            @foreach($courses as $c)
                                <option value="{{ $c->name }}" data-fee="{{ $c->tuition_fee }}" {{ old('chuong_trinh') === $c->name ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                        @error('chuong_trinh') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Cấp độ (Bắt buộc) -->
                    <div class="md:col-span-4">
                        <label for="cap_do" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Cấp độ <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <select id="cap_do"
                                name="cap_do"
                                required
                                class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition text-gray-900 cursor-pointer">
                            <option value="" disabled {{ old('cap_do') ? '' : 'selected' }}>-- Chọn cấp độ --</option>
                            
                            @foreach($levels as $lvl)
                                <option value="{{ $lvl->code }}" {{ old('cap_do') === $lvl->code ? 'selected' : '' }}>{{ $lvl->name }} ({{ $lvl->target }})</option>
                            @endforeach
                        </select>
                        @error('cap_do') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Sĩ số tối đa (Bắt buộc) -->
                    <div class="md:col-span-4">
                        <label for="si_so_toi_da" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Sĩ số tối đa <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <div class="relative">
                            <input type="number"
                                   id="si_so_toi_da"
                                   name="si_so_toi_da"
                                   min="1"
                                   max="100"
                                   value="{{ old('si_so_toi_da') }}"
                                   placeholder="VD: 16"
                                   required
                                   class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[11px] text-gray-400 font-medium">
                                học viên
                            </div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">Giới hạn số học viên xếp lớp tối đa.</p>
                        @error('si_so_toi_da') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Ngưỡng khai giảng (số học viên tối thiểu để mở lớp) -->
                    <div class="md:col-span-4">
                        <label for="min_students" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Ngưỡng khai giảng
                        </label>
                        <div class="relative">
                            <input type="number"
                                   id="min_students"
                                   name="min_students"
                                   min="1"
                                   max="100"
                                   value="{{ old('min_students', 6) }}"
                                   class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[11px] text-gray-400 font-medium">
                                học viên
                            </div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa.</p>
                        @error('min_students') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Trạng thái khởi tạo (Readonly indicator) -->
                    <div class="md:col-span-12 flex items-center">
                        <div class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider">Trạng thái lớp ban đầu:</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                    Chưa cấu hình lịch (Khởi tạo)
                                </span>
                            </div>
                            <span class="text-[11px] text-gray-400 italic">Tự động kích hoạt khi xếp ca ở TKB</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Khối 2: Phòng học & Nhân sự giảng dạy (Tùy chọn - có thể để trống gán sau) -->
            <div class="p-6 md:p-8 space-y-6 bg-gray-50/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">
                            2. Phòng học &amp; Đội ngũ phụ trách
                        </h2>
                    </div>
                    <span class="text-xs px-2.5 py-1 bg-gray-100 text-gray-600 rounded-md font-semibold">
                        Tùy chọn • Để trống gán sau
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <!-- Phòng học (Tùy chọn) -->
                    <div class="md:col-span-6">
                        <label for="phong_hoc" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Phòng học dự kiến
                        </label>
                        <select id="phong_hoc"
                                name="phong_hoc"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition text-gray-900 cursor-pointer">
                            <option value="" selected>-- Chưa gán phòng (để trống) --</option>
                            <option value="P101" {{ old('phong_hoc') === 'P101' ? 'selected' : '' }}>Phòng 101 (Sức chứa 20 - Tầng 1)</option>
                            <option value="P202" {{ old('phong_hoc') === 'P202' ? 'selected' : '' }}>Phòng 202 (Sức chứa 16 - Tầng 2)</option>
                            <option value="P302" {{ old('phong_hoc') === 'P302' ? 'selected' : '' }}>Phòng 302 (Sức chứa 18 - Tầng 3)</option>
                            <option value="LAB_A" {{ old('phong_hoc') === 'LAB_A' ? 'selected' : '' }}>Phòng Lab A (Sức chứa 24 - Tầng 4)</option>
                            <option value="LAB_B" {{ old('phong_hoc') === 'LAB_B' ? 'selected' : '' }}>Phòng Lab B (Sức chứa 24 - Tầng 4)</option>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">Danh sách phòng hiện có của trung tâm (dùng chung các chi nhánh).</p>
                    </div>

                    <!-- Giáo viên chính (Tùy chọn) -->
                    <div class="md:col-span-6">
                        <label for="giao_vien_chinh" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Giáo viên chính
                        </label>
                        <select id="giao_vien_chinh"
                                name="giao_vien_chinh"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition text-gray-900 cursor-pointer">
                            <option value="" selected>-- Chưa gán giáo viên chính (để trống) --</option>
                            @foreach($teachers as $t)
                                <option value="{{ $t->id }}" {{ old('giao_vien_chinh') == $t->id ? 'selected' : '' }}>
                                    {{ $t->name }} ({{ $t->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Trợ giảng (Tùy chọn) -->
                    <div class="md:col-span-6">
                        <label for="tro_giang" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Trợ giảng
                        </label>
                        <select id="tro_giang"
                                name="tro_giang"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition text-gray-900 cursor-pointer">
                            <option value="" selected>-- Chưa gán trợ giảng (để trống) --</option>
                            @foreach($assistants as $ta)
                                <option value="{{ $ta->id }}" {{ old('tro_giang') == $ta->id ? 'selected' : '' }}>
                                    {{ $ta->name }} ({{ $ta->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Giáo viên nước ngoài (GVNN) (Tùy chọn) -->
                    <div class="md:col-span-6">
                        <label for="giao_vien_nn" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Giáo viên nước ngoài (GVNN)
                        </label>
                        <select id="giao_vien_nn"
                                name="giao_vien_nn"
                                class="w-full px-3.5 py-2.5 text-xs bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-secondary/20 focus:border-secondary transition text-gray-900 cursor-pointer">
                            <option value="" selected>-- Không áp dụng hoặc gán sau (để trống) --</option>
                            @foreach($foreignTeachers as $teacher)
                                <option value="{{ $teacher->id }}" {{ old('giao_vien_nn') == $teacher->id ? 'selected' : '' }}>
                                    {{ $teacher->name }} ({{ $teacher->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <!-- Khối 3: Lên lịch học tự động (Render TKB ngay khi tạo lớp) -->
            <div class="p-6 md:p-8 space-y-6 bg-gray-50/40" x-data="scheduleGenerator()" x-cloak>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">
                            3. Lên lịch học tự động
                        </h2>
                    </div>
                    <span class="text-xs px-2.5 py-1 bg-gray-100 text-gray-600 rounded-md font-semibold">
                        Tùy chọn • Không render sẽ tạo lớp chờ TKB
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <!-- Start Date -->
                    <div class="md:col-span-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Ngày bắt đầu</label>
                        <input type="date" x-model="startDate" class="w-full px-3.5 py-2.5 text-xs border border-gray-200 rounded-xl focus:ring-primary-container focus:border-primary-container">
                    </div>
                    <!-- End Date -->
                    <div class="md:col-span-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Ngày kết thúc</label>
                        <input type="date" x-model="endDate" class="w-full px-3.5 py-2.5 text-xs border border-gray-200 rounded-xl focus:ring-primary-container focus:border-primary-container">
                    </div>
                    <!-- Days of Week -->
                    <div class="md:col-span-6">
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Ngày học trong tuần</label>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="day in daysOfWeek" :key="day.value">
                                <label class="flex items-center gap-1 bg-gray-100 px-3 py-1.5 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-200">
                                    <input type="checkbox" :value="day.value" x-model="selectedDays" class="text-primary focus:ring-primary-container rounded">
                                    <span class="text-xs font-medium text-gray-700" x-text="day.label"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    <!-- Shift config -->
                    <div class="md:col-span-12">
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Cấu hình ca học</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <template x-for="shift in shifts" :key="shift.name">
                                <label class="flex items-center gap-2 bg-gray-50 p-3 rounded-xl border border-gray-200 cursor-pointer hover:bg-blue-50">
                                    <input type="checkbox" :value="shift.name" x-model="selectedShifts" class="text-blue-500 focus:ring-blue-500 rounded">
                                    <div class="flex flex-col">
                                        <span class="text-xs font-bold text-gray-900" x-text="shift.name"></span>
                                        <span class="text-[10px] text-gray-500" x-text="shift.start + ' - ' + shift.end"></span>
                                    </div>
                                </label>
                            </template>
                        </div>
                    </div>

                    <div class="md:col-span-12 flex justify-end">
                        <button type="button" @click="generateSchedule" class="px-5 py-2 bg-blue-600 text-white text-xs font-bold rounded-xl hover:bg-blue-700 transition">
                            ⚡ Render Thời Khoá Biểu
                        </button>
                    </div>
                </div>

                <!-- Generated Schedule Table -->
                <div x-show="generatedSessions.length > 0" class="mt-6 border border-gray-200 rounded-xl overflow-hidden bg-white">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="py-3 px-4 text-xs font-bold text-gray-700">Ngày</th>
                                <th class="py-3 px-4 text-xs font-bold text-gray-700">Ca học</th>
                                <th class="py-3 px-4 text-xs font-bold text-gray-700">Phòng trống</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <template x-for="(session, index) in generatedSessions" :key="index">
                                <tr>
                                    <td class="py-3 px-4 text-xs font-medium text-gray-900" x-text="session.dateFormatted"></td>
                                    <td class="py-3 px-4">
                                        <span class="inline-block px-2 py-1 bg-blue-50 text-blue-700 font-bold text-[10px] rounded" x-text="session.shift + ' (' + session.start + ' - ' + session.end + ')'"></span>
                                    </td>
                                    <td class="py-3 px-4 relative">
                                        <div x-show="session.loading" class="text-xs text-gray-500">Đang kiểm tra...</div>
                                        <select x-show="!session.loading" x-model="session.room" class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:ring-primary-container focus:border-primary-container">
                                            <option value="">-- Chọn phòng --</option>
                                            <template x-for="room in allRooms">
                                                <option :value="room" :disabled="session.occupiedRooms.includes(room)" x-text="room + (session.occupiedRooms.includes(room) ? ' (Đã trùng lịch)' : ' (Trống)')"></option>
                                            </template>
                                        </select>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <input type="hidden" name="schedule_sessions_json" :value="generatedSessions.length > 0 ? JSON.stringify(generatedSessions) : ''">
                    @error('schedule_sessions_json') <p class="text-[11px] text-rose-500 px-4 py-2">{{ $message }}</p> @enderror
                </div>
            </div>

            <!-- Khối 3: Học phí & Ghi chú quản lý (Tùy chọn) -->
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                        <h2 class="text-base font-bold text-gray-900 uppercase tracking-wide">
                            4. Học phí &amp; Ghi chú nội bộ
                        </h2>
                    </div>
                    <span class="text-xs px-2.5 py-1 bg-gray-100 text-gray-600 rounded-md font-semibold">
                        Tùy chọn
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    <!-- Học phí (Tùy chọn) -->
                    <div class="md:col-span-6">
                        <label for="hoc_phi" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Mức học phí niêm yết
                        </label>
                        <div class="relative">
                            <input type="number"
                                   id="hoc_phi"
                                   name="hoc_phi"
                                   value="{{ old('hoc_phi') }}"
                                   placeholder="VD: 8500000"
                                   min="0"
                                   step="50000"
                                   class="w-full pl-3.5 pr-14 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition font-mono font-bold text-gray-900">
                            <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-xs font-bold text-gray-400">
                                VNĐ
                            </div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">Đơn giá trọn khóa trước khi áp dụng ưu đãi/học bổng.</p>
                        @error('hoc_phi') <p class="text-[11px] text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Ghi chú (Tùy chọn) -->
                    <div class="md:col-span-12">
                        <label for="ghi_chu" class="block text-xs font-bold text-gray-700 mb-1.5">
                            Ghi chú vận hành <span class="text-[11px] font-normal text-gray-400">(Tùy chọn)</span>
                        </label>
                        <textarea id="ghi_chu"
                                  name="ghi_chu"
                                  rows="3"
                                  placeholder="Ghi chú thêm về yêu cầu đầu vào, lớp liên kết doanh nghiệp hoặc lưu ý đặc biệt cho giáo viên phụ trách..."
                                  class="w-full px-3.5 py-2.5 text-xs bg-gray-50/50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition placeholder:text-gray-400">{{ old('ghi_chu') }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Action Footer (Buttons) -->
            <div class="p-6 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-gray-500 text-center sm:text-left">
                    Sau khi bấm <strong class="text-gray-800">"Lưu lớp học"</strong>, hệ thống sẽ tạo bản ghi lớp và tự động chuyển hướng đến <strong class="text-gray-800">Hồ sơ lớp học</strong>.
                </div>

                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <a href="{{ route('classes.academic-list') }}" class="w-full sm:w-auto px-5 py-2.5 rounded-xl border border-gray-300 bg-white text-gray-700 text-xs font-semibold hover:bg-gray-100 transition text-center">
                        Hủy bỏ
                    </a>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-primary-container text-white text-xs font-bold shadow-sm hover:bg-primary-dark transition flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        <span>Lưu &amp; Chuyển sang Hồ sơ lớp</span>
                    </button>
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

@push('scripts')
    {{-- Alpine đã được nạp toàn cục qua bundle Vite (resources/js/app.js);
         nạp thêm CDN ở đây gây double-init cho schedule generator. --}}
    <script>
        function scheduleGenerator() {
            return {
                startDate: '',
                endDate: '',
                selectedDays: [],
                selectedShifts: [],
                generatedSessions: (function () {
                    // Khôi phục thời khóa biểu đã render khi server trả lỗi validation,
                    // tránh bắt người dùng render lại toàn bộ từ đầu.
                    const previous = @js(old('schedule_sessions_json'));
                    if (!previous) return [];
                    try {
                        return JSON.parse(previous).map(function (session) {
                            return Object.assign({}, session, {
                                dateFormatted: session.dateFormatted || new Date(session.date + 'T00:00:00').toLocaleDateString('vi-VN'),
                                loading: false,
                                occupiedRooms: session.occupiedRooms || [],
                            });
                        });
                    } catch (e) {
                        return [];
                    }
                })(),
                allRooms: ['P101', 'P202', 'P302', 'LAB_A', 'LAB_B'],
                daysOfWeek: [
                    { value: '1', label: 'T2' },
                    { value: '2', label: 'T3' },
                    { value: '3', label: 'T4' },
                    { value: '4', label: 'T5' },
                    { value: '5', label: 'T6' },
                    { value: '6', label: 'T7' },
                    { value: '0', label: 'CN' }
                ],
                shifts: [
                    { name: 'Ca 1', start: '08:00', end: '09:30' },
                    { name: 'Ca 2', start: '09:30', end: '11:00' },
                    { name: 'Ca 3', start: '14:00', end: '15:30' },
                    { name: 'Ca 4', start: '15:30', end: '17:00' },
                    { name: 'Ca Tối 1', start: '18:00', end: '19:30' },
                    { name: 'Ca Tối 2', start: '19:30', end: '21:00' }
                ],

                async generateSchedule() {
                    if (!this.startDate || !this.endDate || this.selectedDays.length === 0 || this.selectedShifts.length === 0) {
                        alert('Vui lòng chọn đầy đủ Ngày bắt đầu, Ngày kết thúc, Ngày học và Ca học!');
                        return;
                    }

                    let start = new Date(this.startDate);
                    let end = new Date(this.endDate);
                    let branchId = document.getElementById('chi_nhanh').value;

                    this.generatedSessions = [];
                    let shiftsToApply = this.shifts.filter(s => this.selectedShifts.includes(s.name));

                    let currentDate = new Date(start);
                    while (currentDate <= end) {
                        let day = currentDate.getDay().toString();
                        if (this.selectedDays.includes(day)) {
                            let dateStr = currentDate.toISOString().split('T')[0];
                            let dateFormatted = currentDate.toLocaleDateString('vi-VN');

                            for (let shift of shiftsToApply) {
                                let session = {
                                    date: dateStr,
                                    dateFormatted: dateFormatted,
                                    shift: shift.name,
                                    start: shift.start,
                                    end: shift.end,
                                    room: '',
                                    occupiedRooms: [],
                                    loading: true
                                };

                                this.generatedSessions.push(session);
                                this.checkRoomAvailability(session, branchId);
                            }
                        }
                        currentDate.setDate(currentDate.getDate() + 1);
                    }
                },

                async checkRoomAvailability(session, branchId) {
                    try {
                        const response = await fetch('{{ route('classes.check-availability') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                branch_id: branchId,
                                date: session.date,
                                start_time: session.start,
                                end_time: session.end
                            })
                        });

                        const data = await response.json();
                        session.occupiedRooms = data.occupied_rooms || [];

                        // Auto assign first available room
                        let freeRoom = this.allRooms.find(r => !session.occupiedRooms.includes(r));
                        if (freeRoom) {
                            session.room = freeRoom;
                        }
                    } catch (e) {
                        console.error(e);
                    } finally {
                        session.loading = false;
                    }
                }
            }
        }
    </script>
@endpush

</x-app-layout>
