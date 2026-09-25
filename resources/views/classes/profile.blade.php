<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('classes.create') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">school</span>
                        Hồ sơ lớp học
                    </h1>
                    <p class="text-xs text-gray-500">Tra cứu thông tin toàn diện về lớp học, phòng ốc, giáo viên phụ trách và danh sách học viên theo từng lớp.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if($class)
                    @can('class.update')
                    <a href="{{ route('classes.edit', $class->id) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-secondary/10 hover:bg-secondary/20 text-secondary text-xs font-semibold transition">
                        <span class="material-symbols-outlined text-[18px]">edit</span>
                        <span>Chỉnh sửa</span>
                    </a>
                    @endcan
                    @can('class.delete')
                    <form method="POST" action="{{ route('classes.destroy', $class->id) }}" onsubmit="return confirm('Xóa lớp {{ $class->name }}?')" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold transition">
                            <span class="material-symbols-outlined text-[18px]">delete</span>
                            <span>Xóa lớp</span>
                        </button>
                    </form>
                    @endcan
                @endif
                <a href="{{ route('classes.academic-overview') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-dark transition">
                    <span class="material-symbols-outlined text-[18px]">dashboard</span>
                    <span>Sơ đồ khối lớp</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('classes.partials.flow-header', ['activeStep' => 3])

    

    {{-- Quyền xem SĐT / nút sửa quyết định phía server (trước là nút đổi "góc nhìn" demo phía trình duyệt). --}}
    @php
        $canManageClass = $class && auth()->user()->can('class.update');
    @endphp
    <div class="max-w-6xl mx-auto space-y-6">
        <!-- Chọn lớp -->
        <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <!-- Class Switcher -->
            <div class="flex items-center gap-2.5">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Đang xem lớp:</span>
                <select class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container cursor-pointer"
                        onchange="window.location.href = '{{ route('classes.profile') }}/' + this.value">
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($class && $class->id === $c->id) ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->code }})
                        </option>
                    @endforeach
                </select>
            </div>

        </div>

        <!-- Page Header (Exact Match BA) -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-2 border-b border-gray-200 gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
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
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                    {{ $statusLabel }}
                </span>
                </div>
                <p class="text-xs text-gray-500 mt-1">Thông tin chi tiết về lớp học, giáo viên và danh sách học viên</p>
            </div>

            <div class="flex items-center gap-2">
                @if($canManageClass)
                    <a href="{{ route('classes.edit', $class->id) }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-secondary/10 hover:bg-secondary/20 text-secondary text-xs font-semibold transition">
                        <span>✏️</span>
                        <span>Sửa thông tin lớp</span>
                    </a>
                @endif
            </div>
        </div>

        <!-- General Information Card (Exact Match BA 8 fields) -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <div class="flex justify-between items-center pb-4 mb-6 border-b border-gray-100">
                <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
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
                <!-- 1. Chi nhánh -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Chi nhánh</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->branch?->name ?? 'Chưa cập nhật' }}</span>
                </div>

                <!-- 2. CM quản lý -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">CM quản lý</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->assistant?->name ?? 'Chưa phân công' }}</span>
                </div>

                <!-- 3. Chương trình -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Chương trình</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->program ?? $class?->course?->name ?? 'Chưa cập nhật' }}</span>
                </div>

                <!-- 4. Cấp độ -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Cấp độ</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->level ?? 'Chưa cập nhật' }}</span>
                </div>

                <!-- 5. Phòng học -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Phòng học</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->room ?? 'Chưa cập nhật' }}</span>
                </div>

                <!-- 6. Sĩ số -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Sĩ số</span>
                    @if ($class)
                        @php $seat = $class->seatSummary(); @endphp
                        <span class="text-xs font-bold text-primary" data-seats="{{ $class->id }}">{{ $seat['occupied'] }} / {{ $seat['capacity'] ?: '∞' }} học viên</span>
                        <span class="block text-[11px] mt-0.5 {{ $seat['left'] === 0 ? 'text-red-600 font-bold' : 'text-gray-500' }}">
                            {{ $seat['left'] === null ? 'Không giới hạn sĩ số' : ($seat['left'] === 0 ? 'Đã đủ sĩ số' : 'Còn '.$seat['left'].' chỗ') }}
                            · Ngưỡng khai giảng {{ $seat['min'] }}
                        </span>
                        @if ($seat['needed'] > 0)
                            <span class="block text-[11px] font-semibold text-amber-700">Cần thêm {{ $seat['needed'] }} học viên để khai giảng</span>
                        @endif
                    @else
                        <span class="text-xs text-gray-400">—</span>
                    @endif
                </div>

                <!-- 7. Giáo viên chính -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Giáo viên chính</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->teacher?->name ?? 'Chưa phân công' }}</span>
                </div>

                <!-- 8. Lịch học -->
                <div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Lịch học</span>
                    <span class="text-xs font-bold text-gray-900">{{ $class?->schedule_text ?? 'Chưa cập nhật' }}</span>
                </div>
            </div>
        </div>

        <!-- Students List Table (Exact Match BA) -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <div>
                    <h2 class="text-base font-bold text-gray-900">Danh sách học sinh</h2>
                    <p class="text-xs text-gray-500">Danh sách xếp lớp chính thức của lớp {{ $class?->code ?? '' }}</p>
                </div>
                <span class="px-3 py-1 rounded-full bg-primary-container/10 text-primary font-bold text-xs">
                    {{ $students->count() }} học sinh
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                            <th class="py-3 px-4 w-12 text-center">STT</th>
                            <th class="py-3 px-4">Họ và tên</th>
                            <th class="py-3 px-4">Ngày sinh</th>
                            <th class="py-3 px-4">Trường học</th>
                            <th class="py-3 px-4">Địa chỉ</th>
                            <th class="py-3 px-4">Tên phụ huynh</th>
                            @if ($canManageClass)
                                <th class="py-3 px-4 text-primary font-bold">SĐT</th>
                            @endif
                            <th class="py-3 px-4">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($students as $idx => $st)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-3 px-4 text-center font-bold text-gray-400">{{ $idx + 1 }}</td>
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900">{{ $st->name }}</div>
                                    <div class="text-[10px] text-gray-400 font-mono">{{ $st->code ?? '—' }}</div>
                                </td>
                                <td class="py-3 px-4 text-gray-600 font-mono">
                                    {{ $st->dob ? $st->dob->format('d/m/Y') : '—' }}
                                </td>
                                <td class="py-3 px-4 text-gray-700">
                                    {{ $st->target ?? '—' }}
                                </td>
                                <td class="py-3 px-4 text-gray-600 max-w-[200px] truncate">
                                    {{ $st->address ?? '—' }}
                                </td>
                                <td class="py-3 px-4 text-gray-800 font-medium">
                                    {{ $st->parent_name ?? '—' }}
                                </td>
                                @if ($canManageClass)
                                    <td class="py-3 px-4 font-mono font-bold text-gray-900">
                                        {{ $st->phone ?? '—' }}
                                    </td>
                                @endif
                                <td class="py-3 px-4 text-gray-500">
                                    {{ $st->notes ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-8 text-center text-gray-400 text-xs">
                                    Chưa có học sinh nào trong lớp.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
