<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">rate_review</span>
                    Đánh giá KPI — {{ $staff->name }}
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">Kỳ tháng {{ $month }}/{{ $year }} · {{ $staff->getRoleNames()->first() }}</p>
            </div>
            <a href="{{ route('kpi.monthly', ['month' => $month, 'year' => $year]) }}" class="text-xs font-semibold text-gray-500 hover:text-primary flex items-center gap-1">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Về tổng hợp
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        @if ($isSelf ?? false)
            <x-ui.alert type="warning" title="Không tự chấm KPI">Bạn đang xem phiếu KPI của chính mình — việc chấm điểm do cấp quản lý thực hiện.</x-ui.alert>
        @endif

        @if ($criteria->isEmpty())
            <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300">tune</span>
                <p class="mt-2 text-sm">Chưa có chỉ số KPI. Vui lòng <a href="{{ route('kpi.criteria') }}" class="text-primary font-semibold">cấu hình chỉ số</a> trước.</p>
            </div>
        @else
            <form method="POST" action="{{ route('kpi.evaluate.store', $staff->id) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                <input type="hidden" name="year" value="{{ $year }}">

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
                    @foreach ($criteria as $cr)
                        @php $item = $scores->get($cr->id); @endphp
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-gray-900">{{ $cr->name }}
                                    <span class="text-[11px] font-bold text-primary">({{ rtrim(rtrim(number_format($cr->weight,2),'0'),'.') }}%)</span>
                                </div>
                                <div class="text-[11px] text-gray-400">
                                    @if($cr->target) Mục tiêu: {{ $cr->target }} @endif
                                    @if($cr->description) · {{ $cr->description }} @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <input type="number" name="score[{{ $cr->id }}]" value="{{ $item?->score !== null ? rtrim(rtrim(number_format($item->score,2),'0'),'.') : '' }}"
                                       min="0" max="100" step="1" placeholder="0-100"
                                       class="w-24 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container text-center font-bold">
                                <span class="text-xs text-gray-400">%</span>
                            </div>
                            <input type="text" name="note[{{ $cr->id }}]" value="{{ $item?->note }}" placeholder="Ghi chú..."
                                   class="w-full sm:w-56 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                        </div>
                    @endforeach
                </div>

                <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Nhận xét tổng quan</label>
                    <textarea name="comment" rows="3" class="w-full text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">{{ $evaluation?->comment }}</textarea>
                </div>

                <div class="flex items-center justify-between">
                    @if ($evaluation)
                        <span class="text-sm text-gray-500">Điểm tổng hiện tại: <span class="font-black text-primary">{{ rtrim(rtrim(number_format($evaluation->total_score,2),'0'),'.') }}%</span></span>
                    @else <span></span> @endif
                    @unless ($isSelf ?? false)
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">save</span> Lưu đánh giá
                    </button>
                    @endunless
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
