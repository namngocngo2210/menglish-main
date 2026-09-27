<x-app-layout>
    <x-ui.page-header title="Lịch học thử" icon="event_available" description="Các buổi học thử đã đặt cho khách tuyển sinh. Đặt hoặc hủy học thử trong hồ sơ khách (CRM); giáo viên buổi đó thấy khách và nhận xét vào hồ sơ." />

    <div class="space-y-4">
        <x-ui.tabs>
            <x-ui.tab :href="route('classes.trial-booking', array_filter(['scope' => 'upcoming', 'status' => $status]))" :active="$scope === 'upcoming'">Hôm nay &amp; sắp tới</x-ui.tab>
            <x-ui.tab :href="route('classes.trial-booking', array_filter(['scope' => 'past', 'status' => $status]))" :active="$scope === 'past'">Đã diễn ra</x-ui.tab>
        </x-ui.tabs>

        <form method="GET" class="flex flex-wrap items-center gap-sm">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <x-ui.select name="status" aria-label="Trạng thái" onchange="this.form.submit()">
                <option value="">Tất cả trạng thái</option>
                @foreach (\App\Models\CrmTrialBooking::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </form>

        <x-ui.data-table min-width="960px" class="shadow-sm">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="w-[200px]">Buổi học</th>
                        <th class="w-[220px]">Khách học thử</th>
                        <th class="w-[110px]">Trạng thái</th>
                        <th>Nhận xét của giáo viên</th>
                        <th class="w-[120px] text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        <tr class="align-top">
                            <td>
                                <div class="font-bold text-on-surface">{{ $booking->classModel?->name }}</div>
                                <div class="text-on-surface-variant">{{ $booking->session?->date?->format('d/m/Y') }} · {{ $booking->session?->start_time?->format('H:i') }}–{{ $booking->session?->end_time?->format('H:i') }}</div>
                                <div class="text-on-surface-variant/70">{{ $booking->classModel?->course?->name }} · {{ $booking->classModel?->branch?->name }}</div>
                            </td>
                            <td>
                                <div class="font-bold text-on-surface">{{ $booking->customer?->name }}</div>
                                @if ($booking->customer?->parent_name)
                                    <div class="text-on-surface-variant">PH: {{ $booking->customer->parent_name }}</div>
                                @endif
                                <div class="text-on-surface-variant">{{ $booking->customer?->stage_label }} · Test: {{ $booking->customer?->test_score ?? 'Chưa test' }}</div>
                                <div class="text-on-surface-variant/70">Phụ trách: {{ $booking->customer?->assignedUser?->name ?? '—' }} · Đặt bởi: {{ $booking->bookedBy?->name ?? '—' }}</div>
                            </td>
                            <td>
                                <x-ui.badge :color="$booking->status === 'attended' ? 'success' : ($booking->status === 'no_show' ? 'error' : ($booking->status === 'cancelled' ? 'neutral' : 'info'))" :pill="true">{{ $booking->status_label }}</x-ui.badge>
                            </td>
                            <td>
                                @if ($booking->feedback_at)
                                    @if ($booking->rating)<div><span class="font-bold">{{ $booking->rating }}/5</span>@if ($booking->remarksSummary() !== '') · {{ $booking->remarksSummary() }}@endif</div>@endif
                                    <div class="text-on-surface-variant">{{ $booking->feedback ?: '—' }}</div>
                                    <div class="text-[11px] text-on-surface-variant/70">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at->format('d/m/Y H:i') }}</div>
                                @else
                                    <span class="text-on-surface-variant/70 italic">Chưa có nhận xét</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($booking->customer)
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', $booking->customer_id)">Hồ sơ khách</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="event_available" :title="$scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Chưa có lịch học thử sắp tới.'" description="Đặt học thử trong hồ sơ khách tuyển sinh (nút Đặt lịch học thử)." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$bookings" unit="buổi" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
