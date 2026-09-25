<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.teacher-view') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">edit_attributes</span>
                        Đề xuất sửa giáo trình (Cổng Giáo viên — Bước #5)
                    </h1>
                    <p class="text-xs text-gray-500">Giáo viên gửi đề xuất sửa lỗi, cập nhật tài liệu hoặc bổ sung hoạt động học tập lên Ban Học thuật.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('syllabus.versions') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">checklist_rtl</span>
                    <span>Admin duyệt đề xuất (Bước #6)</span>
                </a>
                <a href="{{ route('syllabus.teacher-adjust') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">speed</span>
                    <span>Xin điều chỉnh tiến độ (Bước #7)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 5])

    <!-- 2-Column Bento Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Khối Form gửi đề xuất (Left 4 cols) -->
        <div class="lg:col-span-4 flex flex-col gap-4 min-w-0">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">post_add</span>
                    <h2 class="text-sm font-bold text-gray-900">Gửi đề xuất sửa mới</h2>
                </div>

                <form onsubmit="event.preventDefault(); alert('Đã gửi đề xuất sửa giáo trình tới Ban Học thuật thành công!');" class="flex flex-col gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn giáo trình <span class="text-rose-500">*</span></label>
                        <select required class="w-full rounded-xl border border-gray-200 bg-white text-xs p-2.5 focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                            <option value="">Chọn giáo trình...</option>
                            @foreach ($curriculums as $c)
                                <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->version }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn buổi học / Unit (Tùy chọn)</label>
                        <select class="w-full rounded-xl border border-gray-200 bg-white text-xs p-2.5 focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                            <option value="">-- Toàn bộ giáo trình (Chung) --</option>
                            <option value="1">Buổi 1: Introduction to IELTS &amp; Greetings</option>
                            <option value="2">Buổi 2: Present Simple &amp; Daily Routine</option>
                            <option value="3">Buổi 3: Reading &amp; Skimming Techniques</option>
                            <option value="4">Buổi 4: Speaking Part 1 Warm-up</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Loại đề xuất</label>
                        <select class="w-full rounded-xl border border-gray-200 bg-white text-xs p-2.5 focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                            <option>Sửa lỗi chính tả / ngữ pháp trong bài giảng</option>
                            <option>Cập nhật link file Audio nghe bị lỗi</option>
                            <option>Thay đổi độ dài / thời gian bài tập Writing</option>
                            <option>Bổ sung game tương tác khởi động đầu giờ</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Mô tả thay đổi đề xuất <span class="text-rose-500">*</span></label>
                        <textarea required rows="4" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary outline-none resize-none" placeholder="Nhập chi tiết nội dung cần sửa đổi, số trang tài liệu và giải thích lý do..."></textarea>
                    </div>

                    <button type="submit" class="w-full bg-primary hover:bg-primary-hover text-white py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        <span>Gửi đề xuất tới Ban Học thuật</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Khối Danh sách đề xuất đã gửi (Right 8 cols) -->
        <div class="lg:col-span-8 flex flex-col gap-4 min-w-0">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col h-full">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">history_edu</span>
                        <h2 class="text-sm font-bold text-gray-900">Lịch sử đề xuất của bạn</h2>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-gray-200 text-gray-700">3 đề xuất</span>
                </div>

                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="py-3 px-4">Giáo trình / Buổi</th>
                                <th class="py-3 px-4">Nội dung đề xuất</th>
                                <th class="py-3 px-4 whitespace-nowrap">Ngày gửi</th>
                                <th class="py-3 px-4 text-right">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                            <tr class="hover:bg-gray-50/80 transition-colors">
                                <td class="py-3.5 px-4">
                                    <p class="font-bold text-gray-900">IELTS Starter Foundation</p>
                                    <p class="text-[11px] text-gray-400">Buổi 1: Writing Task 1</p>
                                </td>
                                <td class="py-3.5 px-4 max-w-xs">
                                    <p class="text-gray-800 line-clamp-2">Tăng thời gian bài tập Writing Task 2 từ 150 từ (20p) lên 250 từ (40p) để khớp chuẩn format thi IELTS 2024.</p>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-500 whitespace-nowrap">
                                    Hôm nay
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        Đang chờ duyệt (Step #6)
                                    </span>
                                </td>
                            </tr>
                            <tr class="hover:bg-gray-50/80 transition-colors">
                                <td class="py-3.5 px-4">
                                    <p class="font-bold text-gray-900">TOEIC 500+ FastTrack</p>
                                    <p class="text-[11px] text-gray-400">Buổi 15: Listening</p>
                                </td>
                                <td class="py-3.5 px-4 max-w-xs">
                                    <p class="text-gray-800 line-clamp-2">Cập nhật link audio phần Listening Test 2, link cũ bị lỗi không mở được trên máy chiếu lớp.</p>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-500 whitespace-nowrap">
                                    10/10/2026
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Đã áp dụng
                                    </span>
                                </td>
                            </tr>
                            <tr class="hover:bg-gray-50/80 transition-colors">
                                <td class="py-3.5 px-4">
                                    <p class="font-bold text-gray-900">Giao tiếp Cơ bản A1</p>
                                    <p class="text-[11px] text-gray-400">Chung toàn khóa</p>
                                </td>
                                <td class="py-3.5 px-4 max-w-xs">
                                    <p class="text-gray-800 line-clamp-2">Đề xuất thêm phần game tương tác Kahoot/Quizlet vào cuối mỗi bài để tăng hứng thú phản xạ.</p>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-500 whitespace-nowrap">
                                    05/10/2026
                                </td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Đã áp dụng
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
