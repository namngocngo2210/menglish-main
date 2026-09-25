<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">checklist_rtl</span>
                        Duyệt đề xuất sửa giáo trình (Bước #6)
                    </h1>
                    <p class="text-xs text-gray-500">Xem xét và xử lý các đề xuất chỉnh sửa nội dung giáo trình, bài học gửi từ Giáo viên / Học thuật.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('teacher-portal.shortcut', '09_de_xuat_sua_giao_trinh') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">edit_attributes</span>
                    <span>Cổng GV gửi đề xuất (Bước #5)</span>
                </a>
                <a href="{{ route('syllabus.adjustment-requests') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">rule</span>
                    <span>Duyệt tiến độ (Bước #8)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 6])

    <div class="max-w-6xl mx-auto space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Left Column: Inputs & Changes -->
            <div class="lg:col-span-8 space-y-6">
                <!-- Khối Thông tin chung -->
                <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-5 pb-3 border-b border-gray-100">
                        <span class="material-symbols-outlined text-primary">info</span>
                        <h2 class="text-sm font-bold text-gray-900">Thông tin chung đề xuất</h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Giáo trình áp dụng</label>
                            <div class="relative">
                                <select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3.5 py-2.5 text-xs font-semibold text-gray-800 appearance-none outline-none">
                                    @foreach ($curriculums as $c)
                                        <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->version }})</option>
                                    @endforeach
                                </select>
                                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400 text-[18px]">expand_more</span>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Buổi học / Unit cần sửa</label>
                            <div class="relative">
                                <select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-3.5 py-2.5 text-xs font-semibold text-gray-800 appearance-none outline-none">
                                    <option value="1">Buổi 01: Introduction to Writing Task 1 &amp; Greetings</option>
                                    <option value="2">Buổi 02: Present Simple &amp; Daily Routine</option>
                                    <option value="3">Buổi 03: Reading &amp; Skimming Techniques</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400 text-[18px]">expand_more</span>
                            </div>
                        </div>

                        <div class="md:col-span-2 space-y-1 mt-1">
                            <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Người đề xuất sửa đổi</label>
                            <div class="flex items-center gap-3 bg-gray-50 p-3.5 rounded-xl border border-gray-100">
                                <div class="w-10 h-10 rounded-full bg-orange-100 text-primary font-bold flex items-center justify-center text-sm">
                                    N
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900">Nguyễn Văn An</p>
                                    <p class="text-[11px] text-gray-500">Giáo viên phụ trách khối IELTS Foundation • ID: GV001</p>
                                </div>
                                <span class="ml-auto px-2.5 py-1 rounded-full text-[10px] font-bold bg-orange-100 text-primary border border-orange-200">
                                    Gửi từ Cổng Giáo viên (Step #5)
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Khối Nội dung đề xuất thay đổi -->
                <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-5 pb-3 border-b border-gray-100">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary">edit_document</span>
                            <h2 class="text-sm font-bold text-gray-900">Nội dung thay đổi chi tiết (So sánh)</h2>
                        </div>
                        <span class="text-xs text-gray-400 font-mono">Phiên bản đề xuất: v1.1-draft</span>
                    </div>

                    <div class="space-y-4">
                        <!-- Item 1: Comparison Card -->
                        <div class="p-4 bg-gray-50/70 border border-gray-200 rounded-xl space-y-3">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider flex items-center gap-1">
                                        <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                        <span>Nội dung cũ trong giáo trình</span>
                                    </label>
                                    <div class="w-full bg-rose-50/50 border border-rose-200/70 rounded-xl p-3 text-xs text-gray-700 leading-relaxed font-sans">
                                        Phần bài tập về nhà yêu cầu viết đoạn văn ngắn 150 từ trong 20 phút cho Writing Task 2.
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-[11px] font-bold text-primary uppercase tracking-wider flex items-center gap-1">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                        <span>Nội dung mới đề xuất sửa</span>
                                    </label>
                                    <div class="w-full bg-emerald-50/50 border border-emerald-200/70 rounded-xl p-3 text-xs text-gray-800 leading-relaxed font-sans font-medium">
                                        Yêu cầu viết tối thiểu 250 từ trong 40 phút để phù hợp với chuẩn đề thi thật và bổ sung dàn ý bài mẫu.
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-1 pt-2 border-t border-gray-200/60">
                                <label class="block text-[11px] font-bold text-gray-600 uppercase tracking-wider">Lý do thay đổi từ Giáo viên:</label>
                                <div class="w-full bg-white border border-gray-200 rounded-xl px-3.5 py-2.5 text-xs text-gray-700 font-medium">
                                    Đồng bộ hóa với format chuẩn bài thi IELTS 2024 theo khuyến nghị của Hội đồng Khảo thí Cambridge.
                                </div>
                            </div>
                        </div>

                        <!-- Item 2: Audio & Resource amendment -->
                        <div class="p-4 bg-gray-50/70 border border-gray-200 rounded-xl space-y-3">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider">Link file Audio cũ</label>
                                    <div class="w-full bg-gray-100 border border-gray-200 rounded-xl p-2.5 text-xs font-mono text-gray-500 truncate">
                                        /audio/ielts_starter_track_01_old.mp3
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-[11px] font-bold text-primary uppercase tracking-wider">Link file Audio thay thế</label>
                                    <div class="w-full bg-white border border-emerald-200 rounded-xl p-2.5 text-xs font-mono text-emerald-800 truncate font-semibold">
                                        /audio/ielts_starter_track_01_remastered_2024.mp3
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Right Column: Status & Approval -->
            <div class="lg:col-span-4 space-y-6">
                <section class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm flex flex-col justify-between h-full">
                    <div>
                        <div class="flex items-center gap-2 mb-5 pb-3 border-b border-gray-100">
                            <span class="material-symbols-outlined text-primary">verified_user</span>
                            <h2 class="text-sm font-bold text-gray-900">Trạng thái phê duyệt</h2>
                        </div>

                        <div class="space-y-5">
                            <!-- Trạng thái hiện tại -->
                            <div class="space-y-1.5">
                                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Trạng thái hiện tại</label>
                                <div>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-amber-50 text-amber-700 rounded-full border border-amber-200 text-xs font-bold">
                                        <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                        <span>Đang chờ Admin duyệt</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Người duyệt -->
                            <div class="space-y-1.5">
                                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Người phê duyệt (Admin)</label>
                                <div class="flex items-center gap-2.5 p-2.5 bg-gray-50 rounded-xl border border-gray-100">
                                    <div class="w-8 h-8 rounded-full bg-primary-container text-white font-bold flex items-center justify-center text-xs shadow-2xs">
                                        {{ substr(Auth::user()->name ?? 'A', 0, 1) }}
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-gray-900">{{ Auth::user()->name ?? 'Administrator' }}</p>
                                        <p class="text-[10px] text-gray-500">Ban Đào tạo &amp; Học thuật</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Ghi chú phản hồi -->
                            <div class="space-y-1.5">
                                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Phản hồi / Ghi chú từ người duyệt</label>
                                <textarea rows="3" placeholder="Nhập lý do phê duyệt hoặc yêu cầu chỉnh sửa thêm trước khi xuất bản..." class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none"></textarea>
                            </div>

                            <!-- Timeline -->
                            <div class="space-y-2 pt-3 border-t border-gray-100">
                                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Lịch sử xử lý</label>
                                <div class="relative pl-5 space-y-3 before:absolute before:left-2 before:top-2 before:bottom-2 before:w-0.5 before:bg-gray-200 text-xs">
                                    <div class="relative">
                                        <div class="absolute -left-[17px] top-1 w-2.5 h-2.5 rounded-full bg-primary-container ring-2 ring-white"></div>
                                        <p class="font-bold text-gray-900">Đã gửi đề xuất sửa</p>
                                        <p class="text-[10px] text-gray-400">10:30, Hôm nay - Nguyễn Văn An</p>
                                    </div>
                                    <div class="relative opacity-60">
                                        <div class="absolute -left-[17px] top-1 w-2.5 h-2.5 rounded-full bg-gray-300 ring-2 ring-white"></div>
                                        <p class="font-medium text-gray-600">Hệ thống chuyển ban học thuật</p>
                                        <p class="text-[10px] text-gray-400">Đang chờ bạn phê duyệt</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="pt-5 mt-5 border-t border-gray-100 flex flex-col gap-2">
                        <button type="button" onclick="alert('Đã phê duyệt đề xuất sửa giáo trình thành công!')" class="w-full py-2.5 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center justify-center gap-1.5">
                            <span class="material-symbols-outlined text-[18px]">check_circle</span>
                            <span>Phê duyệt xuất bản v1.1</span>
                        </button>
                        <button type="button" onclick="alert('Đã gửi phản hồi yêu cầu làm rõ cho giáo viên!')" class="w-full py-2 bg-gray-50 hover:bg-rose-50 text-gray-600 hover:text-rose-600 border border-gray-200 text-xs font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                            <span class="material-symbols-outlined text-[18px]">close</span>
                            <span>Từ chối / Yêu cầu sửa lại</span>
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
