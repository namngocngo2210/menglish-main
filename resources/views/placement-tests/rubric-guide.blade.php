<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('placement-tests.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">auto_awesome</span>
                        Thang Điểm &amp; Hướng Dẫn Nhận Xét Tự Động
                    </h1>
                    <p class="text-xs text-gray-500">Hệ thống quy chuẩn điểm số, nhận xét theo từng kỹ năng và gợi ý xếp lớp chuẩn Cambridge YLE (Starters - Movers)</p>
                </div>
            </div>
            <div class="hidden sm:flex items-center gap-2 bg-slate-900 text-white px-3.5 py-1.5 rounded-xl border border-slate-700 shadow-xs">
                <span class="material-symbols-outlined text-amber-400 text-[18px]">verified</span>
                <div>
                    <p class="text-[9px] text-slate-400 uppercase font-bold">Phiên bản quy chuẩn</p>
                    <p class="text-[11px] font-bold text-white">Cambridge YLE Starter - Movers</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-6xl mx-auto space-y-6" x-data="rubricApp()">
        
        <!-- Header Banner -->
        <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-950 text-white rounded-2xl p-6 shadow-md border border-slate-800 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="flex items-center gap-2 text-orange-400 font-bold text-xs uppercase tracking-wider">
                    <span class="material-symbols-outlined text-[18px]">psychology</span>
                    MEnglish CRM Academic Engine
                </div>
                <h2 class="text-2xl font-black tracking-tight">Quy Chuẩn Đánh Giá Năng Lực Đầu Vào</h2>
                <p class="text-slate-300 text-xs leading-relaxed max-w-2xl">
                    Hệ thống tự động tính toán band điểm 4 kỹ năng (Listening, Reading, Writing, Speaking), sinh nhận xét chuyên môn và gợi ý lộ trình lớp học tương ứng cho CRM và Phụ huynh.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('placement-tests.create') }}" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-xs font-bold shadow-md transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">add_circle</span>
                    <span>Tạo đề thi mới</span>
                </a>
            </div>
        </div>

        <!-- Interactive Score Simulator Card -->
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
                    <span class="material-symbols-outlined text-orange-600 text-[20px]">calculate</span>
                    <h3>Công cụ Tính Điểm &amp; Tạo Nhận Xét Trực Tiếp (Live Simulator)</h3>
                </div>
                <span class="text-xs bg-orange-50 text-orange-700 border border-orange-200 font-bold px-2.5 py-0.5 rounded-full">Interactive Tool</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 uppercase mb-1">Chọn Khối Lớp</label>
                    <select x-model="khoiKey" @change="recalc()" class="w-full text-xs font-semibold border-slate-300 rounded-xl focus:ring-primary-container focus:border-primary-container p-2.5 bg-slate-50">
                        <option value="khoi1_2">KHỐI 1 - 2</option>
                        <option value="khoi2_3">KHỐI 2 LÊN 3</option>
                        <option value="khoi3_4">KHỐI 3 LÊN 4</option>
                        <option value="khoi4_5">KHỐI 4 LÊN 5</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 uppercase mb-1">Điểm Nghe (Listening)</label>
                    <input type="number" x-model.number="scoreL" @input="recalc()" step="0.5" min="0" class="w-full text-xs font-mono font-bold border-slate-300 rounded-xl focus:ring-primary-container focus:border-primary-container p-2.5">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 uppercase mb-1">Điểm Đọc &amp; Viết (R&amp;W)</label>
                    <input type="number" x-model.number="scoreR" @input="recalc()" step="0.5" min="0" class="w-full text-xs font-mono font-bold border-slate-300 rounded-xl focus:ring-primary-container focus:border-primary-container p-2.5">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 uppercase mb-1">Điểm Speaking (Nói)</label>
                    <input type="number" x-model.number="scoreS" @input="recalc()" step="0.5" min="0" class="w-full text-xs font-mono font-bold border-slate-300 rounded-xl focus:ring-primary-container focus:border-primary-container p-2.5">
                </div>
            </div>

            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <span class="text-xs font-bold uppercase text-slate-600">Kết quả &amp; Nhận xét Sinh Tự Động:</span>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-slate-700 font-medium">Tổng điểm: <strong class="text-orange-600 text-base font-black font-mono" x-text="totalScore"></strong> <span class="text-slate-400 font-mono" x-text="'/ ' + maxTotal"></span></span>
                        <span class="text-xs font-bold bg-orange-600 text-white px-3 py-1 rounded-full shadow-xs" x-text="placementCourse"></span>
                    </div>
                </div>
                <textarea rows="3" readonly class="w-full text-xs text-slate-800 bg-white border border-slate-300 rounded-xl p-3 leading-relaxed font-sans" x-text="generatedComment"></textarea>
            </div>
        </div>

        <!-- Rubric Navigation Tabs -->
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-2">
                <button type="button" @click="activeTab = 'tab1'" :class="activeTab === 'tab1' ? 'bg-orange-600 text-white font-bold border-orange-600 shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_one</span> Khối 1 - 2 (Starters)
                </button>
                <button type="button" @click="activeTab = 'tab2'" :class="activeTab === 'tab2' ? 'bg-orange-600 text-white font-bold border-orange-600 shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_two</span> Khối 2 lên 3 (Starters)
                </button>
                <button type="button" @click="activeTab = 'tab3'" :class="activeTab === 'tab3' ? 'bg-orange-600 text-white font-bold border-orange-600 shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_3</span> Khối 3 lên 4 (Movers)
                </button>
                <button type="button" @click="activeTab = 'tab4'" :class="activeTab === 'tab4' ? 'bg-orange-600 text-white font-bold border-orange-600 shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_4</span> Khối 4 lên 5 (Movers)
                </button>
            </div>

            <!-- TAB 1: KHỐI 1 - 2 -->
            <div x-show="activeTab === 'tab1'" class="space-y-4">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-slate-900 text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-orange-500 text-[18px]">grade</span>
                            KHỐI 1 - 2 (Tổng điểm tối đa: 35)
                        </h3>
                        <span class="text-[10px] bg-slate-800 text-slate-300 font-bold px-3 py-1 rounded-full uppercase">Starters Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-slate-200 text-xs">
                            <thead>
                                <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/4 border border-slate-700">LISTENING /10</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">READING AND WRITING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 2 - 4 điểm:</div>
                                        Con bắt đầu hình thành kỹ năng nghe cơ bản, nhận diện được một số từ vựng đơn giản qua tranh, chưa quen xử lý bài nghe điền từ hoặc câu hỏi đa thông tin.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 5 điểm:</div>
                                        Chưa có nền từ vựng tốt, nhận diện một số từ đơn cơ bản, đọc câu chưa phân biệt được đúng sai.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 2 - 4 điểm:</div>
                                        Chưa hình thành kỹ năng nghe nói cơ bản, chỉ nắm bắt được 1-2 câu hỏi thông tin cá nhân đơn giản nhất.
                                    </td>
                                </tr>
                                <tr class="bg-slate-50/60">
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 5 - 7 điểm:</div>
                                        Đã có kỹ năng nghe cơ bản, nghe nhận diện từ vựng qua tranh, nhận diện từ khóa nhưng chưa theo kịp tốc độ bài nghe dài.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 6 - 10 điểm:</div>
                                        Nhận diện cơ bản từ vựng, cần củng cố nhớ chính tả. Đọc câu phân biệt được đúng sai ở mức đơn giản.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 5 - 7 điểm:</div>
                                        Nhận diện được các câu hỏi cơ bản theo tranh, phát âm tương đối rõ ràng, cần rèn luyện thêm sự tự tin khi mở rộng câu.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 8 - 10 điểm:</div>
                                        Nghe tốt, nắm vững từ vựng các chủ đề đời sống, nhận diện thông tin chính xác từ bài hội thoại.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 11 - 15 điểm:</div>
                                        Vốn từ phong phú, nhớ chính tả tốt, đọc hiểu câu đơn hoàn chỉnh và xử lý bài tập linh hoạt.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 8 - 10 điểm:</div>
                                        Nói trôi chảy, phản xạ nhanh với các câu hỏi Starters, phát âm chuẩn và tự tin trả lời nguyên câu.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-amber-50/70 p-4 border-t border-amber-200">
                        <h4 class="font-bold text-xs uppercase text-amber-900 mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 1-2)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm &lt; 10</span>
                                <span class="font-black text-amber-900 text-xs">PRE STARTERS (FAM 0)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 10 - 15</span>
                                <span class="font-black text-amber-900 text-xs">STARTERS (FAM 1 _ BÀI ĐẦU)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 16 - 25</span>
                                <span class="font-black text-amber-900 text-xs">STARTERS (FAM 1 _ BÀI 5 - 10)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: KHỐI 2 LÊN 3 -->
            <div x-show="activeTab === 'tab2'" class="space-y-4">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-slate-900 text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-orange-500 text-[18px]">grade</span>
                            KHỐI 2 LÊN 3 (Tổng điểm tối đa: 40)
                        </h3>
                        <span class="text-[10px] bg-slate-800 text-slate-300 font-bold px-3 py-1 rounded-full uppercase">Starters Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-slate-200 text-xs">
                            <thead>
                                <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/3 border border-slate-700">LISTENING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">READING AND WRITING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 6 điểm:</div>
                                        Kỹ năng nghe cơ bản, nhận diện từ khóa quen thuộc, chưa quen phân biệt thông tin đa chiều.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 5 điểm:</div>
                                        Nhận diện từ đơn lẻ, ngữ pháp cơ bản cần củng cố để viết câu và sắp xếp câu hoàn chỉnh.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 2 - 4 điểm:</div>
                                        Giao tiếp được các câu hỏi thông dụng nhất, cần nâng cao sự tự tin và vốn từ phản xạ.
                                    </td>
                                </tr>
                                <tr class="bg-slate-50/60">
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 6 - 10 điểm:</div>
                                        Nghe hiểu khá các bài nghe nhận diện, phân biệt được câu hỏi và nắm bắt thông tin 1 chiều.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 6 - 10 điểm:</div>
                                        Nhận diện từ vựng tốt, đọc hiểu câu đơn, cần trau dồi thêm kỹ năng sắp xếp trật tự từ.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 5 - 7 điểm:</div>
                                        Phát âm tốt, trả lời được các câu hỏi về tranh, phản xạ tự nhiên với giáo viên.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 11 - 15 điểm:</div>
                                        Nghe tốt, phân biệt được thông tin gây nhiễu, bắt kịp tốc độ các đoạn hội thoại hoàn chỉnh.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 11 - 15 điểm:</div>
                                        Nền từ vựng và ngữ pháp vững vàng, đọc hiểu linh hoạt, làm bài viết và sắp xếp câu chuẩn xác.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 8 - 10 điểm:</div>
                                        Nói lưu loát, diễn đạt ý rõ ràng, tự tin trả lời câu dài và mô tả tranh sinh động.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-amber-50/70 p-4 border-t border-amber-200">
                        <h4 class="font-bold text-xs uppercase text-amber-900 mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 2 lên 3)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 10 - 20</span>
                                <span class="font-black text-amber-900 text-xs">PRE STARTERS _ FAM 1 (DƯỚI U5)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 20 - 30</span>
                                <span class="font-black text-amber-900 text-xs">STARTERS (FAM 1 _ UNIT 6 - 10)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 30 - 40</span>
                                <span class="font-black text-amber-900 text-xs">STARTERS (FAM 1 _ UNIT 7 - 12)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: KHỐI 3 LÊN 4 -->
            <div x-show="activeTab === 'tab3'" class="space-y-4">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-slate-900 text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-orange-500 text-[18px]">grade</span>
                            KHỐI 3 LÊN 4 (Tổng điểm tối đa: 45)
                        </h3>
                        <span class="text-[10px] bg-slate-800 text-slate-300 font-bold px-3 py-1 rounded-full uppercase">Movers Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-slate-200 text-xs">
                            <thead>
                                <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/3 border border-slate-700">LISTENING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">READING AND WRITING /20</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 6 điểm:</div>
                                        Nghe nhận diện từ khóa cơ bản, cần làm quen thêm với các dạng đề thi Cambridge Movers.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 7 điểm:</div>
                                        Nhận diện từ đơn lẻ, cần tăng cường ngữ pháp ứng dụng và kỹ năng viết đoạn văn ngắn.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 2 - 4 điểm:</div>
                                        Hiểu câu hỏi cơ bản, cần luyện phản xạ ghép câu và mô tả tranh so sánh điểm khác biệt.
                                    </td>
                                </tr>
                                <tr class="bg-slate-50/60">
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 6 - 10 điểm:</div>
                                        Bắt đầu nghe hiểu các câu ngắn 1 chiều, nắm được thông tin chính trong bài hội thoại.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 7 - 15 điểm:</div>
                                        Đọc hiểu câu ngắn và kết nối thông tin tốt, viết được các cụm từ hoàn chỉnh.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 5 - 7 điểm:</div>
                                        Phát âm rõ ràng, đủ từ vựng để trả lời câu hỏi, phản xạ giao tiếp tự tin.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 11 - 15 điểm:</div>
                                        Nghe hiểu toàn diện level Movers, phân biệt thông tin gây nhiễu và ghi chép chính xác.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 15 - 20 điểm:</div>
                                        Nền từ vựng Movers phong phú, nắm vững cấu trúc câu phức và xử lý bài đọc hiểu xuất sắc.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 8 - 10 điểm:</div>
                                        Giao tiếp trôi chảy, mô tả tranh và so sánh sự khác biệt chi tiết, diễn đạt tự nhiên.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-amber-50/70 p-4 border-t border-amber-200">
                        <h4 class="font-bold text-xs uppercase text-amber-900 mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 3 lên 4)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 10 - 20</span>
                                <span class="font-black text-amber-900 text-xs">FAM 2 (NỬA ĐẦU)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 20 - 35</span>
                                <span class="font-black text-amber-900 text-xs">FAM 2 (NỬA SAU)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 35 - 45</span>
                                <span class="font-black text-amber-900 text-xs">LUYỆN THI MOVERS</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: KHỐI 4 LÊN 5 -->
            <div x-show="activeTab === 'tab4'" class="space-y-4">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-slate-900 text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-orange-500 text-[18px]">grade</span>
                            KHỐI 4 LÊN 5 (Tổng điểm tối đa: 40)
                        </h3>
                        <span class="text-[10px] bg-slate-800 text-slate-300 font-bold px-3 py-1 rounded-full uppercase">Movers Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-slate-200 text-xs">
                            <thead>
                                <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/3 border border-slate-700">LISTENING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">READING AND WRITING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-slate-700">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 6 điểm:</div>
                                        Kỹ năng nghe mức hình thành cơ bản, cần rèn luyện thêm bài tập nghe chọn tranh và điền từ.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 1 - 5 điểm:</div>
                                        Nhận diện từ vựng đơn, cần củng cố cấu trúc câu và các thì căn bản.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 2 - 4 điểm:</div>
                                        Giao tiếp câu đơn, cần mở rộng câu và rèn luyện kể chuyện theo tranh.
                                    </td>
                                </tr>
                                <tr class="bg-slate-50/60">
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 6 - 10 điểm:</div>
                                        Nghe hiểu tốt các hội thoại thông thường, nhận diện đúng thông tin câu hỏi.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 6 - 10 điểm:</div>
                                        Nắm vững từ vựng trọng tâm, đọc hiểu trôi chảy đoạn văn ngắn.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 5 - 7 điểm:</div>
                                        Nói lưu loát, phát âm tốt, tự tin trình bày câu trả lời hoàn chỉnh.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 11 - 15 điểm:</div>
                                        Kỹ năng nghe xuất sắc, xử lý nhanh các bẫy thông tin và nắm trọn vẹn ngữ cảnh.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 11 - 15 điểm:</div>
                                        Ngữ pháp vững vàng, vốn từ vựng phong phú, đọc hiểu nhanh và viết câu chính xác.
                                    </td>
                                    <td class="p-3 border border-slate-200 align-top">
                                        <div class="font-bold text-orange-600 mb-1">Mức 8 - 10 điểm:</div>
                                        Phản xạ tự nhiên như người bản xứ, mô tả tranh sinh động và lập luận logic.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-amber-50/70 p-4 border-t border-amber-200">
                        <h4 class="font-bold text-xs uppercase text-amber-900 mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 4 lên 5)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 10 - 20</span>
                                <span class="font-black text-amber-900 text-xs">FAM 2 (NỬA ĐẦU)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 20 - 30</span>
                                <span class="font-black text-amber-900 text-xs">FAM 2 (NỬA SAU)</span>
                            </div>
                            <div class="bg-white p-3 rounded-xl border border-amber-200 text-center">
                                <span class="text-[11px] text-slate-500 font-bold block">Tổng điểm 30 - 40</span>
                                <span class="font-black text-amber-900 text-xs">LUYỆN THI MOVERS</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function rubricApp() {
            return {
                activeTab: 'tab2',
                khoiKey: 'khoi2_3',
                scoreL: 11,
                scoreR: 12,
                scoreS: 8,
                totalScore: 31,
                maxTotal: 40,
                placementCourse: 'STARTERS (FAM 1 _ UNIT 7 - 12)',
                generatedComment: '',

                init() {
                    this.recalc();
                },

                recalc() {
                    const RUBRICS = {
                        khoi1_2: {
                            maxL: 10, maxR: 15, maxS: 10,
                            listening: s => s>=8 ? "Con nghe tốt, nắm được hệ thống từ vựng các chủ đề xung quanh..." : (s>=5 ? "Con đã có kĩ năng nghe cơ bản, nhận diện từ vựng qua tranh..." : "Con bắt đầu hình thành kĩ năng nghe cơ bản..."),
                            reading: s => s>=11 ? "Con có vốn từ cơ bản, nhớ chính tả, nắm câu đơn giản." : (s>=6 ? "Con nhận diện cơ bản từ vựng, chưa nhớ chính tả..." : "Con chưa có nền từ tốt..."),
                            speaking: s => s>=8 ? "Con nói trôi chảy, nắm câu hỏi Starters..." : (s>=5 ? "Con có kĩ năng nghe nói cơ bản, nhận diện câu hỏi tranh..." : "Con chưa hình thành kĩ năng nghe nói cơ bản..."),
                            placement: t => t<10 ? "PRE STARTERS (FAM 0)" : (t<=15 ? "STARTERS (FAM 1 _ Ở NHỮNG BÀI ĐẦU)" : (t<=25 ? "STARTERS (FAM 1 _ TỪ BÀI 5 - 10)" : "STARTERS (FAM 1 _ NÂNG CAO)"))
                        },
                        khoi2_3: {
                            maxL: 15, maxR: 15, maxS: 10,
                            listening: s => s>=11 ? "Con nghe khá, phân biệt thông tin gây nhiễu..." : (s>=6 ? "Con nghe trung bình khá, nhận diện thông tin 1 chiều..." : "Con nghe cơ bản, chưa quen bài nghe đa dạng..."),
                            reading: s => s>=11 ? "Con có nền từ khá tốt, đọc hiểu câu cơ bản..." : (s>=6 ? "Con nhận diện cơ bản một số từ vựng..." : "Con chưa có nền từ tốt, gặp khó khăn điền từ..."),
                            speaking: s => s>=8 ? "Con nói trôi chảy, khá tự tin và trả lời tốt..." : (s>=5 ? "Con có kĩ năng nghe nói cơ bản, phát âm tốt..." : "Con chưa hình thành kĩ năng nghe nói..."),
                            placement: t => t<20 ? "PRE STARTERS _ FAM 1 (TỪ ĐẦU _ DƯỚI U5)" : (t<=30 ? "STARTERS (FAM 1 _ UNIT 6 - 10)" : "STARTERS (FAM 1 _ UNIT 7 - 12)")
                        },
                        khoi3_4: {
                            maxL: 15, maxR: 20, maxS: 10,
                            listening: s => s>=11 ? "Con nghe khá/tốt, nắm nội dung Movers..." : (s>=6 ? "Con nghe trung bình khá..." : "Kĩ năng nghe ở mức hình thành cơ bản..."),
                            reading: s => s>=15 ? "Con có nền từ vựng khá, xử lí bài Movers tốt..." : (s>=7 ? "Con có kiến thức cơ bản, đọc hiểu câu ngắn..." : "Con nhận diện từ đơn cơ bản..."),
                            speaking: s => s>=8 ? "Con nói trôi chảy, mô tả tranh khá..." : (s>=5 ? "Con có kĩ năng nghe nói cơ bản..." : "Con nghe hiểu cơ bản, nền từ yếu..."),
                            placement: t => t<20 ? "FAM 2 (NỬA ĐẦU)" : (t<=35 ? "FAM 2 (NỬA SAU)" : "LUYỆN THI MOVERS")
                        },
                        khoi4_5: {
                            maxL: 15, maxR: 15, maxS: 10,
                            listening: s => s>=11 ? "Con nghe khá/tốt..." : (s>=6 ? "Con nghe trung bình khá..." : "Kĩ năng nghe hình thành cơ bản..."),
                            reading: s => s>=11 ? "Con có nền từ vựng khá..." : (s>=6 ? "Con có kiến thức cơ bản..." : "Con nhận diện từ đơn..."),
                            speaking: s => s>=8 ? "Con nói trôi chảy..." : (s>=5 ? "Con có kĩ năng nghe nói..." : "Con nghe hiểu cơ bản..."),
                            placement: t => t<20 ? "FAM 2 (NỬA ĐẦU)" : (t<=30 ? "FAM 2 (NỬA SAU)" : "LUYỆN THI MOVERS")
                        }
                    };

                    const r = RUBRICS[this.khoiKey];
                    let l = parseFloat(this.scoreL) || 0;
                    let rd = parseFloat(this.scoreR) || 0;
                    let s = parseFloat(this.scoreS) || 0;

                    l = Math.min(Math.max(l, 0), r.maxL);
                    rd = Math.min(Math.max(rd, 0), r.maxR);
                    s = Math.min(Math.max(s, 0), r.maxS);

                    this.totalScore = Math.round((l + rd + s) * 10) / 10;
                    this.maxTotal = r.maxL + r.maxR + r.maxS;
                    this.placementCourse = r.placement(this.totalScore);

                    this.generatedComment = 
                        `【Kỹ năng Nghe】: ${r.listening(l)}\n` +
                        `【Kỹ năng Đọc & Viết】: ${r.reading(rd)}\n` +
                        `【Kỹ năng Nói】: ${r.speaking(s)}\n` +
                        `【Đề xuất Xếp lớp】: ${this.placementCourse}`;
                }
            };
        }
    </script>
</x-app-layout>
