<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">school</span>
                    Nhận xét học thử
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">Khách học thử (chưa chốt) trên buổi dạy của bạn. Nhận xét như học sinh chính thức — lưu vào hồ sơ khách tuyển sinh để Học vụ / tư vấn viên theo dõi.</p>
            </div>
            <a href="{{ route('teacher.home') }}" class="text-xs font-semibold text-gray-500 hover:text-primary flex items-center gap-1">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Về trang chủ
            </a>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        <div class="flex items-center gap-2 text-xs">
            <a href="{{ route('teacher.trial-guests', ['scope' => 'upcoming']) }}" class="px-3 py-1.5 rounded-lg font-semibold {{ $scope === 'upcoming' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Hôm nay &amp; sắp tới</a>
            <a href="{{ route('teacher.trial-guests', ['scope' => 'past']) }}" class="px-3 py-1.5 rounded-lg font-semibold {{ $scope === 'past' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Đã diễn ra</a>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-x-auto">
            <table class="w-full text-xs text-left min-w-[960px]">
                <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                    <tr>
                        <th class="p-3 w-[200px]">Buổi học</th>
                        <th class="p-3 w-[200px]">Khách học thử</th>
                        <th class="p-3 w-[110px]">Trạng thái</th>
                        <th class="p-3">Nhận xét của giáo viên</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($bookings as $booking)
                        @php($started = $booking->sessionHasStarted())
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
                                <div class="text-gray-500">Kết quả test: {{ $booking->customer?->test_score ?? 'Chưa test' }}</div>
                                @if ($booking->notes)
                                    <div class="mt-1 text-gray-600">Ghi chú của Học vụ: {{ $booking->notes }}</div>
                                @endif
                            </td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded-full font-bold {{ $booking->status === 'attended' ? 'bg-emerald-50 text-emerald-700' : ($booking->status === 'no_show' ? 'bg-rose-50 text-rose-700' : 'bg-sky-50 text-sky-700') }}">{{ $booking->status_label }}</span>
                            </td>
                            <td class="p-3">
                                @if ($booking->feedback_at)
                                    <div class="text-gray-700 space-y-0.5 mb-2">
                                        @if ($booking->rating)<div><span class="font-bold">{{ $booking->rating }}/5</span>@if ($booking->remarksSummary() !== '') · {{ $booking->remarksSummary() }}@endif</div>@endif
                                        <div>{{ $booking->feedback ?: '—' }}</div>
                                        <div class="text-[11px] text-gray-400">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at->format('d/m/Y H:i') }}</div>
                                    </div>
                                @endif
                                @if ($started)
                                    <form action="{{ route('teacher.trial-guests.feedback', $booking) }}" method="POST" class="space-y-2">
                                        @csrf
                                        <div class="flex flex-wrap gap-2">
                                            <select name="status" class="rounded-lg border-gray-200 text-xs">
                                                <option value="attended" @selected($booking->status !== 'no_show')>Có mặt (đã học thử)</option>
                                                <option value="no_show" @selected($booking->status === 'no_show')>Vắng mặt</option>
                                            </select>
                                            <select name="rating" class="rounded-lg border-gray-200 text-xs" title="Mức độ phù hợp với lớp">
                                                @foreach ([5, 4, 3, 2, 1] as $rating)
                                                    <option value="{{ $rating }}" @selected((int) ($booking->rating ?? 4) === $rating)>{{ $rating }}/5</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2">
                                            @foreach (\App\Models\CrmTrialBooking::REMARK_FIELDS as $key => $label)
                                                <label class="block">
                                                    <span class="text-[10px] font-semibold uppercase text-gray-500">{{ $label }}</span>
                                                    <input type="text" name="remarks[{{ $key }}]" value="{{ $booking->remarks[$key] ?? '' }}" maxlength="255" class="w-full rounded-lg border-gray-200 text-xs" placeholder="{{ ['grammar' => 'Khá', 'attitude' => 'Hăng hái', 'result' => 'Đạt mục tiêu'][$key] }}">
                                                </label>
                                            @endforeach
                                        </div>
                                        <textarea name="feedback" rows="2" maxlength="3000" placeholder="Nhận xét chi tiết: mức độ phù hợp với lớp, tương tác, đề xuất..." class="w-full rounded-lg border-gray-200 text-xs">{{ $booking->feedback }}</textarea>
                                        <x-ui.button type="submit" size="sm" icon="rate_review">Lưu nhận xét</x-ui.button>
                                    </form>
                                @else
                                    <p class="text-gray-400 italic">Nhận xét được mở từ ngày học thử.</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-gray-400">{{ $scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Không có khách học thử sắp tới trên buổi dạy của bạn.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $bookings->links() }}
    </div>
</x-app-layout>
