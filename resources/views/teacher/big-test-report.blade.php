<x-app-layout>
    <x-ui.page-header title="Bảng điểm Big Test các lớp tôi phụ trách" icon="military_tech" :back="route('teacher.home')" />

    <x-ui.data-table class="shadow-sm">
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Học viên</th>
                    <th>Kỳ thi / Lớp</th>
                    <th class="text-center">Nghe</th>
                    <th class="text-center">Đọc</th>
                    <th class="text-center">Viết</th>
                    <th class="text-center">Nói</th>
                    <th class="text-center">Tổng</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($results as $r)
                    <tr>
                        <td class="font-bold">{{ $r->student?->name ?? '—' }}</td>
                        <td>
                            <div class="font-semibold text-primary">{{ $r->bigTest?->title ?? '—' }}</div>
                            <div class="text-[11px] text-on-surface-variant/70">{{ $r->bigTest?->classModel?->name }}</div>
                        </td>
                        <td class="text-center font-mono">{{ $r->listening_score ?? '—' }}</td>
                        <td class="text-center font-mono">{{ $r->reading_score ?? '—' }}</td>
                        <td class="text-center font-mono">{{ $r->writing_score ?? '—' }}</td>
                        <td class="text-center font-mono">{{ $r->speaking_score ?? '—' }}</td>
                        <td class="text-center font-mono font-bold text-primary">{{ $r->overall_score ?? '—' }}</td>
                        <td>
                            <x-ui.badge :color="$r->status === 'approved' ? 'success' : 'warning'" :pill="true">
                                {{ $r->status === 'approved' ? 'Đã duyệt' : 'Chờ duyệt' }}
                            </x-ui.badge>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="military_tech" title="Chưa có kết quả Big Test nào cho các lớp của bạn." /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$results" /></x-slot:footer>
    </x-ui.data-table>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    @include('teacher.partials.bottom-nav')
</x-app-layout>
