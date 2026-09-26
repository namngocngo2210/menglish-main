{{-- Danh sách kỳ lương — vào từng kỳ để xem bảng lương theo mockup epic-7/danh-sach-bang-luong-theo-ky --}}
<x-app-layout>
    @php
        $statusColors = ['draft' => 'info', 'reviewing' => 'warning', 'approved' => 'success', 'paid' => 'secondary'];
        $statusTexts = ['draft' => 'Đang tính', 'reviewing' => 'Đang soát', 'approved' => 'Đã chốt', 'paid' => 'Đã trả'];
    @endphp

    <x-ui.page-header title="Danh sách bảng lương theo kỳ" description="Quản lý, tổng hợp chấm công và chốt lương giáo viên, nhân sự theo từng kỳ.">
        <x-slot:actions>
            @can('payroll.create')
                <x-ui.button icon="add_circle" x-on:click="$dispatch('open-modal', 'new-period')">Tạo kỳ lương mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @error('month')
        <x-ui.alert type="error" class="mb-md">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.alert type="warning" title="Lưu ý chốt bảng lương định kỳ" class="mb-lg">
        Hoàn tất đối soát <a href="{{ route('payroll.timesheets.teachers') }}" class="font-semibold underline">chấm công giáo viên</a>,
        chốt KPI (bậc KPI giữ HS, KPI tự do, <a href="{{ route('kpi.monthly') }}" class="font-semibold underline">đánh giá KPI Học vụ</a>) và
        xử lý <a href="{{ route('penalties.index') }}" class="font-semibold underline">biên bản vi phạm</a> trước khi bấm "Chốt bảng lương".
    </x-ui.alert>

    <form method="GET" action="{{ route('payroll.periods.index') }}" role="search"
          class="mb-lg flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
        <x-ui.select label="Kỳ lương" onchange="if (this.value) window.location.href = this.value" aria-label="Mở kỳ lương" placeholder="-- Mở kỳ lương --">
            @foreach ($allPeriods as $p)
                <option value="{{ route('payroll.periods.show', $p->id) }}">{{ $p->title }} ({{ $p->code }})</option>
            @endforeach
        </x-ui.select>
        <div class="min-w-[240px] flex-1">
            <x-ui.input type="search" name="search" :value="$search" icon="search" placeholder="Tìm kỳ lương / giáo viên..." aria-label="Tìm kiếm" />
        </div>
        <x-ui.select name="status" :options="$statusTexts" placeholder="Mọi trạng thái" aria-label="Trạng thái" onchange="this.form.submit()" />
        <x-ui.button type="submit" variant="secondary" icon="filter_list">Lọc</x-ui.button>
    </form>

    <x-ui.data-table min-width="960px">
        <x-slot:header>
            <h3 class="font-h3 text-h3 text-on-surface">Các kỳ tính lương</h3>
            <span class="font-mono font-body-small text-body-small text-on-surface-variant">{{ $periods->total() }} kỳ lương</span>
        </x-slot:header>
        <table>
            <thead>
                <tr>
                    <th>Mã kỳ</th>
                    <th>Tên kỳ tính lương</th>
                    <th>Khoảng thời gian</th>
                    <th class="text-center">Số nhân sự</th>
                    <th class="text-right">Tổng giờ dạy</th>
                    <th class="text-right">Tổng chi lương</th>
                    <th>Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periods as $p)
                    <tr>
                        <td class="font-code text-code font-semibold">{{ $p->code }}</td>
                        <td class="font-semibold">{{ $p->title }}</td>
                        <td class="font-code text-code">{{ $p->start_date->format('d/m/Y') }} – {{ $p->end_date->format('d/m/Y') }}</td>
                        <td class="text-center">{{ $p->records_count > 0 ? $p->records_count : $p->total_staff }} người</td>
                        <td class="text-right font-mono">{{ $p->total_hours }}h</td>
                        <td><x-ui.money :value="$p->total_amount" suffix="đ" /></td>
                        <td><x-ui.badge :color="$statusColors[$p->status] ?? 'neutral'">{{ $statusTexts[$p->status] ?? $p->status_label }}</x-ui.badge></td>
                        <td class="text-right">
                            <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('payroll.periods.show', $p->id)">Chi tiết bảng lương</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="account_balance_wallet" title="Chưa có kỳ tính lương nào" description="Tạo kỳ lương mới để hệ thống tổng hợp chấm công, KPI, hoa hồng và phạt." /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$periods" unit="kỳ lương" /></x-slot:footer>
    </x-ui.data-table>

    @can('payroll.create')
        <x-ui.modal name="new-period" title="Tạo kỳ tính lương mới" max-width="md">
            <form id="new-period-form" action="{{ route('payroll.periods.store') }}" method="POST" class="space-y-md">
                @csrf
                <div class="grid grid-cols-2 gap-md">
                    <x-ui.select name="month" label="Tháng tính lương" required :value="old('month', date('n'))"
                                 :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => 'Tháng '.str_pad($m, 2, '0', STR_PAD_LEFT)])->all()" />
                    <x-ui.input type="number" name="year" label="Năm" required :value="old('year', date('Y'))" min="2025" max="2100" />
                </div>
                <x-ui.alert type="info">Hệ thống tự quét chấm công hợp lệ, KPI, hoa hồng (gate kép), thưởng tái tục và phạt quá hạn theo công thức Q3.</x-ui.alert>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-period')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-period-form">Khởi tạo &amp; Tính toán</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</x-app-layout>
