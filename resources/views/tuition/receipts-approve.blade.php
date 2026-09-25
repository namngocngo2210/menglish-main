<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tuition.students') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-indigo-600">fact_check</span>
                        Duyệt Phiếu Thu &amp; Khớp sao kê ngân hàng
                    </h1>
                    <p class="text-xs text-gray-500">Kiểm tra thông tin giao dịch, chứng từ chuyển khoản và duyệt ghi nhận doanh thu</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">sync</span>
                    <span>Đồng bộ sao kê Vietcombank</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Pending Approvals Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-amber-500 text-base">pending_actions</span>
                    Danh sách phiếu thu chờ kế toán duyệt (4 phiếu)
                </h2>
            </div>

            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Mã phiếu &amp; Ngày lập</th>
                        <th class="py-3 px-4">Học viên</th>
                        <th class="py-3 px-4">Lớp học</th>
                        <th class="py-3 px-4">Số tiền nộp</th>
                        <th class="py-3 px-4">Hình thức</th>
                        <th class="py-3 px-4">Mã giao dịch</th>
                        <th class="py-3 px-4">Người lập phiếu</th>
                        <th class="py-3 px-4 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    <tr class="hover:bg-indigo-50/20 transition">
                        <td class="py-3.5 px-4 font-mono font-medium">
                            <div class="font-bold text-gray-900">PT-2026-0889</div>
                            <div class="text-[10px] text-gray-400">17/08/2026 09:15</div>
                        </td>
                        <td class="py-3.5 px-4 font-medium text-gray-900">Vũ Thị Minh Hằng</td>
                        <td class="py-3.5 px-4 font-semibold text-gray-800">IELTS 6.5 - B2408</td>
                        <td class="py-3.5 px-4 font-mono font-bold text-primary">12.500.000đ</td>
                        <td class="py-3.5 px-4">
                            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-bold">VietQR</span>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-gray-600">FT2608149882</td>
                        <td class="py-3.5 px-4 font-medium">Trần Thu Hà</td>
                        <td class="py-3.5 px-4 text-right whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1.5">
                                <button class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-sm">
                                    Duyệt phiếu
                                </button>
                                <button class="px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-100 text-gray-600 font-semibold text-xs transition">
                                    Từ chối
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr class="hover:bg-indigo-50/20 transition">
                        <td class="py-3.5 px-4 font-mono font-medium">
                            <div class="font-bold text-gray-900">PT-2026-0888</div>
                            <div class="text-[10px] text-gray-400">16/08/2026 15:40</div>
                        </td>
                        <td class="py-3.5 px-4 font-medium text-gray-900">Nguyễn Đình Trọng</td>
                        <td class="py-3.5 px-4 font-semibold text-gray-800">IELTS 5.5 - A2408</td>
                        <td class="py-3.5 px-4 font-mono font-bold text-primary">4.500.000đ</td>
                        <td class="py-3.5 px-4">
                            <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 text-[10px] font-bold">Tiền mặt</span>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-gray-400">—</td>
                        <td class="py-3.5 px-4 font-medium">Lê Quốc Bảo</td>
                        <td class="py-3.5 px-4 text-right whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1.5">
                                <button class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-sm">
                                    Duyệt phiếu
                                </button>
                                <button class="px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-100 text-gray-600 font-semibold text-xs transition">
                                    Từ chối
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
