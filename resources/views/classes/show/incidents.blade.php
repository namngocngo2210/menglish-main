{{-- Trang lớp · Sự vụ: nhật ký sự vụ gắn với lớp này (staff_reports.class_id); ghi sự vụ mới ngay tại đây. --}}
@php
    $sevColors = ['urgent' => 'error', 'important' => 'warning'];
    $statusColors = ['resolved' => 'success', 'following' => 'secondary'];
    $statusLabels = ['open' => 'Mới', 'following' => 'Đang theo dõi', 'resolved' => 'Đã xử lý'];
@endphp

<div class="space-y-4">
    @can('staff_report.submit')
        <form method="POST" action="{{ route('reports.journal.store') }}" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
            @csrf
            <input type="hidden" name="class_id" value="{{ $class->id }}">
            <h2 class="text-sm font-bold text-on-surface">Ghi sự vụ cho lớp {{ $class->code }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-ui.input id="incident_title" name="title" required placeholder="Tiêu đề sự vụ *" aria-label="Tiêu đề sự vụ" />
                </div>
                <x-ui.select id="incident_severity" name="severity" :options="['normal' => 'Bình thường', 'important' => 'Quan trọng', 'urgent' => 'Khẩn cấp']" aria-label="Mức độ" />
            </div>
            <x-ui.textarea id="incident_content" name="content" rows="2" placeholder="Mô tả chi tiết..." aria-label="Mô tả chi tiết" />
            <div class="flex items-center justify-between gap-3">
                <x-ui.date id="incident_date" name="report_date" :value="now()->toDateString()" aria-label="Ngày sự vụ" />
                <x-ui.button type="submit" icon="add">Ghi sự vụ</x-ui.button>
            </div>
        </form>
    @endcan

    <x-ui.data-table>
        <x-slot:header>
            <h2 class="text-base font-bold text-on-surface">Sự vụ của lớp</h2>
            @can('staff_report.submit')
                <a href="{{ route('reports.journal') }}" class="text-xs font-semibold text-primary hover:underline">Mở nhật ký sự vụ để theo dõi / cập nhật</a>
            @endcan
        </x-slot:header>
        <table class="text-xs">
            <thead>
                <tr>
                    <th>Ngày</th>
                    <th>Sự vụ</th>
                    <th>Mức độ</th>
                    <th>Người ghi</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($incidents as $incident)
                    <tr>
                        <td class="font-code">{{ $incident->report_date?->format('d/m/Y') }}</td>
                        <td class="max-w-md">
                            <p class="font-semibold text-on-surface">{{ $incident->title }}</p>
                            @if ($incident->content)
                                <p class="line-clamp-2 text-[11px] text-on-surface-variant">{{ $incident->content }}</p>
                            @endif
                            @if ($incident->followups->isNotEmpty())
                                <p class="mt-1 text-[11px] text-on-surface-variant">{{ $incident->followups->count() }} follow-up · mới nhất: {{ \Illuminate\Support\Str::limit($incident->followups->first()->content, 80) }}</p>
                            @endif
                        </td>
                        <td><x-ui.badge :color="$sevColors[$incident->severity] ?? 'neutral'">{{ $incident->severity_label }}</x-ui.badge></td>
                        <td>{{ $incident->user?->name ?? '—' }}</td>
                        <td><x-ui.badge :color="$statusColors[$incident->status] ?? 'primary'" :pill="true">{{ $statusLabels[$incident->status] ?? \App\Support\StatusLabel::for($incident->status) }}</x-ui.badge></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5"><x-ui.empty-state icon="task_alt" title="Lớp chưa có sự vụ nào." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
</div>
