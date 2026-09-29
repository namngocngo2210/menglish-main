{{-- Mockup: roundcuoi 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/01_cau_hinh_kpi_hoc_vu_1, 02_cau_hinh_kpi_hoc_vu_2 --}}
<x-app-layout>
    @php
        $fmtWeight = fn ($w) => rtrim(rtrim(number_format((float) $w, 2), '0'), '.');
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');
        $matched = abs((float) $totalWeight - 100) < 0.001;
    @endphp

    <x-ui.page-header title="Cấu hình KPI Học vụ"
                      description="Quản lý và thiết lập các chỉ số KPI đánh giá hiệu suất học vụ — 6 nhóm / 15 mục, tiền KPI tháng = quỹ × điểm KPI có trọng số (tự động vào bảng lương).">
        <x-slot:actions>
            @can('kpi.manage')
                <x-ui.button icon="add" x-on:click="$dispatch('open-modal', 'new-kpi')">Thêm mục mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-lg">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.alert :type="$matched ? 'success' : 'warning'"
                    :title="'Trạng thái quỹ KPI: Tổng trọng số hiện tại: '.$money($fund * $totalWeight / 100).'đ / Quỹ KPI: '.$money($fund).'đ ('.($matched ? 'Khớp' : 'Chưa khớp').')'">
            Tổng trọng số đang áp dụng: {{ $fmtWeight($totalWeight) }}%.
            @unless ($matched)
                Tổng trọng số của các mục đang áp dụng chưa khớp với Quỹ KPI hiện tại. Vui lòng kiểm tra lại để đảm bảo tính chính xác khi tính lương.
            @endunless
        </x-ui.alert>

        <datalist id="kpi-groups">
            @foreach ($groups as $group)
                <option value="{{ $group }}"></option>
            @endforeach
        </datalist>

        {{-- List / edit, theo nhóm --}}
        <div class="space-y-4">
            @forelse ($criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm') as $groupName => $items)
                <div class="space-y-2">
                    <div class="flex items-center justify-between px-1">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">{{ $groupName }}</h3>
                        <span class="text-xs font-semibold text-on-surface-variant">{{ $items->count() }} mục · {{ $money($items->where('is_active', true)->sum(fn ($c) => $c->fundAmount($fund))) }} đ</span>
                    </div>
                    @foreach ($items as $cr)
                        <form method="POST" action="{{ route('kpi.criteria.update', $cr->id) }}" class="bg-surface-container-lowest rounded-xl p-4 border {{ $cr->is_active ? 'border-outline-variant' : 'border-outline-variant opacity-60' }} shadow-sm">
                            @csrf @method('PUT')
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                                <input type="text" name="code" value="{{ $cr->code }}" placeholder="Mã" aria-label="Mã mục" class="sm:col-span-1 text-sm rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container" title="Mã mục">
                                <input type="text" name="name" value="{{ $cr->name }}" aria-label="Tên mục" class="sm:col-span-3 text-sm rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <input type="number" name="weight" step="0.25" min="0" max="100" value="{{ $fmtWeight($cr->weight) }}" aria-label="Trọng số % quỹ" class="sm:col-span-1 text-sm rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container" title="Trọng số % quỹ">
                                <span class="sm:col-span-1 text-xs font-bold text-primary font-mono" title="Tiền KPI tối đa của mục">{{ $money($cr->fundAmount($fund)) }} đ</span>
                                <input type="text" name="threshold_full" value="{{ $cr->threshold_full }}" placeholder="Ngưỡng 100%" aria-label="Ngưỡng 100%" class="sm:col-span-2 text-sm rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="threshold_half" value="{{ $cr->threshold_half }}" placeholder="Ngưỡng 50%" aria-label="Ngưỡng 50%" class="sm:col-span-2 text-sm rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <label class="sm:col-span-1 flex items-center gap-1 text-xs text-on-surface-variant">
                                    <input type="checkbox" name="is_active" value="1" @checked($cr->is_active) class="rounded border-outline-variant text-primary focus:ring-primary-container"> Bật
                                </label>
                                <div class="sm:col-span-1 flex items-center justify-end gap-1">
                                    <x-ui.button type="submit" variant="ghost" size="sm" icon="save" title="Lưu" class="!text-tertiary" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center mt-2">
                                <input type="text" name="group_name" value="{{ $cr->group_name }}" list="kpi-groups" placeholder="Nhóm" aria-label="Nhóm" class="sm:col-span-3 text-xs rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="target" value="{{ $cr->target }}" placeholder="Mục tiêu" aria-label="Mục tiêu" class="sm:col-span-3 text-xs rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="unit" value="{{ $cr->unit }}" placeholder="Đơn vị" aria-label="Đơn vị" class="sm:col-span-1 text-xs rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="description" value="{{ $cr->description }}" placeholder="Mô tả" aria-label="Mô tả" class="sm:col-span-4 text-xs rounded-lg border-outline-variant focus:border-primary-container focus:ring-primary-container">
                                <div class="sm:col-span-1 flex justify-end">
                                    <x-ui.button type="submit" form="del-{{ $cr->id }}" variant="danger-text" size="sm">Xoá</x-ui.button>
                                </div>
                            </div>
                        </form>
                        <form id="del-{{ $cr->id }}" method="POST" action="{{ route('kpi.criteria.destroy', $cr->id) }}" data-confirm="Xoá mục KPI này?" data-confirm-label="Xóa" data-confirm-danger class="hidden">@csrf @method('DELETE')</form>
                    @endforeach
                </div>
            @empty
                <div class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm">
                    <x-ui.empty-state icon="tune" title="Chưa có mục KPI nào. Bấm &quot;Thêm mục mới&quot; để tạo." />
                </div>
            @endforelse
        </div>
    </div>

    @can('kpi.manage')
        <x-ui.modal name="new-kpi" title="Thêm mục KPI mới" max-width="xl" :show="old('_modal') === 'new-kpi'">
            <form id="kpi-add-form" method="POST" action="{{ route('kpi.criteria.store') }}" class="space-y-md">
                @csrf
                <input type="hidden" name="_modal" value="new-kpi">
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <x-ui.input name="group_name" label="Nhóm KPI" list="kpi-groups" placeholder="vd: Chăm sóc học viên" />
                    <x-ui.input name="code" label="Mã" placeholder="vd: 1.4" />
                </div>
                <x-ui.input name="name" label="Tên mục" required />
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <x-ui.input type="number" name="weight" label="Trọng số % quỹ" required step="0.25" min="0" max="100" />
                    <x-ui.input name="unit" label="Đơn vị" placeholder="vd: %, buổi" />
                    <x-ui.input name="threshold_full" label="Ngưỡng đạt 100%" />
                    <x-ui.input name="threshold_half" label="Ngưỡng đạt 50%" />
                </div>
                <x-ui.input name="target" label="Mục tiêu" placeholder="vd: >= 95%" />
                <x-ui.input name="description" label="Mô tả" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-kpi')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="kpi-add-form" icon="add">Thêm mục</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</x-app-layout>
