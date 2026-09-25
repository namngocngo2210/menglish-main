<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('placement-tests.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span>Chi Tiết &amp; Chấm Điểm Bài Test: Nguyễn Thị Thuỳ Dung</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold border border-emerald-200">Đã chấm: 5.0 Overall</span>
                    </h1>
                    <p class="text-xs text-gray-500 font-mono">Bài thi: TEST-01 · Ngày làm: 14/08/2026 · Thời gian hoàn thành: 38 phút</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('crm.closing-wizard') }}" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">how_to_reg</span>
                    <span>Tư vấn &amp; Xếp lớp ngay</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Score Summary Panel -->
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100">
                    Bảng Điểm Thành Phần 4 Kỹ Năng
                </h3>

                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50">
                        <span class="font-semibold text-gray-700">Listening (Nghe hiểu):</span>
                        <span class="font-mono font-bold text-primary text-sm">11/15 (5.5)</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50">
                        <span class="font-semibold text-gray-700">Reading &amp; Grammar:</span>
                        <span class="font-mono font-bold text-primary text-sm">18/25 (5.0)</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50">
                        <span class="font-semibold text-gray-700">Writing (Tự luận):</span>
                        <span class="font-mono font-bold text-indigo-700 text-sm">4.5</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50">
                        <span class="font-semibold text-gray-700">Speaking (Phỏng vấn):</span>
                        <span class="font-mono font-bold text-indigo-700 text-sm">5.0</span>
                    </div>

                    <div class="pt-2 p-3 bg-orange-50 rounded-xl border border-orange-200 text-center">
                        <span class="text-[11px] text-gray-500 font-semibold uppercase">Điểm Tổng Kết (Overall CEFR / IELTS):</span>
                        <div class="text-2xl font-black text-primary font-mono mt-0.5">5.0 Overall (B1)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail Answers & Audio Player -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Audio recording player -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-3">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-purple-600 text-base">mic</span>
                    Bản ghi âm câu trả lời Speaking của học viên
                </h3>
                <div class="p-3 bg-purple-50/50 rounded-xl border border-purple-100 flex items-center justify-between">
                    <div>
                        <div class="font-bold text-xs text-gray-900">Question 1: Describe your hometown and favorite place.</div>
                        <div class="text-[11px] text-gray-500">Thời lượng: 01:24s · File audio mp3</div>
                    </div>
                    <button class="px-3 py-1.5 rounded-lg bg-purple-600 text-white font-bold text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">play_arrow</span>
                        <span>Nghe Audio</span>
                    </button>
                </div>
            </div>

            <!-- Writing submission -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-3">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-600 text-base">edit_note</span>
                    Bài viết tự luận (Writing Prompt)
                </h3>
                <div class="p-4 bg-gray-50 rounded-xl border border-gray-100 text-xs text-gray-800 leading-relaxed font-mono">
                    "In my opinion, learning English is very important for young people today because it helps us find good jobs and connect with international friends. However, many students find grammar difficult..."
                </div>
            </div>

            <!-- Teacher assessment & rubric form -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100">
                    Nhận xét chuyên môn &amp; Đề xuất lộ trình học
                </h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Khóa học khuyến nghị:</label>
                        <select class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-bold text-primary">
                            <option selected>Khóa IELTS 6.5 Intensive (Target 6.5 sau 3 tháng)</option>
                            <option>Khóa Giao tiếp Pro B1</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Lời khuyên chi tiết cho học viên &amp; phụ huynh:</label>
                        <textarea rows="3" class="w-full text-xs rounded-xl border border-gray-200 p-2.5" placeholder="Học viên nắm từ vựng khá, phát âm rõ ràng, cần cải thiện tốc độ phản xạ và cấu trúc câu phức...">Học viên tiếp thu tốt, từ vựng nền tảng khá, đủ điều kiện vào học khóa IELTS 6.5 Intensive để bứt phá band điểm.</textarea>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button class="px-5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition">
                        Lưu kết quả &amp; Gửi SMS báo điểm
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
