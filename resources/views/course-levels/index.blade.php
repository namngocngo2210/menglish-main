<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">layers</span>
                    Khung Trình Độ &amp; Cấp Độ Đào Tạo (Database)
                </h1>
                <p class="text-xs text-gray-500">Chuẩn hóa các cấp độ đào tạo theo thang đo CEFR quốc tế và IELTS Target</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="document.getElementById('newLevelModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Thêm cấp độ mới</span>
                </button>
            </div>
        </div>
    </x-slot>

    <!-- Navigation Sub-Tabs -->
    <div class="border-b border-gray-200 bg-white -mt-4 -mx-4 sm:-mt-6 sm:-mx-6 px-6 pt-3 mb-5">
        <div class="flex items-center gap-6 overflow-x-auto text-xs font-semibold scrollbar-none">
            <a href="{{ route('courses.index') }}" class="pb-3 border-b-2 border-transparent text-gray-600 hover:text-gray-900 transition whitespace-nowrap flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px]">sell</span>
                <span>Bảng giá &amp; Danh mục Khóa học</span>
            </a>
            <a href="{{ route('course-levels.index') }}" class="pb-3 border-b-2 border-primary text-primary font-bold transition whitespace-nowrap flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px]">layers</span>
                <span>Khung Trình Độ CEFR / IELTS</span>
            </a>
            <a href="{{ route('syllabus.documents') }}" class="pb-3 border-b-2 border-transparent text-gray-600 hover:text-gray-900 transition whitespace-nowrap flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px]">menu_book</span>
                <span>Giáo trình &amp; Syllabus</span>
            </a>
        </div>
    </div>

    <!-- Create Level Modal -->
    <div id="newLevelModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <h3 class="font-bold text-sm text-gray-900">Thêm Khung Trình Độ Mới</h3>
                <button type="button" onclick="document.getElementById('newLevelModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('course-levels.store') }}" method="POST" class="space-y-3">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Mã cấp độ (Code)</label>
                        <input type="text" name="code" placeholder="C2" required class="w-full text-xs font-bold rounded-xl border border-gray-200 p-2 font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số buổi học</label>
                        <input type="number" name="lessons_count" value="24" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Tên trình độ</label>
                    <input type="text" name="name" placeholder="Proficiency (Chuyên gia)" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Mục tiêu đầu ra (Target)</label>
                    <input type="text" name="target" placeholder="CEFR C2 / IELTS 8.5+" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Thời lượng ước tính</label>
                    <input type="text" name="duration" placeholder="14 tuần / 28 buổi" class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('newLevelModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg border text-xs text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-1.5 bg-primary text-white text-xs font-bold rounded-lg shadow-sm">Lưu vào CSDL</button>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        <!-- Level Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[780px]">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4 min-w-[100px] whitespace-nowrap">Mã cấp độ</th>
                            <th class="py-3 px-4 min-w-[200px] whitespace-nowrap">Tên trình độ</th>
                            <th class="py-3 px-4 min-w-[180px] whitespace-nowrap">Chuẩn đầu ra (Target)</th>
                            <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Thời lượng / Số buổi</th>
                            <th class="py-3 px-4 text-center min-w-[140px] whitespace-nowrap">Số khóa học trực thuộc</th>
                            <th class="py-3 px-4 text-center min-w-[120px] whitespace-nowrap">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($levels as $lv)
                            <tr class="hover:bg-orange-50/20 transition">
                                <td class="py-3.5 px-4 font-mono font-bold text-gray-900 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 border border-indigo-200/70 font-mono font-bold text-xs">
                                        {{ $lv->code }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-bold text-gray-900 whitespace-nowrap">{{ $lv->name }}</td>
                                <td class="py-3.5 px-4 font-semibold text-primary whitespace-nowrap">{{ $lv->target }}</td>
                                <td class="py-3.5 px-4 text-gray-600 whitespace-nowrap">{{ $lv->duration ?? "{$lv->lessons_count} buổi" }}</td>
                                <td class="py-3.5 px-4 text-center font-mono font-bold whitespace-nowrap">{{ $lv->courses_count }} khóa</td>
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Đang áp dụng</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8 text-gray-400 text-xs">Chưa có khung trình độ nào.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
