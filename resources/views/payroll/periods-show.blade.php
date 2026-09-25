<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span>Chi tiết Bảng lương Tổng hợp (Kỳ 08/2026)</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 font-semibold border border-amber-200">Đang tính toán (Draft)</span>
                    </h1>
                    <p class="text-xs text-gray-500">Mã kỳ lương: {{ $id }} · Chu kỳ: 01/08/2026 - 31/08/2026</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button class="px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">verified</span>
                    <span>Chốt &amp; Duyệt Bảng Lương</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Sub-department Navigation Pills -->
        <div class="bg-white rounded-2xl p-2.5 border border-gray-200 shadow-sm flex items-center gap-2 overflow-x-auto">
            <a href="{{ route('payroll.periods.show', $id) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-bold bg-navy text-white shadow-sm whitespace-nowrap">
                Tổng hợp toàn hệ thống
            </a>
            <a href="{{ route('payroll.periods.fulltime', $id) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-700 whitespace-nowrap">
                Bảng lương GV Full-time
            </a>
            <a href="{{ route('payroll.periods.academic', $id) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-700 whitespace-nowrap">
                Bảng lương Học thuật
            </a>
            <a href="{{ route('payroll.periods.operations', $id) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-700 whitespace-nowrap">
                Bảng lương Học vụ &amp; Quản lý lớp
            </a>
        </div>

        <!-- Summary Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Giáo viên / Nhân sự</th>
                        <th class="py-3 px-4">Khối / Vai trò</th>
                        <th class="py-3 px-4">Lương cứng</th>
                        <th class="py-3 px-4">Giờ dạy thực tế</th>
                        <th class="py-3 px-4">Thù lao giảng dạy</th>
                        <th class="py-3 px-4">Thưởng KPI &amp; Tái tục</th>
                        <th class="py-3 px-4">Giảm trừ / Phạt</th>
                        <th class="py-3 px-4">Thực lĩnh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    <tr class="hover:bg-cyan-50/20 transition">
                        <td class="py-3.5 px-4 font-bold text-gray-900">
                            ThS. Nguyễn Quốc Anh
                            <div class="text-[10px] text-gray-400 font-mono">GV-001 · Full-time Senior</div>
                        </td>
                        <td class="py-3.5 px-4"><span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-bold text-[10px]">GV Full-time</span></td>
                        <td class="py-3.5 px-4 font-mono font-medium">15.000.000đ</td>
                        <td class="py-3.5 px-4 font-mono font-bold text-gray-900">64 giờ</td>
                        <td class="py-3.5 px-4 font-mono font-bold">19.200.000đ</td>
                        <td class="py-3.5 px-4 font-mono text-emerald-600 font-bold">+3.500.000đ</td>
                        <td class="py-3.5 px-4 font-mono text-rose-600">-0đ</td>
                        <td class="py-3.5 px-4 font-mono font-black text-primary text-sm">37.700.000đ</td>
                    </tr>
                    <tr class="hover:bg-cyan-50/20 transition">
                        <td class="py-3.5 px-4 font-bold text-gray-900">
                            Ms. Jessica Taylor
                            <div class="text-[10px] text-gray-400 font-mono">GV-002 · Native Speaker</div>
                        </td>
                        <td class="py-3.5 px-4"><span class="px-2 py-0.5 rounded bg-purple-50 text-purple-700 font-bold text-[10px]">GV Bản ngữ</span></td>
                        <td class="py-3.5 px-4 font-mono font-medium">—</td>
                        <td class="py-3.5 px-4 font-mono font-bold text-gray-900">48 giờ</td>
                        <td class="py-3.5 px-4 font-mono font-bold">28.800.000đ</td>
                        <td class="py-3.5 px-4 font-mono text-emerald-600 font-bold">+2.000.000đ</td>
                        <td class="py-3.5 px-4 font-mono text-rose-600">-0đ</td>
                        <td class="py-3.5 px-4 font-mono font-black text-primary text-sm">30.800.000đ</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
