<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">leaderboard</span>
                Tổng hợp KPI tháng {{ $month }}/{{ $year }}
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Đánh giá hiệu suất nhân sự theo bộ chỉ số KPI</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        

        <form method="GET" class="flex flex-wrap items-center gap-3 bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
            <select name="month" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                @for ($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($m == $month)>Tháng {{ $m }}</option>
                @endfor
            </select>
            <input type="number" name="year" value="{{ $year }}" class="w-24 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
            <button type="submit" class="px-4 py-2 rounded-lg bg-primary-container text-white text-xs font-bold">Xem</button>
            <a href="{{ route('kpi.criteria') }}" class="ml-auto text-xs font-semibold text-primary hover:underline flex items-center gap-1">
                <span class="material-symbols-outlined text-[16px]">tune</span> Cấu hình chỉ số
            </a>
        </form>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
            @forelse ($staff as $s)
                @php $eval = $evaluations->get($s->id); @endphp
                <div class="p-4 flex items-center justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-gray-900">{{ $s->name }}</div>
                        <div class="text-[11px] text-gray-400">{{ $s->getRoleNames()->first() }}</div>
                    </div>
                    <div class="text-right">
                        @if ($eval)
                            <div class="text-lg font-black {{ $eval->total_score >= 80 ? 'text-emerald-600' : ($eval->total_score >= 60 ? 'text-amber-600' : 'text-rose-600') }}">
                                {{ rtrim(rtrim(number_format($eval->total_score,2),'0'),'.') }}%
                            </div>
                            <div class="text-[10px] text-gray-400">Đã đánh giá</div>
                        @else
                            <div class="text-xs text-gray-400 italic">Chưa đánh giá</div>
                        @endif
                    </div>
                    <a href="{{ route('kpi.evaluate', ['userId' => $s->id, 'month' => $month, 'year' => $year]) }}"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition {{ $eval ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-orange-50 text-primary hover:bg-orange-100' }}">
                        {{ $eval ? 'Xem / Sửa' : 'Đánh giá' }}
                    </a>
                </div>
            @empty
                <div class="p-10 text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">group_off</span>
                    <p class="mt-2 text-sm">Chưa có nhân sự nào để đánh giá.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
