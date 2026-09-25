<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.big-tests.distribution') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">analytics</span>
                        Bảng Điểm &amp; Kết Quả Khảo Thí Big Test
                    </h1>
                    <p class="text-xs text-gray-500 mt-0.5">Theo dõi điểm số 4 kỹ năng (Nghe, Nói, Đọc, Viết) và đánh giá tiến độ của học viên</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if($test)
                    @can('syllabus.approve_adjustment')
                        <form method="POST" action="{{ route('syllabus.big-tests.results.approve', $test->id) }}">@csrf
                            <button class="px-3.5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-semibold">Duyệt kết quả</button>
                        </form>
                        <form method="POST" action="{{ route('syllabus.big-tests.send-zalo', $test->id) }}">@csrf
                            <button class="px-3.5 py-2 bg-blue-600 text-white rounded-xl text-xs font-semibold">Gửi kết quả đã duyệt</button>
                        </form>
                    @endcan
                @endif
                <button type="button" onclick="window.print();" class="px-3.5 py-2 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-xl text-xs font-semibold shadow-2xs transition flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px] text-gray-500">print</span>
                    <span>In bảng điểm</span>
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        <!-- 1. Test Filter & Quick Stats -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <!-- Test Selector Card -->
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 space-y-3">
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Chọn Kỳ Thi Big Test:</label>
                <div class="flex items-center gap-2">
                    <select onchange="window.location.href='{{ route('syllabus.big-tests.results') }}/' + this.value" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-semibold text-gray-900 bg-white focus:ring-primary-container focus:border-primary-container">
                        @foreach ($allTests as $t)
                            <option value="{{ $t->id }}" {{ $test?->id === $t->id ? 'selected' : '' }}>
                                [{{ $t->code }}] {{ $t->title }} · {{ $t->classModel?->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @if ($test)
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-500 font-mono pt-1">
                        <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 font-bold">Lớp: {{ $test->classModel?->name }}</span>
                        <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">Mã: {{ $test->code }}</span>
                        <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">Phòng: {{ $test->room }}</span>
                        @if ($test->stage)
                            <span class="px-2 py-0.5 rounded-md bg-purple-50 text-purple-700 font-bold" title="Duyệt và gửi đủ kết quả cho phụ huynh sẽ đóng chặng này và tự mở chặng kế tiếp">Big Test cuối {{ $test->stage->label }}{{ $test->results_completed_at ? ' · đã hoàn tất' : '' }}</span>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Stats Summary 1 -->
            @php
                $totalCount = $results->count();
                $taken = $results->where('is_absent', false)->whereNotNull('overall_score');
                $takenCount = $taken->count();
                $absentCount = $results->where('is_absent', true)->count();
                $avgOverall = $takenCount > 0 ? round($taken->avg('overall_score'), 1) : 0;
                $highestScore = $takenCount > 0 ? $taken->max('overall_score') : 0;
            @endphp
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 flex items-center justify-between">
                <div>
                    <div class="text-[11px] font-bold text-gray-500 uppercase">Điểm Trung Bình Cả Lớp</div>
                    <div class="text-2xl font-black text-indigo-600 font-mono mt-1">{{ $avgOverall }} <span class="text-xs font-normal text-gray-400">/ 10</span></div>
                    <div class="text-[10px] text-gray-400 mt-0.5">Dựa trên {{ $takenCount }} học viên dự thi ({{ $absentCount }} vắng)</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">award_star</span>
                </div>
            </div>

            <!-- Stats Summary 2 -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 flex items-center justify-between">
                <div>
                    <div class="text-[11px] font-bold text-gray-500 uppercase">Điểm Cao Nhất (Top Score)</div>
                    <div class="text-2xl font-black text-emerald-600 font-mono mt-1">
                        {{ $highestScore }} <span class="text-xs font-normal text-gray-400">/ 10</span>
                    </div>
                    <div class="text-[10px] text-emerald-600 font-semibold mt-0.5">
                        Tổng số thí sinh: {{ $totalCount }} học viên
                    </div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">military_tech</span>
                </div>
            </div>
        </div>

        <!-- 2. Results Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs overflow-hidden">
            <div class="p-4 bg-slate-50/70 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Danh Sách Bảng Điểm Chi Tiết ({{ $results->count() }} Học viên)</h2>
                    <p class="text-[11px] text-gray-500">Kết quả khảo thí định kỳ được lưu trữ phục vụ xếp lớp và đánh giá năng lực</p>
                </div>
            </div>

            @php($resultsByStudent = $results->keyBy('student_id'))
            @php($canGrade = $test && auth()->user()->can('syllabus.update'))
            @php($canSend = $test && auth()->user()->can('syllabus.approve_adjustment'))
            @if ($canSend)
                {{-- Form gửi từng học viên nằm ngoài form nhập điểm (không lồng form); nút bấm tham chiếu qua thuộc tính form= --}}
                @foreach ($results as $res)
                    @if ($res->status === 'approved' && ! $res->parent_notified && ! $res->is_absent)
                        <form id="send-ph-{{ $res->id }}" method="POST" action="{{ route('syllabus.big-tests.send-single-zalo', $res->id) }}" class="hidden">@csrf</form>
                    @endif
                @endforeach
            @endif
            @if($canGrade)
            <form method="POST" action="{{ route('syllabus.big-tests.results.store', $test->id) }}">
                @csrf
            @endif
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1100px]">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Học viên &amp; Mã số</th>
                            <th class="py-3 px-2 text-center">Vắng thi</th>
                            <th class="py-3 px-3 text-center">Listening</th>
                            <th class="py-3 px-3 text-center">Reading</th>
                            <th class="py-3 px-3 text-center">Writing</th>
                            <th class="py-3 px-3 text-center">Speaking</th>
                            <th class="py-3 px-4 text-center bg-orange-50/60 text-orange-950">Overall</th>
                            <th class="py-3 px-4">Nhận xét &amp; link video bài thi</th>
                            <th class="py-3 px-4">Trạng thái</th>
                            <th class="py-3 px-4">Đã gửi PH</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($students as $index => $student)
                            @php($res = $resultsByStudent->get($student->id))
                            @php($locked = ! $canGrade || ($res?->isLocked() ?? false))
                            @php($absent = (bool) old("results.$index.is_absent", $res?->is_absent))
                            <tr class="hover:bg-blue-50/30 transition" x-data="{ absent: @js($absent) }">
                                <td class="py-3.5 px-4">
                                    <input type="hidden" name="results[{{ $index }}][student_id]" value="{{ $student->id }}" @disabled($locked)>
                                    <div class="font-bold text-gray-900">{{ $student->name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono mt-0.5">Mã HV: {{ $student->code ?? 'HV-' . $student->id }}</div>
                                </td>
                                <td class="py-3.5 px-2 text-center">
                                    <input type="checkbox" name="results[{{ $index }}][is_absent]" value="1" x-model="absent" @checked($absent) @disabled($locked)
                                           class="rounded border-gray-300 text-rose-600 focus:ring-rose-500 h-4 w-4" title="Đánh dấu học viên vắng thi">
                                </td>
                                @foreach (['listening_score', 'reading_score', 'writing_score', 'speaking_score'] as $skill)
                                    <td class="py-3.5 px-3">
                                        <input type="number" step=".1" min="0" max="10" name="results[{{ $index }}][{{ $skill }}]" value="{{ old("results.$index.$skill", $res?->$skill) }}" placeholder="—"
                                               @disabled($locked) :disabled="absent || @js($locked)" class="w-16 rounded border-gray-200 text-xs disabled:bg-gray-50">
                                    </td>
                                @endforeach
                                <td class="py-3.5 px-4 text-center font-mono font-black text-orange-600 bg-orange-50/40 text-base">
                                    @if ($res?->is_absent)
                                        <span class="text-xs font-bold text-rose-600">Vắng thi</span>
                                    @else
                                        {{ $res?->overall_score ?? '—' }}
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 space-y-1 min-w-[220px]">
                                    <textarea name="results[{{ $index }}][progress_note]" rows="2" @disabled($locked) placeholder="Nhận xét tiến độ" class="w-full rounded border-gray-200 text-xs disabled:bg-gray-50">{{ old("results.$index.progress_note", $res?->progress_note) }}</textarea>
                                    @if ($locked)
                                        @if ($res?->video_url)
                                            <a href="{{ $res->video_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11px] text-primary font-semibold hover:underline">
                                                <span class="material-symbols-outlined text-[14px]">video_library</span>Link video bài thi
                                            </a>
                                        @endif
                                    @else
                                        <input type="url" name="results[{{ $index }}][video_url]" value="{{ old("results.$index.video_url", $res?->video_url) }}" placeholder="Link video bài thi (https://...)" class="w-full rounded border-gray-200 text-xs">
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <x-ui.badge :color="match ($res?->status) { 'approved' => 'success', 'sent' => 'info', 'pending_review' => 'warning', default => 'neutral' }">{{ $res?->status_label ?? 'Chưa nhập' }}</x-ui.badge>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if ($res?->parent_notified)
                                        <span class="inline-flex items-center gap-1 text-emerald-700 font-semibold text-[11px]">
                                            <span class="material-symbols-outlined text-[16px]">mark_email_read</span>{{ $res->notified_at?->format('d/m H:i') ?? 'Đã gửi' }}
                                        </span>
                                    @elseif ($canSend && $res?->status === 'approved' && ! $res->is_absent)
                                        <button type="submit" form="send-ph-{{ $res->id }}" class="px-2.5 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[14px]">send</span>Gửi PH
                                        </button>
                                    @else
                                        <span class="text-[11px] text-gray-400">{{ $res?->is_absent ? 'Vắng thi' : 'Chưa gửi' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-10 text-gray-400 text-xs">
                                    <div class="flex flex-col items-center justify-center space-y-2">
                                        <span class="material-symbols-outlined text-4xl text-gray-300">sentiment_neutral</span>
                                        <span>Chưa có kết quả thi cho kỳ thi Big Test này.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($canGrade)
                @if($students->contains(fn ($s) => ! ($resultsByStudent->get($s->id)?->isLocked() ?? false)))
                    <div class="p-4 border-t flex items-center justify-between gap-3">
                        <span class="text-[11px] text-gray-500">Học viên vắng: tích "Vắng thi" (không nhập điểm). Dòng để trống sẽ bỏ qua. Điểm đã duyệt/đã gửi phụ huynh không thể sửa.</span>
                        <button class="px-4 py-2 bg-primary-container text-white rounded-xl text-xs font-bold">Lưu điểm chờ duyệt</button>
                    </div>
                @endif
            </form>
            @endif
        </div>

    </div>
</x-app-layout>
