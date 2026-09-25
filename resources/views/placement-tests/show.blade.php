<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('placement-tests.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 font-mono font-bold text-[11px]">{{ $test->code }}</span>
                        @if ($test->is_preset)
                            <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[12px]">lock</span>
                                <span>Đề mẫu hệ thống (Khóa sửa)</span>
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-md bg-teal-50 text-teal-700 border border-teal-200 text-[10px] font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-[12px]">edit</span>
                                <span>Đề tạo tay (Tùy biến)</span>
                            </span>
                        @endif
                    </div>
                    <h1 class="text-lg font-bold text-gray-900 tracking-tight mt-0.5">{{ $test->title }}</h1>
                </div>
            </div>

            <!-- Compact Sleek Actions Menu -->
            <div class="flex items-center gap-1.5 flex-wrap">
                <!-- Copy Portal Link -->
                <button type="button" onclick="navigator.clipboard.writeText('{{ route('portal.test.take', $test->code) }}'); alert('Đã sao chép link làm bài thi: {{ route('portal.test.take', $test->code) }}');" class="px-2.5 py-1.5 rounded-lg bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-semibold transition flex items-center gap-1 shadow-2xs">
                    <span class="material-symbols-outlined text-[15px] text-gray-500">content_copy</span>
                    <span>Sao chép Link</span>
                </button>

                <!-- Open Portal -->
                <a href="{{ route('portal.test.take', $test->code) }}" target="_blank" class="px-2.5 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-700 text-white text-xs font-semibold shadow-2xs transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                    <span>Cổng làm bài</span>
                </a>

                @if (!$test->is_preset)
                    <a href="{{ route('placement-tests.edit', $test->id) }}" class="px-2.5 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-semibold shadow-2xs transition flex items-center gap-1">
                        <span class="material-symbols-outlined text-[15px]">edit</span>
                        <span>Sửa đề</span>
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Overview Stats Card -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-3.5 bg-white rounded-xl border border-gray-200 shadow-2xs space-y-0.5">
                <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Cấp độ mục tiêu</div>
                <div class="text-sm font-extrabold text-gray-900 truncate">{{ $test->target_level }}</div>
            </div>
            <div class="p-3.5 bg-white rounded-xl border border-gray-200 shadow-2xs space-y-0.5">
                <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Thời lượng làm bài</div>
                <div class="text-sm font-extrabold text-indigo-700 font-mono">{{ $test->duration_minutes }} phút</div>
            </div>
            <div class="p-3.5 bg-white rounded-xl border border-gray-200 shadow-2xs space-y-0.5">
                <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Tổng số câu hỏi</div>
                <div class="text-sm font-extrabold text-orange-600 font-mono">{{ $test->questions_count }} câu</div>
            </div>
            <a href="#submissions-list" class="p-3.5 bg-white hover:bg-emerald-50/50 rounded-xl border border-gray-200 hover:border-emerald-300 shadow-2xs space-y-0.5 transition block group">
                <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider flex items-center justify-between">
                    <span>Lượt thí sinh đã thi</span>
                    <span class="material-symbols-outlined text-[14px] text-emerald-600 group-hover:translate-y-0.5 transition">arrow_downward</span>
                </div>
                <div class="text-sm font-extrabold text-emerald-600 font-mono flex items-center gap-1">
                    <span>{{ $test->submissions->count() }} lượt</span>
                    <span class="text-[11px] font-normal text-gray-500">(Bấm xem điểm)</span>
                </div>
            </a>
        </div>

        <!-- Candidate Submissions & Scores Table for this Test -->
        <div id="submissions-list" class="bg-white rounded-2xl border border-gray-200 shadow-2xs overflow-hidden scroll-mt-6">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-white">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-xs text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-emerald-600 text-base">fact_check</span>
                        <span>Danh Sách Thí Sinh Đã Thi Bộ Đề Này ({{ $test->submissions->count() }})</span>
                    </span>
                </div>
                <span class="text-[11px] text-gray-400">Điểm số chi tiết 4 kỹ năng &amp; Khóa học xếp lớp</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Thí sinh</th>
                            <th class="py-3 px-4">Số điện thoại</th>
                            <th class="py-3 px-4 text-center">Listening</th>
                            <th class="py-3 px-4 text-center">Reading</th>
                            <th class="py-3 px-4 text-center">Writing</th>
                            <th class="py-3 px-4 text-center">Speaking</th>
                            <th class="py-3 px-4 text-center">Overall (Band)</th>
                            <th class="py-3 px-4">Khóa học đề xuất</th>
                            <th class="py-3 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($test->submissions as $sub)
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-3 px-4">
                                    <div class="font-bold text-gray-900 flex items-center gap-1.5">
                                        <span>{{ $sub->candidate_name }}</span>
                                        @if ($sub->customer)
                                            <a href="{{ route('crm.customers.show', $sub->customer->id) }}" class="px-1.5 py-0.2 rounded bg-blue-50 hover:bg-blue-100 text-blue-700 font-normal text-[10px] inline-flex items-center gap-0.5" title="Mở hồ sơ Lead trong CRM">
                                                <span>Lead CRM</span>
                                                <span class="material-symbols-outlined text-[11px]">open_in_new</span>
                                            </a>
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5">
                                        {{ $sub->created_at ? $sub->created_at->format('H:i d/m/Y') : 'Vừa xong' }}
                                    </div>
                                </td>
                                <td class="py-3 px-4 font-mono text-gray-600">{{ $sub->candidate_phone }}</td>
                                <td class="py-3 px-4 text-center font-mono font-bold text-indigo-600">{{ $sub->listening_score }}</td>
                                <td class="py-3 px-4 text-center font-mono font-bold text-emerald-600">{{ $sub->reading_score }}</td>
                                <td class="py-3 px-4 text-center font-mono font-bold text-purple-600">{{ $sub->writing_score }}</td>
                                <td class="py-3 px-4 text-center font-mono font-bold text-rose-600">{{ $sub->speaking_score }}</td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-2 py-0.5 rounded-full bg-orange-100 text-orange-800 font-mono font-black text-xs">
                                        @if ($sub->isPending()) Chờ chấm @else {{ $sub->overall_score ?? '—' }} ({{ $sub->cefr_level ?? '—' }}) @endif
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-semibold text-orange-600">{{ $sub->recommended_course }}</td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $sub->id]) }}" target="_blank" class="px-2 py-1 rounded-lg bg-orange-50 hover:bg-orange-100 text-orange-700 font-bold text-[11px] transition inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[13px]">description</span>
                                            <span>Phiếu điểm</span>
                                        </a>
                                        <a href="{{ route('placement-tests.results.show', $sub->id) }}" class="px-2 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-[11px] transition inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[13px]">edit_note</span>
                                            <span>{{ $sub->isPending() ? 'Chấm bài' : 'Chấm lại' }}</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-8 text-gray-400 text-xs">
                                    Chưa có thí sinh nào nộp bài thi cho bộ đề này.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Question List View -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 md:p-6 space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                <div>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-indigo-600 text-base">format_list_numbered</span>
                        <span>Chi Tiết Toàn Bộ Câu Hỏi &amp; Đáp Án Chuẩn (Answer Keys)</span>
                    </h2>
                    <p class="text-[11px] text-gray-500">Giáo viên và Học vụ có thể xem trước nội dung, hình ảnh, audio và đáp án đối soát.</p>
                </div>
            </div>

            @php
                $questions = is_array($test->questions) ? $test->questions : [];
            @endphp

            <div class="space-y-5">
                @forelse ($questions as $idx => $q)
                    <div class="p-4 rounded-xl border border-gray-200/90 bg-gray-50/40 space-y-3">
                        <!-- Question Header -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded-md bg-slate-900 text-white font-mono font-bold text-[11px]">Câu {{ $idx + 1 }}</span>
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-bold {{ match($q['skill'] ?? '') {
                                    'listening' => 'bg-indigo-50 text-indigo-700 border border-indigo-200',
                                    'reading' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
                                    'grammar' => 'bg-blue-50 text-blue-700 border border-blue-200',
                                    'writing' => 'bg-purple-50 text-purple-700 border border-purple-200',
                                    'speaking' => 'bg-rose-50 text-rose-700 border border-rose-200',
                                    default => 'bg-gray-100 text-gray-700'
                                } }}">
                                    {{ ucfirst($q['skill'] ?? 'General') }}
                                </span>
                                <span class="text-[11px] text-gray-400 font-mono">({{ $q['type'] ?? 'multiple_choice' }})</span>
                            </div>
                            <span class="text-xs font-mono font-semibold text-gray-500">{{ $q['points'] ?? 1 }} điểm</span>
                        </div>

                        <!-- Question Title -->
                        <div class="text-xs md:text-sm font-bold text-gray-900 leading-snug">
                            {{ $q['title'] ?? '' }}
                        </div>

                        <!-- Passage if exists -->
                        @if (!empty($q['passage']))
                            <div class="p-3 bg-emerald-50/60 border border-emerald-100 rounded-xl text-xs leading-relaxed text-slate-800 font-medium italic">
                                <strong>Đoạn văn / Ngữ cảnh:</strong> "{{ $q['passage'] }}"
                            </div>
                        @endif

                        <!-- Audio player if exists -->
                        @if (!empty($q['audio_url']))
                            <div class="p-3 bg-gradient-to-r from-indigo-50/90 to-blue-50/90 border border-indigo-200/80 rounded-xl space-y-2 shadow-2xs">
                                <div class="flex items-center justify-between text-[11px] font-bold text-indigo-900">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base text-indigo-600">volume_up</span>
                                        <span>File Audio Listening: {{ basename($q['audio_url']) }}</span>
                                    </div>
                                    <a href="{{ $q['audio_url'] }}" target="_blank" class="text-[10px] text-indigo-600 hover:underline flex items-center gap-0.5 font-normal">
                                        <span>Tải file</span>
                                        <span class="material-symbols-outlined text-[12px]">download</span>
                                    </a>
                                </div>
                                <audio controls class="w-full h-8" preload="none">
                                    <source src="{{ $q['audio_url'] }}" type="audio/mpeg">
                                    Trình duyệt không hỗ trợ audio.
                                </audio>
                            </div>
                        @endif

                        <!-- Illustration Image if exists -->
                        @if (!empty($q['image_url']))
                            <div class="my-2 p-2 bg-white rounded-xl border border-gray-200 max-w-md mx-auto">
                                <img src="{{ $q['image_url'] }}" alt="Question illustration" class="max-h-56 mx-auto object-contain rounded-lg">
                            </div>
                        @endif

                        <!-- Options / Choices -->
                        @if (!empty($q['options']))
                            <div class="grid grid-cols-1 {{ count($q['options']) > 2 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2' }} gap-2 text-xs">
                                @foreach ($q['options'] as $opt)
                                    @php
                                        $isCorrect = (string)($opt['key'] ?? '') === (string)($q['correct_answer'] ?? '');
                                    @endphp
                                    <div class="p-2.5 rounded-xl border {{ $isCorrect ? 'bg-emerald-50 border-emerald-300 ring-2 ring-emerald-200' : 'bg-white border-gray-200' }} flex flex-col justify-between">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="flex items-center gap-1.5">
                                                <span class="w-4.5 h-4.5 rounded-full {{ $isCorrect ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700' }} font-bold text-[11px] flex items-center justify-center font-mono shrink-0">
                                                    {{ $opt['key'] }}
                                                </span>
                                                <span class="font-medium text-gray-800 text-xs">{{ $opt['text'] }}</span>
                                            </div>
                                            @if ($isCorrect)
                                                <span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-emerald-600 text-white flex items-center gap-0.5">
                                                    <span class="material-symbols-outlined text-[11px]">check</span>
                                                    <span>Đúng</span>
                                                </span>
                                            @endif
                                        </div>
                                        @if (!empty($opt['image_url']))
                                            <img src="{{ $opt['image_url'] }}" alt="{{ $opt['key'] }}" class="max-h-28 rounded-lg border border-gray-100 object-contain bg-gray-50 p-1 mx-auto mt-1.5">
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <!-- Fill blank answer or Explanation -->
                        @if (!empty($q['correct_answer']))
                            <div class="p-2.5 bg-amber-50/70 border border-amber-200 rounded-xl text-xs flex items-center justify-between">
                                <div>
                                    <span class="font-bold text-amber-900">Đáp án chuẩn (Key):</span>
                                    <span class="font-mono font-black text-amber-800 text-xs ml-1">{{ $q['correct_answer'] }}</span>
                                    @if (!empty($q['explanation']))
                                        <span class="text-gray-500 italic ml-2 text-[11px]">({{ $q['explanation'] }})</span>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <!-- Speaking Cue Points -->
                        @if (!empty($q['cue_points']))
                            <div class="p-2.5 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-950 whitespace-pre-line font-mono">
                                <strong>Gợi ý phỏng vấn Speaking:</strong>
                                {{ $q['cue_points'] }}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-400 text-xs">
                        Đề thi chưa có câu hỏi nào.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
