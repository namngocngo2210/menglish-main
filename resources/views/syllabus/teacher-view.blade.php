<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">menu_book</span>
                        Xem tài liệu giáo trình (Cổng Giáo viên — Bước #4)
                    </h1>
                    <p class="text-xs text-gray-500">Giáo viên tra cứu tài liệu bài giảng, giáo án và slide theo từng buổi học trực tuyến có bảo mật bản quyền.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.teacher-propose') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">edit_attributes</span>
                    <span>Đề xuất sửa (Bước #5)</span>
                </a>
                <a href="{{ route('syllabus.documents') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">folder_shared</span>
                    <span>Quản lý kho tài liệu (Bước #1)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 4])

    <!-- Main Content Area: Teacher Portal PDF Viewer -->
    <div class="space-y-6">
        <!-- Sub-header & Tabs -->
        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-xs text-gray-500">
                <span class="material-symbols-outlined text-primary text-[18px]">school</span>
                <span class="font-medium text-gray-700">Khóa học áp dụng:</span>
                <span class="px-2.5 py-0.5 rounded-lg bg-orange-50 text-primary font-bold border border-orange-200/60">IELTS Intensive Foundation</span>
                <span class="text-gray-300">•</span>
                <span>Chặng 1: Nền tảng (0 - 3.0)</span>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex border-b border-gray-200">
                    <button class="pb-2 px-3 border-b-2 border-primary-container text-primary font-bold text-xs">Tài liệu bài giảng</button>
                    <button class="pb-2 px-3 border-b-2 border-transparent text-gray-500 hover:text-gray-800 font-medium text-xs transition">Tổng quan syllabus</button>
                    <button class="pb-2 px-3 border-b-2 border-transparent text-gray-500 hover:text-gray-800 font-medium text-xs transition">Nội dung buổi học</button>
                </div>
            </div>
        </div>

        <!-- 2-Column Bento Grid: Document list (Left 4 cols) & PDF Canvas Reader (Right 8 cols) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[750px]">
            <!-- Left: Document List -->
            <section class="lg:col-span-4 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col h-full min-w-0">
                <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <h2 class="text-sm font-bold text-gray-900">Danh sách tài liệu chặng</h2>
                    <span class="bg-orange-100 text-primary px-2 py-0.5 rounded-full text-[10px] font-bold">Khóa Starter</span>
                </div>

                <div class="overflow-y-auto p-4 space-y-4 flex-1 custom-scrollbar">
                    <!-- Chặng 1 Group -->
                    <div class="space-y-2.5">
                        <h3 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider pl-1">Chặng 1: Nền tảng (Buổi 1 - 10)</h3>
                        
                        <!-- Item 1 (Selected) -->
                        <div class="bg-orange-50/50 text-gray-900 p-3.5 rounded-xl border border-primary-container cursor-pointer transition-all shadow-2xs relative overflow-hidden">
                            <div class="flex items-start gap-3 relative z-10">
                                <div class="bg-white p-2 rounded-lg text-red-600 shadow-2xs border border-red-100">
                                    <span class="material-symbols-outlined text-[20px]">picture_as_pdf</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-xs font-bold text-gray-900 truncate mb-1">Giáo trình Starter - Bài 1 &amp; 2</h4>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-500">
                                        <span class="flex items-center gap-1 font-mono"><span class="material-symbols-outlined text-[13px]">description</span> PDF • 24 trang</span>
                                        <span class="text-gray-300">•</span>
                                        <span class="flex items-center gap-0.5 text-rose-600 font-medium"><span class="material-symbols-outlined text-[13px]">lock</span> Không tải về</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Item 2 -->
                        <div class="bg-white hover:bg-gray-50 p-3.5 rounded-xl border border-gray-200 cursor-pointer transition-all group">
                            <div class="flex items-start gap-3">
                                <div class="bg-gray-100 group-hover:bg-white p-2 rounded-lg text-emerald-600 shadow-2xs border border-gray-100">
                                    <span class="material-symbols-outlined text-[20px]">co_present</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-xs font-bold text-gray-800 truncate mb-1">Slide Bài giảng Bài 1 &amp; 2 (Trình chiếu)</h4>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-500">
                                        <span class="flex items-center gap-1 font-mono"><span class="material-symbols-outlined text-[13px]">smart_display</span> Slide PPTX</span>
                                        <span class="text-gray-300">•</span>
                                        <span class="flex items-center gap-0.5 text-emerald-600 font-medium"><span class="material-symbols-outlined text-[13px]">download</span> Có thể tải</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chặng 2 Group -->
                    <div class="space-y-2.5 pt-2 border-t border-gray-100">
                        <h3 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider pl-1">Chặng 2: Giao tiếp &amp; Phản xạ</h3>

                        <!-- Item 3 -->
                        <div class="bg-white hover:bg-gray-50 p-3.5 rounded-xl border border-gray-200 cursor-pointer transition-all group">
                            <div class="flex items-start gap-3">
                                <div class="bg-gray-100 group-hover:bg-white p-2 rounded-lg text-red-600 shadow-2xs border border-gray-100">
                                    <span class="material-symbols-outlined text-[20px]">picture_as_pdf</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-xs font-bold text-gray-800 truncate mb-1">Giáo trình Starter - Bài 3 &amp; 4</h4>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-500">
                                        <span class="flex items-center gap-1 font-mono"><span class="material-symbols-outlined text-[13px]">description</span> PDF</span>
                                        <span class="text-gray-300">•</span>
                                        <span class="flex items-center gap-0.5 text-rose-600 font-medium"><span class="material-symbols-outlined text-[13px]">lock</span> Không tải về</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Item 4 -->
                        <div class="bg-white hover:bg-gray-50 p-3.5 rounded-xl border border-gray-200 cursor-pointer transition-all group">
                            <div class="flex items-start gap-3">
                                <div class="bg-gray-100 group-hover:bg-white p-2 rounded-lg text-blue-600 shadow-2xs border border-gray-100">
                                    <span class="material-symbols-outlined text-[20px]">movie</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-xs font-bold text-gray-800 truncate mb-1">Video tình huống giao tiếp mẫu</h4>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-500">
                                        <span class="flex items-center gap-1 font-mono"><span class="material-symbols-outlined text-[13px]">play_circle</span> MP4</span>
                                        <span class="text-gray-300">•</span>
                                        <span class="flex items-center gap-0.5 text-rose-600 font-medium"><span class="material-symbols-outlined text-[13px]">lock</span> Chỉ xem</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Right: PDF Viewer Canvas with Watermark & Zoom Controls -->
            <section class="lg:col-span-8 bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden relative min-w-0">
                <!-- Preview Toolbar Header -->
                <div class="p-4 border-b border-gray-100 flex flex-wrap justify-between items-center bg-gray-50/50 gap-3 z-10">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-red-100 text-red-600 flex items-center justify-center shadow-2xs">
                            <span class="material-symbols-outlined text-[20px]">picture_as_pdf</span>
                        </div>
                        <div>
                            <h2 class="text-xs font-bold text-gray-900">Giáo trình Starter - Bài 1 &amp; 2</h2>
                            <p class="text-[10px] text-gray-400">Chặng 1: Nền tảng • 24 trang • Phiên bản v1.0</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Security Notice -->
                        <div class="hidden sm:flex items-center gap-1 bg-amber-50 text-amber-800 px-2.5 py-1 rounded-lg text-[11px] font-semibold border border-amber-200/60 mr-2">
                            <span class="material-symbols-outlined text-[14px] text-amber-600">shield</span>
                            <span>Bảo mật nội dung</span>
                        </div>

                        <!-- Zoom Controls -->
                        <div class="flex items-center border border-gray-200 rounded-lg bg-white overflow-hidden shadow-2xs">
                            <button class="w-8 h-8 hover:bg-gray-50 flex items-center justify-center text-gray-600" title="Thu nhỏ">
                                <span class="material-symbols-outlined text-[16px]">zoom_out</span>
                            </button>
                            <span class="text-[11px] font-mono text-gray-600 w-12 text-center border-x border-gray-200">100%</span>
                            <button class="w-8 h-8 hover:bg-gray-50 flex items-center justify-center text-gray-600" title="Phóng to">
                                <span class="material-symbols-outlined text-[16px]">zoom_in</span>
                            </button>
                        </div>

                        <button class="w-8 h-8 rounded-lg border border-gray-200 hover:bg-gray-50 flex items-center justify-center text-gray-600 shadow-2xs" title="Toàn màn hình">
                            <span class="material-symbols-outlined text-[18px]">fullscreen</span>
                        </button>
                    </div>
                </div>

                <!-- Preview Canvas -->
                <div class="flex-1 bg-slate-100 overflow-y-auto flex justify-center p-6 relative custom-scrollbar min-h-[500px]">
                    <!-- Watermark Overlay (Security Anti-Leak) -->
                    <div class="absolute inset-0 pointer-events-none z-20 flex flex-col items-center justify-center opacity-[0.04] rotate-[-35deg] gap-20">
                        <p class="text-5xl font-extrabold uppercase tracking-widest text-gray-900 whitespace-nowrap">MENGLISH INTERNAL ONLY</p>
                        <p class="text-4xl font-extrabold uppercase tracking-widest text-gray-900 whitespace-nowrap">{{ Auth::user()->email ?? 'TEACHER@MENGLISH.EDU.VN' }}</p>
                        <p class="text-5xl font-extrabold uppercase tracking-widest text-gray-900 whitespace-nowrap">MENGLISH INTERNAL ONLY</p>
                    </div>

                    <!-- PDF Page Mockup 1 (Paper Preview) -->
                    <div class="bg-white w-full max-w-[760px] shadow-lg rounded-xl mb-6 relative z-10 flex flex-col p-8 border border-gray-200">
                        <div class="border-b-2 border-primary-container pb-2 mb-6 flex justify-between items-end">
                            <div>
                                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">MENGLISH ACADEMIC CURRICULUM</span>
                                <h1 class="text-xl font-black text-primary tracking-tight">STARTER UNIT 1: GREETINGS &amp; INTRODUCTIONS</h1>
                            </div>
                            <span class="text-xs text-gray-400 font-mono">Page 1 / 24</span>
                        </div>

                        <div class="space-y-6 flex-1 text-xs">
                            <!-- Part A -->
                            <div>
                                <h2 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-primary-container"></span>
                                    <span>A. Vocabulary: Common Greetings</span>
                                </h2>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-200/70">
                                        <p class="font-bold text-gray-900 text-xs">Hello / Hi</p>
                                        <p class="text-gray-500 text-[11px] mt-0.5">Xin chào (thân mật &amp; trang trọng)</p>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-200/70">
                                        <p class="font-bold text-gray-900 text-xs">Good morning / Afternoon</p>
                                        <p class="text-gray-500 text-[11px] mt-0.5">Chào buổi sáng / Buổi chiều</p>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-200/70">
                                        <p class="font-bold text-gray-900 text-xs">How are you doing today?</p>
                                        <p class="text-gray-500 text-[11px] mt-0.5">Hôm nay bạn thế nào?</p>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-200/70">
                                        <p class="font-bold text-gray-900 text-xs">I am doing well, thank you</p>
                                        <p class="text-gray-500 text-[11px] mt-0.5">Tôi khỏe, cảm ơn bạn</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Image Illustration -->
                            <div class="w-full h-44 bg-orange-50/40 rounded-xl border border-orange-200/50 flex flex-col items-center justify-center text-center p-4">
                                <span class="material-symbols-outlined text-4xl text-primary/60 mb-1">handshake</span>
                                <p class="font-semibold text-gray-800 text-xs">Phần hình ảnh minh họa bài học ngữ cảnh chào hỏi</p>
                                <p class="text-[11px] text-gray-400">Hình ảnh tương tác trên màn hình TV lớp học và tài liệu của học viên</p>
                            </div>

                            <!-- Part B -->
                            <div>
                                <h2 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-primary-container"></span>
                                    <span>B. Grammar Focus: Verb "To Be" (Present Simple)</span>
                                </h2>
                                <table class="w-full text-left border-collapse text-xs border border-gray-200 rounded-xl overflow-hidden">
                                    <thead class="bg-gray-100 text-gray-700 font-bold uppercase text-[10px]">
                                        <tr>
                                            <th class="p-2.5 border-b border-gray-200">Subject Pronoun</th>
                                            <th class="p-2.5 border-b border-gray-200">Verb (To Be)</th>
                                            <th class="p-2.5 border-b border-gray-200">Example Sentence</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr>
                                            <td class="p-2.5 font-bold text-gray-900">I</td>
                                            <td class="p-2.5 font-mono text-primary font-bold">am</td>
                                            <td class="p-2.5 text-gray-700 italic">I am a new student at MEnglish.</td>
                                        </tr>
                                        <tr>
                                            <td class="p-2.5 font-bold text-gray-900">He / She / It</td>
                                            <td class="p-2.5 font-mono text-primary font-bold">is</td>
                                            <td class="p-2.5 text-gray-700 italic">She is our IELTS instructor.</td>
                                        </tr>
                                        <tr>
                                            <td class="p-2.5 font-bold text-gray-900">We / You / They</td>
                                            <td class="p-2.5 font-mono text-primary font-bold">are</td>
                                            <td class="p-2.5 text-gray-700 italic">They are enthusiastic learners.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Security Notice & Action Bar -->
                <div class="bg-white p-3.5 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3 z-10">
                    <p class="text-xs text-gray-500 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px] text-amber-600">lock</span>
                        <span>Tài liệu này không hỗ trợ tải về máy để bảo mật bản quyền theo chính sách của MEnglish Academy.</span>
                    </p>
                    <button type="button" onclick="alert('Đã ghi nhận giáo viên hoàn thành xem bài giảng này!')" class="bg-primary-container hover:bg-primary-hover text-white px-4 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">check_circle</span>
                        <span>Đánh dấu đã xem bài giảng</span>
                    </button>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
