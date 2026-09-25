<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">school</span>
                        Quản lý Lớp học
                    </h1>
                    <p class="text-xs text-gray-500 mt-0.5">Danh sách tất cả lớp học. Tạo mới, chỉnh sửa và xóa lớp học tại đây.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('classes.trial-booking') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[16px]">event_available</span>
                    <span>Đặt lịch học thử</span>
                </a>
                @can('class.create')
                <a href="{{ route('classes.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-dark transition">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    <span>Tạo lớp mới</span>
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    

    <div class="max-w-6xl mx-auto space-y-5">
        <!-- Stat Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-2xs flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-container/10 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">school</span>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400 uppercase font-bold tracking-wider">Tổng lớp</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $totalCount }}</p>
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-2xs flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">check_circle</span>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400 uppercase font-bold tracking-wider">Đang hoạt động</p>
                    <p class="text-2xl font-bold text-emerald-600">{{ $activeCount }}</p>
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-2xs flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">pending</span>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400 uppercase font-bold tracking-wider">Chờ lịch</p>
                    <p class="text-2xl font-bold text-amber-600">{{ \App\Models\ClassModel::where('status','pending_schedule')->count() }}</p>
                </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-2xs flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gray-100 text-gray-500 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">archive</span>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400 uppercase font-bold tracking-wider">Đã kết thúc</p>
                    <p class="text-2xl font-bold text-gray-500">{{ \App\Models\ClassModel::where('status','completed')->count() }}</p>
                </div>
            </div>
        </div>

        <!-- Search & Filter Bar -->
        <form method="GET" action="{{ route('classes.index') }}" class="bg-white rounded-2xl border border-gray-200 p-4 shadow-2xs flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                <input type="text" name="search" value="{{ $search }}" placeholder="Tìm tên lớp hoặc mã lớp..."
                    class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-xl bg-gray-50 text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
            </div>
            <select name="branch_id" class="px-3 py-2 border border-gray-200 rounded-xl bg-gray-50 text-xs text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition cursor-pointer">
                <option value="">Tất cả chi nhánh</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ $branchFilter == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            <select name="status" class="px-3 py-2 border border-gray-200 rounded-xl bg-gray-50 text-xs text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition cursor-pointer">
                <option value="all" {{ $statusFilter === 'all' || !$statusFilter ? 'selected' : '' }}>Tất cả trạng thái</option>
                <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>Đang hoạt động</option>
                <option value="pending_schedule" {{ $statusFilter === 'pending_schedule' ? 'selected' : '' }}>Chờ lịch</option>
                <option value="completed" {{ $statusFilter === 'completed' ? 'selected' : '' }}>Đã kết thúc</option>
                <option value="cancelled" {{ $statusFilter === 'cancelled' ? 'selected' : '' }}>Đã hủy</option>
            </select>
            <button type="submit" class="px-4 py-2 rounded-xl bg-primary-container text-white text-xs font-bold hover:bg-primary-dark transition flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">filter_list</span>
                Lọc
            </button>
            @if($search || $branchFilter || ($statusFilter && $statusFilter !== 'all'))
                <a href="{{ route('classes.index') }}" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-600 text-xs font-semibold hover:bg-gray-200 transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Xóa lọc
                </a>
            @endif
        </form>

        <!-- Classes Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                            <th class="py-3 px-4">Mã lớp</th>
                            <th class="py-3 px-4">Tên lớp</th>
                            <th class="py-3 px-4">Chi nhánh</th>
                            <th class="py-3 px-4">Giáo viên</th>
                            <th class="py-3 px-4 text-center">Sĩ số</th>
                            <th class="py-3 px-4">Trạng thái</th>
                            <th class="py-3 px-4 text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs">
                        @forelse($classes as $c)
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="py-3 px-4">
                                    <span class="font-mono font-bold text-primary text-[11px] bg-orange-50 px-2 py-0.5 rounded">{{ $c->code }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900">{{ $c->name }}</div>
                                    <div class="text-[11px] text-gray-400 mt-0.5">{{ $c->program }} · {{ $c->level }}</div>
                                </td>
                                <td class="py-3 px-4 text-gray-700 font-medium">{{ $c->branch?->name ?? '—' }}</td>
                                <td class="py-3 px-4 text-gray-700">{{ $c->teacher?->name ?? '—' }}</td>
                                <td class="py-3 px-4 text-center" data-seats="{{ $c->id }}">
                                    @php $seat = $c->seatSummary(); @endphp
                                    <span class="font-bold text-gray-900">{{ $seat['occupied'] }}</span>
                                    <span class="text-gray-400">/{{ $seat['capacity'] ?: '∞' }}</span>
                                    <div class="text-[10px] mt-0.5">
                                        @if ($seat['left'] === null)
                                            <span class="text-gray-400">Không giới hạn</span>
                                        @elseif ($seat['left'] === 0)
                                            <span class="font-bold text-red-600">Đã đủ</span>
                                        @else
                                            <span class="font-semibold text-emerald-700">Còn {{ $seat['left'] }} chỗ</span>
                                        @endif
                                    </div>
                                    @if ($seat['needed'] > 0)
                                        <div class="text-[10px] font-semibold text-amber-700" title="Ngưỡng khai giảng {{ $seat['min'] }} học viên">Thiếu {{ $seat['needed'] }}/{{ $seat['min'] }} để KG</div>
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    @php
                                        $statusMap = [
                                            'active'           => ['label' => 'Đang hoạt động', 'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                                            'pending_schedule' => ['label' => 'Chờ lịch',        'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
                                            'completed'        => ['label' => 'Đã kết thúc',      'class' => 'bg-gray-100 text-gray-600 border-gray-300'],
                                            'cancelled'        => ['label' => 'Đã hủy',           'class' => 'bg-red-50 text-red-600 border-red-200'],
                                        ];
                                        $s = $statusMap[$c->status] ?? ['label' => $c->status, 'class' => 'bg-gray-100 text-gray-500 border-gray-200'];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border {{ $s['class'] }}">{{ $s['label'] }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <a href="{{ route('classes.profile', $c->id) }}"
                                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-[11px] font-semibold transition">
                                            <span class="material-symbols-outlined text-[14px]">visibility</span>
                                            Xem
                                        </a>
                                        @can('class.update')
                                        <a href="{{ route('classes.edit', $c->id) }}"
                                           class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-secondary/10 hover:bg-secondary/20 text-secondary text-[11px] font-semibold transition">
                                            <span class="material-symbols-outlined text-[14px]">edit</span>
                                            Sửa
                                        </a>
                                        @endcan
                                        @can('class.delete')
                                        <form method="POST" action="{{ route('classes.destroy', $c->id) }}"
                                              onsubmit="return confirm('Bạn có chắc muốn xóa lớp {{ $c->name }}? Hành động này không thể hoàn tác.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 text-[11px] font-semibold transition">
                                                <span class="material-symbols-outlined text-[14px]">delete</span>
                                                Xóa
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center">
                                    <div class="flex flex-col items-center gap-3 text-gray-400">
                                        <span class="material-symbols-outlined text-5xl text-gray-200">school</span>
                                        <p class="text-sm font-medium">Không tìm thấy lớp học nào</p>
                                        <a href="{{ route('classes.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary-container text-white text-xs font-bold hover:bg-primary-dark transition">
                                            <span class="material-symbols-outlined text-[16px]">add</span>
                                            Tạo lớp mới
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($classes->hasPages())
                <div class="px-4 py-3 border-t border-gray-100 bg-gray-50">
                    {{ $classes->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
