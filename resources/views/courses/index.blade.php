<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black text-gray-900 tracking-tight flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-primary text-2xl">price_change</span>
                    <span>Quản lý Khóa học &amp; Bảng giá học phí</span>
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">
                    Cấu hình giá niêm yết, số buổi học và liên kết khung trình độ chuẩn CEFR/IELTS
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button 
                    type="button" 
                    onclick="openCreateModal()" 
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition"
                >
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Thêm khóa học &amp; Giá mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" x-data="courseManager()">
        {{-- 1. KPI Cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Tổng số khóa học</span>
                    <div class="text-xl font-black text-gray-900 mt-1 font-mono">{{ number_format($stats['total_courses']) }}</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-orange-50 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">school</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Đang mở tuyển sinh</span>
                    <div class="text-xl font-black text-emerald-700 mt-1 font-mono">{{ number_format($stats['active_courses']) }}</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Học phí trung bình</span>
                    <div class="text-xl font-black text-indigo-700 mt-1 font-mono">{{ number_format($stats['avg_tuition']) }}đ</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">payments</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Mức giá cao nhất</span>
                    <div class="text-xl font-black text-purple-700 mt-1 font-mono">{{ number_format($stats['max_tuition']) }}đ</div>
                </div>
                <div class="w-11 h-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">workspace_premium</span>
                </div>
            </div>
        </div>

        {{-- 2. Filter Bar --}}
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-sm p-4">
            <form method="GET" action="{{ route('courses.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                {{-- Search --}}
                <div class="lg:col-span-2">
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Tìm kiếm khóa học</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2 text-gray-400 text-base">search</span>
                        <input 
                            type="text" 
                            name="search" 
                            value="{{ request('search') }}" 
                            placeholder="Nhập tên khóa học, mã code (IE-65, GT-B1)..." 
                            class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container"
                        />
                    </div>
                </div>

                {{-- Level --}}
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Khung trình độ</label>
                    <select name="course_level_id" class="w-full text-xs rounded-xl border border-gray-200 py-1.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                        <option value="">-- Tất cả trình độ --</option>
                        @foreach ($levels as $lv)
                            <option value="{{ $lv->id }}" {{ request('course_level_id') == $lv->id ? 'selected' : '' }}>
                                {{ $lv->name }} ({{ $lv->code }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Status --}}
                <div>
                    <label class="block text-[11px] font-bold text-gray-600 mb-1">Trạng thái mở bán</label>
                    <select name="status" class="w-full text-xs rounded-xl border border-gray-200 py-1.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                        <option value="">-- Tất cả trạng thái --</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>🟢 Đang mở bán</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>⚪ Tạm ngưng</option>
                    </select>
                </div>

                {{-- Actions --}}
                <div class="flex items-end gap-2">
                    <a href="{{ route('courses.index') }}" class="px-3 py-2 text-xs font-semibold text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-xl transition">
                        Đặt lại
                    </a>
                    <button type="submit" class="flex-1 px-4 py-2 text-xs font-bold text-white bg-primary-container hover:bg-primary-hover rounded-xl shadow-xs transition flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">filter_alt</span>
                        <span>Lọc</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- 3. Courses Table / Price List --}}
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1040px]">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200/80 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 min-w-[90px] whitespace-nowrap">Mã khóa</th>
                            <th class="py-3 px-4 min-w-[220px]">Tên khóa học &amp; Mục tiêu</th>
                            <th class="py-3 px-4 min-w-[220px] whitespace-nowrap">Khung trình độ</th>
                            <th class="py-3 px-4 text-right min-w-[150px] whitespace-nowrap">Giá học phí niêm yết</th>
                            <th class="py-3 px-4 text-center min-w-[90px] whitespace-nowrap">Thời lượng</th>
                            <th class="py-3 px-4 text-center min-w-[100px] whitespace-nowrap">Lớp đang chạy</th>
                            <th class="py-3 px-4 text-center min-w-[120px] whitespace-nowrap">Trạng thái</th>
                            <th class="py-3 px-4 text-right min-w-[130px] whitespace-nowrap">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700">
                        @forelse ($courses as $c)
                            <tr class="hover:bg-orange-50/20 transition group">
                                {{-- Code --}}
                                <td class="py-3.5 px-4 font-mono font-bold text-gray-900 whitespace-nowrap">
                                    <span class="px-2 py-1 bg-gray-100 rounded-lg text-gray-800 border border-gray-200/70 inline-block">
                                        {{ $c->code }}
                                    </span>
                                </td>

                                {{-- Name & Description --}}
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-gray-900 text-xs">{{ $c->name }}</div>
                                    @if ($c->description)
                                        <div class="text-[11px] text-gray-500 line-clamp-1 mt-0.5 max-w-sm">{{ $c->description }}</div>
                                    @endif
                                </td>

                                {{-- Level --}}
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if ($c->level)
                                        <div class="whitespace-nowrap">
                                            <div class="flex items-center gap-1.5 whitespace-nowrap">
                                                <span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 font-mono font-bold text-[11px] border border-indigo-200/70 shrink-0">
                                                    {{ $c->level->code }}
                                                </span>
                                                <span class="font-semibold text-gray-900 text-xs whitespace-nowrap">
                                                    {{ $c->level->name }}
                                                </span>
                                            </div>
                                            @if ($c->level->target)
                                                <div class="text-[10px] text-gray-400 font-medium mt-0.5 whitespace-nowrap flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[13px] text-gray-400">flag</span>
                                                    <span>{{ $c->level->target }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-gray-400 italic text-[11px] whitespace-nowrap">Chưa gắn level</span>
                                    @endif
                                </td>

                                {{-- Tuition Fee (Giá khóa học) --}}
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="font-mono font-extrabold text-sm text-primary">
                                        {{ number_format($c->tuition_fee) }} đ
                                    </div>
                                    <span class="text-[10px] text-gray-400 block">Giá trọn gói</span>
                                </td>

                                {{-- Lessons --}}
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <span class="font-mono font-bold text-gray-800">{{ $c->total_lessons }}</span>
                                    <span class="text-[10px] text-gray-400 block">buổi học</span>
                                </td>

                                {{-- Classes Count --}}
                                <td class="py-3.5 px-4 text-center font-mono font-semibold text-gray-600 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 text-xs font-bold">{{ $c->classes_count }} lớp</span>
                                </td>

                                {{-- Active Status --}}
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <form action="{{ route('courses.toggle', $c->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('PATCH')
                                        <button 
                                            type="submit" 
                                            class="px-2.5 py-1 rounded-full text-[10px] font-bold transition cursor-pointer {{ $c->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-gray-100 text-gray-500 border border-gray-200 hover:bg-gray-200' }}"
                                            title="Bấm để chuyển trạng thái mở bán"
                                        >
                                            {{ $c->is_active ? '🟢 Đang mở bán' : '⚪ Tạm ngưng' }}
                                        </button>
                                    </form>
                                </td>

                                {{-- Actions --}}
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                        {{-- Edit Price / Course Button --}}
                                        <button 
                                            type="button" 
                                            onclick='openEditModal(@json($c))' 
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-orange-50 hover:bg-orange-100 text-primary border border-orange-200 text-xs font-bold transition shadow-2xs cursor-pointer"
                                            title="Chỉnh sửa giá học phí & thông tin khóa học"
                                        >
                                            <span class="material-symbols-outlined text-[15px]">edit</span>
                                            <span>Sửa giá</span>
                                        </button>

                                        {{-- Delete Button --}}
                                        @if ($c->classes_count === 0)
                                            <form action="{{ route('courses.destroy', $c->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa khóa học {{ $c->name }} không?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Xóa">
                                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-12 text-gray-400 text-xs">
                                    <span class="material-symbols-outlined text-4xl block text-gray-300 mb-2">school</span>
                                    Chưa có khóa học nào khớp với điều kiện tìm kiếm.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination (10, 20, 50, 100, All) --}}
            <div class="border-t border-gray-100">
                <x-pagination :paginator="$courses" />
            </div>
        </div>

        {{-- 4. Create Course Modal --}}
        <div id="createCourseModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 backdrop-blur-xs">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 space-y-4 shadow-2xl overflow-hidden">
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-xl">add_circle</span>
                        <h3 class="font-bold text-sm text-gray-900">Thêm Khóa Học &amp; Thiết Lập Giá</h3>
                    </div>
                    <button type="button" onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form action="{{ route('courses.store') }}" method="POST" class="space-y-4 text-xs">
                    @csrf

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Mã khóa học <span class="text-rose-500">*</span></label>
                            <input type="text" name="code" placeholder="Ví dụ: IE-65, GT-B1" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Số buổi học <span class="text-rose-500">*</span></label>
                            <input type="number" name="total_lessons" value="24" min="1" required class="w-full text-xs font-mono rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Tên khóa học <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" placeholder="Ví dụ: IELTS 6.5 Intensive, Giao tiếp Pro B1" required class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                    </div>

                    {{-- TUITION FEE (Giá học phí) --}}
                    <div class="bg-orange-50/70 p-3.5 rounded-2xl border border-orange-200/80">
                        <label class="block font-bold text-orange-950 mb-1 flex items-center justify-between">
                            <span>Giá học phí niêm yết (VNĐ) <span class="text-rose-500">*</span></span>
                            <span class="text-[10px] text-orange-600 font-normal">Học phí trọn gói</span>
                        </label>
                        <div class="relative">
                            <input 
                                type="number" 
                                name="tuition_fee" 
                                placeholder="12500000" 
                                min="0" 
                                step="10000" 
                                required 
                                class="w-full text-sm font-mono font-black text-primary rounded-xl border border-orange-300 bg-white p-2.5 pr-10 focus:ring-2 focus:ring-primary-container focus:border-primary-container" 
                            />
                            <span class="absolute right-3 top-2.5 text-xs font-bold text-orange-400">VNĐ</span>
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Khung trình độ trực thuộc</label>
                        <select name="course_level_id" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                            <option value="">-- Chọn khung trình độ (CEFR/IELTS) --</option>
                            @foreach ($levels as $lv)
                                <option value="{{ $lv->id }}">{{ $lv->name }} ({{ $lv->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Mô tả &amp; Cam kết đầu ra</label>
                        <textarea name="description" rows="2" placeholder="Cam kết band điểm, tài liệu độc quyền..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <input type="checkbox" name="is_active" id="create_is_active" value="1" checked class="rounded text-primary focus:ring-primary-container border-gray-300 w-4 h-4 cursor-pointer" />
                        <label for="create_is_active" class="font-bold text-gray-700 cursor-pointer">Kích hoạt mở bán ngay sau khi tạo</label>
                    </div>

                    <div class="flex justify-end gap-2.5 pt-3 border-t border-gray-100">
                        <button type="button" onclick="closeCreateModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                        <button type="submit" class="px-5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-md">Lưu Khóa Học</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- 5. Edit Course & Price Modal --}}
        <div id="editCourseModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 backdrop-blur-xs">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 space-y-4 shadow-2xl overflow-hidden">
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-xl">edit_note</span>
                        <h3 class="font-bold text-sm text-gray-900">Chỉnh Sửa Giá &amp; Thông Tin Khóa Học</h3>
                    </div>
                    <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form id="editCourseForm" method="POST" action="" class="space-y-4 text-xs">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Mã khóa học <span class="text-rose-500">*</span></label>
                            <input type="text" name="code" id="edit_code" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Số buổi học <span class="text-rose-500">*</span></label>
                            <input type="number" name="total_lessons" id="edit_total_lessons" min="1" required class="w-full text-xs font-mono rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Tên khóa học <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" id="edit_name" required class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                    </div>

                    {{-- EDIT TUITION FEE (Chỉnh sửa giá học phí) --}}
                    <div class="bg-orange-50/80 p-3.5 rounded-2xl border border-orange-200">
                        <label class="block font-bold text-orange-950 mb-1 flex items-center justify-between">
                            <span>Giá học phí niêm yết (VNĐ) <span class="text-rose-500">*</span></span>
                            <span class="text-[10px] text-orange-600 font-normal">Chỉnh sửa giá mới</span>
                        </label>
                        <div class="relative">
                            <input 
                                type="number" 
                                name="tuition_fee" 
                                id="edit_tuition_fee" 
                                min="0" 
                                step="10000" 
                                required 
                                class="w-full text-base font-mono font-black text-primary rounded-xl border border-orange-300 bg-white p-2.5 pr-10 focus:ring-2 focus:ring-primary-container focus:border-primary-container" 
                            />
                            <span class="absolute right-3 top-3 text-xs font-bold text-orange-400">VNĐ</span>
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Khung trình độ trực thuộc</label>
                        <select name="course_level_id" id="edit_course_level_id" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                            <option value="">-- Chưa gắn khung trình độ --</option>
                            @foreach ($levels as $lv)
                                <option value="{{ $lv->id }}">{{ $lv->name }} ({{ $lv->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Mô tả &amp; Mục tiêu</label>
                        <textarea name="description" id="edit_description" rows="2" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:ring-1 focus:ring-primary-container focus:border-primary-container"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <input type="checkbox" name="is_active" id="edit_is_active" value="1" class="rounded text-primary focus:ring-primary-container border-gray-300 w-4 h-4 cursor-pointer" />
                        <label for="edit_is_active" class="font-bold text-gray-700 cursor-pointer">Đang mở bán khóa học này</label>
                    </div>

                    <div class="flex justify-end gap-2.5 pt-3 border-t border-gray-100">
                        <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                        <button type="submit" class="px-5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-md">Cập Nhật Học Phí</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function courseManager() {
            return {};
        }

        function openCreateModal() {
            document.getElementById('createCourseModal').classList.remove('hidden');
        }

        function closeCreateModal() {
            document.getElementById('createCourseModal').classList.add('hidden');
        }

        function openEditModal(course) {
            document.getElementById('editCourseForm').action = `/courses/${course.id}`;
            document.getElementById('edit_code').value = course.code || '';
            document.getElementById('edit_name').value = course.name || '';
            document.getElementById('edit_tuition_fee').value = Math.round(course.tuition_fee) || 0;
            document.getElementById('edit_total_lessons').value = course.total_lessons || 24;
            document.getElementById('edit_course_level_id').value = course.course_level_id || '';
            document.getElementById('edit_description').value = course.description || '';
            document.getElementById('edit_is_active').checked = !!course.is_active;

            document.getElementById('editCourseModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editCourseModal').classList.add('hidden');
        }
    </script>
</x-app-layout>
