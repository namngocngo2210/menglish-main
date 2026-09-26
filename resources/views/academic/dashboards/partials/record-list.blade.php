{{-- Danh sách báo cáo (AcademicRecord) dùng cho tab tuần / tháng của dashboard báo cáo đào tạo. --}}
<table>
    <thead>
        <tr>
            <th>Báo cáo</th>
            <th>Người gửi</th>
            <th>Trạng thái</th>
            <th>Thời gian</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($records as $record)
            <tr>
                <td>
                    <span class="font-bold">{{ $record->title ?: 'Báo cáo' }}</span>
                    @if ($record->record_code)
                        <span class="block font-code text-[11px] text-on-surface-variant">{{ $record->record_code }}</span>
                    @endif
                </td>
                <td class="font-semibold">{{ $record->user?->name ?? 'Chưa cập nhật' }}</td>
                <td>{{ $record->status_label }}</td>
                <td class="font-code text-[11px] text-on-surface-variant">{{ $record->created_at?->format('H:i d/m/Y') }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4"><x-ui.empty-state :title="$empty" /></td>
            </tr>
        @endforelse
    </tbody>
</table>
