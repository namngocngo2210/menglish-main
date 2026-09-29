{{-- Khách học thử của buổi đang xem (chưa phải học sinh chính thức — không có trong danh sách lớp).
     Chỉ hiện trên buổi được xếp; buổi sau khách không còn trong lớp. Nhận xét ở trang "Nhận xét học thử". --}}
@if ($trialGuests->isNotEmpty())
    <section class="rounded-xl border border-secondary/30 bg-secondary/5 p-md" data-testid="session-trial-guests">
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <h2 class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-on-surface">
                <span class="material-symbols-outlined text-[20px] text-secondary" aria-hidden="true">person_search</span>
                Khách học thử buổi này ({{ $trialGuests->count() }})
            </h2>
            <x-ui.button size="sm" variant="secondary" icon="rate_review" :href="route('teacher.trial-guests', ['scope' => $trialGuests->first()->session?->date?->isBefore(today()) ? 'past' : 'upcoming'])">Nhận xét học thử</x-ui.button>
        </div>
        <ul class="mt-sm space-y-xs font-body-small text-body-small">
            @foreach ($trialGuests as $guest)
                <li class="rounded-lg bg-surface-container-lowest px-sm py-xs">
                    <span class="font-semibold text-on-surface">{{ $guest->customer?->name }}</span>
                    <span class="text-on-surface-variant">· Test: {{ $guest->customer?->test_score ?? 'Chưa test' }} · {{ $guest->status_label }}</span>
                    @if ($guest->notes)<span class="block text-on-surface-variant">Ghi chú của Học vụ: {{ $guest->notes }}</span>@endif
                </li>
            @endforeach
        </ul>
        <p class="mt-xs font-caption text-caption text-on-surface-variant">Khách không có trong danh sách điểm danh lớp. Sau giờ học, nhận xét khách để Học vụ chăm sóc tiếp.</p>
    </section>
@endif
