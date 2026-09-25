<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">tune</span>
                Cấu hình KPI Học vụ
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Thiết lập bộ chỉ số & trọng số đánh giá hiệu suất nhân sự học vụ</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-gray-500">Tổng trọng số đang bật:</span>
            <span class="text-sm font-black {{ (float)$totalWeight == 100 ? 'text-emerald-600' : 'text-amber-600' }}">{{ rtrim(rtrim(number_format($totalWeight,2), '0'), '.') }}%</span>
            @if ((float)$totalWeight != 100)
                <span class="text-[11px] text-amber-600">(nên bằng 100%)</span>
            @endif
        </div>

        <!-- Add form -->
        <form method="POST" action="{{ route('kpi.criteria.store') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-gray-900">Thêm chỉ số KPI</h2>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <input type="text" name="name" required placeholder="Tên chỉ số *" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary" value="{{ old('name') }}">
                <input type="number" name="weight" required step="0.5" min="0" max="100" placeholder="Trọng số %" class="text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary" value="{{ old('weight') }}">
                <input type="text" name="unit" placeholder="Đơn vị (vd: %, buổi)" class="text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary" value="{{ old('unit') }}">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="target" placeholder="Mục tiêu (vd: >= 95%)" class="text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary" value="{{ old('target') }}">
                <input type="text" name="description" placeholder="Mô tả" class="text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary" value="{{ old('description') }}">
            </div>
            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">add</span> Thêm chỉ số
                </button>
            </div>
        </form>

        <!-- List / edit -->
        <div class="space-y-2">
            @forelse ($criteria as $cr)
                <form method="POST" action="{{ route('kpi.criteria.update', $cr->id) }}" class="bg-white rounded-2xl p-4 border {{ $cr->is_active ? 'border-gray-200' : 'border-gray-200 opacity-60' }} shadow-sm">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                        <input type="text" name="name" value="{{ $cr->name }}" class="sm:col-span-4 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                        <input type="number" name="weight" step="0.5" min="0" max="100" value="{{ rtrim(rtrim(number_format($cr->weight,2),'0'),'.') }}" class="sm:col-span-1 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary" title="Trọng số %">
                        <input type="text" name="target" value="{{ $cr->target }}" placeholder="Mục tiêu" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                        <input type="text" name="unit" value="{{ $cr->unit }}" placeholder="Đơn vị" class="sm:col-span-1 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                        <input type="text" name="description" value="{{ $cr->description }}" placeholder="Mô tả" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                        <label class="sm:col-span-1 flex items-center gap-1 text-[11px] text-gray-600">
                            <input type="checkbox" name="is_active" value="1" @checked($cr->is_active) class="rounded border-gray-300 text-primary focus:ring-primary"> Bật
                        </label>
                        <div class="sm:col-span-1 flex items-center justify-end gap-1">
                            <button type="submit" class="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50" title="Lưu"><span class="material-symbols-outlined text-[18px]">save</span></button>
                    </div>
                    </div>
                    <div class="flex justify-end mt-1">
                        <button type="submit" form="del-{{ $cr->id }}" class="text-[11px] text-rose-500 hover:underline">Xoá chỉ số</button>
                    </div>
                </form>
                <form id="del-{{ $cr->id }}" method="POST" action="{{ route('kpi.criteria.destroy', $cr->id) }}" onsubmit="return confirm('Xoá chỉ số này?');" class="hidden">@csrf @method('DELETE')</form>
            @empty
                <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">tune</span>
                    <p class="mt-2 text-sm">Chưa có chỉ số KPI nào. Thêm ở form trên.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
