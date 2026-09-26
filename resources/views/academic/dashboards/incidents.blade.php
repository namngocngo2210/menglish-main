<x-app-layout>
    <x-ui.page-header title="Báo cáo & sự vụ" icon="monitoring" description="Báo cáo đào tạo ngày / tuần / tháng và sự vụ của các lớp, các cơ sở.">
        <x-slot:actions>
            @can('staff_report.submit')
                <x-ui.button icon="add" :href="route('reports.journal')">Ghi sự vụ</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
    @include('academic.dashboards.partials.section-tabs')


    @php
        $priorityLabels = ['urgent' => 'Khẩn cấp', 'high' => 'Cao', 'medium' => 'Trung bình', 'low' => 'Thấp'];
        $priorityColors = ['urgent' => 'error', 'high' => 'warning', 'medium' => 'info'];
        $ticketStatusColors = ['open' => 'warning', 'in_progress' => 'info', 'resolved' => 'success'];
    @endphp

    <div class="space-y-6">
        {{-- 4 Metric Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Tổng sự vụ ghi nhận" :value="$totalIncidents.' vụ'" icon="auto_stories" />
            <x-ui.stat-card label="Khẩn cấp cần xử lý" :value="$urgentCount.' ticket khẩn cấp'" tone="error" icon="emergency" />
            <x-ui.stat-card label="Đang theo dõi / Xử lý" :value="$openCount.' vụ'" tone="warning" icon="hourglass_top" />
            <x-ui.stat-card label="Đã giải quyết" :value="$resolvedCount.' vụ'" tone="success" icon="check_circle" />
        </div>

        {{-- Filter & Bảng Sự vụ --}}
        <x-ui.data-table>
            <x-slot:header>
                <div>
                    <h3 class="text-sm font-bold text-on-surface">Nhật ký sự vụ các lớp và cơ sở</h3>
                    <p class="text-xs text-on-surface-variant">Theo dõi, giao quyền xử lý và ghi nhận giải pháp khắc phục</p>
                </div>
                <form method="GET" action="{{ route('academic.dashboards.incidents') }}" class="grid w-full grid-cols-2 gap-2 lg:w-auto lg:grid-cols-4" aria-label="Lọc sự vụ">
                    <x-ui.select name="branch_id" aria-label="Cơ sở" onchange="this.form.submit()" placeholder="Tất cả cơ sở" :value="(string) $branchId"
                                 :options="$branches->pluck('name', 'id')" />
                    <x-ui.select name="class_id" aria-label="Lớp" onchange="this.form.submit()" placeholder="Mọi lớp" :value="(string) $classId"
                                 :options="$classes->mapWithKeys(fn ($c) => [$c->id => $c->code])" />
                    <x-ui.select name="severity" aria-label="Mức độ" onchange="this.form.submit()" :value="$severity"
                                 :options="['all' => 'Mọi mức độ'] + $priorityLabels" />
                    <x-ui.select name="status" aria-label="Trạng thái" onchange="this.form.submit()" :value="$status"
                                 :options="['all' => 'Mọi trạng thái', 'open' => 'Đang xử lý', 'resolved' => 'Đã giải quyết']" />
                    <noscript><x-ui.button type="submit" size="sm" variant="secondary">Lọc</x-ui.button></noscript>
                </form>
            </x-slot:header>

            <table>
                <thead>
                    <tr>
                        <th>Mã / Cơ sở</th>
                        <th>Phân loại sự vụ</th>
                        <th>Chi tiết sự vụ phát sinh</th>
                        <th>Mức độ</th>
                        <th>Người phụ trách & Biện pháp</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($urgentTickets as $ticket)
                        <tr class="{{ $ticket->priority === 'urgent' ? 'bg-error/5' : '' }}">
                            <td>
                                <a href="{{ route('tickets.show', $ticket->id) }}" class="font-code font-bold text-on-surface hover:underline">{{ $ticket->code }}</a>
                                <span class="block text-[11px] text-on-surface-variant">{{ $ticket->creator?->branch?->name ?? 'Chưa cập nhật' }}</span>
                            </td>
                            <td>
                                <x-ui.badge color="neutral" pill :dot="false">{{ $ticket->category_label }}</x-ui.badge>
                            </td>
                            <td class="max-w-sm">
                                <p class="font-semibold">{{ $ticket->title }}</p>
                                <p class="line-clamp-1 text-[11px] text-on-surface-variant">{{ \Illuminate\Support\Str::limit(strip_tags((string) $ticket->description), 140) }}</p>
                            </td>
                            <td>
                                <x-ui.badge :color="$priorityColors[$ticket->priority] ?? 'neutral'" pill>{{ $priorityLabels[$ticket->priority] ?? 'Chưa cập nhật' }}</x-ui.badge>
                            </td>
                            <td>
                                <span class="font-bold">{{ $ticket->assignee?->name ?? 'Chưa phân công' }}</span>
                                <span class="block text-[11px] text-on-surface-variant">Người tạo: {{ $ticket->creator?->name ?? 'Chưa cập nhật' }}</span>
                            </td>
                            <td>
                                <x-ui.badge :color="$ticketStatusColors[$ticket->status] ?? 'neutral'" pill>{{ $ticket->status_label }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($journals as $journal)
                        @php $journalSeverity = ['urgent' => 'urgent', 'important' => 'high', 'normal' => 'medium'][$journal->severity] ?? null; @endphp
                        <tr class="{{ $journal->severity === 'urgent' && $journal->status !== 'resolved' ? 'bg-error/5' : '' }}">
                            <td>
                                <span class="font-code font-bold">Sự vụ #{{ $journal->id }}</span>
                                <span class="block text-[11px] text-on-surface-variant">{{ $journal->classModel?->branch?->name ?? $journal->user?->branch?->name ?? 'Chưa cập nhật' }}</span>
                                @if ($journal->classModel)
                                    <a href="{{ route('classes.show', ['id' => $journal->class_id, 'tab' => 'incidents']) }}" class="block text-[11px] font-semibold text-primary hover:underline">Lớp {{ $journal->classModel->code }}</a>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge color="warning" pill :dot="false">Nhật ký sự vụ</x-ui.badge>
                            </td>
                            <td class="max-w-sm">
                                <p class="font-semibold">{{ $journal->title }}</p>
                                @if ($journal->content)
                                    <p class="line-clamp-1 text-[11px] text-on-surface-variant">{{ $journal->content }}</p>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="$priorityColors[$journalSeverity] ?? 'neutral'" pill>{{ $journal->severity_label }}</x-ui.badge>
                            </td>
                            <td>
                                <span class="font-bold">{{ $journal->user?->name ?? 'Chưa cập nhật' }}</span>
                                @if ($journal->followups->isNotEmpty())
                                    <span class="block line-clamp-1 text-[11px] text-on-surface-variant">{{ $journal->followups->first()->content }}</span>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="['resolved' => 'success', 'following' => 'info'][$journal->status] ?? 'warning'" pill>{{ ['resolved' => 'Đã xử lý', 'following' => 'Đang theo dõi'][$journal->status] ?? 'Mới' }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($incidents as $incident)
                        <tr>
                            <td>
                                <span class="font-code font-bold">{{ $incident->record_code ?: 'Nhật ký #' . $incident->id }}</span>
                                <span class="block text-[11px] text-on-surface-variant">{{ $incident->user?->branch?->name ?? 'Chưa cập nhật' }}</span>
                            </td>
                            <td>
                                <x-ui.badge color="warning" pill :dot="false">Nhật ký học vụ</x-ui.badge>
                            </td>
                            <td class="max-w-sm">
                                <p class="font-semibold">{{ $incident->title }}</p>
                                @if (! empty($incident->data['noi_dung']))
                                    <p class="line-clamp-1 text-[11px] text-on-surface-variant">{{ $incident->data['noi_dung'] }}</p>
                                @endif
                            </td>
                            <td class="text-[11px] text-on-surface-variant/70">—</td>
                            <td>
                                <span class="font-bold">{{ $incident->user?->name ?? 'Chưa cập nhật' }}</span>
                            </td>
                            <td>
                                <x-ui.badge color="neutral" pill>{{ $incident->status_label }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                    @if ($urgentTickets->isEmpty() && $incidents->isEmpty() && $journals->isEmpty())
                        <tr>
                            <td colspan="6"><x-ui.empty-state title="Chưa có sự vụ nào phù hợp bộ lọc." /></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </x-ui.data-table>
    </div>
</x-app-layout>
