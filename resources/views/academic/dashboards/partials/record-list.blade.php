{{-- Danh sách báo cáo (AcademicRecord) dùng cho tab tuần / tháng của dashboard báo cáo đào tạo. --}}
<div class="overflow-x-auto">
    <table class="w-full text-left text-xs">
        <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200">
            <tr>
                <th class="py-3 px-4">Báo cáo</th>
                <th class="py-3 px-4">Người gửi</th>
                <th class="py-3 px-4">Trạng thái</th>
                <th class="py-3 px-4">Thời gian</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($records as $record)
                <tr class="hover:bg-gray-50/80 transition">
                    <td class="py-3 px-4">
                        <span class="font-bold text-gray-900">{{ $record->title ?: 'Báo cáo' }}</span>
                        @if ($record->record_code)
                            <span class="block text-[11px] text-gray-500 font-mono">{{ $record->record_code }}</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 font-semibold text-gray-800">{{ $record->user?->name ?? 'Chưa cập nhật' }}</td>
                    <td class="py-3 px-4">{{ $record->status_label }}</td>
                    <td class="py-3 px-4 font-mono text-gray-500 text-[11px]">{{ $record->created_at?->format('H:i d/m/Y') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="py-8 text-center text-gray-400">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
