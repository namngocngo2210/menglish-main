<x-app-layout>
    <x-ui.page-header title="Quản lý Khóa học & Bảng giá học phí" icon="price_change" description="Cấu hình giá niêm yết, số buổi học và liên kết khung trình độ chuẩn CEFR/IELTS">
        <x-slot:actions>
            <x-ui.button icon="add_circle" onclick="openCreateModal()">Thêm khóa học &amp; Giá mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-5" x-data="courseManager()">
        {{-- 1. KPI Cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <x-ui.stat-card label="Tổng số khóa học" :value="number_format($stats['total_courses'])" tone="primary" icon="school" />
            <x-ui.stat-card label="Đang mở tuyển sinh" :value="number_format($stats['active_courses'])" tone="success" icon="check_circle" />
            <x-ui.stat-card label="Học phí trung bình" :value="number_format($stats['avg_tuition']) . 'đ'" tone="secondary" icon="payments" />
            <x-ui.stat-card label="Mức giá cao nhất" :value="number_format($stats['max_tuition']) . 'đ'" tone="secondary" icon="workspace_premium" />
        </div>

        {{-- 2. Filter Bar --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/90 shadow-sm p-4">
            <form method="GET" action="{{ route('courses.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                {{-- Search --}}
                <div class="lg:col-span-2">
                    <x-ui.input name="search" label="Tìm kiếm khóa học" icon="search" :value="request('search')" class="text-xs"
                                placeholder="Nhập tên khóa học, mã code (IE-65, GT-B1)..." />
                </div>

                {{-- Level --}}
                <x-ui.select name="course_level_id" label="Khung trình độ" class="text-xs" placeholder="-- Tất cả trình độ --"
                             :options="$levels->mapWithKeys(fn ($lv) => [$lv->id => $lv->name . ' (' . $lv->code . ')'])" />

                {{-- Status --}}
                <x-ui.select name="status" label="Trạng thái mở bán" class="text-xs" placeholder="-- Tất cả trạng thái --"
                             :options="['active' => '🟢 Đang mở bán', 'inactive' => '⚪ Tạm ngưng']" />

                {{-- Actions --}}
                <div class="flex items-end gap-2">
                    <x-ui.button variant="secondary" :href="route('courses.index')">Đặt lại</x-ui.button>
                    <x-ui.button type="submit" icon="filter_alt" class="flex-1">Lọc</x-ui.button>
                </div>
            </form>
        </div>

        {{-- 3. Courses Table / Price List --}}
        <x-ui.data-table min-width="1040px">
                <table class="text-xs">
                    <thead>
                        <tr>
                            <th class="min-w-[90px]">Mã khóa</th>
                            <th class="min-w-[220px]">Tên khóa học &amp; Mục tiêu</th>
                            <th class="min-w-[220px]">Khung trình độ</th>
                            <th class="text-right min-w-[150px]">Giá học phí niêm yết</th>
                            <th class="text-center min-w-[90px]">Thời lượng</th>
                            <th class="text-center min-w-[100px]">Lớp đang chạy</th>
                            <th class="text-center min-w-[120px]">Trạng thái</th>
                            <th class="text-right min-w-[130px]">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($courses as $c)
                            <tr class="group">
                                {{-- Code --}}
                                <td class="font-mono font-bold whitespace-nowrap">
                                    <span class="px-2 py-1 bg-surface-container rounded-lg text-on-surface border border-surface-container-highest/70 inline-block">
                                        {{ $c->code }}
                                    </span>
                                </td>

                                {{-- Name & Description --}}
                                <td>
                                    <div class="font-bold text-on-surface text-xs">{{ $c->name }}</div>
                                    @if ($c->description)
                                        <div class="text-[11px] text-on-surface-variant line-clamp-1 mt-0.5 max-w-sm">{{ $c->description }}</div>
                                    @endif
                                </td>

                                {{-- Level --}}
                                <td class="whitespace-nowrap">
                                    @if ($c->level)
                                        <div class="whitespace-nowrap">
                                            <div class="flex items-center gap-1.5 whitespace-nowrap">
                                                <span class="px-2 py-0.5 rounded-md bg-secondary/10 text-secondary font-mono font-bold text-[11px] border border-secondary/20 shrink-0">
                                                    {{ $c->level->code }}
                                                </span>
                                                <span class="font-semibold text-on-surface text-xs whitespace-nowrap">
                                                    {{ $c->level->name }}
                                                </span>
                                            </div>
                                            @if ($c->level->target)
                                                <div class="text-[10px] text-on-surface-variant/70 font-medium mt-0.5 whitespace-nowrap flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[13px] text-on-surface-variant/70">flag</span>
                                                    <span>{{ $c->level->target }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-on-surface-variant/70 italic text-[11px] whitespace-nowrap">Chưa gắn level</span>
                                    @endif
                                </td>

                                {{-- Tuition Fee (Giá khóa học) --}}
                                <td class="text-right whitespace-nowrap">
                                    <div class="font-mono font-extrabold text-sm text-primary">
                                        {{ number_format($c->tuition_fee) }} đ
                                    </div>
                                    <span class="text-[10px] text-on-surface-variant/70 block">Giá trọn gói</span>
                                </td>

                                {{-- Lessons --}}
                                <td class="text-center whitespace-nowrap">
                                    <span class="font-mono font-bold text-on-surface">{{ $c->total_lessons }}</span>
                                    <span class="text-[10px] text-on-surface-variant/70 block">buổi học</span>
                                </td>

                                {{-- Classes Count --}}
                                <td class="text-center font-mono font-semibold text-on-surface-variant whitespace-nowrap">
                                    <x-ui.badge :dot="false" :pill="true">{{ $c->classes_count }} lớp</x-ui.badge>
                                </td>

                                {{-- Active Status --}}
                                <td class="text-center whitespace-nowrap">
                                    <form action="{{ route('courses.toggle', $c->id) }}" method="POST" class="inline-block">
                                        @csrf
                                        @method('PATCH')
                                        <button 
                                            type="submit" 
                                            class="px-2.5 py-1 rounded-full text-[10px] font-bold transition cursor-pointer {{ $c->is_active ? 'bg-tertiary/10 text-tertiary border border-tertiary/30 hover:bg-tertiary/20' : 'bg-surface-container text-on-surface-variant border border-surface-container-highest hover:bg-surface-container-high' }}"
                                            title="Bấm để chuyển trạng thái mở bán"
                                        >
                                            {{ $c->is_active ? '🟢 Đang mở bán' : '⚪ Tạm ngưng' }}
                                        </button>
                                    </form>
                                </td>

                                {{-- Actions --}}
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                        {{-- Edit Price / Course Button --}}
                                        <x-ui.button variant="secondary" size="sm" icon="edit" onclick="openEditModal({{ Js::from($c) }})"
                                                     title="Chỉnh sửa giá học phí & thông tin khóa học">Sửa giá</x-ui.button>

                                        {{-- Delete Button --}}
                                        @if ($c->classes_count === 0)
                                            <form action="{{ route('courses.destroy', $c->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa khóa học {{ $c->name }} không?');">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" />
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8"><x-ui.empty-state icon="school" title="Chưa có khóa học nào khớp với điều kiện tìm kiếm." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

            {{-- Pagination (10, 20, 50, 100, All) --}}
            <x-slot:footer><x-ui.pagination :paginator="$courses" /></x-slot:footer>
        </x-ui.data-table>

        {{-- 4. Create Course Modal --}}
        <x-ui.modal name="create-course" title="Thêm Khóa Học & Thiết Lập Giá" max-width="lg">
                <form id="createCourseForm" action="{{ route('courses.store') }}" method="POST" class="space-y-4 text-xs">
                    @csrf

                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input name="code" id="create_code" label="Mã khóa học" required placeholder="Ví dụ: IE-65, GT-B1" class="text-xs font-mono font-bold" />
                        <x-ui.input type="number" name="total_lessons" id="create_total_lessons" label="Số buổi học" required value="24" min="1" class="text-xs font-mono" />
                    </div>

                    <x-ui.input name="name" id="create_name" label="Tên khóa học" required placeholder="Ví dụ: IELTS 6.5 Intensive, Giao tiếp Pro B1" class="text-xs font-bold" />

                    {{-- TUITION FEE (Giá học phí) --}}
                    <div class="bg-primary-container/5 p-3.5 rounded-2xl border border-primary-container/25">
                        <label for="create_tuition_fee" class="block font-bold text-primary mb-1 flex items-center justify-between">
                            <span>Giá học phí niêm yết (VNĐ) <span class="text-error">*</span></span>
                            <span class="text-[10px] text-primary font-normal">Học phí trọn gói</span>
                        </label>
                        <x-ui.input type="number" name="tuition_fee" id="create_tuition_fee" placeholder="12500000" min="0" step="10000" required suffix="VNĐ"
                                    class="text-sm font-mono font-black text-primary" />
                    </div>

                    <x-ui.select name="course_level_id" id="create_course_level_id" label="Khung trình độ trực thuộc" class="text-xs" placeholder="-- Chọn khung trình độ (CEFR/IELTS) --"
                                 value="" :options="$levels->mapWithKeys(fn ($lv) => [$lv->id => $lv->name . ' (' . $lv->code . ')'])" />

                    <x-ui.textarea name="description" id="create_description" label="Mô tả & Cam kết đầu ra" rows="2" class="text-xs" placeholder="Cam kết band điểm, tài liệu độc quyền..." />

                    <div class="flex items-center gap-2 pt-1">
                        <input type="checkbox" name="is_active" id="create_is_active" value="1" checked class="rounded text-primary focus:ring-primary-container border-outline-variant w-4 h-4 cursor-pointer" />
                        <label for="create_is_active" class="font-bold text-on-surface-variant cursor-pointer">Kích hoạt mở bán ngay sau khi tạo</label>
                    </div>
                </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" onclick="closeCreateModal()">Hủy</x-ui.button>
                <x-ui.button type="submit" form="createCourseForm">Lưu Khóa Học</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- 5. Edit Course & Price Modal --}}
        <x-ui.modal name="edit-course" title="Chỉnh Sửa Giá & Thông Tin Khóa Học" max-width="lg">
                <form id="editCourseForm" method="POST" action="" class="space-y-4 text-xs">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input name="code" id="edit_code" label="Mã khóa học" required value="" class="text-xs font-mono font-bold" />
                        <x-ui.input type="number" name="total_lessons" id="edit_total_lessons" label="Số buổi học" required value="" min="1" class="text-xs font-mono" />
                    </div>

                    <x-ui.input name="name" id="edit_name" label="Tên khóa học" required value="" class="text-xs font-bold" />

                    {{-- EDIT TUITION FEE (Chỉnh sửa giá học phí) --}}
                    <div class="bg-primary-container/10 p-3.5 rounded-2xl border border-primary-container/30">
                        <label for="edit_tuition_fee" class="block font-bold text-primary mb-1 flex items-center justify-between">
                            <span>Giá học phí niêm yết (VNĐ) <span class="text-error">*</span></span>
                            <span class="text-[10px] text-primary font-normal">Chỉnh sửa giá mới</span>
                        </label>
                        <x-ui.input type="number" name="tuition_fee" id="edit_tuition_fee" min="0" step="10000" required suffix="VNĐ"
                                    class="text-base font-mono font-black text-primary" />
                    </div>

                    <x-ui.select name="course_level_id" id="edit_course_level_id" label="Khung trình độ trực thuộc" class="text-xs" placeholder="-- Chưa gắn khung trình độ --"
                                 value="" :options="$levels->mapWithKeys(fn ($lv) => [$lv->id => $lv->name . ' (' . $lv->code . ')'])" />

                    <x-ui.textarea name="description" id="edit_description" label="Mô tả & Mục tiêu" rows="2" class="text-xs" value="" />

                    <div class="flex items-center gap-2 pt-1">
                        <input type="checkbox" name="is_active" id="edit_is_active" value="1" class="rounded text-primary focus:ring-primary-container border-outline-variant w-4 h-4 cursor-pointer" />
                        <label for="edit_is_active" class="font-bold text-on-surface-variant cursor-pointer">Đang mở bán khóa học này</label>
                    </div>
                </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" onclick="closeEditModal()">Hủy</x-ui.button>
                <x-ui.button type="submit" form="editCourseForm">Cập Nhật Học Phí</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>

    <script>
        function courseManager() {
            return {};
        }

        function openCreateModal() {
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'create-course' }));
        }

        function closeCreateModal() {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'create-course' }));
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

            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'edit-course' }));
        }

        function closeEditModal() {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'edit-course' }));
        }
    </script>
</x-app-layout>
