<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">TKB — Quản lý lớp học & Báo cáo Nhân sự</h1>
                <p class="text-sm text-gray-500 mt-0.5">Cấu hình thời khóa biểu lớp học và dự báo nhu cầu nhân sự trợ giảng</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium text-sm hover:bg-gray-50 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Xuất Excel
                </button>
                <a href="{{ route('tasks.classes-dashboard') }}" class="bg-[#F5691A] text-white px-4 py-2 rounded-lg font-medium text-sm hover:bg-[#d85a15] transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">dashboard</span>
                    Xem Dashboard Lớp
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{ searchQuery: '' }">

        

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- SECTION 1: Cấu hình lịch lớp (7 cols) -->
            <section class="lg:col-span-7 space-y-6">
                @if($errors->any())
                    <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-r-xl shadow-xs text-xs text-rose-900">
                        <h4 class="font-bold">Không thể lưu lịch</h4>
                        <ul class="mt-1 list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <!-- Schedule Form Card -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#F5691A]">calendar_month</span>
                            Cấu hình lịch lớp mẫu
                        </h2>
                    </div>

                    <form action="{{ route('tasks.schedule-config.update') }}" method="POST" class="space-y-4 text-xs">
                        @csrf
                        <div>
                            <label class="block font-bold uppercase text-gray-600 mb-1">Chọn lớp học (Chưa có TKB)</label>
                            <select name="class_id" class="w-full rounded-xl border-gray-200 text-xs focus:ring-primary focus:border-primary">
                                <option value="">-- Chọn lớp học --</option>
                                @foreach($classes as $c)
                                    @if(empty($c->schedule_text))
                                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div><label class="block text-gray-500 mb-1">Năm học</label><input name="academic_year" value="{{ old('academic_year', now()->format('Y').' - '.now()->addYear()->format('Y')) }}" class="w-full rounded-lg border-gray-200 text-xs"></div>
                            <div><label class="block text-gray-500 mb-1">Khai giảng</label><input type="date" name="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}" class="w-full rounded-lg border-gray-200 text-xs" required></div>
                            <div><label class="block text-gray-500 mb-1">Kết thúc</label><input type="date" name="end_date" value="{{ old('end_date', now()->addMonths(3)->format('Y-m-d')) }}" class="w-full rounded-lg border-gray-200 text-xs" required></div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Slot 1 -->
                            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-3">
                                <h3 class="font-bold text-gray-800 flex items-center gap-1 text-xs">
                                    <span class="material-symbols-outlined text-[16px] text-emerald-600">looks_one</span>
                                    Slot 1
                                </h3>
                                <div>
                                    <label class="block text-gray-500 mb-1">Ngày trong tuần</label>
                                    <select name="slot1_day" class="w-full rounded-lg border-gray-200 text-xs bg-white">
                                        <option>Thứ 2</option>
                                        <option>Thứ 3</option>
                                        <option>Thứ 4</option>
                                        <option selected>Thứ 5</option>
                                        <option>Thứ 6</option>
                                        <option>Thứ 7</option>
                                        <option>Chủ nhật</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-gray-500 mb-1">Giờ bắt đầu</label>
                                        <input type="time" name="slot1_start" value="18:00" class="w-full rounded-lg border-gray-200 text-xs bg-white font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-gray-500 mb-1">Giờ kết thúc</label>
                                        <input type="time" name="slot1_end" value="19:30" class="w-full rounded-lg border-gray-200 text-xs bg-white font-mono">
                                    </div>
                                </div>
                            </div>

                            <!-- Slot 2 -->
                            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-3">
                                <h3 class="font-bold text-gray-800 flex items-center gap-1 text-xs">
                                    <span class="material-symbols-outlined text-[16px] text-emerald-600">looks_two</span>
                                    Slot 2
                                </h3>
                                <div>
                                    <label class="block text-gray-500 mb-1">Ngày trong tuần</label>
                                    <select name="slot2_day" class="w-full rounded-lg border-gray-200 text-xs bg-white">
                                        <option>Thứ 2</option>
                                        <option>Thứ 3</option>
                                        <option>Thứ 4</option>
                                        <option>Thứ 5</option>
                                        <option>Thứ 6</option>
                                        <option selected>Thứ 7</option>
                                        <option>Chủ nhật</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-gray-500 mb-1">Giờ bắt đầu</label>
                                        <input type="time" name="slot2_start" value="18:00" class="w-full rounded-lg border-gray-200 text-xs bg-white font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-gray-500 mb-1">Giờ kết thúc</label>
                                        <input type="time" name="slot2_end" value="19:30" class="w-full rounded-lg border-gray-200 text-xs bg-white font-mono">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-gray-100 flex justify-end gap-2">
                            <button type="submit" class="bg-[#F5691A] text-white px-4 py-2 rounded-xl font-bold hover:bg-[#d85a15] transition shadow-xs">
                                Cập nhật lịch
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Current Class List -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                        <h3 class="font-bold text-gray-900 text-sm">Danh sách lớp hiện tại</h3>
                        <input type="text" x-model="searchQuery" placeholder="Tìm lớp..."
                               class="rounded-lg border-gray-200 text-xs py-1.5 px-3 w-44 bg-white focus:ring-primary focus:border-primary">
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead class="bg-gray-50/50 text-gray-500 uppercase tracking-wider font-semibold border-b border-gray-200">
                                <tr>
                                    <th class="p-3">Tên Lớp</th>
                                    <th class="p-3">Giảng Viên</th>
                                    <th class="p-3">Trạng Thái</th>
                                    <th class="p-3 text-right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($classes as $c)
                                    <tr class="hover:bg-gray-50 transition" x-show="searchQuery === '' || '{{ strtolower($c->name . $c->code) }}'.includes(searchQuery.toLowerCase())">
                                        <td class="p-3 font-semibold text-gray-900">
                                            {{ $c->name }}
                                            <span class="text-gray-400 font-mono font-normal block text-[10px]">{{ $c->code }} · {{ $c->schedule_text }}</span>
                                        </td>
                                        <td class="p-3 text-gray-600">{{ $c->teacher?->name ?? 'Chưa phân công' }}</td>
                                        <td class="p-3">
                                            @if($c->status === 'active')
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Đang học
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-500 border border-gray-200">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Đã kết thúc
                                                </span>
                                            @endif
                                        </td>
                                        <td class="p-3 text-right">
                                            <form action="{{ route('tasks.schedule-config.update') }}" method="POST" class="inline">
                                                @csrf
                                                <input type="hidden" name="toggle_class_id" value="{{ $c->id }}">
                                                <button type="submit" class="px-2.5 py-1 rounded text-[11px] font-semibold transition {{ $c->status === 'active' ? 'text-rose-600 hover:bg-rose-50' : 'text-blue-600 hover:bg-blue-50' }}">
                                                    {{ $c->status === 'active' ? 'Kết thúc lớp' : 'Mở lại lớp' }}
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- SECTION 2: Báo cáo phòng / nhân sự (5 cols) -->
            <section class="lg:col-span-5 space-y-6">
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 flex flex-col space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#F5691A]">groups</span>
                            Báo cáo phòng / nhân sự
                        </h2>
                    </div>

                    <!-- Filters -->
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <label class="block text-gray-500 font-bold uppercase mb-1">Chi nhánh</label>
                            <select class="w-full rounded-lg border-gray-200 text-xs">
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-500 font-bold uppercase mb-1">Khoảng ngày</label>
                            <input type="date" value="{{ now()->format('Y-m-d') }}" class="w-full rounded-lg border-gray-200 text-xs">
                        </div>
                    </div>

                    <!-- Alert Banner -->
                    <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl flex items-start gap-2.5 text-xs text-amber-900">
                        <span class="material-symbols-outlined text-amber-600 text-[18px] shrink-0 mt-0.5">warning</span>
                        <p>Số lớp đã thay đổi <strong>12 → 15</strong> so với tuần trước, kiểm tra lại số nhân sự trợ giảng cần bố trí.</p>
                    </div>

                    <!-- Editable Table -->
                    <form action="{{ route('tasks.hr-demand.save') }}" method="POST" class="space-y-4">
                        @csrf
                        <div class="border border-gray-200 rounded-xl overflow-hidden shadow-2xs">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead class="bg-gray-50 text-gray-500 uppercase tracking-wider font-semibold border-b border-gray-200">
                                    <tr>
                                        <th class="p-3 w-1/3">Ngày</th>
                                        <th class="p-3 text-center w-1/3">Số ca</th>
                                        <th class="p-3 text-center w-1/3">Nhân sự cần</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($demands as $dm)
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-3 font-semibold text-gray-800">
                                                {{ $dm->day_of_week }}
                                                <span class="text-gray-400 block text-[10px] font-normal font-mono">{{ $dm->report_date ? $dm->report_date->format('d/m') : '' }}</span>
                                            </td>
                                            <td class="p-3 text-center font-bold text-gray-700 font-mono">{{ $dm->shift_count }}</td>
                                            <td class="p-2 text-center">
                                                <input type="number" name="demands[{{ $dm->id }}]" value="{{ $dm->staff_needed }}" min="0" max="20"
                                                       class="w-16 text-center rounded-lg border-gray-200 text-xs py-1 font-bold text-gray-900 focus:ring-primary focus:border-primary">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="w-full bg-[#F5691A] text-white hover:bg-[#d85a15] font-bold text-xs py-3 rounded-xl transition shadow-xs flex items-center justify-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">save</span>
                            Lưu báo cáo nhân sự
                        </button>
                    </form>
                </div>
            </section>
        </div>

    </div>
</x-app-layout>
