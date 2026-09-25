<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('syllabus.documents') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">fact_check</span>
                    Duyệt &amp; Phân Phối Đề Big Test Sang Các Cơ Sở
                </h1>
                <p class="text-xs text-gray-500">Bảo mật đề thi giữa kỳ / cuối kỳ và kiểm soát SLA gửi đề trước giờ thi 24h</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Kỳ thi Big Test</th>
                        <th class="py-3 px-4">Khóa học áp dụng</th>
                        <th class="py-3 px-4">Cơ sở nhận đề</th>
                        <th class="py-3 px-4">Thời gian thi</th>
                        <th class="py-3 px-4">Bảo mật (Passcode)</th>
                        <th class="py-3 px-4 text-right">Trạng thái phân phối</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    <tr class="hover:bg-purple-50/10 transition">
                        <td class="py-3.5 px-4 font-bold text-gray-900">Mid-Term Big Test #08</td>
                        <td class="py-3.5 px-4 font-semibold text-purple-800">IELTS 6.5 Intensive</td>
                        <td class="py-3.5 px-4">Tất cả 3 cơ sở</td>
                        <td class="py-3.5 px-4 font-mono font-medium">25/08/2026 (19:30)</td>
                        <td class="py-3.5 px-4 font-mono text-gray-500">●●●●●●●● (Tự mở lúc 19:00)</td>
                        <td class="py-3.5 px-4 text-right">
                            <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold border border-emerald-200">Đã duyệt phân phối</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
