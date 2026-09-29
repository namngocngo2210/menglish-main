{{-- Trang lớp · Sự vụ: nhật ký sự vụ gắn với lớp này (staff_reports.class_id); ghi sự vụ mới bằng nút "Ghi sự vụ" (modal). --}}
@php
    $sevColors = ['urgent' => 'error', 'important' => 'warning'];
    $statusColors = ['resolved' => 'success', 'following' => 'secondary'];
    $statusLabels = ['open' => 'Mới', 'following' => 'Đang theo dõi', 'resolved' => 'Đã xử lý'];
@endphp

<div class="space-y-4">
    <x-ui.data-table>
        <x-slot:header>
            <h2 class="text-base font-bold text-on-surface">Sự vụ của lớp</h2>
            @can('staff_report.submit')
                <div class="flex flex-wrap items-center gap-md">
                    <a href="{{ route('reports.journal') }}" class="text-xs font-semibold text-primary hover:underline">Mở nhật ký sự vụ để theo dõi / cập nhật</a>
                    <x-ui.button size="sm" icon="add" x-on:click="$dispatch('open-modal', 'new-incident')">Ghi sự vụ</x-ui.button>
                </div>
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

    @can('staff_report.submit')
        <x-ui.modal name="new-incident" :title="'Ghi sự vụ cho lớp '.$class->code" :show="old('_modal') === 'new-incident'">
            <form id="new-incident-form" method="POST" action="{{ route('reports.journal.store') }}" class="space-y-md">
                @csrf
                <input type="hidden" name="_modal" value="new-incident">
                <input type="hidden" name="class_id" value="{{ $class->id }}">
                <x-ui.input id="incident_title" name="title" label="Tiêu đề sự vụ" required />
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <x-ui.select id="incident_severity" name="severity" label="Mức độ" :options="['normal' => 'Bình thường', 'important' => 'Quan trọng', 'urgent' => 'Khẩn cấp']" />
                    <x-ui.date id="incident_date" name="report_date" label="Ngày sự vụ" :value="now()->toDateString()" />
                </div>
                <x-ui.textarea id="incident_content" name="content" label="Mô tả chi tiết" rows="3" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-incident')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-incident-form" icon="add">Ghi sự vụ</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</div>
