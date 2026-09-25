<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">send_to_mobile</span>
                        Duyệt Kết Quả Big Test &amp; Gửi Báo Cáo Phụ Huynh
                    </h1>
                    <p class="text-xs text-gray-500">Phê duyệt bảng điểm thi định kỳ và tự động xuất phiếu kết quả gửi SMS/Zalo ZNS cho Phụ huynh</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">send</span>
                    <span>Duyệt &amp; Gửi Báo Cáo Toàn Lớp</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Học viên</th>
                        <th class="py-3 px-4">Listening</th>
                        <th class="py-3 px-4">Reading</th>
                        <th class="py-3 px-4">Writing</th>
                        <th class="py-3 px-4">Speaking</th>
                        <th class="py-3 px-4">Overall Score</th>
                        <th class="py-3 px-4">Đánh giá tiến bộ</th>
                        <th class="py-3 px-4 text-right">Trạng thái gửi PH</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    <tr class="hover:bg-purple-50/10 transition">
                        <td class="py-3.5 px-4 font-bold text-gray-900">
                            Nguyễn Minh Anh
                            <div class="text-[10px] text-gray-400 font-mono">HV-01 · Lớp IE-2408</div>
                        </td>
                        <td class="py-3.5 px-4 font-mono font-bold">6.5</td>
                        <td class="py-3.5 px-4 font-mono font-bold">6.0</td>
                        <td class="py-3.5 px-4 font-mono font-bold">5.5</td>
                        <td class="py-3.5 px-4 font-mono font-bold">6.0</td>
                        <td class="py-3.5 px-4 font-mono font-black text-primary text-sm">6.0 Overall</td>
                        <td class="py-3.5 px-4"><span class="text-emerald-700 font-semibold">Tăng +1.0 Band so với đầu vào</span></td>
                        <td class="py-3.5 px-4 text-right">
                            <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold border border-emerald-200">Đã gửi Zalo ZNS</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
