<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Bảng KPI tự động</h1>
                <p class="text-sm text-gray-500 mt-0.5">Theo dõi các chỉ số hiệu suất chính của nhân sự tự động hóa</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium text-sm hover:bg-gray-50 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Xuất KPI
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{ staffFilter: 'all' }">

        <!-- Filters Section -->
        <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-4 w-full sm:w-auto">
                <div class="w-full sm:w-64">
                    <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1" for="staff-filter">Chọn nhân sự</label>
                    <select id="staff-filter" x-model="staffFilter" class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary focus:border-primary">
                        <option value="all">Tất cả nhân sự</option>
                        @foreach($kpiData as $kp)
                            <option value="{{ $kp['code'] }}">{{ $kp['name'] }} ({{ $kp['code'] }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-64">
                    <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1" for="date-filter">Kỳ báo cáo</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">calendar_today</span>
                        <input type="text" id="date-filter" readonly value="01/10/2026 - 31/10/2026"
                               class="w-full pl-9 rounded-xl border-gray-200 text-sm bg-gray-50/50 cursor-pointer">
                    </div>
                </div>
            </div>

            <div class="text-xs text-gray-500">
                Cập nhật tự động: <span class="font-mono font-semibold text-gray-800">{{ now()->format('H:i d/m/Y') }}</span>
            </div>
        </div>

        <!-- KPI Data Table -->
        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-[11px] font-semibold tracking-wider border-b border-gray-200">
                        <tr>
                            <th class="py-4 px-6 whitespace-nowrap">Nhân sự</th>
                            <th class="py-4 px-6 text-right whitespace-nowrap">Tỷ lệ giữ chân học viên</th>
                            <th class="py-4 px-6 text-right whitespace-nowrap">Tỷ lệ chuyên cần</th>
                            <th class="py-4 px-6 text-right whitespace-nowrap">Tỷ lệ hoàn thành bài tập</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($kpiData as $row)
                            <tr class="hover:bg-gray-50/80 transition-colors" x-show="staffFilter === 'all' || staffFilter === '{{ $row['code'] }}'">
                                <td class="py-4 px-6 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full {{ $row['bg_color'] }} flex items-center justify-center font-bold text-sm shadow-xs">
                                            {{ $row['initial'] }}
                                        </div>
                                        <div>
                                            <div class="font-bold text-gray-900 text-sm">{{ $row['name'] }}</div>
                                            <div class="text-xs text-gray-400 font-mono">{{ $row['code'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-right whitespace-nowrap font-mono font-semibold text-sm text-gray-900">
                                    {{ $row['retention_rate'] }}
                                </td>
                                <td class="py-4 px-6 text-right whitespace-nowrap font-mono font-semibold text-sm {{ floatval($row['attendance_rate']) < 85 ? 'text-rose-600 font-bold' : 'text-gray-900' }}">
                                    {{ $row['attendance_rate'] }}
                                </td>
                                <td class="py-4 px-6 text-right whitespace-nowrap text-xs text-gray-400 italic">
                                    {{ $row['homework_rate'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="p-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between text-xs text-gray-500">
                <span>Hiển thị {{ count($kpiData) }} nhân sự</span>
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span> Dữ liệu được đồng bộ từ sổ điểm danh và kết quả bài thi
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
