<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">rule</span>
                Rà soát điểm danh (Học vụ)
            </h1>
        </div>
    </x-slot>

    <div class="space-y-6">
        <form method="GET" class="flex flex-wrap items-center gap-3 bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
            <input type="date" name="date" value="{{ $date }}" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
            <select name="class_id" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                <option value="">Tất cả lớp</option>
                @foreach ($classes as $c)
                    <option value="{{ $c->id }}" @selected($classId == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg bg-primary-container text-white text-xs font-bold">Lọc</button>
        </form>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl p-4 border border-emerald-200 shadow-sm">
                <div class="text-[11px] font-semibold text-emerald-600 uppercase">Có mặt</div>
                <div class="mt-1 text-xl font-black text-emerald-600">{{ $summary['present'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-amber-200 shadow-sm">
                <div class="text-[11px] font-semibold text-amber-600 uppercase">Đi muộn</div>
                <div class="mt-1 text-xl font-black text-amber-600">{{ $summary['late'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-rose-200 shadow-sm">
                <div class="text-[11px] font-semibold text-rose-600 uppercase">Vắng</div>
                <div class="mt-1 text-xl font-black text-rose-600">{{ $summary['absent'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-blue-200 shadow-sm">
                <div class="text-[11px] font-semibold text-blue-600 uppercase">Có phép</div>
                <div class="mt-1 text-xl font-black text-blue-600">{{ $summary['excused'] }}</div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
            @forelse ($records as $r)
                @php
                    $cls = match($r->status) {
                        'present' => 'bg-emerald-100 text-emerald-700',
                        'late' => 'bg-amber-100 text-amber-700',
                        'absent' => 'bg-rose-100 text-rose-700',
                        default => 'bg-blue-100 text-blue-700',
                    };
                @endphp
                <div class="p-4 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold text-sm text-gray-900">{{ $r->student?->name }}</div>
                        <div class="text-[11px] text-gray-400">{{ $r->classModel?->name }} · GV: {{ $r->teacher?->name ?? '—' }} @if($r->note) · {{ $r->note }} @endif</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold px-2 py-1 rounded-full {{ $cls }}">{{ $r->status_label }}</span>
                        <span class="text-[10px] text-gray-500">{{ \App\Support\StatusLabel::for($r->review_status, 'Chưa rà soát') }}</span>
                        @if($r->review_status === 'pending_review')
                            <form method="POST" action="{{ route('kpi.attendance-review.update', $r->id) }}" class="flex gap-1">@csrf
                                <button name="decision" value="approved" class="px-2 py-1 rounded bg-emerald-600 text-white text-[10px] font-bold">Duyệt</button>
                                <button name="decision" value="rejected" class="px-2 py-1 rounded bg-rose-600 text-white text-[10px] font-bold">Trả lại</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-10 text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">fact_check</span>
                    <p class="mt-2 text-sm">Không có dữ liệu điểm danh cho ngày/lớp đã chọn.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
