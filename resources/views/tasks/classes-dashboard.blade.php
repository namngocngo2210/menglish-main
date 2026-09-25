<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Dashboard lớp học</h1>
                <p class="text-sm text-gray-500 mt-0.5">Quản lý lịch học, điểm danh, chấm công giảng viên và bố trí trợ giảng</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium text-sm hover:bg-gray-50 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Xuất báo cáo
                </button>
                <a href="{{ route('tasks.schedule-config') }}" class="bg-primary-container text-white px-4 py-2 rounded-lg font-medium text-sm hover:bg-primary transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Thêm / Cấu hình lớp
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{ activeTab: 'day' }">

        <!-- Main Content Card with Tabs -->
        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
            <!-- Tab Headers -->
            <div class="flex border-b border-gray-200 px-6 bg-gray-50/50">
                <button @click="activeTab = 'day'"
                        :class="activeTab === 'day' ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-5 py-3.5 text-sm font-medium border-b-2 transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">today</span>
                    Theo ngày
                </button>
                <button @click="activeTab = 'week'"
                        :class="activeTab === 'week' ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-5 py-3.5 text-sm font-medium border-b-2 transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">calendar_view_week</span>
                    Theo tuần (Ma trận khung giờ)
                </button>
            </div>

            <!-- PANEL 1: THEO NGÀY -->
            <div x-show="activeTab === 'day'" class="p-6 space-y-6">
                <!-- Filters Bar -->
                <div class="flex flex-col sm:flex-row items-center gap-4 bg-gray-50/80 p-4 rounded-xl border border-gray-200">
                    <div class="w-full sm:w-64">
                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Chi nhánh</label>
                        <select class="w-full bg-white border-gray-200 rounded-lg text-sm focus:ring-primary-container focus:border-primary-container">
                            <option>Tất cả chi nhánh</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="w-full sm:w-64">
                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Chọn ngày</label>
                        <input type="date" value="{{ $date }}" class="w-full bg-white border-gray-200 rounded-lg text-sm focus:ring-primary-container focus:border-primary-container">
                    </div>
                    <div class="sm:self-end w-full sm:w-auto">
                        <button class="w-full sm:w-auto px-4 py-2 bg-white border border-gray-200 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-100 transition flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">filter_list</span>
                            Lọc danh sách
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    <!-- Main Table Area (3 cols) -->
                    <div class="lg:col-span-3 space-y-4">
                        <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-sm">
                                    <thead class="bg-gray-50 text-gray-600 uppercase text-[11px] font-semibold tracking-wider border-b border-gray-200">
                                        <tr>
                                            <th class="py-3.5 px-4 whitespace-nowrap">Tên lớp</th>
                                            <th class="py-3.5 px-4 whitespace-nowrap">Khung giờ</th>
                                            <th class="py-3.5 px-4 whitespace-nowrap">Phòng học</th>
                                            <th class="py-3.5 px-4 whitespace-nowrap">GV Chính</th>
                                            <th class="py-3.5 px-4 whitespace-nowrap">GVNN</th>
                                            <th class="py-3.5 px-4 text-center whitespace-nowrap">Sĩ số</th>
                                            <th class="py-3.5 px-4 text-center whitespace-nowrap">Điểm danh</th>
                                            <th class="py-3.5 px-4 text-right whitespace-nowrap">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse($classes as $c)
                                            <tr class="hover:bg-gray-50/80 transition-colors">
                                                <td class="py-4 px-4 font-semibold text-gray-900">
                                                    <div class="flex items-center gap-2">
                                                        <span class="w-2.5 h-2.5 rounded-full {{ str_contains(strtolower($c->name), 'ielts') ? 'bg-blue-600' : (str_contains(strtolower($c->name), 'toeic') ? 'bg-orange-600' : 'bg-emerald-600') }}"></span>
                                                        <span>{{ $c->name }}</span>
                                                    </div>
                                                </td>
                                                <td class="py-4 px-4 whitespace-nowrap font-mono text-xs text-gray-700">{{ $c->schedule_text ?? '18:00 - 20:00' }}</td>
                                                <td class="py-4 px-4 whitespace-nowrap text-gray-600">Phòng {{ $c->id + 100 }}</td>
                                                <td class="py-4 px-4 whitespace-nowrap font-medium text-gray-800">{{ $c->teacher?->name ?? 'Chưa gán' }}</td>
                                                <td class="py-4 px-4 whitespace-nowrap text-gray-600">{{ $c->course?->code === 'IELTS-MASTER' ? 'John Doe' : '—' }}</td>
                                                <td class="py-4 px-4 text-center font-semibold text-gray-800">{{ $c->students->count() }}/{{ $c->max_capacity ?: 20 }}</td>
                                                <td class="py-4 px-4 text-center">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        Đang hoạt động
                                                    </span>
                                                </td>
                                                <td class="py-4 px-4 text-right whitespace-nowrap">
                                                    <a href="{{ route('payroll.timesheets.manual') }}" class="inline-block bg-primary-container text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-primary transition shadow-sm">
                                                        Chấm công
                                                    </a>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="8" class="py-8 text-center text-gray-400 text-xs">Chưa có lớp học nào được cấu hình trong hệ thống.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Area: Trợ giảng làm việc hôm nay (1 col) -->
                    <div class="lg:col-span-1">
                        <div class="bg-gray-50/80 border border-gray-200 rounded-xl p-5 space-y-4">
                            <div class="flex items-center gap-2 border-b border-gray-200 pb-3">
                                <span class="material-symbols-outlined text-primary-container">support_agent</span>
                                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide">Trợ giảng phụ trách</h3>
                            </div>

                            <ul class="space-y-3 text-sm">
                                @forelse($assistantsToday as $ta)
                                    <li class="p-3 bg-white border border-gray-200 rounded-xl flex items-center gap-3 shadow-xs">
                                        <div class="w-9 h-9 rounded-full bg-orange-100 text-primary-container font-bold flex items-center justify-center text-xs shrink-0">
                                            {{ Str::substr($ta->name, 0, 1) }}
                                        </div>
                                        <div class="overflow-hidden">
                                            <p class="font-semibold text-gray-900 text-xs truncate">{{ $ta->name }}</p>
                                            <p class="text-[11px] text-gray-500 font-mono truncate">{{ $ta->email }}</p>
                                        </div>
                                    </li>
                                @empty
                                    <li class="p-3 text-xs text-gray-400 text-center">Chưa có trợ giảng nào.</li>
                                @endforelse
                            </ul>

                            <a href="{{ route('tasks.ta-assign') }}" class="w-full py-2.5 bg-white border border-gray-300 text-gray-700 rounded-xl font-medium text-xs hover:bg-gray-50 transition flex items-center justify-center gap-1.5 shadow-xs">
                                <span class="material-symbols-outlined text-[16px]">add</span>
                                Giao việc cho Trợ giảng
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PANEL 2: THEO TUẦN (MA TRẬN KHUNG GIỜ) -->
            <div x-show="activeTab === 'week'" class="p-6 space-y-6">
                <!-- Filters Bar (Week) -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-gray-50/80 p-4 rounded-xl border border-gray-200">
                    <div class="flex flex-wrap items-center gap-4 w-full sm:w-auto">
                        <div class="w-full sm:w-56">
                            <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Chi nhánh</label>
                            <select class="w-full bg-white border-gray-200 rounded-lg text-sm">
                                <option>Tất cả chi nhánh</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-full sm:w-56">
                            <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Chọn tuần</label>
                            <input type="week" value="{{ $week }}" class="w-full bg-white border-gray-200 rounded-lg text-sm">
                        </div>
                    </div>
                    <!-- Legend -->
                    <div class="flex items-center gap-4 text-xs text-gray-600">
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-3 rounded bg-orange-100 border border-orange-300"></span> Lớp IELTS
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-3 rounded bg-blue-100 border border-blue-300"></span> Lớp Giao tiếp
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-3 rounded bg-emerald-100 border border-emerald-300"></span> Lớp TOEIC
                        </div>
                    </div>
                </div>

                <!-- Matrix Schedule Table -->
                <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[900px] table-fixed text-xs">
                            <thead class="bg-gray-50 text-gray-700 uppercase tracking-wider font-semibold border-b border-gray-200">
                                <tr>
                                    <th class="w-[120px] p-3 text-center border-r border-gray-200 bg-gray-100/70">Khung giờ</th>
                                    <th class="p-3 text-center border-r border-gray-200">Thứ 2<br><span class="text-[10px] text-gray-400 font-normal">Ngày 1</span></th>
                                    <th class="p-3 text-center border-r border-gray-200">Thứ 3<br><span class="text-[10px] text-gray-400 font-normal">Ngày 2</span></th>
                                    <th class="p-3 text-center border-r border-gray-200">Thứ 4<br><span class="text-[10px] text-gray-400 font-normal">Ngày 3</span></th>
                                    <th class="p-3 text-center border-r border-gray-200">Thứ 5<br><span class="text-[10px] text-gray-400 font-normal">Ngày 4</span></th>
                                    <th class="p-3 text-center border-r border-gray-200">Thứ 6<br><span class="text-[10px] text-gray-400 font-normal">Ngày 5</span></th>
                                    <th class="p-3 text-center border-r border-gray-200 bg-gray-50">Thứ 7<br><span class="text-[10px] text-gray-400 font-normal">Ngày 6</span></th>
                                    <th class="p-3 text-center bg-gray-50">Chủ Nhật<br><span class="text-[10px] text-gray-400 font-normal">Ngày 7</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 align-top">
                                <!-- 08:00 - 10:00 -->
                                <tr>
                                    <td class="p-3 text-center font-bold text-gray-600 bg-gray-50 border-r border-gray-200 font-mono">
                                        08:00 - 10:00
                                    </td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-orange-900 truncate">IELTS F. 01</p>
                                            <p class="text-[10px] text-orange-700">Phòng 101</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200 space-y-1.5">
                                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-orange-900 truncate">IELTS F. 01</p>
                                            <p class="text-[10px] text-orange-700">Phòng 101</p>
                                        </div>
                                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-blue-900 truncate">Giao tiếp B2</p>
                                            <p class="text-[10px] text-blue-700">Phòng 205</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-orange-900 truncate">IELTS F. 01</p>
                                            <p class="text-[10px] text-orange-700">Phòng 101</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 bg-gray-50/30"></td>
                                </tr>

                                <!-- 10:00 - 12:00 -->
                                <tr>
                                    <td class="p-3 text-center font-bold text-gray-600 bg-gray-50 border-r border-gray-200 font-mono">
                                        10:00 - 12:00
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-amber-900 truncate">Thi thử IELTS</p>
                                            <p class="text-[10px] text-amber-700">Hội trường</p>
                                        </div>
                                    </td>
                                    <td class="p-2">
                                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-amber-900 truncate">Thi thử IELTS</p>
                                            <p class="text-[10px] text-amber-700">Hội trường</p>
                                        </div>
                                    </td>
                                </tr>

                                <!-- 14:00 - 16:00 -->
                                <tr>
                                    <td class="p-3 text-center font-bold text-gray-600 bg-gray-50 border-r border-gray-200 font-mono">
                                        14:00 - 16:00
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-emerald-900 truncate">TOEIC 500+</p>
                                            <p class="text-[10px] text-emerald-700">Phòng 203</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-emerald-900 truncate">TOEIC 500+</p>
                                            <p class="text-[10px] text-emerald-700">Phòng 203</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 bg-gray-50/30"></td>
                                </tr>

                                <!-- 18:00 - 20:00 -->
                                <tr>
                                    <td class="p-3 text-center font-bold text-gray-600 bg-gray-50 border-r border-gray-200 font-mono">
                                        18:00 - 20:00
                                    </td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-blue-900 truncate">Giao tiếp CB</p>
                                            <p class="text-[10px] text-blue-700">Phòng 105</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-orange-900 truncate">IELTS Int 02</p>
                                            <p class="text-[10px] text-orange-700">Phòng 301</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-blue-900 truncate">Giao tiếp CB</p>
                                            <p class="text-[10px] text-blue-700">Phòng 105</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-orange-900 truncate">IELTS Int 02</p>
                                            <p class="text-[10px] text-orange-700">Phòng 301</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200">
                                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 hover:shadow-md transition">
                                            <p class="font-bold text-blue-900 truncate">Giao tiếp CB</p>
                                            <p class="text-[10px] text-blue-700">Phòng 105</p>
                                        </div>
                                    </td>
                                    <td class="p-2 border-r border-gray-200 bg-gray-50/30"></td>
                                    <td class="p-2 bg-gray-50/30"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
