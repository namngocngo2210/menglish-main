<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">monitoring</span>
                Tổng hợp Báo cáo & Nhật ký toàn trung tâm
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Admin xem nhật ký sự vụ & báo cáo ngày/tuần/tháng của tất cả nhân sự</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <div class="text-[11px] font-semibold text-gray-500 uppercase">Nhật ký</div>
                <div class="mt-1 text-xl font-black text-gray-900">{{ $stats['journal'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <div class="text-[11px] font-semibold text-gray-500 uppercase">BC Ngày</div>
                <div class="mt-1 text-xl font-black text-gray-900">{{ $stats['daily'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <div class="text-[11px] font-semibold text-gray-500 uppercase">BC Tuần</div>
                <div class="mt-1 text-xl font-black text-gray-900">{{ $stats['weekly'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
                <div class="text-[11px] font-semibold text-gray-500 uppercase">BC Tháng</div>
                <div class="mt-1 text-xl font-black text-gray-900">{{ $stats['monthly'] }}</div>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-rose-200 shadow-sm">
                <div class="text-[11px] font-semibold text-rose-500 uppercase">Sự vụ khẩn chưa xử lý</div>
                <div class="mt-1 text-xl font-black text-rose-600">{{ $stats['urgent_open'] }}</div>
            </div>
        </div>

        <!-- Filter -->
        <form method="GET" class="flex flex-wrap items-center gap-3 bg-white rounded-2xl p-4 border border-gray-200 shadow-sm">
            <select name="type" class="text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                <option value="">Tất cả loại</option>
                @foreach (\App\Models\StaffReport::TYPE_LABELS as $val => $lbl)
                    <option value="{{ $val }}" @selected(request('type')===$val)>{{ $lbl }}</option>
                @endforeach
            </select>
            <input type="date" name="date" value="{{ request('date') }}" class="text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
            <button type="submit" class="px-4 py-2 rounded-lg bg-primary text-white text-xs font-bold">Lọc</button>
            <a href="{{ route('reports.all') }}" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-600 text-xs font-semibold">Xóa lọc</a>
        </form>

        <!-- List -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
            @forelse ($reports as $r)
                <div class="p-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-orange-100 text-orange-700">{{ $r->type_label }}</span>
                            @if ($r->type === 'journal')
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">{{ $r->severity_label }}</span>
                            @endif
                            <span class="font-bold text-sm text-gray-900">{{ $r->title }}</span>
                        </div>
                        <div class="text-[11px] text-gray-400 mt-1">
                            {{ $r->report_date->format('d/m/Y') }} · <span class="font-semibold text-gray-600">{{ $r->user?->name }}</span>
                            @if ($r->followups->isNotEmpty()) · {{ $r->followups->count() }} follow-up @endif
                        </div>
                        @if ($r->content)
                            <p class="text-sm text-gray-600 mt-1.5 line-clamp-2">{{ $r->content }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-10 text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">inbox</span>
                    <p class="mt-2 text-sm">Không có báo cáo nào khớp bộ lọc.</p>
                </div>
            @endforelse
        </div>
        {{ $reports->links() }}
    </div>
</x-app-layout>
