<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-black text-gray-900 tracking-tight flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-primary text-2xl">quiz</span>
                    <span>Ngân hàng Đề Test &amp; Chấm Điểm Đầu Vào</span>
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">
                    Quản lý bộ đề thi chuẩn hóa, cổng thi trực tuyến cho Lead và tự động xếp lớp theo khung CEFR/IELTS
                </p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('placement-tests.rubric-guide') }}" 
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-bold shadow-xs transition">
                    <span class="material-symbols-outlined text-[17px] text-amber-500">auto_awesome</span>
                    <span>Thang điểm &amp; Tiêu chí</span>
                </a>
                <a href="{{ route('placement-tests.create') }}" 
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-xs transition active:scale-95">
                    <span class="material-symbols-outlined text-[17px]">add_circle</span>
                    <span>Tạo Đề Mới</span>
                </a>
            </div>
        </div>
    </x-slot>

    <!-- Sub-Navigation Tabs -->
    <div class="border-b border-gray-200 bg-white -mt-4 -mx-4 sm:-mt-6 sm:-mx-6 px-6 pt-3 mb-6">
        <div class="flex items-center gap-6 overflow-x-auto text-xs font-semibold scrollbar-none">
            <a href="{{ route('placement-tests.index') }}" 
               class="pb-3 border-b-2 border-primary text-primary font-bold transition whitespace-nowrap flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px]">quiz</span>
                <span>Ngân hàng Bộ Đề ({{ $tests->count() }})</span>
            </a>
            <a href="#submissions" 
               class="pb-3 border-b-2 border-transparent text-gray-600 hover:text-gray-900 transition whitespace-nowrap flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px]">assignment_turned_in</span>
                <span>Bảng Điểm Thí Sinh ({{ $recentSubmissions->count() }})</span>
            </a>
            <a href="{{ route('placement-tests.rubric-guide') }}" 
               class="pb-3 border-b-2 border-transparent text-gray-600 hover:text-gray-900 transition whitespace-nowrap flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px]">rule</span>
                <span>Thang Điểm &amp; Rubric</span>
            </a>
        </div>
    </div>

    <div class="space-y-6" x-data="placementTestManager()">
        <!-- 1. KPI Statistics Overview -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Tổng số bộ đề</span>
                    <div class="text-2xl font-black text-gray-900 mt-0.5 font-mono">{{ $stats['total_tests'] ?? $tests->count() }}</div>
                    <span class="text-[11px] text-gray-500 font-medium">
                        {{ $stats['preset_tests'] ?? $tests->where('is_preset', true)->count() }} đề chuẩn • {{ $stats['custom_tests'] ?? $tests->where('is_preset', false)->count() }} tùy biến
                    </span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-orange-50 text-primary flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">quiz</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Lượt thi nộp bài</span>
                    <div class="text-2xl font-black text-emerald-700 mt-0.5 font-mono">{{ $stats['total_submissions'] ?? \App\Models\PlacementTestSubmission::count() }}</div>
                    <span class="text-[11px] text-emerald-600 font-medium">Học viên &amp; Lead đã làm bài</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">assignment_turned_in</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Thời lượng trung bình</span>
                    <div class="text-2xl font-black text-indigo-700 mt-0.5 font-mono">{{ $stats['avg_duration'] ?? round($tests->avg('duration_minutes')) }}p</div>
                    <span class="text-[11px] text-indigo-600 font-medium">Từ 15p Nói đến 60p IELTS</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">schedule</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-gray-200/90 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block">Khung quy chuẩn</span>
                    <div class="text-xl font-black text-purple-700 mt-1">CEFR &amp; Cambridge</div>
                    <span class="text-[11px] text-purple-600 font-medium">Starters, Movers, Flyers, KET</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">verified</span>
                </div>
            </div>
        </div>

        <!-- 2. Search & Filter Bar Section (Designed after Mockup) -->
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-2xs p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-end">
                <!-- Search Input -->
                <div class="sm:col-span-2 lg:col-span-5">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Tìm kiếm tên hoặc mã đề</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-gray-400 text-[18px]">search</span>
                        <input 
                            type="text" 
                            x-model="search" 
                            placeholder="Nhập tên đề, mã đề (VD: Starters, G1, IELTS, SPEAKING)..." 
                            class="w-full h-10 pl-9 pr-8 bg-gray-50/50 border border-gray-200 rounded-xl text-xs text-gray-800 placeholder-gray-400 focus:bg-white focus:border-primary focus:ring-1 focus:ring-primary transition"
                        >
                        <button 
                            x-show="search.length > 0" 
                            @click="search = ''" 
                            type="button" 
                            class="absolute right-2.5 top-2.5 text-gray-400 hover:text-gray-600 transition"
                        >
                            <span class="material-symbols-outlined text-[16px]">close</span>
                        </button>
                    </div>
                </div>

                <!-- Filter Cấp độ -->
                <div class="lg:col-span-3">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Cấp độ mục tiêu</label>
                    <div class="relative">
                        <select 
                            x-model="levelFilter" 
                            class="w-full h-10 px-3 pr-8 bg-gray-50/50 border border-gray-200 rounded-xl text-xs text-gray-800 focus:bg-white focus:border-primary focus:ring-1 focus:ring-primary transition appearance-none"
                        >
                            <option value="">Tất cả cấp độ</option>
                            <option value="Pre-Starters">Mầm non &amp; Tiền tiểu học (Pre-Starters)</option>
                            <option value="Starters">Tiểu học Starters</option>
                            <option value="Movers">Tiểu học Movers</option>
                            <option value="Flyers">Tiểu học Flyers</option>
                            <option value="THCS">Chuyển cấp &amp; THCS (KET / PET)</option>
                            <option value="IELTS">IELTS &amp; Tổng hợp</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-2.5 top-2.5 text-gray-400 pointer-events-none text-[18px]">expand_more</span>
                    </div>
                </div>

                <!-- Filter Loại đề -->
                <div class="lg:col-span-2">
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Loại đề thi</label>
                    <div class="relative">
                        <select 
                            x-model="typeFilter" 
                            class="w-full h-10 px-3 pr-8 bg-gray-50/50 border border-gray-200 rounded-xl text-xs text-gray-800 focus:bg-white focus:border-primary focus:ring-1 focus:ring-primary transition appearance-none"
                        >
                            <option value="">Tất cả loại đề</option>
                            <option value="speaking">Chuyên kỹ năng Nói (Speaking)</option>
                            <option value="standard">Tổng hợp 4 Kỹ năng</option>
                            <option value="preset">Đề mẫu chuẩn hóa</option>
                            <option value="custom">Đề tùy biến (Tự tạo)</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-2.5 top-2.5 text-gray-400 pointer-events-none text-[18px]">expand_more</span>
                    </div>
                </div>

                <!-- Reset Button -->
                <div class="lg:col-span-2 flex items-center gap-2">
                    <button 
                        @click="resetFilters()" 
                        type="button" 
                        class="w-full h-10 px-3 border border-gray-200 hover:border-gray-300 bg-white hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold transition flex items-center justify-center gap-1.5 shadow-2xs"
                    >
                        <span class="material-symbols-outlined text-[16px] text-gray-500">restart_alt</span>
                        <span>Làm mới</span>
                    </button>
                </div>
            </div>

            <!-- Filter Status Bar -->
            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                <div class="flex items-center gap-2">
                    <span>Hiển thị:</span>
                    <span class="px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 font-bold font-mono text-[11px]" x-text="visibleCount + ' / {{ $tests->count() }} bộ đề'"></span>
                    <span x-show="search || levelFilter || typeFilter" class="text-amber-600 font-medium flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">filter_alt</span>
                        <span>Đang lọc</span>
                    </span>
                </div>
                <div class="text-[11px] text-gray-400 hidden sm:block">
                    Click vào tiêu đề hoặc "Link thi" để mở đề kiểm tra
                </div>
            </div>
        </div>

        <!-- 3. Test Bank Data Table -->
        <div class="bg-white rounded-2xl border border-gray-200/90 shadow-2xs overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">format_list_bulleted</span>
                    <h2 class="font-bold text-xs text-gray-900 uppercase tracking-wider">Danh Sách Bộ Đề Thi Chuẩn Hóa</h2>
                    <span class="px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 font-mono font-bold text-xs">{{ $tests->count() }} bộ đề</span>
                </div>
                <div class="text-[11px] text-gray-500 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[15px] text-emerald-600">touch_app</span>
                    <span>Bấm vào số lượt thi để xem ngay danh sách thí sinh &amp; bảng điểm chi tiết bên dưới</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[960px]">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3.5 px-4 min-w-[340px]">Tên Đề Test &amp; Mã Đề</th>
                            <th class="py-3.5 px-4 min-w-[170px]">Cấp độ mục tiêu</th>
                            <th class="py-3.5 px-4 min-w-[110px]">Thời lượng</th>
                            <th class="py-3.5 px-4 text-center min-w-[90px]">Số câu hỏi</th>
                            <th class="py-3.5 px-4 text-center min-w-[130px]">Lượt đã thi</th>
                            <th class="py-3.5 px-4 text-center min-w-[110px]">Phân loại</th>
                            <th class="py-3.5 px-4 text-right min-w-[150px]">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($tests as $t)
                            @php
                                $isSpeaking = str_contains(strtoupper($t->code), 'SPEAKING');
                            @endphp
                            <tr 
                                x-show="matches('{{ $t->id }}')" 
                                class="hover:bg-slate-50/80 transition-colors {{ $t->is_preset ? 'bg-white' : 'bg-teal-50/15' }}"
                            >
                                <!-- Test Title & Code -->
                                <td class="py-3.5 px-4">
                                    <div class="space-y-1">
                                        <a href="{{ route('placement-tests.show', $t->id) }}" 
                                           class="font-bold text-gray-900 hover:text-primary transition text-[13px] leading-snug group inline-flex items-center gap-1.5"
                                           title="Xem chi tiết bộ đề này">
                                            <span>{{ $t->title }}</span>
                                            <span class="material-symbols-outlined text-[14px] text-gray-300 group-hover:text-primary group-hover:translate-x-0.5 transition shrink-0">arrow_forward</span>
                                        </a>
                                        <div class="flex items-center gap-2 flex-wrap pt-0.5">
                                            <span class="px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 font-mono font-bold text-[10px] tracking-wide border border-gray-200/80 shrink-0">
                                                ID: {{ $t->code }}
                                            </span>
                                            @if ($isSpeaking)
                                                <span class="px-2 py-0.5 rounded-md bg-rose-50 text-rose-700 border border-rose-200/60 text-[10px] font-bold inline-flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[12px]">mic</span>
                                                    <span>Test Nói (Speaking)</span>
                                                </span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200/60 text-[10px] font-bold inline-flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[12px]">quiz</span>
                                                    <span>4 Kỹ năng Chuẩn</span>
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <!-- Target Level -->
                                <td class="py-3.5 px-4">
                                    <div class="flex items-center gap-1.5 text-gray-800 font-medium">
                                        <span class="material-symbols-outlined text-[17px] text-gray-400 shrink-0">school</span>
                                        <span class="text-xs">{{ $t->target_level }}</span>
                                    </div>
                                </td>

                                <!-- Duration -->
                                <td class="py-3.5 px-4">
                                    <div class="flex items-center gap-1.5 text-gray-700 font-medium">
                                        <span class="material-symbols-outlined text-[16px] text-gray-400">schedule</span>
                                        <span class="font-mono font-bold text-xs">{{ $t->duration_minutes }}</span>
                                        <span class="text-[11px] text-gray-500">phút</span>
                                    </div>
                                </td>

                                <!-- Questions Count -->
                                <td class="py-3.5 px-4 text-center">
                                    <span class="px-2.5 py-0.5 rounded-md bg-amber-50 text-amber-800 border border-amber-200/60 font-mono font-bold text-xs inline-block">
                                        {{ $t->questions_count }} câu
                                    </span>
                                </td>

                                <!-- Clickable Submissions Count -->
                                <td class="py-3.5 px-4 text-center">
                                    <a href="{{ route('placement-tests.index', ['test_id' => $t->id]) }}#submissions" 
                                       class="px-2.5 py-1 rounded-xl {{ $t->submissions_count > 0 ? 'bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-gray-50 text-gray-400 border border-gray-100' }} font-mono font-bold text-xs inline-flex items-center gap-1.5 transition shadow-2xs group"
                                       title="Bấm để xem danh sách thí sinh và điểm số của đề này">
                                        <span class="material-symbols-outlined text-[14px] text-emerald-600 group-hover:scale-110 transition">groups</span>
                                        <span>{{ $t->submissions_count }} lượt</span>
                                        <span class="material-symbols-outlined text-[13px] text-gray-400 group-hover:text-emerald-700 group-hover:translate-y-0.5 transition">arrow_downward</span>
                                    </a>
                                </td>

                                <!-- Type / Preset -->
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    @if ($t->is_preset)
                                        <span class="px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-bold inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[12px] text-slate-500">lock</span>
                                            <span>Đề chuẩn</span>
                                        </span>
                                    @else
                                        <span class="px-2.5 py-0.5 rounded-md bg-teal-50 text-teal-700 border border-teal-200 text-[10px] font-bold inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[12px] text-teal-600">edit_note</span>
                                            <span>Tùy biến</span>
                                        </span>
                                    @endif
                                </td>

                                <!-- Actions -->
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Open Portal link -->
                                        <a href="{{ route('portal.test.take', $t->code) }}" 
                                           target="_blank" 
                                           class="px-2.5 py-1 rounded-lg bg-orange-50 hover:bg-primary hover:text-white text-primary border border-orange-200/80 font-bold text-[11px] transition inline-flex items-center gap-1 shadow-2xs group" 
                                           title="Mở cổng làm bài thi trực tuyến cho thí sinh">
                                            <span class="material-symbols-outlined text-[14px] group-hover:rotate-12 transition">play_circle</span>
                                            <span>Link thi</span>
                                        </a>

                                        <!-- View details -->
                                        <a href="{{ route('placement-tests.show', $t->id) }}" 
                                           class="p-1.5 rounded-lg bg-gray-100 hover:bg-indigo-50 text-gray-600 hover:text-indigo-600 transition" 
                                           title="Xem chi tiết đề &amp; câu hỏi">
                                            <span class="material-symbols-outlined text-[16px]">visibility</span>
                                        </a>

                                        <!-- Duplicate button -->
                                        <a href="{{ route('placement-tests.duplicate', $t->id) }}" 
                                           class="p-1.5 rounded-lg bg-gray-100 hover:bg-emerald-50 text-gray-600 hover:text-emerald-600 transition" 
                                           title="Nhân bản đề thi này">
                                            <span class="material-symbols-outlined text-[16px]">content_copy</span>
                                        </a>

                                        @if (!$t->is_preset)
                                            <!-- Edit button -->
                                            <a href="{{ route('placement-tests.edit', $t->id) }}" 
                                               class="p-1.5 rounded-lg bg-gray-100 hover:bg-teal-50 text-gray-600 hover:text-teal-600 transition" 
                                               title="Sửa câu hỏi &amp; cấu hình">
                                                <span class="material-symbols-outlined text-[16px]">edit</span>
                                            </a>
                                            <!-- Delete button -->
                                            <form action="{{ route('placement-tests.destroy', $t->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xóa đề thi này?');" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="p-1.5 rounded-lg bg-gray-100 hover:bg-rose-50 text-gray-400 hover:text-rose-600 transition" title="Xóa đề thi">
                                                    <span class="material-symbols-outlined text-[16px]">delete</span>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-12 text-gray-400 text-xs">
                                    <span class="material-symbols-outlined text-4xl text-gray-300 block mx-auto mb-2">quiz</span>
                                    <span>Chưa có bộ đề kiểm tra nào. Bấm "Tạo Đề Mới" để bắt đầu.</span>
                                </td>
                            </tr>
                        @endforelse

                        <!-- Dynamic empty state when Alpine filters out everything -->
                        <tr x-show="visibleCount === 0" style="display: none;">
                            <td colspan="7" class="text-center py-12 text-gray-400 text-xs">
                                <span class="material-symbols-outlined text-4xl text-gray-300 block mx-auto mb-2">search_off</span>
                                <div class="font-semibold text-gray-600 text-sm">Không tìm thấy bộ đề thi nào phù hợp</div>
                                <p class="text-gray-400 mt-1">Vui lòng thử điều chỉnh lại từ khóa tìm kiếm hoặc bỏ chọn các bộ lọc.</p>
                                <button @click="resetFilters()" class="mt-3 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold inline-flex items-center gap-1 transition">
                                    <span class="material-symbols-outlined text-[14px]">restart_alt</span>
                                    <span>Xóa tất cả bộ lọc</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. Recent Submissions Section (Bảng Điểm & Kết Quả Thí Sinh) -->
        <div id="submissions" class="bg-white rounded-2xl border border-gray-200/90 shadow-2xs overflow-hidden scroll-mt-6">
            <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-gray-50/50">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="material-symbols-outlined text-emerald-600 text-[20px]">assignment_turned_in</span>
                    <h2 class="font-bold text-xs text-gray-900 uppercase tracking-wider">Danh Sách Thí Sinh &amp; Kết Quả Chấm Điểm</h2>
                    @if ($selectedTest)
                        <span class="px-2.5 py-1 rounded-xl bg-indigo-50 text-indigo-800 border border-indigo-200 font-bold text-xs flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[14px]">filter_alt</span>
                            <span>Đang lọc theo đề: <strong>{{ $selectedTest->title }} ({{ $selectedTest->code }})</strong></span>
                            <span class="ml-1 px-1.5 py-0.2 rounded-full bg-indigo-600 text-white font-mono text-[10px]">{{ $recentSubmissions->count() }} thí sinh</span>
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 font-mono font-bold text-xs">{{ $recentSubmissions->count() }} bài nộp gần nhất</span>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    @if ($selectedTest)
                        <a href="{{ route('placement-tests.index') }}#submissions" class="px-3 py-1.5 rounded-xl bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-semibold transition flex items-center gap-1 shadow-2xs">
                            <span class="material-symbols-outlined text-[14px]">close</span>
                            <span>Bỏ lọc đề này</span>
                        </a>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1120px]">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3.5 px-4 min-w-[190px] whitespace-nowrap">Thí sinh dự thi</th>
                            <th class="py-3.5 px-4 min-w-[120px] whitespace-nowrap">Số điện thoại</th>
                            <th class="py-3.5 px-4 min-w-[210px] whitespace-nowrap">Đề kiểm tra</th>
                            <th class="py-3.5 px-4 text-center min-w-[80px] whitespace-nowrap">Listening</th>
                            <th class="py-3.5 px-4 text-center min-w-[80px] whitespace-nowrap">Reading</th>
                            <th class="py-3.5 px-4 text-center min-w-[80px] whitespace-nowrap">Writing</th>
                            <th class="py-3.5 px-4 text-center min-w-[80px] whitespace-nowrap">Speaking</th>
                            <th class="py-3.5 px-4 text-center min-w-[140px] whitespace-nowrap">Overall (Band)</th>
                            <th class="py-3.5 px-4 min-w-[180px] whitespace-nowrap">Khóa học đề xuất</th>
                            <th class="py-3.5 px-4 text-right min-w-[170px] whitespace-nowrap">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($recentSubmissions as $sub)
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900 flex items-center gap-1.5 whitespace-nowrap">
                                        <span class="whitespace-nowrap">{{ $sub->candidate_name }}</span>
                                        @if ($sub->customer)
                                            <a href="{{ route('crm.customers.show', $sub->customer->id) }}" class="px-1.5 py-0.2 rounded bg-blue-50 hover:bg-blue-100 text-blue-700 font-normal text-[10px] inline-flex items-center gap-0.5 shrink-0 whitespace-nowrap" title="Mở hồ sơ Lead trong CRM">
                                                <span>Lead CRM</span>
                                                <span class="material-symbols-outlined text-[11px]">open_in_new</span>
                                            </a>
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5 whitespace-nowrap flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[12px]">schedule</span>
                                        <span>{{ $sub->created_at ? $sub->created_at->format('H:i d/m/Y') : 'Vừa xong' }}</span>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-gray-600 whitespace-nowrap">{{ $sub->candidate_phone }}</td>
                                <td class="py-3.5 px-4">
                                    <span class="font-semibold text-gray-800 leading-snug block whitespace-nowrap">{{ $sub->test?->title }}</span>
                                    <span class="font-mono text-[10px] text-indigo-600 font-bold whitespace-nowrap">ID: {{ $sub->test?->code }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-indigo-600 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-700">{{ $sub->listening_score ?? '-' }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-emerald-600 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700">{{ $sub->reading_score ?? '-' }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-purple-600 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded bg-purple-50 text-purple-700">{{ $sub->writing_score ?? '-' }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold text-rose-600 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700">{{ $sub->speaking_score ?? '-' }}</span>
                                </td>
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full bg-orange-100 text-orange-800 font-mono font-black text-xs whitespace-nowrap inline-flex items-center justify-center">
                                        {{ $sub->overall_score }} ({{ $sub->cefr_level }})
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-primary whitespace-nowrap">{{ $sub->recommended_course }}</td>
                                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                        <a href="{{ route('portal.test.scorecard', $sub->id) }}" target="_blank" class="px-2.5 py-1 rounded-lg bg-orange-50 hover:bg-primary hover:text-white text-primary font-bold text-[11px] transition inline-flex items-center gap-1 shrink-0 whitespace-nowrap border border-orange-200">
                                            <span class="material-symbols-outlined text-[13px]">description</span>
                                            <span>Phiếu điểm</span>
                                        </a>
                                        <a href="{{ route('placement-tests.results.show', $sub->id) }}" class="px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-[11px] transition inline-flex items-center gap-1 shrink-0 whitespace-nowrap">
                                            <span class="material-symbols-outlined text-[13px]">edit_note</span>
                                            <span>Chấm lại</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-12 text-gray-400 text-xs">
                                    <span class="material-symbols-outlined text-4xl text-gray-300 block mx-auto mb-2">assignment_late</span>
                                    @if ($selectedTest)
                                        <div>Chưa có thí sinh nào nộp bài cho bộ đề <strong>{{ $selectedTest->title }}</strong>.</div>
                                    @else
                                        <div>Chưa có bài thi nào được nộp trong hệ thống.</div>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Alpine.js Component Script for Instant Search & Filtering -->
    <script>
        function placementTestManager() {
            const testList = [
                @foreach ($tests as $t)
                {
                    id: '{{ $t->id }}',
                    code: {!! json_encode(strtoupper($t->code)) !!},
                    title: {!! json_encode(mb_strtolower($t->title)) !!},
                    level: {!! json_encode(mb_strtolower($t->target_level)) !!},
                    isPreset: {{ $t->is_preset ? 'true' : 'false' }},
                    isSpeaking: {{ str_contains(strtoupper($t->code), 'SPEAKING') ? 'true' : 'false' }}
                },
                @endforeach
            ];

            return {
                search: '',
                levelFilter: '',
                typeFilter: '',
                tests: testList,

                matches(testId) {
                    const item = this.tests.find(t => t.id == testId);
                    if (!item) return true;

                    // 1. Search Query
                    if (this.search.trim() !== '') {
                        const q = this.search.trim().toLowerCase();
                        const matchCode = item.code.toLowerCase().includes(q);
                        const matchTitle = item.title.includes(q);
                        if (!matchCode && !matchTitle) return false;
                    }

                    // 2. Level Filter
                    if (this.levelFilter !== '') {
                        const lf = this.levelFilter.toLowerCase();
                        if (!item.level.includes(lf)) return false;
                    }

                    // 3. Type Filter
                    if (this.typeFilter === 'speaking' && !item.isSpeaking) return false;
                    if (this.typeFilter === 'standard' && item.isSpeaking) return false;
                    if (this.typeFilter === 'preset' && !item.isPreset) return false;
                    if (this.typeFilter === 'custom' && item.isPreset) return false;

                    return true;
                },

                get visibleCount() {
                    return this.tests.filter(t => this.matches(t.id)).length;
                },

                resetFilters() {
                    this.search = '';
                    this.levelFilter = '';
                    this.typeFilter = '';
                }
            };
        }
    </script>
</x-app-layout>
