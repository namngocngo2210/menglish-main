<x-app-layout>
    <x-ui.page-header title="Hồ sơ lớp học" icon="school">
        <x-slot:actions>
            @if($class)
                @can('class.update')
                    <x-ui.button variant="secondary" icon="edit" :href="route('classes.edit', $class->id)">Chỉnh sửa</x-ui.button>
                @endcan
                @can('class.delete')
                <form method="POST" action="{{ route('classes.destroy', $class->id) }}" onsubmit="return confirm('Xóa lớp {{ $class->name }}?')" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger-text" icon="delete">Xóa lớp</x-ui.button>
                </form>
                @endcan
            @endif
            <x-ui.button icon="dashboard" :href="route('classes.academic-overview')">Sơ đồ khối lớp</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    

    {{-- Quyền xem SĐT / nút sửa quyết định phía server (trước là nút đổi "góc nhìn" demo phía trình duyệt). --}}
    @php
        $canManageClass = $class && auth()->user()->can('class.update');
    @endphp
    <div class="max-w-6xl mx-auto space-y-6">
        {{-- Chọn lớp --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            {{-- Class Switcher --}}
            <div class="flex items-center gap-2.5">
                <span class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Đang xem lớp:</span>
                <x-ui.select aria-label="Đang xem lớp" class="text-xs font-bold cursor-pointer"
                             onchange="window.location.href = '{{ route('classes.profile') }}/' + this.value">
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($class && $class->id === $c->id) ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->code }})
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

        </div>

        {{-- Page Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-2 border-b border-surface-container-highest gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-on-surface tracking-tight">
                        {{ $class ? 'Hồ sơ lớp ' . $class->name : 'Chưa có lớp học' }}
                    </h1>
                @php
                    $statusLabel = match ($class?->status) {
                        'active' => 'Đang học',
                        'pending_schedule' => 'Chờ cấu hình lịch',
                        'upcoming' => 'Sắp khai giảng',
                        'completed' => 'Đã kết thúc',
                        'cancelled' => 'Đã hủy',
                        default => 'Chưa xác định',
                    };
                @endphp
                <x-ui.badge color="success" :pill="true">{{ $statusLabel }}</x-ui.badge>
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if($canManageClass)
                    <x-ui.button variant="secondary" size="sm" :href="route('classes.edit', $class->id)">
                        <span>✏️</span>
                        <span>Sửa thông tin lớp</span>
                    </x-ui.button>
                @endif
            </div>
        </div>

        {{-- General Information Card --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-6 shadow-sm">
            <div class="flex justify-between items-center pb-4 mb-6 border-b border-surface-container-highest">
                <h2 class="text-base font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">info</span>
                    Thông tin chung
                </h2>
                @if ($canManageClass)
                <a href="{{ route('tasks.schedule-config') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline">
                    <span>📅</span>
                    <span>Cấu hình lịch</span>
                </a>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                {{-- 1. Chi nhánh --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Chi nhánh</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->branch?->name ?? 'Chưa cập nhật' }}</span>
                </div>

                {{-- 2. CM quản lý --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">CM quản lý</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->assistant?->name ?? 'Chưa phân công' }}</span>
                </div>

                {{-- 3. Chương trình --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Chương trình</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->program ?? $class?->course?->name ?? 'Chưa cập nhật' }}</span>
                </div>

                {{-- 4. Cấp độ --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Cấp độ</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->level ?? 'Chưa cập nhật' }}</span>
                </div>

                {{-- 5. Phòng học --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Phòng học</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->room ?? 'Chưa cập nhật' }}</span>
                </div>

                {{-- 6. Sĩ số --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Sĩ số</span>
                    @if ($class)
                        @php $seat = $class->seatSummary(); @endphp
                        <span class="text-xs font-bold text-primary" data-seats="{{ $class->id }}">{{ $seat['occupied'] }} / {{ $seat['capacity'] ?: '∞' }} học viên</span>
                        <span class="block text-[11px] mt-0.5 {{ $seat['left'] === 0 ? 'text-error font-bold' : 'text-on-surface-variant' }}">
                            {{ $seat['left'] === null ? 'Không giới hạn sĩ số' : ($seat['left'] === 0 ? 'Đã đủ sĩ số' : 'Còn '.$seat['left'].' chỗ') }}
                            · Ngưỡng khai giảng {{ $seat['min'] }}
                        </span>
                        @if ($seat['needed'] > 0)
                            <span class="block text-[11px] font-semibold text-warning">Cần thêm {{ $seat['needed'] }} học viên để khai giảng</span>
                        @endif
                    @else
                        <span class="text-xs text-on-surface-variant/70">—</span>
                    @endif
                </div>

                {{-- 7. Giáo viên chính --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Giáo viên chính</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->teacher?->name ?? 'Chưa phân công' }}</span>
                </div>

                {{-- 8. Lịch học --}}
                <div>
                    <span class="text-[11px] font-bold text-on-surface-variant/70 uppercase tracking-wider block mb-1">Lịch học</span>
                    <span class="text-xs font-bold text-on-surface">{{ $class?->schedule_text ?? 'Chưa cập nhật' }}</span>
                </div>
            </div>
        </div>

        {{-- Students List Table --}}
        <x-ui.data-table>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-bold text-on-surface">Danh sách học sinh</h2>
                    <p class="text-xs text-on-surface-variant">Danh sách xếp lớp chính thức của lớp {{ $class?->code ?? '' }}</p>
                </div>
                <x-ui.badge color="primary" :dot="false" :pill="true">{{ $students->count() }} học sinh</x-ui.badge>
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
                            @if ($canManageClass)
                                <th class="text-primary font-bold">SĐT</th>
                            @endif
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($students as $idx => $st)
                            <tr>
                                <td class="text-center font-bold text-on-surface-variant/70">{{ $idx + 1 }}</td>
                                <td>
                                    <div class="font-bold text-on-surface">{{ $st->name }}</div>
                                    <div class="text-[10px] text-on-surface-variant/70 font-mono">{{ $st->code ?? '—' }}</div>
                                </td>
                                <td class="text-on-surface-variant font-mono">
                                    {{ $st->dob ? $st->dob->format('d/m/Y') : '—' }}
                                </td>
                                <td class="text-on-surface-variant">
                                    {{ $st->target ?? '—' }}
                                </td>
                                <td class="text-on-surface-variant max-w-[200px] truncate">
                                    {{ $st->address ?? '—' }}
                                </td>
                                <td class="text-on-surface font-medium">
                                    {{ $st->parent_name ?? '—' }}
                                </td>
                                @if ($canManageClass)
                                    <td class="font-mono font-bold text-on-surface">
                                        {{ $st->phone ?? '—' }}
                                    </td>
                                @endif
                                <td class="text-on-surface-variant">
                                    {{ $st->notes ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8"><x-ui.empty-state icon="group_off" title="Chưa có học sinh nào trong lớp." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
        </x-ui.data-table>
    </div>
</x-app-layout>
