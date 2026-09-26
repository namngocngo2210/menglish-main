<x-app-layout>
    <x-ui.page-header title="Thang Điểm & Hướng Dẫn Nhận Xét Tự Động" :back="route('placement-tests.index')" description="Hệ thống quy chuẩn điểm số, nhận xét theo từng kỹ năng và gợi ý xếp lớp chuẩn Cambridge YLE (Starters - Movers)">
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2 bg-inverse-surface text-white px-3.5 py-1.5 rounded-xl border border-white/10 shadow-xs">
                <span class="material-symbols-outlined text-warning/70 text-[18px]">verified</span>
                <div>
                    <p class="text-[9px] text-inverse-on-surface/70 uppercase font-bold">Phiên bản quy chuẩn</p>
                    <p class="text-[11px] font-bold text-white">Cambridge YLE Starter - Movers</p>
                </div>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="max-w-6xl mx-auto space-y-6" x-data="rubricApp({{ Js::from(\App\Services\PlacementRubricService::clientConfig()) }})">
        
        {{-- Header Banner --}}
        <div class="bg-gradient-to-r from-inverse-surface via-inverse-surface to-on-secondary-fixed text-white rounded-2xl p-6 shadow-md border border-inverse-surface flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="flex items-center gap-2 text-primary/70 font-bold text-xs uppercase tracking-wider">
                    <span class="material-symbols-outlined text-[18px]">psychology</span>
                    MEnglish CRM Academic Engine
                </div>
                <h2 class="text-2xl font-black tracking-tight">Quy Chuẩn Đánh Giá Năng Lực Đầu Vào</h2>
                <p class="text-inverse-on-surface/80 text-xs leading-relaxed max-w-2xl">
                    Chấm theo khối lớp: Tổng = Nghe + Đọc &amp; Viết + Nói (điểm thô), tra tổng điểm ra lớp đề xuất; nhận xét từng kỹ năng gợi ý theo băng điểm (người chấm sửa được). Nói luôn nhập tay.
                    Khối chưa có thang (lớp 5–9, IELTS, người đi làm, mầm non): {{ \App\Services\PlacementRubricService::noRubricNotice() }}.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button icon="add_circle" :href="route('placement-tests.create')">Tạo đề thi mới</x-ui.button>
            </div>
        </div>

        {{-- Interactive Score Simulator Card --}}
        <div class="bg-surface-container-lowest rounded-2xl p-6 shadow-sm border border-surface-container-highest space-y-4">
            <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                <div class="flex items-center gap-2 text-on-surface font-bold text-sm">
                    <span class="material-symbols-outlined text-primary text-[20px]">calculate</span>
                    <h3>Công cụ Tính Điểm &amp; Tạo Nhận Xét Trực Tiếp (Live Simulator)</h3>
                </div>
                <x-ui.badge color="primary" pill>Dùng đúng thang điểm hệ thống</x-ui.badge>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                <x-ui.select label="Chọn Khối Lớp" x-model="khoiKey" x-on:change="recalc()" class="font-semibold">
                    @foreach (\App\Services\PlacementRubricService::rubrics() as $groupKey => $groupRubric)
                        <option value="{{ $groupKey }}">{{ mb_strtoupper($groupRubric['label']) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input type="number" label="Điểm Nghe (Listening)" x-model.number="scoreL" x-on:input="recalc()" step="0.5" min="0" class="font-mono font-bold" />
                <x-ui.input type="number" label="Điểm Đọc & Viết (R&W)" x-model.number="scoreR" x-on:input="recalc()" step="0.5" min="0" class="font-mono font-bold" />
                <x-ui.input type="number" label="Điểm Speaking (Nói)" x-model.number="scoreS" x-on:input="recalc()" step="0.5" min="0" class="font-mono font-bold" />
            </div>

            <div class="bg-surface-container-low p-4 rounded-xl border border-surface-container-highest space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <span class="text-xs font-bold uppercase text-on-surface-variant">Kết quả &amp; Nhận xét Sinh Tự Động:</span>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-on-surface-variant font-medium">Tổng điểm: <strong class="text-primary text-base font-black font-mono" x-text="totalScore"></strong> <span class="text-on-surface-variant/70 font-mono" x-text="'/ ' + maxTotal"></span></span>
                        <span class="text-xs font-bold bg-primary-container text-white px-3 py-1 rounded-full shadow-xs" x-text="placementCourse"></span>
                    </div>
                </div>
                <x-ui.textarea rows="3" readonly class="leading-relaxed font-sans" x-text="generatedComment" />
            </div>
        </div>

        {{-- Rubric Navigation Tabs --}}
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 border-b border-surface-container-highest pb-2">
                <button type="button" @click="activeTab = 'tab1'" :class="activeTab === 'tab1' ? 'bg-primary-container text-white font-bold border-primary-container shadow-xs' : 'bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low border-surface-container-highest'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_one</span> Khối 1 - 2 (Starters)
                </button>
                <button type="button" @click="activeTab = 'tab2'" :class="activeTab === 'tab2' ? 'bg-primary-container text-white font-bold border-primary-container shadow-xs' : 'bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low border-surface-container-highest'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_two</span> Khối 2 lên 3 (Starters)
                </button>
                <button type="button" @click="activeTab = 'tab3'" :class="activeTab === 'tab3' ? 'bg-primary-container text-white font-bold border-primary-container shadow-xs' : 'bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low border-surface-container-highest'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_3</span> Khối 3 lên 4 (Movers)
                </button>
                <button type="button" @click="activeTab = 'tab4'" :class="activeTab === 'tab4' ? 'bg-primary-container text-white font-bold border-primary-container shadow-xs' : 'bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low border-surface-container-highest'" class="px-4 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">looks_4</span> Khối 4 lên 5 (Movers)
                </button>
            </div>

            {{-- TAB 1: KHỐI 1 - 2 --}}
            <div x-show="activeTab === 'tab1'" class="space-y-4">
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-surface-container-highest overflow-hidden">
                    <div class="bg-inverse-surface text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[18px]">grade</span>
                            KHỐI 1 - 2 (Tổng điểm tối đa: 35)
                        </h3>
                        <span class="text-[10px] bg-white/10 text-inverse-on-surface/80 font-bold px-3 py-1 rounded-full uppercase">Starters Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-surface-container-highest text-xs">
                            <thead>
                                <tr class="bg-inverse-surface text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/4 border border-white/10">LISTENING /10</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">READING AND WRITING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container-highest bg-surface-container-lowest">
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 2 - 4 điểm:</div>
                                        Con bắt đầu hình thành kỹ năng nghe cơ bản, nhận diện được một số từ vựng đơn giản qua tranh, chưa quen xử lý bài nghe điền từ hoặc câu hỏi đa thông tin.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 5 điểm:</div>
                                        Chưa có nền từ vựng tốt, nhận diện một số từ đơn cơ bản, đọc câu chưa phân biệt được đúng sai.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 2 - 4 điểm:</div>
                                        Chưa hình thành kỹ năng nghe nói cơ bản, chỉ nắm bắt được 1-2 câu hỏi thông tin cá nhân đơn giản nhất.
                                    </td>
                                </tr>
                                <tr class="bg-surface-container-low/60">
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 5 - 7 điểm:</div>
                                        Đã có kỹ năng nghe cơ bản, nghe nhận diện từ vựng qua tranh, nhận diện từ khóa nhưng chưa theo kịp tốc độ bài nghe dài.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 6 - 10 điểm:</div>
                                        Nhận diện cơ bản từ vựng, cần củng cố nhớ chính tả. Đọc câu phân biệt được đúng sai ở mức đơn giản.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 5 - 7 điểm:</div>
                                        Nhận diện được các câu hỏi cơ bản theo tranh, phát âm tương đối rõ ràng, cần rèn luyện thêm sự tự tin khi mở rộng câu.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 8 - 10 điểm:</div>
                                        Nghe tốt, nắm vững từ vựng các chủ đề đời sống, nhận diện thông tin chính xác từ bài hội thoại.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 11 - 15 điểm:</div>
                                        Vốn từ phong phú, nhớ chính tả tốt, đọc hiểu câu đơn hoàn chỉnh và xử lý bài tập linh hoạt.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 8 - 10 điểm:</div>
                                        Nói trôi chảy, phản xạ nhanh với các câu hỏi Starters, phát âm chuẩn và tự tin trả lời nguyên câu.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-warning/5 p-4 border-t border-warning/30">
                        <h4 class="font-bold text-xs uppercase text-on-warning-container mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 1-2)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm &lt; 10</span>
                                <span class="font-black text-on-warning-container text-xs">PRE STARTERS (FAM 0)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 10 - 15</span>
                                <span class="font-black text-on-warning-container text-xs">STARTERS (FAM 1 _ BÀI ĐẦU)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 16 - 25</span>
                                <span class="font-black text-on-warning-container text-xs">STARTERS (FAM 1 _ BÀI 5 - 10)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 2: KHỐI 2 LÊN 3 --}}
            <div x-show="activeTab === 'tab2'" class="space-y-4">
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-surface-container-highest overflow-hidden">
                    <div class="bg-inverse-surface text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[18px]">grade</span>
                            KHỐI 2 LÊN 3 (Tổng điểm tối đa: 40)
                        </h3>
                        <span class="text-[10px] bg-white/10 text-inverse-on-surface/80 font-bold px-3 py-1 rounded-full uppercase">Starters Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-surface-container-highest text-xs">
                            <thead>
                                <tr class="bg-inverse-surface text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/3 border border-white/10">LISTENING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">READING AND WRITING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container-highest bg-surface-container-lowest">
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 6 điểm:</div>
                                        Kỹ năng nghe cơ bản, nhận diện từ khóa quen thuộc, chưa quen phân biệt thông tin đa chiều.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 5 điểm:</div>
                                        Nhận diện từ đơn lẻ, ngữ pháp cơ bản cần củng cố để viết câu và sắp xếp câu hoàn chỉnh.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 2 - 4 điểm:</div>
                                        Giao tiếp được các câu hỏi thông dụng nhất, cần nâng cao sự tự tin và vốn từ phản xạ.
                                    </td>
                                </tr>
                                <tr class="bg-surface-container-low/60">
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 6 - 10 điểm:</div>
                                        Nghe hiểu khá các bài nghe nhận diện, phân biệt được câu hỏi và nắm bắt thông tin 1 chiều.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 6 - 10 điểm:</div>
                                        Nhận diện từ vựng tốt, đọc hiểu câu đơn, cần trau dồi thêm kỹ năng sắp xếp trật tự từ.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 5 - 7 điểm:</div>
                                        Phát âm tốt, trả lời được các câu hỏi về tranh, phản xạ tự nhiên với giáo viên.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 11 - 15 điểm:</div>
                                        Nghe tốt, phân biệt được thông tin gây nhiễu, bắt kịp tốc độ các đoạn hội thoại hoàn chỉnh.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 11 - 15 điểm:</div>
                                        Nền từ vựng và ngữ pháp vững vàng, đọc hiểu linh hoạt, làm bài viết và sắp xếp câu chuẩn xác.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 8 - 10 điểm:</div>
                                        Nói lưu loát, diễn đạt ý rõ ràng, tự tin trả lời câu dài và mô tả tranh sinh động.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-warning/5 p-4 border-t border-warning/30">
                        <h4 class="font-bold text-xs uppercase text-on-warning-container mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 2 lên 3)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 10 - 20</span>
                                <span class="font-black text-on-warning-container text-xs">PRE STARTERS _ FAM 1 (DƯỚI U5)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 20 - 30</span>
                                <span class="font-black text-on-warning-container text-xs">STARTERS (FAM 1 _ UNIT 6 - 10)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 30 - 40</span>
                                <span class="font-black text-on-warning-container text-xs">STARTERS (FAM 1 _ UNIT 7 - 12)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 3: KHỐI 3 LÊN 4 --}}
            <div x-show="activeTab === 'tab3'" class="space-y-4">
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-surface-container-highest overflow-hidden">
                    <div class="bg-inverse-surface text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[18px]">grade</span>
                            KHỐI 3 LÊN 4 (Tổng điểm tối đa: 45)
                        </h3>
                        <span class="text-[10px] bg-white/10 text-inverse-on-surface/80 font-bold px-3 py-1 rounded-full uppercase">Movers Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-surface-container-highest text-xs">
                            <thead>
                                <tr class="bg-inverse-surface text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/3 border border-white/10">LISTENING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">READING AND WRITING /20</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container-highest bg-surface-container-lowest">
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 6 điểm:</div>
                                        Nghe nhận diện từ khóa cơ bản, cần làm quen thêm với các dạng đề thi Cambridge Movers.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 7 điểm:</div>
                                        Nhận diện từ đơn lẻ, cần tăng cường ngữ pháp ứng dụng và kỹ năng viết đoạn văn ngắn.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 2 - 4 điểm:</div>
                                        Hiểu câu hỏi cơ bản, cần luyện phản xạ ghép câu và mô tả tranh so sánh điểm khác biệt.
                                    </td>
                                </tr>
                                <tr class="bg-surface-container-low/60">
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 6 - 10 điểm:</div>
                                        Bắt đầu nghe hiểu các câu ngắn 1 chiều, nắm được thông tin chính trong bài hội thoại.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 7 - 15 điểm:</div>
                                        Đọc hiểu câu ngắn và kết nối thông tin tốt, viết được các cụm từ hoàn chỉnh.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 5 - 7 điểm:</div>
                                        Phát âm rõ ràng, đủ từ vựng để trả lời câu hỏi, phản xạ giao tiếp tự tin.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 11 - 15 điểm:</div>
                                        Nghe hiểu toàn diện level Movers, phân biệt thông tin gây nhiễu và ghi chép chính xác.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 15 - 20 điểm:</div>
                                        Nền từ vựng Movers phong phú, nắm vững cấu trúc câu phức và xử lý bài đọc hiểu xuất sắc.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 8 - 10 điểm:</div>
                                        Giao tiếp trôi chảy, mô tả tranh và so sánh sự khác biệt chi tiết, diễn đạt tự nhiên.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-warning/5 p-4 border-t border-warning/30">
                        <h4 class="font-bold text-xs uppercase text-on-warning-container mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 3 lên 4)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 10 - 20</span>
                                <span class="font-black text-on-warning-container text-xs">FAM 2 (NỬA ĐẦU)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 20 - 35</span>
                                <span class="font-black text-on-warning-container text-xs">FAM 2 (NỬA SAU)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 35 - 45</span>
                                <span class="font-black text-on-warning-container text-xs">LUYỆN THI MOVERS</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- TAB 4: KHỐI 4 LÊN 5 --}}
            <div x-show="activeTab === 'tab4'" class="space-y-4">
                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-surface-container-highest overflow-hidden">
                    <div class="bg-inverse-surface text-white px-5 py-3 flex justify-between items-center">
                        <h3 class="font-bold text-xs uppercase tracking-wide flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[18px]">grade</span>
                            KHỐI 4 LÊN 5 (Tổng điểm tối đa: 40)
                        </h3>
                        <span class="text-[10px] bg-white/10 text-inverse-on-surface/80 font-bold px-3 py-1 rounded-full uppercase">Movers Level</span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <table class="w-full border-collapse border border-surface-container-highest text-xs">
                            <thead>
                                <tr class="bg-inverse-surface text-white text-[11px] font-bold uppercase">
                                    <th class="p-3 text-left w-1/3 border border-white/10">LISTENING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">READING AND WRITING /15</th>
                                    <th class="p-3 text-left w-1/3 border border-white/10">SPEAKING /10</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container-highest bg-surface-container-lowest">
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 6 điểm:</div>
                                        Kỹ năng nghe mức hình thành cơ bản, cần rèn luyện thêm bài tập nghe chọn tranh và điền từ.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 1 - 5 điểm:</div>
                                        Nhận diện từ vựng đơn, cần củng cố cấu trúc câu và các thì căn bản.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 2 - 4 điểm:</div>
                                        Giao tiếp câu đơn, cần mở rộng câu và rèn luyện kể chuyện theo tranh.
                                    </td>
                                </tr>
                                <tr class="bg-surface-container-low/60">
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 6 - 10 điểm:</div>
                                        Nghe hiểu tốt các hội thoại thông thường, nhận diện đúng thông tin câu hỏi.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 6 - 10 điểm:</div>
                                        Nắm vững từ vựng trọng tâm, đọc hiểu trôi chảy đoạn văn ngắn.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 5 - 7 điểm:</div>
                                        Nói lưu loát, phát âm tốt, tự tin trình bày câu trả lời hoàn chỉnh.
                                    </td>
                                </tr>
                                <tr>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 11 - 15 điểm:</div>
                                        Kỹ năng nghe xuất sắc, xử lý nhanh các bẫy thông tin và nắm trọn vẹn ngữ cảnh.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 11 - 15 điểm:</div>
                                        Ngữ pháp vững vàng, vốn từ vựng phong phú, đọc hiểu nhanh và viết câu chính xác.
                                    </td>
                                    <td class="p-3 border border-surface-container-highest align-top">
                                        <div class="font-bold text-primary mb-1">Mức 8 - 10 điểm:</div>
                                        Phản xạ tự nhiên như người bản xứ, mô tả tranh sinh động và lập luận logic.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-warning/5 p-4 border-t border-warning/30">
                        <h4 class="font-bold text-xs uppercase text-on-warning-container mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px]">map</span> Quyết định Xếp lớp tự động (Khối 4 lên 5)
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 10 - 20</span>
                                <span class="font-black text-on-warning-container text-xs">FAM 2 (NỬA ĐẦU)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 20 - 30</span>
                                <span class="font-black text-on-warning-container text-xs">FAM 2 (NỬA SAU)</span>
                            </div>
                            <div class="bg-surface-container-lowest p-3 rounded-xl border border-warning/30 text-center">
                                <span class="text-[11px] text-on-surface-variant font-bold block">Tổng điểm 30 - 40</span>
                                <span class="font-black text-on-warning-container text-xs">LUYỆN THI MOVERS</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        // Bộ mô phỏng dùng chung cấu hình với form chấm điểm (PlacementRubricService::clientConfig) — không lặp lại thang điểm trong JS.
        function rubricApp(config) {
            return {
                config,
                activeTab: 'tab2',
                khoiKey: 'khoi_2_3',
                scoreL: 11,
                scoreR: 12,
                scoreS: 8,
                totalScore: 0,
                maxTotal: 0,
                placementCourse: '',
                generatedComment: '',

                init() {
                    this.recalc();
                },

                band(rubric, skill, score) {
                    let text = '';
                    (rubric.bands[skill] || []).forEach((b) => { if (score >= b.min) text = b.text; });
                    return text || '—';
                },

                recalc() {
                    const r = this.config.groups[this.khoiKey];
                    if (!r) return;
                    const clamp = (v, max) => Math.min(Math.max(parseFloat(v) || 0, 0), max);
                    const l = clamp(this.scoreL, r.max.listening);
                    const rw = clamp(this.scoreR, r.max.reading_writing);
                    const s = clamp(this.scoreS, r.max.speaking);

                    this.totalScore = Math.round((l + rw + s) * 10) / 10;
                    this.maxTotal = r.max.listening + r.max.reading_writing + r.max.speaking;
                    const t = this.totalScore;
                    const hit = (r.placements || []).find((p) => (p.lt !== null ? t < p.lt : (p.lte !== null ? t <= p.lte : true)));
                    this.placementCourse = hit ? hit.class : 'Chưa có lớp tương ứng — Học vụ chọn lớp thủ công';

                    this.generatedComment =
                        `【Kỹ năng Nghe】: ${this.band(r, 'listening', l)}\n` +
                        `【Kỹ năng Đọc & Viết】: ${this.band(r, 'reading_writing', rw)}\n` +
                        `【Kỹ năng Nói】: ${this.band(r, 'speaking', s)}\n` +
                        `【Đề xuất Xếp lớp】: ${this.placementCourse}`;
                }
            };
        }
    </script>
</x-app-layout>
