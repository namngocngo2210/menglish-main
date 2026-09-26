<x-app-layout>
    <x-ui.page-header title="Nhận xét học thử" icon="school" description="Khách học thử (chưa chốt) trên buổi dạy của bạn. Nhận xét như học sinh chính thức — lưu vào hồ sơ khách tuyển sinh để Học vụ / tư vấn viên theo dõi.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow_back" :href="route('teacher.home')">Về trang chủ</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-4">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.tabs>
            <x-ui.tab :href="route('teacher.trial-guests', ['scope' => 'upcoming'])" :active="$scope === 'upcoming'">Hôm nay &amp; sắp tới</x-ui.tab>
            <x-ui.tab :href="route('teacher.trial-guests', ['scope' => 'past'])" :active="$scope === 'past'">Đã diễn ra</x-ui.tab>
        </x-ui.tabs>

        <x-ui.data-table min-width="960px" class="shadow-sm">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="w-[200px]">Buổi học</th>
                        <th class="w-[200px]">Khách học thử</th>
                        <th class="w-[110px]">Trạng thái</th>
                        <th>Nhận xét của giáo viên</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        @php($started = $booking->sessionHasStarted())
                        <tr class="align-top">
                            <td>
                                <div class="font-bold text-on-surface">{{ $booking->classModel?->name }}</div>
                                <div class="text-on-surface-variant">{{ $booking->session?->date?->format('d/m/Y') }} · {{ $booking->session?->start_time?->format('H:i') }}–{{ $booking->session?->end_time?->format('H:i') }}</div>
                                <div class="text-on-surface-variant/70">{{ $booking->classModel?->course?->name }}</div>
                            </td>
                            <td>
                                <div class="font-bold text-on-surface">{{ $booking->customer?->name }}</div>
                                @if ($booking->customer?->parent_name)
                                    <div class="text-on-surface-variant">PH: {{ $booking->customer->parent_name }}</div>
                                @endif
                                <div class="text-on-surface-variant">Kết quả test: {{ $booking->customer?->test_score ?? 'Chưa test' }}</div>
                                @if ($booking->notes)
                                    <div class="mt-1 text-on-surface-variant">Ghi chú của Học vụ: {{ $booking->notes }}</div>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="$booking->status === 'attended' ? 'success' : ($booking->status === 'no_show' ? 'error' : 'info')" :pill="true">{{ $booking->status_label }}</x-ui.badge>
                            </td>
                            <td>
                                @if ($booking->feedback_at)
                                    <div class="text-on-surface-variant space-y-0.5 mb-2">
                                        @if ($booking->rating)<div><span class="font-bold">{{ $booking->rating }}/5</span>@if ($booking->remarksSummary() !== '') · {{ $booking->remarksSummary() }}@endif</div>@endif
                                        <div>{{ $booking->feedback ?: '—' }}</div>
                                        <div class="text-[11px] text-on-surface-variant/70">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at->format('d/m/Y H:i') }}</div>
                                    </div>
                                @endif
                                @if ($started)
                                    <form action="{{ route('teacher.trial-guests.feedback', $booking) }}" method="POST" class="space-y-2">
                                        @csrf
                                        <div class="flex flex-wrap gap-2">
                                            <x-ui.select name="status" id="trial-status-{{ $booking->id }}" class="text-xs" aria-label="Trạng thái">
                                                <option value="attended" @selected($booking->status !== 'no_show')>Có mặt (đã học thử)</option>
                                                <option value="no_show" @selected($booking->status === 'no_show')>Vắng mặt</option>
                                            </x-ui.select>
                                            <x-ui.select name="rating" id="trial-rating-{{ $booking->id }}" class="text-xs" title="Mức độ phù hợp với lớp">
                                                @foreach ([5, 4, 3, 2, 1] as $rating)
                                                    <option value="{{ $rating }}" @selected((int) ($booking->rating ?? 4) === $rating)>{{ $rating }}/5</option>
                                                @endforeach
                                            </x-ui.select>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2">
                                            @foreach (\App\Models\CrmTrialBooking::REMARK_FIELDS as $key => $label)
                                                <label class="block">
                                                    <span class="text-[10px] font-semibold uppercase text-on-surface-variant">{{ $label }}</span>
                                                    <input type="text" name="remarks[{{ $key }}]" value="{{ $booking->remarks[$key] ?? '' }}" maxlength="255" class="w-full rounded-lg border-outline-variant bg-surface-container-lowest text-xs text-on-surface focus:border-primary-container focus:ring-primary-container/20" placeholder="{{ ['grammar' => 'Khá', 'attitude' => 'Hăng hái', 'result' => 'Đạt mục tiêu'][$key] }}">
                                                </label>
                                            @endforeach
                                        </div>
                                        <textarea name="feedback" rows="2" maxlength="3000" placeholder="Nhận xét chi tiết: mức độ phù hợp với lớp, tương tác, đề xuất..." class="w-full rounded-lg border-outline-variant bg-surface-container-lowest text-xs text-on-surface focus:border-primary-container focus:ring-primary-container/20">{{ $booking->feedback }}</textarea>
                                        <x-ui.button type="submit" size="sm" icon="rate_review">Lưu nhận xét</x-ui.button>
                                    </form>
                                @else
                                    <p class="text-on-surface-variant/70 italic">Nhận xét được mở từ ngày học thử.</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state icon="school" :title="$scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Không có khách học thử sắp tới trên buổi dạy của bạn.'" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$bookings" /></x-slot:footer>
        </x-ui.data-table>
    </div>
    <div class="h-20 md:hidden" aria-hidden="true"></div>
    @include('teacher.partials.bottom-nav')
</x-app-layout>
