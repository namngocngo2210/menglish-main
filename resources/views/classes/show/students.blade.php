{{-- Trang lớp · Học viên: danh sách xếp lớp chính thức (SĐT chỉ hiện với người quản lý lớp). --}}
<x-ui.data-table>
    <x-slot:header>
        <div>
            <h2 class="text-base font-bold text-on-surface">Danh sách học sinh</h2>
            <p class="text-xs text-on-surface-variant">Danh sách xếp lớp chính thức của lớp {{ $class->code }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.badge color="primary" :dot="false" :pill="true">{{ $students->count() }} học sinh</x-ui.badge>
            @if ($canManage && Route::has('students.enrollments'))
                @can('student.view')
                    <x-ui.button variant="secondary" size="sm" icon="how_to_reg" :href="route('students.enrollments')">Xác nhận nhập học</x-ui.button>
                @endcan
            @endif
        </div>
    </x-slot:header>
    <table class="text-xs">
        <thead>
            <tr>
                <th class="w-12 text-center">STT</th>
                <th>Họ và tên</th>
                <th>Ngày sinh</th>
                <th>Trường học</th>
                <th>Địa chỉ</th>
                <th>Tên phụ huynh</th>
                @if ($canManage)
                    <th class="font-bold text-primary">SĐT</th>
                @endif
                <th>Ghi chú</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $idx => $st)
                <tr>
                    <td class="text-center font-bold text-on-surface-variant/70">{{ $idx + 1 }}</td>
                    <td>
                        <div class="font-bold text-on-surface">{{ $st->name }}</div>
                        <div class="font-mono text-[10px] text-on-surface-variant/70">{{ $st->code ?? '—' }}</div>
                    </td>
                    <td class="font-mono text-on-surface-variant">{{ $st->dob ? $st->dob->format('d/m/Y') : '—' }}</td>
                    <td class="text-on-surface-variant">{{ $st->target ?? '—' }}</td>
                    <td class="max-w-[200px] truncate text-on-surface-variant">{{ $st->address ?? '—' }}</td>
                    <td class="font-medium text-on-surface">{{ $st->parent_name ?? '—' }}</td>
                    @if ($canManage)
                        <td class="font-mono font-bold text-on-surface">{{ $st->phone ?? '—' }}</td>
                    @endif
                    <td class="text-on-surface-variant">{{ $st->notes ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8"><x-ui.empty-state icon="group_off" title="Chưa có học sinh nào trong lớp." /></td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-ui.data-table>
