<x-app-layout>
    <x-ui.page-header title="Rà soát điểm danh (Học vụ)" icon="rule" />

    <div class="space-y-6">
        <x-ui.filter-bar :search="null" class="!mb-0">
            <x-ui.date name="date" :value="$date" />
            <x-ui.select name="class_id" :value="$classId" placeholder="Tất cả lớp" :options="$classes->pluck('name', 'id')" />
        </x-ui.filter-bar>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-ui.stat-card label="Có mặt" :value="$summary['present']" tone="success" />
            <x-ui.stat-card label="Đi muộn" :value="$summary['late']" tone="warning" />
            <x-ui.stat-card label="Vắng" :value="$summary['absent']" tone="error" />
            <x-ui.stat-card label="Có phép" :value="$summary['excused']" tone="secondary" />
        </div>

        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm divide-y divide-surface-container-highest">
            @forelse ($records as $r)
                @php
                    $badgeColor = match($r->status) {
                        'present' => 'success',
                        'late' => 'warning',
                        'absent' => 'error',
                        default => 'info',
                    };
                @endphp
                <div class="p-4 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold text-sm text-on-surface">{{ $r->student?->name }}</div>
                        <div class="text-[11px] text-on-surface-variant/70">{{ $r->classModel?->name }} · GV: {{ $r->teacher?->name ?? '—' }} @if($r->note) · {{ $r->note }} @endif</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui.badge :color="$badgeColor" :pill="true">{{ $r->status_label }}</x-ui.badge>
                        <span class="text-[10px] text-on-surface-variant">{{ \App\Support\StatusLabel::for($r->review_status, 'Chưa rà soát') }}</span>
                        @if($r->review_status === 'pending_review')
                            <form method="POST" action="{{ route('kpi.attendance-review.update', $r->id) }}" class="flex gap-1">@csrf
                                <x-ui.button type="submit" name="decision" value="approved" variant="success" size="sm">Duyệt</x-ui.button>
                                <x-ui.button type="submit" name="decision" value="rejected" variant="danger" size="sm">Trả lại</x-ui.button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <x-ui.empty-state icon="fact_check" title="Không có dữ liệu điểm danh cho ngày/lớp đã chọn." />
            @endforelse
        </div>
    </div>
</x-app-layout>
