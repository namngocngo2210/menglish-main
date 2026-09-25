<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">tune</span>
                Cấu hình KPI Học vụ
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">6 nhóm / 15 mục KPI Học vụ — tiền KPI tháng = quỹ × điểm KPI có trọng số (tự động vào bảng lương)</p>
        </div>
    </x-slot>

    @php
        $fmtWeight = fn ($w) => rtrim(rtrim(number_format((float) $w, 2), '0'), '.');
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');
    @endphp

    <div class="space-y-6">

        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        <div class="flex flex-wrap items-center gap-2 rounded-xl border {{ (float) $totalWeight == 100 ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }} px-4 py-3">
            <span class="material-symbols-outlined {{ (float) $totalWeight == 100 ? 'text-emerald-600' : 'text-amber-600' }}">{{ (float) $totalWeight == 100 ? 'check_circle' : 'warning' }}</span>
            <span class="text-xs font-semibold text-gray-600">Tổng trọng số đang áp dụng:</span>
            <span class="text-sm font-black {{ (float) $totalWeight == 100 ? 'text-emerald-600' : 'text-amber-600' }}">{{ $fmtWeight($totalWeight) }}% = {{ $money($fund * $totalWeight / 100) }}đ</span>
            <span class="text-xs text-gray-600">/ Quỹ KPI {{ $money($fund) }}đ</span>
            @if ((float) $totalWeight != 100)
                <span class="text-[11px] text-amber-700">(chưa khớp quỹ — nên bằng 100%)</span>
            @endif
        </div>

        <!-- Add form -->
        <form method="POST" action="{{ route('kpi.criteria.store') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-gray-900">Thêm mục KPI</h2>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <input type="text" name="group_name" list="kpi-groups" placeholder="Nhóm KPI (vd: Chăm sóc học viên)" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('group_name') }}">
                <input type="text" name="code" placeholder="Mã (vd: 1.4)" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('code') }}">
                <input type="text" name="name" required placeholder="Tên mục *" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('name') }}">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <input type="number" name="weight" required step="0.25" min="0" max="100" placeholder="Trọng số % quỹ *" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('weight') }}">
                <input type="text" name="threshold_full" placeholder="Ngưỡng đạt 100%" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('threshold_full') }}">
                <input type="text" name="threshold_half" placeholder="Ngưỡng đạt 50%" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('threshold_half') }}">
                <input type="text" name="unit" placeholder="Đơn vị (vd: %, buổi)" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('unit') }}">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="target" placeholder="Mục tiêu (vd: >= 95%)" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('target') }}">
                <input type="text" name="description" placeholder="Mô tả" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('description') }}">
            </div>
            <datalist id="kpi-groups">
                @foreach ($groups as $group)
                    <option value="{{ $group }}"></option>
                @endforeach
            </datalist>
            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">add</span> Thêm mục
                </button>
            </div>
        </form>

        <!-- List / edit, theo nhóm -->
        <div class="space-y-4">
            @forelse ($criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm') as $groupName => $items)
                <div class="space-y-2">
                    <div class="flex items-center justify-between px-1">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-700">{{ $groupName }}</h3>
                        <span class="text-[11px] font-semibold text-gray-500">{{ $items->count() }} mục · {{ $money($items->where('is_active', true)->sum(fn ($c) => $c->fundAmount($fund))) }}đ</span>
                    </div>
                    @foreach ($items as $cr)
                        <form method="POST" action="{{ route('kpi.criteria.update', $cr->id) }}" class="bg-white rounded-2xl p-4 border {{ $cr->is_active ? 'border-gray-200' : 'border-gray-200 opacity-60' }} shadow-sm">
                            @csrf @method('PUT')
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                                <input type="text" name="code" value="{{ $cr->code }}" placeholder="Mã" class="sm:col-span-1 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" title="Mã mục">
                                <input type="text" name="name" value="{{ $cr->name }}" class="sm:col-span-3 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <input type="number" name="weight" step="0.25" min="0" max="100" value="{{ $fmtWeight($cr->weight) }}" class="sm:col-span-1 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" title="Trọng số % quỹ">
                                <span class="sm:col-span-1 text-xs font-bold text-primary font-mono" title="Tiền KPI tối đa của mục">{{ $money($cr->fundAmount($fund)) }}đ</span>
                                <input type="text" name="threshold_full" value="{{ $cr->threshold_full }}" placeholder="Ngưỡng 100%" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="threshold_half" value="{{ $cr->threshold_half }}" placeholder="Ngưỡng 50%" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <label class="sm:col-span-1 flex items-center gap-1 text-[11px] text-gray-600">
                                    <input type="checkbox" name="is_active" value="1" @checked($cr->is_active) class="rounded border-gray-300 text-primary focus:ring-primary-container"> Bật
                                </label>
                                <div class="sm:col-span-1 flex items-center justify-end gap-1">
                                    <button type="submit" class="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50" title="Lưu"><span class="material-symbols-outlined text-[18px]">save</span></button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center mt-2">
                                <input type="text" name="group_name" value="{{ $cr->group_name }}" list="kpi-groups" placeholder="Nhóm" class="sm:col-span-3 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="target" value="{{ $cr->target }}" placeholder="Mục tiêu" class="sm:col-span-3 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="unit" value="{{ $cr->unit }}" placeholder="Đơn vị" class="sm:col-span-1 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <input type="text" name="description" value="{{ $cr->description }}" placeholder="Mô tả" class="sm:col-span-4 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                                <div class="sm:col-span-1 flex justify-end">
                                    <button type="submit" form="del-{{ $cr->id }}" class="text-[11px] text-rose-500 hover:underline">Xoá</button>
                                </div>
                            </div>
                        </form>
                        <form id="del-{{ $cr->id }}" method="POST" action="{{ route('kpi.criteria.destroy', $cr->id) }}" onsubmit="return confirm('Xoá mục KPI này?');" class="hidden">@csrf @method('DELETE')</form>
                    @endforeach
                </div>
            @empty
                <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">tune</span>
                    <p class="mt-2 text-sm">Chưa có mục KPI nào. Thêm ở form trên.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
