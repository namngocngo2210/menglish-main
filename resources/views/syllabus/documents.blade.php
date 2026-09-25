<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">folder_shared</span>
                    Quản lý tài liệu giáo trình
                </h1>
                <p class="text-xs text-gray-500">Quản lý và cập nhật tài liệu cho các khóa học theo chuẩn hệ thống Round Cuối.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.builder') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">edit_document</span>
                    <span>Soạn Syllabus (Bước #2)</span>
                </a>
                <a href="{{ route('teacher-portal.shortcut', '08_xem_tai_lieu_giao_trinh') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">menu_book</span>
                    <span>Xem bài giảng GV (Bước #4)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 1])

    <!-- Main Content 2-Column Grid (Bento Style) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Section: Tải lên tài liệu mới (Left Column) -->
        <section class="lg:col-span-4 flex flex-col gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">upload_file</span>
                    <h2 class="text-sm font-bold text-gray-900">Tải lên tài liệu mới</h2>
                </div>

                <form action="{{ route('syllabus.documents.store') }}" method="POST" class="space-y-3.5">
                    @csrf
                    <!-- Mã giáo trình & Phiên bản -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Mã giáo trình <span class="text-rose-500">*</span></label>
                            <input type="text" name="code" value="CUR-{{ strtoupper(Str::random(4)) }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" placeholder="CUR-GTB2" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Phiên bản <span class="text-rose-500">*</span></label>
                            <input type="text" name="version" value="v1.0" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-mono focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                        </div>
                    </div>

                    <!-- Tên tài liệu / Giáo trình -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tên tài liệu / Giáo trình <span class="text-rose-500">*</span></label>
                        <input type="text" name="title" placeholder="IELTS Reading Masterclass - Student Book" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-medium focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                    </div>

                    <!-- Chọn khóa học -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn khóa học áp dụng <span class="text-rose-500">*</span></label>
                        <select name="course_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none">
                            <option value="">-- Chọn khóa học --</option>
                            @foreach ($courses as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Chọn chặng học -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn chặng học <span class="text-rose-500">*</span></label>
                        <select class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none">
                            <option value="phase1">Chặng 1 (0 - 3.0: Xây dựng nền tảng)</option>
                            <option value="phase2">Chặng 2 (3.0 - 5.0: Kỹ năng chuyên sâu)</option>
                            <option value="phase3">Chặng 3 (5.0 - 6.5: Luyện đề &amp; Bứt phá)</option>
                        </select>
                    </div>

                    <!-- Chọn đối tượng xem -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Chọn đối tượng xem <span class="text-rose-500">*</span></label>
                        <div class="grid grid-cols-2 gap-2 bg-gray-50 p-3 rounded-xl border border-gray-100 text-xs">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input checked type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Admin</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input checked type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Học vụ</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input checked type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Học thuật</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input id="teacher-checkbox" type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary-container h-4 w-4" />
                                <span class="font-medium text-gray-700">Giáo viên</span>
                            </label>
                        </div>
                    </div>

                    <!-- Vùng kéo thả file -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tài liệu đính kèm (File PDF/DOCX) <span class="text-rose-500">*</span></label>
                        <div class="border-2 border-dashed border-gray-200 hover:border-primary-container/50 rounded-xl p-5 flex flex-col items-center justify-center text-center bg-gray-50/50 hover:bg-orange-50/20 transition-all cursor-pointer">
                            <span class="material-symbols-outlined text-3xl text-gray-400 mb-1">cloud_upload</span>
                            <p class="text-xs font-semibold text-gray-700">Kéo thả file vào đây hoặc</p>
                            <span class="mt-1.5 px-3 py-1 bg-white border border-gray-200 rounded-lg text-[11px] font-semibold text-gray-700 shadow-2xs hover:bg-gray-50">Chọn file từ máy tính</span>
                            <p class="text-[10px] text-gray-400 mt-2">Hỗ trợ PDF, DOCX, PPTX (Tối đa 50MB)</p>
                        </div>
                    </div>

                    <!-- Banner cảnh báo tự động khi tích chọn Giáo viên -->
                    <div id="teacher-warning-banner" class="hidden bg-amber-50 text-amber-900 p-3 rounded-xl flex items-start gap-2 border border-amber-200 text-xs">
                        <span class="material-symbols-outlined text-amber-600 shrink-0 text-[18px]">lock</span>
                        <p class="leading-relaxed"><strong>Khóa tải xuống (Bảo mật):</strong> Giáo viên chỉ được phép xem trực tuyến trên Cổng Giáo viên (Step #4) để bảo vệ bản quyền giáo trình.</p>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2 border-t border-gray-100">
                        <button type="submit" class="w-full bg-primary-container hover:bg-primary-hover text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition-all flex items-center justify-center gap-2 text-xs">
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            <span>Lưu tài liệu giáo trình</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Section: Danh sách tài liệu đã tải lên (Right Column) -->
        <section class="lg:col-span-8 flex flex-col gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col h-full">
                <!-- Search & Filters Toolbar -->
                <div class="p-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-3 bg-gray-50/50">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">library_books</span>
                        <h2 class="text-sm font-bold text-gray-900">Danh sách tài liệu đã tải lên</h2>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-200 text-gray-700">{{ $curriculums->count() }} tài liệu</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                            <input type="text" id="docSearchInput" onkeyup="filterDocsTable()" placeholder="Tìm kiếm tài liệu..." class="pl-9 pr-3 py-1.5 rounded-xl border border-gray-200 text-xs w-56 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none bg-white" />
                        </div>
                        <a href="{{ route('teacher-portal.shortcut', '08_xem_tai_lieu_giao_trinh') }}" target="_blank" class="p-2 rounded-xl border border-gray-200 text-gray-600 hover:text-primary hover:bg-orange-50/30 transition" title="Mở Trình đọc PDF Cổng Giáo viên (Step #4)">
                            <span class="material-symbols-outlined text-[18px]">preview</span>
                        </a>
                    </div>
                </div>

                <!-- Table Content -->
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse text-xs" id="docsTable">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="py-3 px-4">Tên tài liệu</th>
                                <th class="py-3 px-4">Chặng học</th>
                                <th class="py-3 px-4">Đối tượng xem</th>
                                <th class="py-3 px-4">Quyền truy cập</th>
                                <th class="py-3 px-4 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                            @forelse ($curriculums as $index => $cur)
                                @php
                                    $isOnlineOnly = ($index % 2 === 1);
                                    $stageNumber = ($index % 3) + 1;
                                @endphp
                                <tr class="hover:bg-gray-50/80 transition-colors group">
                                    <td class="py-3.5 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-xl {{ $isOnlineOnly ? 'bg-orange-50 text-primary' : 'bg-red-50 text-red-600' }} flex items-center justify-center shrink-0 shadow-2xs">
                                                <span class="material-symbols-outlined text-[20px]">picture_as_pdf</span>
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900 line-clamp-1 text-xs">{{ $cur->title }}</p>
                                                <p class="text-[11px] text-gray-400 mt-0.5">
                                                    {{ $cur->course?->name ?? 'MEnglish General' }} • <span class="font-mono">{{ $cur->file_size ?? '2.4 MB' }}</span> • <span class="font-mono text-primary font-semibold">{{ $cur->version }}</span>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                        <span class="font-medium text-gray-800">Chặng {{ $stageNumber }}</span>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="flex flex-wrap gap-1">
                                            <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold text-[10px] border border-blue-200/50">Admin</span>
                                            <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-semibold text-[10px] border border-amber-200/50">Học thuật</span>
                                            @if($isOnlineOnly)
                                                <span class="px-2 py-0.5 rounded-full bg-orange-50 text-primary font-semibold text-[10px] border border-orange-200/50">Giáo viên</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                        @if($isOnlineOnly)
                                            <span class="px-2.5 py-1 rounded-lg bg-rose-50 text-rose-700 text-[11px] font-medium inline-flex items-center gap-1 border border-rose-200/60">
                                                <span class="material-symbols-outlined text-[13px]">visibility</span>
                                                Chỉ xem online
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-[11px] font-medium inline-flex items-center gap-1 border border-emerald-200/60">
                                                <span class="material-symbols-outlined text-[13px]">download</span>
                                                Có thể tải
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('teacher-portal.shortcut', '08_xem_tai_lieu_giao_trinh') }}" target="_blank" class="p-1.5 rounded-lg text-gray-500 hover:text-primary hover:bg-orange-50 transition" title="Xem trên Cổng GV (Step #4)">
                                                <span class="material-symbols-outlined text-[18px]">visibility</span>
                                            </a>
                                            <a href="{{ route('syllabus.builder') }}" class="p-1.5 rounded-lg text-gray-500 hover:text-primary hover:bg-orange-50 transition" title="Soạn Unit (Step #2)">
                                                <span class="material-symbols-outlined text-[18px]">edit_document</span>
                                            </a>
                                            <button type="button" class="p-1.5 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Xóa tài liệu">
                                                <span class="material-symbols-outlined text-[18px]">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-12 text-center text-gray-400">Chưa có tài liệu giáo trình nào trong CSDL.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination -->
                <div class="p-4 border-t border-gray-100 flex items-center justify-between mt-auto bg-gray-50/30">
                    <p class="text-xs text-gray-500">Hiển thị {{ $curriculums->count() }} tài liệu trong kho</p>
                    <div class="flex items-center gap-1">
                        <button class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-400 hover:bg-white disabled:opacity-40" disabled>
                            <span class="material-symbols-outlined text-[18px]">chevron_left</span>
                        </button>
                        <button class="w-8 h-8 flex items-center justify-center rounded-lg bg-primary-container text-white text-xs font-bold shadow-2xs">1</button>
                        <button class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-white text-xs font-medium">2</button>
                        <button class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-400 hover:bg-white">
                            <span class="material-symbols-outlined text-[18px]">chevron_right</span>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        // Interactive warning banner logic
        const teacherCheckbox = document.getElementById('teacher-checkbox');
        const warningBanner = document.getElementById('teacher-warning-banner');

        if (teacherCheckbox && warningBanner) {
            teacherCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    warningBanner.classList.remove('hidden');
                } else {
                    warningBanner.classList.add('hidden');
                }
            });
        }

        // Search filter
        function filterDocsTable() {
            const input = document.getElementById('docSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('docsTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const text = tr[i].textContent || tr[i].innerText;
                if (text.toLowerCase().indexOf(filter) > -1) {
                    tr[i].style.display = '';
                } else {
                    tr[i].style.display = 'none';
                }
            }
        }
    </script>
</x-app-layout>
