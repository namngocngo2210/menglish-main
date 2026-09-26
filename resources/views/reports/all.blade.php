<x-app-layout>
    <x-ui.page-header title="Tổng hợp Báo cáo & Nhật ký toàn trung tâm" icon="monitoring" />

    <div class="space-y-6">
        {{-- Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
            <x-ui.stat-card label="Nhật ký" :value="$stats['journal']" />
            <x-ui.stat-card label="BC Ngày" :value="$stats['daily']" />
            <x-ui.stat-card label="BC Tuần" :value="$stats['weekly']" />
            <x-ui.stat-card label="BC Tháng" :value="$stats['monthly']" />
            <x-ui.stat-card label="Sự vụ khẩn chưa xử lý" :value="$stats['urgent_open']" tone="error" class="border-error/30" />
        </div>

        {{-- Filter --}}
        <form method="GET" class="flex flex-wrap items-center gap-3 bg-surface-container-lowest rounded-2xl p-4 border border-surface-container-highest shadow-sm">
            <x-ui.select name="type" :options="\App\Models\StaffReport::TYPE_LABELS" placeholder="Tất cả loại" aria-label="Loại báo cáo" />
            <x-ui.date name="date" :value="request('date')" aria-label="Ngày báo cáo" />
            <x-ui.button type="submit">Lọc</x-ui.button>
            <x-ui.button variant="secondary" :href="route('reports.all')">Xóa lọc</x-ui.button>
        </form>

        {{-- List --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm divide-y divide-surface-container-highest">
            @forelse ($reports as $r)
                <div class="p-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <x-ui.badge color="primary" :pill="true" :dot="false">{{ $r->type_label }}</x-ui.badge>
                            @if ($r->type === 'journal')
                                <x-ui.badge color="neutral" :pill="true" :dot="false">{{ $r->severity_label }}</x-ui.badge>
                            @endif
                            <span class="font-bold text-sm text-on-surface">{{ $r->title }}</span>
                        </div>
                        <div class="text-[11px] text-on-surface-variant/70 mt-1">
                            {{ $r->report_date->format('d/m/Y') }} · <span class="font-semibold text-on-surface-variant">{{ $r->user?->name }}</span>
                            @if ($r->followups->isNotEmpty()) · {{ $r->followups->count() }} follow-up @endif
                        </div>
                        @if ($r->content)
                            <p class="text-sm text-on-surface-variant mt-1.5 line-clamp-2">{{ $r->content }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <x-ui.empty-state icon="inbox" title="Không có báo cáo nào khớp bộ lọc." />
            @endforelse
        </div>
        <x-ui.pagination :paginator="$reports" :options="[]" />
    </div>
</x-app-layout>
