<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">edit_document</span>
                        Soạn syllabus theo chặng
                    </h1>
                    <p class="text-xs text-gray-500">Thiết lập cấu trúc chương trình học và nội dung chi tiết từng buổi học.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.assignments') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">assignment_ind</span>
                    <span>Giao chặng GV (Bước #3)</span>
                </a>
                <a href="{{ route('teacher-portal.shortcut', '08_xem_tai_lieu_giao_trinh') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">visibility</span>
                    <span>Cổng Giáo viên (Bước #4)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 2])

    <div class="max-w-5xl mx-auto space-y-6 pb-20">
        <!-- Section 1: Thông tin chung chặng học -->
        <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center gap-2 mb-5 pb-3 border-b border-gray-100">
                <span class="material-symbols-outlined text-primary">info</span>
                <h2 class="text-sm font-bold text-gray-900">Thông tin chung chặng học</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-1.5">
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider">Tên chặng học / Giáo trình <span class="text-rose-500">*</span></label>
                    <input type="text" value="{{ $curriculum?->title ?? 'Chặng 1: Xây dựng nền tảng (Foundation)' }}" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-medium focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider">Chính sách mở khóa</label>
                    <div class="relative">
                        <select class="w-full text-xs rounded-xl border border-gray-200 p-2.5 appearance-none bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none pr-8">
                            <option>Mở khóa theo tuần</option>
                            <option>Hoàn thành bài kiểm tra (Big Test) mới được mở</option>
                            <option>Mở khóa thủ công bởi Admin</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400 text-[18px]">expand_more</span>
                    </div>
                </div>

                <div class="md:col-span-2 space-y-1.5">
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider">Link ảnh / Tài liệu tổng quan chặng (overview_link)</label>
                    <div class="flex gap-2">
                        <input type="text" value="https://dungthu.meducation.vn/syllabus-overview-stage-1.jpg" class="flex-1 text-xs rounded-xl border border-gray-200 p-2.5 font-mono text-gray-600 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                        <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold flex items-center gap-1 transition">
                            <span class="material-symbols-outlined text-[16px]">visibility</span>
                            <span>Xem thử</span>
                        </button>
                    </div>
                </div>

                <!-- Image Preview Area -->
                <div class="md:col-span-2">
                    <div class="w-full h-48 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/60 flex flex-col items-center justify-center overflow-hidden relative group hover:border-primary-container/40 transition">
                        <div class="flex flex-col items-center text-center p-4">
                            <span class="material-symbols-outlined text-4xl text-gray-400 mb-1">image</span>
                            <p class="text-xs font-semibold text-gray-700">Khu vực hiển thị preview ảnh mục lục tổng quan</p>
                            <p class="text-[11px] text-gray-400">Hình ảnh trực quan chặng học sẽ được hiển thị cho Học viên &amp; Giáo viên tra cứu</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section 2: Form Soạn Bài Học (Unit) Mới -->
        <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm relative overflow-hidden">
            <div class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container"></div>
            <div class="flex items-center justify-between mb-5 pb-3 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">add_circle</span>
                    <h2 class="text-sm font-bold text-gray-900">Soạn bài học (Unit) mới vào chặng</h2>
                </div>
                <span class="px-2.5 py-1 bg-orange-100 text-primary font-bold text-[11px] rounded-full">
                    Đã có {{ $units->count() }} buổi
                </span>
            </div>

            <form action="{{ route('syllabus.units.store') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="curriculum_id" value="{{ $curriculum?->id ?? 1 }}" />

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Số thứ tự Buổi <span class="text-rose-500">*</span></label>
                        <div class="flex items-center">
                            <span class="px-3 py-2.5 bg-gray-100 border border-r-0 border-gray-200 rounded-l-xl text-xs font-bold text-gray-600">Buổi</span>
                            <input type="number" name="unit_number" value="{{ ($units->max('unit_number') ?? 0) + 1 }}" required class="w-full text-xs rounded-r-xl border border-gray-200 p-2.5 font-mono font-bold focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                        </div>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Tiêu đề bài học (Unit Title) <span class="text-rose-500">*</span></label>
                        <input type="text" name="title" placeholder="Buổi 01: Introduction to IELTS &amp; Greetings" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                    </div>
                </div>

                <!-- Target / Objectives -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Mục tiêu buổi học (Target Objectives)</label>
                    <textarea name="objectives" rows="2" placeholder="Người học cần nắm được format bài thi, vận dụng 15 từ vựng chủ đề Greetings..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none"></textarea>
                </div>

                <!-- Main Content (Vocab & Grammar) & Homework -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>Trọng tâm Từ vựng (Vocabulary Focus)</span>
                            <span class="text-[11px] text-gray-400 font-mono">Markdown</span>
                        </label>
                        <div class="border border-gray-200 rounded-xl overflow-hidden focus-within:border-primary-container">
                            <div class="flex items-center gap-1 px-3 py-1.5 border-b border-gray-100 bg-gray-50 text-gray-500">
                                <span class="material-symbols-outlined text-[16px]">format_bold</span>
                                <span class="material-symbols-outlined text-[16px]">format_italic</span>
                                <span class="material-symbols-outlined text-[16px]">format_list_bulleted</span>
                            </div>
                            <textarea name="vocabulary_focus" rows="4" placeholder="- Topic: Greetings &amp; Introducing self&#10;- Core words: Hello, Greetings, Enthusiastic, Professional" class="w-full text-xs p-2.5 border-none focus:ring-0 outline-none"></textarea>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>Trọng tâm Ngữ pháp (Grammar Focus)</span>
                            <span class="text-[11px] text-gray-400 font-mono">Markdown</span>
                        </label>
                        <div class="border border-gray-200 rounded-xl overflow-hidden focus-within:border-primary-container">
                            <div class="flex items-center gap-1 px-3 py-1.5 border-b border-gray-100 bg-gray-50 text-gray-500">
                                <span class="material-symbols-outlined text-[16px]">format_bold</span>
                                <span class="material-symbols-outlined text-[16px]">format_italic</span>
                                <span class="material-symbols-outlined text-[16px]">rule</span>
                            </div>
                            <textarea name="grammar_focus" rows="4" placeholder="- Present Simple with 'To Be' (am/is/are)&#10;- Basic sentence order: S + V + O" class="w-full text-xs p-2.5 border-none focus:ring-0 outline-none"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Homework Guide -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Bài tập về nhà (Homework Guide)</label>
                    <div class="border border-gray-200 rounded-xl overflow-hidden focus-within:border-primary-container">
                        <div class="flex items-center gap-1 px-3 py-1.5 border-b border-gray-100 bg-gray-50 text-gray-500">
                            <span class="material-symbols-outlined text-[16px]">format_list_numbered</span>
                            <span class="material-symbols-outlined text-[16px]">checklist</span>
                            <span class="material-symbols-outlined text-[16px]">attach_file</span>
                        </div>
                        <textarea name="homework_guide" rows="3" placeholder="1. Quay video giới thiệu bản thân 60s nộp Cổng Học sinh (Flow 4)&#10;2. Làm bài tập trang 12-14 trong Workbook Starter" class="w-full text-xs p-2.5 border-none focus:ring-0 outline-none"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-3 border-t border-gray-100">
                    <button type="submit" class="px-5 py-2.5 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        <span>Lưu buổi học vào CSDL</span>
                    </button>
                </div>
            </form>
        </section>

        <!-- Section 3: Danh sách các buổi học đã soạn (Cards) -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">menu_book</span>
                    <h2 class="text-sm font-bold text-gray-900">Danh sách buổi học trong chặng ({{ $units->count() }})</h2>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($units as $u)
                    <div class="group bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm hover:border-primary-container/60 transition relative">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-primary-container"></div>
                        <div class="p-5">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-orange-100 text-primary font-bold flex items-center justify-center text-xs">
                                        {{ $u->unit_number }}
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-bold text-gray-900">{{ $u->title }}</h3>
                                        <span class="text-[11px] text-gray-400">Buổi #{{ $u->unit_number }} trong khung chương trình</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1">
                                    <a href="{{ route('teacher-portal.shortcut', '08_xem_tai_lieu_giao_trinh') }}" target="_blank" class="px-2.5 py-1 rounded-lg bg-gray-50 hover:bg-gray-100 text-gray-600 text-xs font-medium border border-gray-200 inline-flex items-center gap-1 transition" title="Xem trên Cổng GV">
                                        <span class="material-symbols-outlined text-[15px]">visibility</span>
                                        <span class="hidden sm:inline">Xem Slide GV</span>
                                    </a>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                                <div class="bg-gray-50 rounded-xl p-3.5 border border-gray-100 space-y-2">
                                    <div class="font-semibold text-gray-800 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-primary text-[16px]">flag</span>
                                        <span>Mục tiêu buổi học (Target)</span>
                                    </div>
                                    <p class="text-gray-600 leading-relaxed">{{ $u->objectives ?? 'Chưa cập nhật mục tiêu' }}</p>
                                </div>

                                <div class="bg-gray-50 rounded-xl p-3.5 border border-gray-100 space-y-2">
                                    <div class="font-semibold text-gray-800 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-primary text-[16px]">assignment</span>
                                        <span>Bài tập về nhà (Homework)</span>
                                    </div>
                                    <p class="text-gray-600 leading-relaxed">{{ $u->homework_guide ?? 'Quay video bài tập và làm bài tập trên hệ thống portal' }}</p>
                                </div>

                                @if ($u->vocabulary_focus || $u->grammar_focus)
                                    <div class="md:col-span-2 bg-orange-50/40 rounded-xl p-3.5 border border-orange-200/50 space-y-2">
                                        <div class="font-semibold text-orange-950 flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-primary text-[16px]">menu_book</span>
                                            <span>Trọng tâm kiến thức chính</span>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-gray-700">
                                            <div>
                                                <span class="font-medium text-gray-900 block text-[11px] uppercase tracking-wider mb-0.5">Từ vựng:</span>
                                                <p class="font-mono text-[11px] whitespace-pre-line">{{ $u->vocabulary_focus ?? '—' }}</p>
                                            </div>
                                            <div>
                                                <span class="font-medium text-gray-900 block text-[11px] uppercase tracking-wider mb-0.5">Ngữ pháp:</span>
                                                <p class="font-mono text-[11px] whitespace-pre-line">{{ $u->grammar_focus ?? '—' }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center bg-white rounded-2xl border border-gray-200 text-gray-400 text-xs">
                        Chưa có buổi học nào được soạn trong chặng này. Hãy sử dụng form ở trên để thêm buổi học mới!
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <!-- Sticky Bottom Bar -->
    <div class="fixed bottom-0 left-0 lg:left-[280px] right-0 bg-white border-t border-gray-200 py-3 px-6 z-20 shadow-lg">
        <div class="max-w-5xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <span class="material-symbols-outlined text-emerald-600 text-[18px]">check_circle</span>
                <span>Hệ thống Syllabus tự động đồng bộ CSDL thời gian thực</span>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.documents') }}" class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-xs font-semibold hover:bg-gray-50 transition">
                    Quay lại
                </a>
                <a href="{{ route('syllabus.assignments') }}" class="px-4 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5">
                    <span>Tiếp tục: Giao chặng GV (Bước #3)</span>
                    <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
