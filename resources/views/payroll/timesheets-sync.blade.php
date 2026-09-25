<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('payroll.periods.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">sync</span>
                        Lịch Sử Đồng Bộ Dữ Liệu Máy Chấm Công
                    </h1>
                    <p class="text-xs text-gray-500">Nhật ký quét vân tay / FaceID từ thiết bị phần học viện tại các cơ sở</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Thời gian đồng bộ</th>
                        <th class="py-3 px-4">Cơ sở thiết bị</th>
                        <th class="py-3 px-4">Tên máy chấm công</th>
                        <th class="py-3 px-4 text-center">Số lượt Check-in</th>
                        <th class="py-3 px-4 text-center">Khớp lịch dạy</th>
                        <th class="py-3 px-4">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($syncLogs as $log)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="py-3.5 px-4 font-mono font-medium">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="py-3.5 px-4 font-bold text-gray-900">{{ $log->branch?->name ?? 'Toàn hệ thống' }}</td>
                            <td class="py-3.5 px-4">{{ $log->device_name }}</td>
                            <td class="py-3.5 px-4 font-bold font-mono text-center">{{ $log->total_records }} lượt</td>
                            <td class="py-3.5 px-4 font-bold text-emerald-600 text-center">{{ $log->matched_records }} / {{ $log->total_records }}</td>
                            <td class="py-3.5 px-4">
                                <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold text-[10px]">Đồng bộ thành công</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-400 text-xs">Chưa có lịch sử đồng bộ máy chấm công.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
