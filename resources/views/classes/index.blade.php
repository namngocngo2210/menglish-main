<x-app-layout>
    <x-ui.page-header title="Quản lý Lớp học" icon="school">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="event_available" :href="route('classes.trial-booking')">Đặt lịch học thử</x-ui.button>
            @can('class.create')
                <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    

    <div class="max-w-6xl mx-auto space-y-5">
        {{-- Stat Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-ui.stat-card label="Tổng lớp" :value="$totalCount" tone="primary" icon="school" />
            <x-ui.stat-card label="Đang hoạt động" :value="$activeCount" tone="success" icon="check_circle" />
            <x-ui.stat-card label="Chờ lịch" :value="\App\Models\ClassModel::where('status','pending_schedule')->count()" tone="warning" icon="pending" />
            <x-ui.stat-card label="Đã kết thúc" :value="\App\Models\ClassModel::where('status','completed')->count()" icon="archive" />
        </div>

        {{-- Search & Filter Bar --}}
        <form method="GET" action="{{ route('classes.index') }}" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-2xs flex flex-col sm:flex-row gap-3">
            <div class="flex-1">
                <x-ui.input name="search" icon="search" :value="$search" placeholder="Tìm tên lớp hoặc mã lớp..." class="text-xs" />
            </div>
            <x-ui.select name="branch_id" class="text-xs cursor-pointer" placeholder="Tất cả chi nhánh" :value="$branchFilter" :options="$branches->pluck('name', 'id')" />
            <x-ui.select name="status" class="text-xs cursor-pointer" :value="$statusFilter ?: 'all'"
                         :options="['all' => 'Tất cả trạng thái', 'active' => 'Đang hoạt động', 'pending_schedule' => 'Chờ lịch', 'completed' => 'Đã kết thúc', 'cancelled' => 'Đã hủy']" />
            <x-ui.button type="submit" icon="filter_list">Lọc</x-ui.button>
            @if($search || $branchFilter || ($statusFilter && $statusFilter !== 'all'))
                <x-ui.button variant="secondary" icon="close" :href="route('classes.index')">Xóa lọc</x-ui.button>
            @endif
        </form>

        {{-- Classes Table --}}
        <x-ui.data-table>
                <table>
                    <thead>
                        <tr>
                            <th>Mã lớp</th>
                            <th>Tên lớp</th>
                            <th>Chi nhánh</th>
                            <th>Giáo viên</th>
                            <th class="text-center">Sĩ số</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="text-xs">
                        @forelse($classes as $c)
                            <tr>
                                <td>
                                    <span class="font-mono font-bold text-primary text-[11px] bg-primary-container/10 px-2 py-0.5 rounded">{{ $c->code }}</span>
                                </td>
                                <td>
                                    <div class="font-bold text-on-surface">{{ $c->name }}</div>
                                    <div class="text-[11px] text-on-surface-variant/70 mt-0.5">{{ $c->program }} · {{ $c->level }}</div>
                                </td>
                                <td class="text-on-surface-variant font-medium">{{ $c->branch?->name ?? '—' }}</td>
                                <td class="text-on-surface-variant">{{ $c->teacher?->name ?? '—' }}</td>
                                <td class="text-center" data-seats="{{ $c->id }}">
                                    @php $seat = $c->seatSummary(); @endphp
                                    <span class="font-bold text-on-surface">{{ $seat['occupied'] }}</span>
                                    <span class="text-on-surface-variant/70">/{{ $seat['capacity'] ?: '∞' }}</span>
                                    <div class="text-[10px] mt-0.5">
                                        @if ($seat['left'] === null)
                                            <span class="text-on-surface-variant/70">Không giới hạn</span>
                                        @elseif ($seat['left'] === 0)
                                            <span class="font-bold text-error">Đã đủ</span>
                                        @else
                                            <span class="font-semibold text-tertiary">Còn {{ $seat['left'] }} chỗ</span>
                                        @endif
                                    </div>
                                    @if ($seat['needed'] > 0)
                                        <div class="text-[10px] font-semibold text-warning" title="Ngưỡng khai giảng {{ $seat['min'] }} học viên">Thiếu {{ $seat['needed'] }}/{{ $seat['min'] }} để KG</div>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $statusMap = [
                                            'active'           => ['label' => 'Đang hoạt động', 'color' => 'success'],
                                            'pending_schedule' => ['label' => 'Chờ lịch',        'color' => 'warning'],
                                            'completed'        => ['label' => 'Đã kết thúc',      'color' => 'neutral'],
                                            'cancelled'        => ['label' => 'Đã hủy',           'color' => 'error'],
                                        ];
                                        $s = $statusMap[$c->status] ?? ['label' => $c->status, 'color' => 'neutral'];
                                    @endphp
                                    <x-ui.badge :color="$s['color']" :pill="true">{{ $s['label'] }}</x-ui.badge>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.button variant="secondary" size="sm" icon="visibility" :href="route('classes.profile', $c->id)">Xem</x-ui.button>
                                        @can('class.update')
                                        <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('classes.edit', $c->id)">Sửa</x-ui.button>
                                        @endcan
                                        @can('class.delete')
                                        <form method="POST" action="{{ route('classes.destroy', $c->id) }}"
                                              onsubmit="return confirm('Bạn có chắc muốn xóa lớp {{ $c->name }}? Hành động này không thể hoàn tác.')">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete">Xóa</x-ui.button>
                                        </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-ui.empty-state icon="school" title="Không tìm thấy lớp học nào">
                                        <x-ui.button icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
                                    </x-ui.empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            <x-slot:footer><x-ui.pagination :paginator="$classes" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
