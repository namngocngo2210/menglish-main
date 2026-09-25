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

                @if ($isAcademicStaff)
                    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800">
                        KPI Học vụ tính lương tự động: <strong>quỹ {{ number_format($fund, 0, ',', '.') }}đ × điểm KPI tổng</strong>
                        (mục chưa chấm tính 0%). Chấm theo ngưỡng: đạt ngưỡng 100% → 100, đạt ngưỡng 50% → 50.
                    </div>
                @endif

                @foreach ($criteria->groupBy(fn ($c) => $c->group_name ?: 'Chưa phân nhóm') as $groupName => $groupItems)
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
                    <div class="px-4 py-2 bg-gray-50 rounded-t-2xl flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-700">{{ $groupName }}</span>
                        <span class="text-[11px] text-gray-500">Quỹ nhóm {{ number_format($groupItems->sum(fn ($c) => $c->fundAmount($fund)), 0, ',', '.') }}đ</span>
                    </div>
                    @foreach ($groupItems as $cr)
                        @php $item = $scores->get($cr->id); @endphp
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-gray-900">@if($cr->code)<span class="font-mono text-gray-500">{{ $cr->code }}</span> @endif{{ $cr->name }}
                                    <span class="text-[11px] font-bold text-primary">({{ rtrim(rtrim(number_format($cr->weight,2),'0'),'.') }}% · {{ number_format($cr->fundAmount($fund), 0, ',', '.') }}đ)</span>
                                </div>
                                <div class="text-[11px] text-gray-400">
                                    @if($cr->threshold_full) Ngưỡng 100%: {{ $cr->threshold_full }} @endif
                                    @if($cr->threshold_half) · Ngưỡng 50%: {{ $cr->threshold_half }} @endif
                                    @if($cr->target) · Mục tiêu: {{ $cr->target }} @endif
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
                @endforeach

                <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Nhận xét tổng quan</label>
                    <textarea name="comment" rows="3" class="w-full text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">{{ $evaluation?->comment }}</textarea>
                </div>

                <div class="flex items-center justify-between">
                    @if ($evaluation)
                        <span class="text-sm text-gray-500">Điểm tổng hiện tại: <span class="font-black text-primary">{{ rtrim(rtrim(number_format($evaluation->total_score,2),'0'),'.') }}%</span>
                            @if ($isAcademicStaff) · Tiền KPI dự tính: <span class="font-black text-primary">{{ number_format(round($fund * (float) $evaluation->total_score / 100), 0, ',', '.') }}đ</span> @endif
                        </span>
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
