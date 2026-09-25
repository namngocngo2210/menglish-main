<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="space-y-1">
                <nav class="flex items-center gap-1.5 text-xs text-slate-500 font-medium">
                    <a href="{{ route('dashboard') }}" class="hover:text-primary transition">Trang chủ</a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <a href="{{ route('students.index') }}" class="hover:text-primary transition">Hồ sơ học sinh</a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <span class="text-primary font-bold">Chi tiết</span>
                </nav>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600">account_circle</span>
                    Chi Tiết Hồ Sơ Học Sinh: {{ $student->name }}
                </h1>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('students.index') }}" class="px-3.5 py-2 rounded-xl border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                    <span>Danh sách học sinh</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 1: THÔNG TIN CƠ BẢN (BENTO PROFILE GRID) -->
        <!-- ────────────────────────────────────────────── -->
        <section class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Profile Info Card (Left Col 4) -->
            <div class="lg:col-span-4 bg-white border border-gray-200 rounded-2xl p-6 flex flex-col items-center text-center space-y-4 shadow-sm">
                <div class="relative group">
                    <div class="w-32 h-32 rounded-full border-4 border-orange-200 bg-gradient-to-br from-orange-100 to-amber-50 text-orange-600 flex items-center justify-center font-black text-4xl shadow-md overflow-hidden">
                        {{ Str::substr($student->name, 0, 1) }}
                    </div>
                    <div class="absolute bottom-1 right-1 bg-orange-600 text-white p-1.5 rounded-full shadow-lg">
                        <span class="material-symbols-outlined text-[16px]">school</span>
                    </div>
                </div>

                <div class="space-y-1">
                    <h2 class="text-xl font-black text-gray-900">{{ $student->name }}</h2>
                    <p class="text-gray-500 text-xs flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[16px] text-orange-500">cake</span>
                        <span>
                            @if ($student->dob)
                                {{ $student->dob->format('d/m/Y') }} ({{ $student->dob->age }} tuổi)
                            @else
                                Chưa cập nhật ngày sinh
                            @endif
                        </span>
                    </p>
                    <p class="text-gray-500 text-xs flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[16px] text-orange-500">call</span>
                        <span class="font-mono font-bold text-gray-800">{{ $student->phone }}</span>
                    </p>
                    @if ($student->email)
                        <p class="text-gray-400 text-xs font-mono">{{ $student->email }}</p>
                    @endif
                </div>

                <div class="pt-4 w-full border-t border-gray-100 grid grid-cols-2 gap-4 text-xs">
                    <div class="text-center p-2 rounded-xl bg-slate-50 border border-slate-100">
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Mã học sinh</p>
                        <p class="font-bold text-orange-600 font-mono mt-0.5">{{ $student->code }}</p>
                    </div>
                    <div class="text-center p-2 rounded-xl bg-slate-50 border border-slate-100">
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Ngày nhập học</p>
                        <p class="font-bold text-gray-800 font-mono mt-0.5">{{ $student->created_at ? $student->created_at->format('d/m/Y') : '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- Edit Form Card (Right Col 8) -->
            <div class="lg:col-span-8 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-orange-600 text-base">edit_document</span>
                        <span>Chỉnh sửa thông tin học viên</span>
                    </h3>
                    <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] px-2.5 py-0.5 rounded-full font-bold">
                        Quyền: Quản trị viên
                    </span>
                </div>

                <form action="{{ route('students.update', $student->id) }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Họ và tên <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $student->name) }}" required class="w-full text-xs font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary focus:border-primary" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Số điện thoại <span class="text-rose-500">*</span></label>
                            <input type="tel" name="phone" value="{{ old('phone', $student->phone) }}" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary focus:border-primary" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Email liên hệ</label>
                            <input type="email" name="email" value="{{ old('email', $student->email) }}" placeholder="hocvien@menglish.edu.vn" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary focus:border-primary" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Mục tiêu học tập</label>
                            <input type="text" name="target" value="{{ old('target', $student->target) }}" placeholder="VD: IELTS 6.5, Cambridge Starters..." class="w-full text-xs font-semibold rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary focus:border-primary" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Địa chỉ liên hệ</label>
                            <textarea name="address" rows="2" placeholder="Nhập địa chỉ của học viên..." class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary focus:border-primary">{{ old('address', $student->address) }}</textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Ghi chú đặc biệt / Lời dặn phụ huynh</label>
                            <textarea name="notes" rows="2" placeholder="Nhập ghi chú về học sinh (ví dụ: dị ứng, sở thích, mục tiêu học tập...)" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary focus:border-primary">{{ old('notes', $student->notes) }}</textarea>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-gray-100 flex items-center justify-end gap-3">
                        <a href="{{ route('students.show', $student->id) }}" class="px-4 py-2 border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 rounded-xl text-xs font-semibold transition">Hủy</a>
                        <button type="submit" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]">save</span>
                            <span>Lưu thay đổi</span>
                        </button>
                    </div>
                </form>
            </div>

        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 2: TRẠNG THÁI VÒNG ĐỜI (LIFECYCLE BAR) -->
        <!-- ────────────────────────────────────────────── -->
        <section class="bg-white border border-gray-200 rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm">
            <div class="flex items-center gap-6">
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Trạng thái hiện tại</span>
                    <div class="flex items-center gap-2 mt-1">
                        @if ($student->status === 'studying')
                            <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold text-xs flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>Đang học</span>
                            </span>
                        @elseif ($student->status === 'deferred')
                            <span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 font-bold text-xs flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                <span>Bảo lưu</span>
                            </span>
                        @elseif ($student->status === 'dropped')
                            <span class="px-3 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 font-bold text-xs flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                <span>Đã thôi học</span>
                            </span>
                        @else
                            <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 font-bold text-xs flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                <span>Hoàn thành khóa</span>
                            </span>
                        @endif
                    </div>
                </div>

                <div class="h-8 w-px bg-gray-200 hidden sm:block"></div>

                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Thời gian cập nhật</span>
                    <span class="text-xs font-semibold text-gray-700 mt-1 font-mono">
                        {{ $student->updated_at ? $student->updated_at->format('d/m/Y H:i') : '—' }}
                    </span>
                </div>
            </div>

            <!-- Lifecycle Status Switcher Form -->
            @can('student.change_status')
            <form action="{{ route('students.status.update', $student->id) }}" method="POST" class="relative">
                @csrf
                @method('PUT')

                <div class="flex items-center gap-2">
                    <label class="text-[11px] font-bold text-gray-600 uppercase">Đổi trạng thái:</label>
                    <select name="status" onchange="this.form.submit()" class="text-xs font-bold rounded-xl border border-gray-300 bg-slate-50 py-2 px-3 focus:ring-primary focus:border-primary cursor-pointer">
                        <option value="studying" {{ $student->status === 'studying' ? 'selected' : '' }}>🟢 Đang học</option>
                        <option value="deferred" {{ $student->status === 'deferred' ? 'selected' : '' }}>🟡 Bảo lưu</option>
                        <option value="dropped" {{ $student->status === 'dropped' ? 'selected' : '' }}>🔴 Đã thôi học</option>
                        <option value="graduated" {{ $student->status === 'graduated' ? 'selected' : '' }}>🔵 Hoàn thành khóa</option>
                    </select>
                </div>
            </form>
            @endcan
        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 3: LỘ TRÌNH HỌC TẬP & DANH SÁCH BUỔI HỌC -->
        <!-- ────────────────────────────────────────────── -->
        <section class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-50/70">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600 text-xl">auto_stories</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Lộ trình học tập &amp; Danh sách buổi học</h3>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="window.print();" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-700 shadow-2xs transition flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-[15px]">download</span>
                        <span>Xuất lộ trình</span>
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 text-center w-16">Buổi</th>
                            <th class="py-3 px-4">Ngày học / Thời gian</th>
                            <th class="py-3 px-4">Nội dung bài học &amp; Trọng tâm</th>
                            <th class="py-3 px-4">Giáo viên phụ trách</th>
                            <th class="py-3 px-4 text-right">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @if ($student->currentClass && $student->currentClass->timesheets && $student->currentClass->timesheets->count() > 0)
                            @foreach ($student->currentClass->timesheets as $idx => $ts)
                                <tr class="hover:bg-blue-50/20 transition">
                                    <td class="py-3 px-4 text-center font-bold font-mono text-gray-900">#{{ $idx + 1 }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-gray-900">{{ $ts->teaching_date ? $ts->teaching_date->format('d/m/Y') : '—' }}</div>
                                        <div class="text-[11px] font-mono text-gray-500">{{ $ts->hours }} giờ học</div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-bold text-gray-900">{{ $ts->type }}</span>
                                        <p class="text-[11px] text-gray-500">{{ $ts->notes ?? 'Bài học theo giáo trình lớp ' . $student->currentClass->name }}</p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 rounded-full bg-orange-100 text-orange-700 flex items-center justify-center text-[10px] font-black">
                                                {{ Str::substr($ts->teacher?->name ?? $student->currentClass->teacher?->name ?? 'G', 0, 1) }}
                                            </div>
                                            <span class="font-semibold text-gray-800">{{ $ts->teacher?->name ?? $student->currentClass->teacher?->name ?? 'Chưa gán GV' }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <span class="text-emerald-700 font-bold text-xs inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                            <span>{{ $ts->status_label ?? 'Đã hoàn thành' }}</span>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        @elseif (isset($syllabusUnits) && $syllabusUnits->count() > 0)
                            @foreach ($syllabusUnits->take(5) as $uIdx => $unit)
                                <tr class="hover:bg-blue-50/20 transition">
                                    <td class="py-3 px-4 text-center font-bold font-mono text-gray-900">#{{ $unit->unit_number ?: ($uIdx + 1) }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-gray-900">Theo tiến độ lớp học</div>
                                        <div class="text-[11px] font-mono text-gray-500">{{ $student->currentClass?->schedule_text ?? '1.5 giờ / buổi' }}</div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-bold text-gray-900">Unit {{ $unit->unit_number }}: {{ $unit->title }}</span>
                                        <p class="text-[11px] text-gray-500">{{ $unit->grammar_focus ? 'Ngữ pháp: ' . $unit->grammar_focus : ($unit->objectives ?? 'Mục tiêu chuẩn đầu ra') }}</p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-[10px] font-black">
                                                {{ Str::substr($student->currentClass?->teacher?->name ?? 'GV', 0, 1) }}
                                            </div>
                                            <span class="font-semibold text-gray-800">{{ $student->currentClass?->teacher?->name ?? 'Giáo viên phụ trách' }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <span class="text-orange-600 font-bold text-xs inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[16px]">schedule</span>
                                            <span>Theo lộ trình</span>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="5" class="text-center py-8 text-gray-400 text-xs">
                                    Chưa có danh sách buổi học được ghi nhận cho học viên.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 4: HỒ SƠ TỔNG HỢP (BENTO HUB VIEW) -->
        <!-- ────────────────────────────────────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Card 1: Lớp học hiện tại (Col 4) -->
            <div class="lg:col-span-4 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col justify-between space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                    <span class="material-symbols-outlined text-blue-600 text-xl">class</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Lớp học hiện tại</h3>
                </div>

                <div class="space-y-3 flex-grow">
                    <div class="p-3.5 bg-blue-50/70 rounded-xl border border-blue-100 space-y-1">
                        <p class="font-black text-blue-950 text-sm">{{ $student->currentClass?->name ?? 'Chưa phân lớp học' }}</p>
                        <p class="text-xs text-blue-700 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[15px]">location_on</span>
                            <span>{{ $student->currentClass?->branch?->name ?? $student->branch?->name ?? 'Chưa phân cơ sở' }}</span>
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div class="space-y-0.5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase">Lịch học</p>
                            <p class="font-bold text-gray-800">{{ $student->currentClass?->schedule_text ?? 'Chưa xếp lịch' }}</p>
                            <p class="font-mono text-blue-600 font-semibold text-[11px]">{{ $student->currentClass?->teacher?->name ? 'GV: ' . $student->currentClass->teacher->name : 'Chưa có GV' }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-[10px] font-bold text-gray-400 uppercase">Thời gian</p>
                            <p class="font-bold text-gray-800">
                                @if ($student->currentClass?->start_date)
                                    {{ $student->currentClass->start_date->format('d/m/Y') }}
                                @else
                                    Đang triển khai
                                @endif
                            </p>
                            <p class="text-[10px] text-gray-400 italic">{{ $student->currentClass?->status ?? 'Active' }}</p>
                        </div>
                    </div>
                </div>

                <a href="{{ route('students.enrollments') }}" class="w-full py-2 border border-blue-600 text-blue-600 hover:bg-blue-600 hover:text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1">
                    <span>Chi tiết lớp học &amp; Xếp lớp</span>
                    <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                </a>
            </div>

            <!-- Card 2: Điểm danh & Chuyên cần (Col 3) -->
            <div class="lg:col-span-3 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col items-center justify-between text-center space-y-3">
                <div class="w-full flex items-center gap-2 pb-2 border-b border-gray-100 text-left">
                    <span class="material-symbols-outlined text-emerald-600 text-xl">how_to_reg</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Chuyên cần</h3>
                </div>

                <!-- Circular Progress Ring SVG (Real Computed Percentage) -->
                @php
                    $attended = (int)$student->attended_lessons;
                    $total = (int)$student->total_lessons;
                    $pct = $total > 0 ? round(($attended / $total) * 100) : 100;
                    $absent = max(0, $total - $attended);
                @endphp
                <div class="relative w-28 h-28 flex items-center justify-center my-1">
                    <svg class="w-full h-full transform -rotate-90">
                        <circle class="text-gray-100" cx="56" cy="56" fill="transparent" r="48" stroke="currentColor" stroke-width="8"></circle>
                        <circle class="text-emerald-500 transition-all duration-1000" cx="56" cy="56" fill="transparent" r="48" stroke="currentColor" stroke-dasharray="301.6" stroke-dashoffset="{{ 301.6 - (301.6 * $pct / 100) }}" stroke-width="8"></circle>
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-2xl font-black text-gray-900 font-mono">{{ $pct }}%</span>
                    </div>
                </div>

                <div class="w-full space-y-1 text-xs pt-1">
                    <div class="flex justify-between text-gray-600">
                        <span>Tổng số buổi:</span>
                        <span class="font-bold text-gray-900 font-mono">{{ $total }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span>Số buổi vắng:</span>
                        <span class="font-bold text-rose-600 font-mono">{{ $absent }}</span>
                    </div>
                </div>

                <a href="{{ route('payroll.timesheets.teachers') }}" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition">
                    Lịch sử điểm danh
                </a>
            </div>

            <!-- Card 3: Thông tin Học phí (Col 5) -->
            <div class="lg:col-span-5 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col justify-between space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-xl">payments</span>
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Thông tin học phí</h3>
                    </div>
                    <a href="{{ route('tuition.receipts.create') }}?student_id={{ $student->id }}" class="text-orange-600 font-bold text-xs hover:underline flex items-center gap-0.5">
                        <span class="material-symbols-outlined text-[15px]">add_circle</span>
                        <span>Lập phiếu thu</span>
                    </a>
                </div>

                <div class="space-y-2 flex-grow max-h-[220px] overflow-y-auto pr-1 text-xs">
                    @if ($student->tuition && $student->tuition->receipts && $student->tuition->receipts->count() > 0)
                        @foreach ($student->tuition->receipts as $receipt)
                            <div class="p-3 border border-gray-200 rounded-xl flex items-center justify-between hover:border-orange-300 transition">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-base">receipt_long</span>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 font-mono">#{{ $receipt->receipt_number }}</p>
                                        <p class="text-[10px] text-gray-400 font-mono">{{ $receipt->payment_date ? $receipt->payment_date->format('d/m/Y') : $receipt->created_at->format('d/m/Y') }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-black text-gray-900 font-mono">{{ number_format($receipt->amount) }}đ</p>
                                    <span class="text-[10px] font-bold uppercase {{ $receipt->status === 'approved' ? 'text-emerald-600' : 'text-amber-600' }}">
                                        {{ $receipt->status === 'approved' ? 'Đã thanh toán' : 'Chờ duyệt' }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <div class="p-4 border border-dashed border-gray-200 rounded-xl text-center space-y-1">
                            <span class="material-symbols-outlined text-gray-300 text-3xl">receipt_long</span>
                            <p class="text-xs text-gray-400">Chưa có phiếu thu nào được ghi nhận cho học viên.</p>
                        </div>
                    @endif
                </div>

                <div class="pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                    <div>
                        <p class="text-[10px] text-gray-400 uppercase font-bold">Tổng học phí đã nộp</p>
                        <p class="font-black text-orange-600 text-base font-mono">
                            {{ number_format($student->tuition ? $student->tuition->paid_amount : 0) }}đ
                        </p>
                    </div>
                    <a href="{{ route('tuition.students') }}" class="text-xs font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-0.5">
                        <span>Lịch sử sổ thu</span>
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>

        </div>

    </div>
</x-app-layout>
