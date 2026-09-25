<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">school</span>
                Khách học thử trên buổi dạy của tôi
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Phản hồi của giáo viên được lưu vào hồ sơ khách (CRM) để tư vấn viên / Học vụ theo dõi.</p>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-x-auto">
            <table class="w-full text-xs text-left min-w-[900px]">
                <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                    <tr>
                        <th class="p-3">Buổi học</th>
                        <th class="p-3">Khách học thử</th>
                        <th class="p-3">Trạng thái</th>
                        <th class="p-3">Phản hồi của giáo viên</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($bookings as $booking)
                        <tr class="align-top">
                            <td class="p-3">
                                <div class="font-bold text-gray-900">{{ $booking->classModel?->name }}</div>
                                <div class="text-gray-500">{{ $booking->session?->date?->format('d/m/Y') }} · {{ $booking->session?->start_time?->format('H:i') }}–{{ $booking->session?->end_time?->format('H:i') }}</div>
                                <div class="text-gray-400">{{ $booking->classModel?->course?->name }}</div>
                            </td>
                            <td class="p-3">
                                <div class="font-bold text-gray-900">{{ $booking->customer?->name }}</div>
                                @if ($booking->customer?->parent_name)
                                    <div class="text-gray-500">PH: {{ $booking->customer->parent_name }}</div>
                                @endif
                                <div class="text-gray-500">Điểm test: {{ $booking->customer?->test_score ?? 'Chưa test' }}</div>
                                @if ($booking->notes)
                                    <div class="mt-1 text-gray-600">{{ $booking->notes }}</div>
                                @endif
                            </td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded-full font-bold {{ $booking->status === 'attended' ? 'bg-emerald-50 text-emerald-700' : ($booking->status === 'no_show' ? 'bg-rose-50 text-rose-700' : 'bg-sky-50 text-sky-700') }}">{{ $booking->status_label }}</span>
                            </td>
                            <td class="p-3">
                                @if ($booking->feedback_at)
                                    <div class="text-gray-700">
                                        @if ($booking->rating)<span class="font-bold">{{ $booking->rating }}/5</span> · @endif{{ $booking->feedback ?: '—' }}
                                    </div>
                                    <div class="text-[11px] text-gray-400 mt-1">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at->format('d/m/Y H:i') }}</div>
                                @endif
                                <form action="{{ route('teacher.trial-guests.feedback', $booking) }}" method="POST" class="mt-2 space-y-2">
                                    @csrf
                                    <div class="flex gap-2">
                                        <select name="status" class="rounded-lg border-gray-200 text-xs">
                                            <option value="attended" @selected($booking->status !== 'no_show')>Đã học thử</option>
                                            <option value="no_show" @selected($booking->status === 'no_show')>Vắng mặt</option>
                                        </select>
                                        <select name="rating" class="rounded-lg border-gray-200 text-xs">
                                            @foreach ([5, 4, 3, 2, 1] as $rating)
                                                <option value="{{ $rating }}" @selected((int) $booking->rating === $rating)>{{ $rating }}/5</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <textarea name="feedback" rows="2" placeholder="Mức độ phù hợp, tương tác, đề xuất lớp..." class="w-full rounded-lg border-gray-200 text-xs">{{ $booking->feedback }}</textarea>
                                    <x-ui.button type="submit" size="sm" icon="rate_review">Lưu phản hồi</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-gray-400">Chưa có khách học thử nào trên buổi dạy của bạn.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $bookings->links() }}
    </div>
</x-app-layout>
