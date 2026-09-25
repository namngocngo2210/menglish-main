<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('teacher.home') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">military_tech</span>
                    Bảng điểm Big Test các lớp tôi phụ trách
                </h1>
                <p class="text-xs text-gray-500">Kết quả các kỳ Big Test của lớp dạy chính và lớp đồng giảng (GVNN)</p>
            </div>
        </div>
    </x-slot>

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                    <th class="py-3 px-4">Học viên</th>
                    <th class="py-3 px-4">Kỳ thi / Lớp</th>
                    <th class="py-3 px-4 text-center">Nghe</th>
                    <th class="py-3 px-4 text-center">Đọc</th>
                    <th class="py-3 px-4 text-center">Viết</th>
                    <th class="py-3 px-4 text-center">Nói</th>
                    <th class="py-3 px-4 text-center">Tổng</th>
                    <th class="py-3 px-4">Trạng thái</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($results as $r)
                    <tr class="hover:bg-orange-50/20 transition">
                        <td class="py-3 px-4 font-bold text-gray-900">{{ $r->student?->name ?? '—' }}</td>
                        <td class="py-3 px-4">
                            <div class="font-semibold text-primary">{{ $r->bigTest?->title ?? '—' }}</div>
                            <div class="text-[11px] text-gray-400">{{ $r->bigTest?->classModel?->name }}</div>
                        </td>
                        <td class="py-3 px-4 text-center font-mono">{{ $r->listening_score ?? '—' }}</td>
                        <td class="py-3 px-4 text-center font-mono">{{ $r->reading_score ?? '—' }}</td>
                        <td class="py-3 px-4 text-center font-mono">{{ $r->writing_score ?? '—' }}</td>
                        <td class="py-3 px-4 text-center font-mono">{{ $r->speaking_score ?? '—' }}</td>
                        <td class="py-3 px-4 text-center font-mono font-bold text-primary">{{ $r->overall_score ?? '—' }}</td>
                        <td class="py-3 px-4">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $r->status === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200' }}">
                                {{ $r->status === 'approved' ? 'Đã duyệt' : 'Chờ duyệt' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-8 text-gray-400 text-xs">Chưa có kết quả Big Test nào cho các lớp của bạn.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-3 border-t border-gray-100">
            <x-pagination :paginator="$results" />
        </div>
    </div>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    @include('teacher.partials.bottom-nav')
</x-app-layout>
