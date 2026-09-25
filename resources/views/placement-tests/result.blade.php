<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('placement-tests.index') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="font-h1 text-h1 text-on-surface">Chi tiết bài làm &amp; chấm điểm</h1>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-orange-100 text-primary-container border border-orange-200">
                            {{ $submission->scoreSummary() ?? 'Chưa có điểm' }}
                        </span>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase border {{ $submission->isPending() ? 'bg-sky-50 text-sky-700 border-sky-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200' }}">
                            {{ $submission->isPending() ? 'Chờ chấm' : 'Đã chấm điểm' }}
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 font-mono mt-0.5">
                        {{ $submission->test?->title ?? 'Đề Test Đầu Vào MEnglish' }} · Thí sinh: <strong class="text-gray-800">{{ $submission->candidate_name }}</strong> · SĐT: {{ $submission->candidate_phone }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-wrap shrink-0">
                @if ($submission->customer_id)
                    <a href="{{ route('crm.customers.show', $submission->customer_id) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-bold transition shadow-2xs">
                        <span class="material-symbols-outlined text-[16px] text-indigo-600">person</span>
                        <span>Hồ sơ khách</span>
                    </a>
                @endif
                <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition shadow-xs">
                    <span class="material-symbols-outlined text-[16px] text-amber-400">military_tech</span>
                    <span>Bảng điểm (Scorecard)</span>
                </a>
                <a href="{{ route('placement-tests.rubric-guide') }}" class="inline-flex items-center gap-1 px-3 py-2 rounded-xl bg-orange-50 border border-orange-200 hover:bg-orange-100 text-primary-container text-xs font-bold transition">
                    <span class="material-symbols-outlined text-[16px]">menu_book</span>
                    <span>Thang điểm &amp; hướng dẫn nhận xét</span>
                </a>
            </div>
        </div>
    </x-slot>


    <div class="max-w-6xl mx-auto space-y-6" x-data="placementResultEngine({
        questions: {{ Js::from($questions) }},
        answers: {{ Js::from($submission->answers ?? []) }},
        writingContent: {{ Js::from($submission->writing_content ?? '') }}
    })">

        <!-- ────────────────────────────────────────────── -->
        <!-- 1. BẢNG TỔNG HỢP ĐIỂM & FORM CHẤM NHANH -->
        <!-- ────────────────────────────────────────────── -->
        <form action="{{ route('placement-tests.results.update', $submission->id) }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf

            <div class="flex items-center justify-between border-b border-gray-100 pb-3 flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary-container text-xl">fact_check</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Chấm điểm theo thang điểm khối lớp</h2>
                </div>
                <span class="px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-bold">Tổng = Nghe + Đọc &amp; Viết + Nói → lớp đề xuất</span>
                <div class="text-xs text-gray-500 font-mono">
                    Nộp bài lúc: {{ $submission->created_at ? $submission->created_at->format('H:i, d/m/Y') : date('H:i, d/m/Y') }}
                </div>
            </div>

            @if ($submission->isPending() && ($submission->listening_score !== null || $submission->reading_score !== null))
                <div class="p-3 rounded-xl bg-sky-50 border border-sky-200 text-xs text-sky-900">
                    Điểm tự chấm từ bài online (đã quy về thang của khối): Nghe {{ $submission->listening_score ?? '—' }} · Phần Đọc trắc nghiệm {{ $submission->reading_score ?? '—' }}.
                    Học vụ kiểm tra lại, cộng phần Viết vào ô Đọc &amp; Viết và nhập điểm Nói.
                </div>
            @endif

            <div class="max-w-3xl">
                @include('placement-tests.partials.rubric-score-fields', [
                    'submission' => $submission,
                    'defaultGroup' => $submission->resolvedGradeGroup(),
                ])
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-gray-100 flex-wrap gap-3">
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    <span class="material-symbols-outlined text-[16px] text-primary">info</span>
                    <span>Khi lưu điểm, hệ thống tính tổng điểm, tra lớp đề xuất và đồng bộ kết quả sang hồ sơ khách CRM.</span>
                </div>
                <div class="flex items-center gap-sm">
                    @if ($submission->isPending())
                        <button type="submit" name="action" value="draft" class="rounded-lg border border-outline-variant px-lg py-sm font-body-medium text-body-medium text-on-surface hover:bg-surface-container-low">Lưu bản nháp</button>
                    @endif
                    <button type="submit" name="action" value="confirm" class="inline-flex items-center gap-xs rounded-lg bg-primary-container px-lg py-sm font-body-medium text-body-medium text-white shadow-sm hover:bg-primary">
                        <span class="material-symbols-outlined text-[18px]">check</span>Xác nhận kết quả
                    </button>
                </div>
            </div>
        </form>

        <!-- ────────────────────────────────────────────── -->
        <!-- 2. BẢNG ĐỐI CHIẾU CÂU HỎI, ĐÁP ÁN CHỌN & ĐÁP ÁN ĐÚNG -->
        <!-- ────────────────────────────────────────────── -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden space-y-4 p-6">
            
            <!-- Section Header & Filter Toolbar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-gray-100 pb-4">
                <div>
                    <h2 class="text-base font-black text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-indigo-600">checklist_rtl</span>
                        <span>Đối Chiếu Chi Tiết Từng Câu Hỏi &amp; Đáp Án Thí Sinh Đã Chọn</span>
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Xem chi tiết lựa chọn của thí sinh so với đáp án chuẩn của đề, phân tích lỗi sai và lời giải thích.
                    </p>
                </div>

                <!-- Stats Quick Badges -->
                <div class="flex items-center gap-2 shrink-0 flex-wrap">
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-slate-100 text-slate-800 border border-slate-200 font-mono">
                        Tổng: <span x-text="questions.length"></span> câu
                    </span>
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span>
                        <span>Đúng: <strong x-text="correctCount"></strong></span>
                    </span>
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 font-mono flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">cancel</span>
                        <span>Sai: <strong x-text="incorrectCount"></strong></span>
                    </span>
                </div>
            </div>

            <!-- Filter Buttons -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
                <button type="button" @click="currentFilter = 'all'" :class="currentFilter === 'all' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    Tất Cả (<span x-text="questions.length"></span>)
                </button>
                <button type="button" @click="currentFilter = 'listening'" :class="currentFilter === 'listening' ? 'bg-indigo-600 text-white font-bold' : 'bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    🎧 Listening
                </button>
                <button type="button" @click="currentFilter = 'reading'" :class="currentFilter === 'reading' ? 'bg-emerald-600 text-white font-bold' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    📖 Reading &amp; Grammar
                </button>
                <button type="button" @click="currentFilter = 'writing'" :class="currentFilter === 'writing' ? 'bg-amber-600 text-white font-bold' : 'bg-amber-50 hover:bg-amber-100 text-amber-700 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    ✍️ Writing
                </button>
                <button type="button" @click="currentFilter = 'speaking'" :class="currentFilter === 'speaking' ? 'bg-rose-600 text-white font-bold' : 'bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    🗣️ Speaking
                </button>
                <button type="button" @click="currentFilter = 'correct'" :class="currentFilter === 'correct' ? 'bg-emerald-700 text-white font-bold' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    ✓ Câu Đúng
                </button>
                <button type="button" @click="currentFilter = 'incorrect'" :class="currentFilter === 'incorrect' ? 'bg-rose-700 text-white font-bold' : 'bg-rose-50 hover:bg-rose-100 text-rose-800 font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    ✗ Câu Sai
                </button>
            </div>

            <!-- Questions List -->
            <div class="space-y-5 pt-2">
                @foreach ($questions as $idx => $q)
                    @php
                        $qId = $q['id'] ?? ($idx + 1);
                        $qSkill = $q['skill'] ?? 'general';
                        $qType = $q['type'] ?? 'multiple_choice';
                        $correctAnswer = trim((string)($q['correct_answer'] ?? ''));
                        
                        // Lấy câu trả lời của thí sinh
                        $submissionAnswers = $submission->answers ?? [];
                        $candidateAnswer = trim((string)($submissionAnswers[$qId] ?? $submissionAnswers['q' . $qId] ?? ($submissionAnswers[$idx] ?? '')));
                        
                        if ($qSkill === 'writing' && empty($candidateAnswer)) {
                            $candidateAnswer = $submission->writing_content;
                        }

                        $isObjective = in_array($qType, ['multiple_choice', 'fill_blank', 'single_choice']);
                        $isCorrect = $isObjective && !empty($candidateAnswer) && !empty($correctAnswer) && (strcasecmp($candidateAnswer, $correctAnswer) === 0);
                        $isIncorrect = $isObjective && !empty($candidateAnswer) && !empty($correctAnswer) && !$isCorrect;
                    @endphp

                    <div class="p-5 rounded-2xl border transition-all duration-200 {{ $isCorrect ? 'bg-emerald-50/20 border-emerald-200' : ($isIncorrect ? 'bg-rose-50/20 border-rose-200' : 'bg-slate-50/40 border-gray-200') }}"
                         x-show="shouldShowQuestion('{{ $qSkill }}', {{ $isCorrect ? 'true' : 'false' }}, {{ $isIncorrect ? 'true' : 'false' }})">

                        <!-- Question Header -->
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100 flex-wrap gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="w-7 h-7 rounded-lg bg-slate-900 text-white font-black text-xs flex items-center justify-center font-mono">
                                    #{{ $idx + 1 }}
                                </span>

                                <!-- Skill Badge -->
                                @if ($qSkill === 'listening')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800 border border-indigo-200 uppercase">
                                        🎧 Listening
                                    </span>
                                @elseif ($qSkill === 'reading')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 uppercase">
                                        📖 Reading
                                    </span>
                                @elseif ($qSkill === 'grammar')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-200 uppercase">
                                        🔤 Grammar
                                    </span>
                                @elseif ($qSkill === 'writing')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200 uppercase">
                                        ✍️ Writing Task
                                    </span>
                                @elseif ($qSkill === 'speaking')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-200 uppercase">
                                        🗣️ Speaking Prompt
                                    </span>
                                @endif

                                <!-- Type Badge -->
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-medium bg-gray-100 text-gray-600 border border-gray-200">
                                    {{ $qType === 'multiple_choice' ? 'Trắc nghiệm 4 lựa chọn' : ($qType === 'fill_blank' ? 'Điền từ vào chỗ trống' : ($qType === 'essay' ? 'Tự luận Writing' : 'Phỏng vấn Speaking')) }}
                                </span>
                            </div>

                            <!-- Accuracy / Points Badge -->
                            <div class="flex items-center gap-2">
                                @if ($isCorrect)
                                    <span class="px-3 py-1 rounded-xl text-xs font-black bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[15px] text-emerald-600">check_circle</span>
                                        <span>CHÍNH XÁC (+{{ $q['points'] ?? 1 }}đ)</span>
                                    </span>
                                @elseif ($isIncorrect)
                                    <span class="px-3 py-1 rounded-xl text-xs font-black bg-rose-100 text-rose-800 border border-rose-300 flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[15px] text-rose-600">cancel</span>
                                        <span>CHƯA ĐÚNG (0đ)</span>
                                    </span>
                                @elseif ($isObjective && empty($candidateAnswer))
                                    <span class="px-2.5 py-1 rounded-xl text-xs font-semibold bg-gray-100 text-gray-500 border border-gray-200">
                                        Chưa có câu trả lời
                                    </span>
                                @endif
                                <span class="text-xs font-mono font-bold text-gray-400">({{ $q['points'] ?? 1 }} điểm)</span>
                            </div>
                        </div>

                        <!-- Audio Player if present -->
                        @if (!empty($q['audio_url']))
                            <div class="mt-3 p-3 bg-indigo-50/70 border border-indigo-200 rounded-xl flex items-center gap-3">
                                <span class="material-symbols-outlined text-indigo-700 text-xl">headphones</span>
                                <div class="flex-1">
                                    <span class="text-[11px] font-bold text-indigo-900 block mb-1">File Nghe Audio của câu hỏi:</span>
                                    <audio controls class="w-full h-8">
                                        <source src="{{ $q['audio_url'] }}" type="audio/mpeg">
                                        Trình duyệt không hỗ trợ audio player.
                                    </audio>
                                </div>
                            </div>
                        @endif

                        <!-- Passage / Context if present -->
                        @if (!empty($q['passage']))
                            <div class="mt-3 p-3.5 bg-slate-100/80 border border-slate-200 rounded-xl space-y-1">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Đoạn văn đọc hiểu / Bối cảnh:</span>
                                <p class="text-xs text-slate-800 leading-relaxed font-serif italic">{{ $q['passage'] }}</p>
                            </div>
                        @endif

                        <!-- Question Title -->
                        <div class="mt-3">
                            <h3 class="text-sm font-bold text-gray-900 leading-snug">
                                {{ $q['title'] ?? 'Câu hỏi trắc nghiệm' }}
                            </h3>
                        </div>

                        <!-- MULTIPLE CHOICE OPTIONS -->
                        @if ($qType === 'multiple_choice' && !empty($q['options']))
                            <div class="mt-3.5 space-y-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 block mb-1">Các lựa chọn &amp; Đối chiếu câu trả lời:</span>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    @foreach ($q['options'] as $opt)
                                        @php
                                            $optKey = $opt['key'] ?? '';
                                            $isThisCorrectAnswer = (strcasecmp($optKey, $correctAnswer) === 0);
                                            $isThisCandidateChoice = (strcasecmp($optKey, $candidateAnswer) === 0);
                                        @endphp

                                        <div class="p-3 rounded-xl border transition flex items-start justify-between gap-2.5
                                            @if ($isThisCorrectAnswer && $isThisCandidateChoice)
                                                bg-emerald-50 border-emerald-400 text-emerald-950 ring-2 ring-emerald-400/20 font-bold
                                            @elseif ($isThisCorrectAnswer)
                                                bg-emerald-50/60 border-emerald-300 text-emerald-900 font-bold
                                            @elseif ($isThisCandidateChoice)
                                                bg-rose-50 border-rose-300 text-rose-950 font-bold
                                            @else
                                                bg-white border-gray-200 text-gray-700
                                            @endif
                                        ">
                                            <div class="flex items-start gap-2.5">
                                                <span class="w-6 h-6 rounded-lg flex items-center justify-center font-black text-xs font-mono shrink-0
                                                    @if ($isThisCorrectAnswer)
                                                        bg-emerald-600 text-white
                                                    @elseif ($isThisCandidateChoice)
                                                        bg-rose-600 text-white
                                                    @else
                                                        bg-gray-100 text-gray-700
                                                    @endif
                                                ">
                                                    {{ $optKey }}
                                                </span>
                                                <span class="mt-0.5 leading-relaxed">{{ $opt['text'] ?? '' }}</span>
                                            </div>

                                            <!-- Badges on the right of each option -->
                                            <div class="shrink-0 flex flex-col items-end gap-1">
                                                @if ($isThisCandidateChoice && $isThisCorrectAnswer)
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-emerald-600 text-white flex items-center gap-1 shadow-2xs">
                                                        <span class="material-symbols-outlined text-[12px]">done_all</span>
                                                        <span>Thí sinh chọn (Đúng)</span>
                                                    </span>
                                                @elseif ($isThisCandidateChoice)
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-rose-600 text-white flex items-center gap-1 shadow-2xs">
                                                        <span class="material-symbols-outlined text-[12px]">close</span>
                                                        <span>Thí sinh chọn (Sai)</span>
                                                    </span>
                                                @elseif ($isThisCorrectAnswer)
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center gap-1">
                                                        <span class="material-symbols-outlined text-[12px]">check</span>
                                                        <span>Đáp án đúng của đề</span>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- FILL BLANK QUESTION -->
                        @if ($qType === 'fill_blank')
                            <div class="mt-3.5 p-3.5 bg-white border border-gray-200 rounded-xl space-y-2 text-xs">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="p-2.5 rounded-lg border {{ $isCorrect ? 'bg-emerald-50/60 border-emerald-200 text-emerald-950' : 'bg-rose-50/60 border-rose-200 text-rose-950' }}">
                                        <span class="text-[10px] font-bold uppercase tracking-wider block mb-1 text-gray-500">Câu trả lời của thí sinh:</span>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-black text-sm">{{ $candidateAnswer ?: '(Bỏ trống / Chưa điền)' }}</span>
                                            @if ($isCorrect)
                                                <span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span>
                                            @else
                                                <span class="material-symbols-outlined text-rose-600 text-base">cancel</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="p-2.5 bg-emerald-50/80 border border-emerald-300 rounded-lg text-emerald-950">
                                        <span class="text-[10px] font-bold uppercase tracking-wider block mb-1 text-emerald-800">Đáp án chuẩn của đề bài:</span>
                                        <span class="font-mono font-black text-sm text-emerald-700">{{ $correctAnswer }}</span>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <!-- ESSAY WRITING TASK -->
                        @if ($qSkill === 'writing' || $qType === 'essay')
                            <div class="mt-3.5 space-y-3">
                                <div class="p-4 bg-white border border-amber-200 rounded-xl space-y-2 shadow-2xs">
                                    <div class="flex items-center justify-between border-b border-amber-100 pb-2">
                                        <span class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-amber-600 text-[16px]">edit_document</span>
                                            <span>Toàn văn Bài làm Writing của Thí sinh:</span>
                                        </span>
                                        <span class="text-[11px] font-mono font-bold text-amber-800 bg-amber-100/60 px-2 py-0.5 rounded-md">
                                            {{ str_word_count($submission->writing_content ?? '') }} từ
                                        </span>
                                    </div>
                                    <div class="p-3 bg-amber-50/30 rounded-lg text-xs text-gray-800 leading-relaxed font-serif whitespace-pre-wrap">
                                        {{ $submission->writing_content ?: 'Thí sinh không nhập nội dung bài viết.' }}
                                    </div>
                                </div>

                                @if (!empty($q['rubric_note']))
                                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-[11px] text-slate-700 flex items-start gap-2">
                                        <span class="material-symbols-outlined text-amber-600 text-base shrink-0 mt-0.5">rule</span>
                                        <div>
                                            <strong class="text-slate-900">Hướng dẫn chấm Writing:</strong> {{ $q['rubric_note'] }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <!-- SPEAKING PROMPT TASK -->
                        @if ($qSkill === 'speaking' || $qType === 'speaking_prompt')
                            <div class="mt-3.5 space-y-3">
                                @if (!empty($q['cue_points']))
                                    <div class="p-3.5 bg-rose-50/50 border border-rose-200 rounded-xl space-y-1.5 text-xs">
                                        <span class="font-bold text-rose-950 uppercase text-[10px] block">Gợi ý chủ đề phỏng vấn (Cue Points):</span>
                                        <div class="text-rose-900 leading-relaxed whitespace-pre-line font-medium pl-1">
                                            {{ $q['cue_points'] }}
                                        </div>
                                    </div>
                                @endif

                                @if ($submission->speaking_audio_url)
                                    <div class="p-3 bg-white border border-rose-200 rounded-xl flex items-center gap-3">
                                        <span class="material-symbols-outlined text-rose-600 text-xl">mic</span>
                                        <div class="flex-1">
                                            <span class="text-xs font-bold text-gray-800 block mb-1">File ghi âm bài nói của thí sinh:</span>
                                            <audio controls class="w-full h-8">
                                                <source src="{{ $submission->speaking_audio_url }}" type="audio/mpeg">
                                            </audio>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <!-- EXPLANATION & TRANSCRIPT EVIDENCE -->
                        @if (!empty($q['explanation']))
                            <div class="mt-3 p-3 bg-amber-50/60 border border-amber-200/80 rounded-xl flex items-start gap-2 text-xs">
                                <span class="material-symbols-outlined text-primary-container text-base shrink-0 mt-0.5">lightbulb</span>
                                <div>
                                    <strong class="text-amber-950">Giải thích chi tiết &amp; Dẫn chứng bài làm:</strong>
                                    <p class="text-amber-900 leading-relaxed mt-0.5">{{ $q['explanation'] }}</p>
                                </div>
                            </div>
                        @endif

                    </div>
                @endforeach
            </div>

        </div>

    </div>

    <script>
        function placementResultEngine(data) {
            return {
                questions: data.questions,
                answers: data.answers,
                writingContent: data.writingContent,
                currentFilter: 'all',

                get correctCount() {
                    let count = 0;
                    this.questions.forEach((q, idx) => {
                        const qId = q.id || (idx + 1);
                        const userAns = (this.answers[qId] || this.answers['q' + qId] || this.answers[idx] || '').toString().trim();
                        const correctAns = (q.correct_answer || '').toString().trim();
                        if (['multiple_choice', 'fill_blank', 'single_choice'].includes(q.type) && userAns && correctAns && userAns.toLowerCase() === correctAns.toLowerCase()) {
                            count++;
                        }
                    });
                    return count;
                },

                get incorrectCount() {
                    let count = 0;
                    this.questions.forEach((q, idx) => {
                        const qId = q.id || (idx + 1);
                        const userAns = (this.answers[qId] || this.answers['q' + qId] || this.answers[idx] || '').toString().trim();
                        const correctAns = (q.correct_answer || '').toString().trim();
                        if (['multiple_choice', 'fill_blank', 'single_choice'].includes(q.type) && userAns && correctAns && userAns.toLowerCase() !== correctAns.toLowerCase()) {
                            count++;
                        }
                    });
                    return count;
                },

                shouldShowQuestion(skill, isCorrect, isIncorrect) {
                    if (this.currentFilter === 'all') return true;
                    if (this.currentFilter === 'correct') return isCorrect;
                    if (this.currentFilter === 'incorrect') return isIncorrect;
                    if (this.currentFilter === 'listening') return skill === 'listening';
                    if (this.currentFilter === 'reading') return skill === 'reading' || skill === 'grammar';
                    if (this.currentFilter === 'writing') return skill === 'writing';
                    if (this.currentFilter === 'speaking') return skill === 'speaking';
                    return true;
                }
            };
        }
    </script>
</x-app-layout>
