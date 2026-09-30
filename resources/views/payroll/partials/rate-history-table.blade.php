{{-- Bảng lịch sử phiên bản đơn giá GV. Biến: $rows, $endDates, $unitSuffix, $withTeacher (hiện cột GV), $title / $paginator (tuỳ chọn) --}}
<x-ui.data-table min-width="1000px" :sticky="$withTeacher ? 'first' : null">
    @isset($title)
        <x-slot:header>
            <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">history</span>{{ $title }}
            </h3>
        </x-slot:header>
    @endisset
    <table>
        <thead>
            <tr>
                @if ($withTeacher)<th>Giáo viên</th>@endif
                <th>Loại GV</th>
                <th class="text-right">Đơn giá</th>
                <th>Đơn vị tính</th>
                <th>Hiệu lực từ</th>
                <th>Đến ngày</th>
                <th>Trạng thái</th>
                <th class="min-w-[16rem]">Ghi chú</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $end = $endDates[$row->id] ?? null;
                    [$stateLabel, $stateColor] = $row->effective_from->isFuture()
                        ? ['Chưa hiệu lực', 'info']
                        : ($end && $end->lt(today()) ? ['Đã hết hạn', 'neutral'] : ['Đang áp dụng', 'success']);
                @endphp
                <tr>
                    @if ($withTeacher)
                        <td class="font-semibold"><a href="{{ route('payroll.config.teacher-rates', ['teacher_id' => $row->user_id]) }}" class="hover:text-primary">{{ $row->user?->name ?? '—' }}</a></td>
                    @endif
                    <td>{{ $row->teacher_type_label }}</td>
                    <td><x-ui.money :value="$row->hourly_rate" suffix="" /></td>
                    <td>{{ $unitSuffix[$row->rate_unit] ?? 'VNĐ / giờ' }} <span class="sr-only">{{ $row->unit_label }}</span></td>
                    <td class="font-code text-code">{{ $row->effective_from->format('d/m/Y') }}</td>
                    <td class="font-code text-code">{{ $end ? $end->format('d/m/Y') : 'Hiện tại' }}</td>
                    <td><x-ui.badge :color="$stateColor">{{ $stateLabel }}</x-ui.badge></td>
                    <td>
                        {{ $row->note ?? '—' }}
                        <span class="block font-caption text-caption text-on-surface-variant">{{ $row->creator?->name ?? 'Hệ thống' }} · {{ $row->created_at?->format('d/m/Y H:i') }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $withTeacher ? 8 : 7 }}"><x-ui.empty-state icon="history" title="Chưa có lịch sử đơn giá" description="Bấm “Cập nhật đơn giá” để thêm đơn giá mới." /></td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($paginator ?? null)
        <x-slot:footer><x-ui.pagination :paginator="$paginator" /></x-slot:footer>
    @endif
</x-ui.data-table>
