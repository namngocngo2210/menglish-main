<x-app-layout>
    <x-ui.page-header title="Chi tiết bài làm & chấm điểm" :back="route('placement-tests.index')">
        <x-slot:badges>
            <x-ui.badge color="primary" pill :dot="false" class="font-bold">{{ $submission->scoreSummary() ?? 'Chưa có điểm' }}</x-ui.badge>
            <x-ui.badge :color="$submission->isPending() ? 'secondary' : 'success'" pill class="uppercase">{{ $submission->isPending() ? 'Chờ chấm' : 'Đã chấm điểm' }}</x-ui.badge>
        </x-slot:badges>
        <x-slot:meta>
            <span class="font-mono">{{ $submission->test?->title ?? 'Đề Test Đầu Vào MEnglish' }} · Thí sinh: <strong class="text-on-surface">{{ $submission->candidate_name }}</strong> · SĐT: {{ $submission->candidate_phone }}</span>
        </x-slot:meta>
        <x-slot:actions>
            @if ($submission->customer_id)
                <x-ui.button variant="secondary" icon="person" :href="route('crm.customers.show', $submission->customer_id)">Hồ sơ khách</x-ui.button>
            @endif
            <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-inverse-surface hover:bg-inverse-surface/90 text-white text-xs font-bold transition shadow-xs">
                <span class="material-symbols-outlined text-[16px] text-warning/70">military_tech</span>
                <span>Bảng điểm (Scorecard)</span>
            </a>
            <x-ui.button variant="secondary" icon="menu_book" :href="route('placement-tests.rubric-guide')">Thang điểm &amp; hướng dẫn nhận xét</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    <div class="max-w-6xl mx-auto space-y-6" x-data="placementResultEngine({
        questions: {{ Js::from($questions) }},
        answers: {{ Js::from($submission->answers ?? []) }},
        writingContent: {{ Js::from($submission->writing_content ?? '') }}
    })">

        {{-- ────────────────────────────────────────────── --}}
        {{-- 1. BẢNG TỔNG HỢP ĐIỂM & FORM CHẤM NHANH --}}
        {{-- ────────────────────────────────────────────── --}}
        <form action="{{ route('placement-tests.results.update', $submission->id) }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-6 space-y-6">
            @csrf

            <div class="flex items-center justify-between border-b border-surface-container-highest pb-3 flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary-container text-xl">fact_check</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Chấm điểm theo thang điểm khối lớp</h2>
                </div>
                <span class="px-2 py-0.5 rounded-md bg-warning-container border border-warning/30 text-on-warning-container text-[11px] font-bold">Tổng = Nghe + Đọc &amp; Viết + Nói → lớp đề xuất</span>
                <div class="text-xs text-on-surface-variant font-mono">
                    Nộp bài lúc: {{ $submission->created_at ? $submission->created_at->format('H:i, d/m/Y') : date('H:i, d/m/Y') }}
                </div>
            </div>

            @if ($submission->isPending() && ($submission->listening_score !== null || $submission->reading_score !== null))
                <x-ui.alert type="info">
                    Điểm tự chấm từ bài online (đã quy về thang của khối): Nghe {{ $submission->listening_score ?? '—' }} · Phần Đọc trắc nghiệm {{ $submission->reading_score ?? '—' }}.
                    Học vụ kiểm tra lại, cộng phần Viết vào ô Đọc &amp; Viết và nhập điểm Nói.
                </x-ui.alert>
            @endif

            <div class="max-w-3xl">
                @include('placement-tests.partials.rubric-score-fields', [
                    'submission' => $submission,
                    'defaultGroup' => $submission->resolvedGradeGroup(),
                ])
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-surface-container-highest flex-wrap gap-3">
                <div class="flex items-center gap-2 text-xs text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px] text-primary">info</span>
                    <span>Khi lưu điểm, hệ thống tính tổng điểm, tra lớp đề xuất và đồng bộ kết quả sang hồ sơ khách CRM.</span>
                </div>
                <div class="flex items-center gap-sm">
                    @if ($submission->isPending())
                        <x-ui.button type="submit" variant="secondary" name="action" value="draft">Lưu bản nháp</x-ui.button>
                    @endif
                    <x-ui.button type="submit" icon="check" name="action" value="confirm">Xác nhận kết quả</x-ui.button>
                </div>
            </div>
        </form>

        {{-- ────────────────────────────────────────────── --}}
        {{-- 2. BẢNG ĐỐI CHIẾU CÂU HỎI, ĐÁP ÁN CHỌN & ĐÁP ÁN ĐÚNG --}}
        {{-- ────────────────────────────────────────────── --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden space-y-4 p-6">
            
            {{-- Section Header & Filter Toolbar --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-surface-container-highest pb-4">
                <div>
                    <h2 class="text-base font-black text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-secondary">checklist_rtl</span>
                        <span>Đối Chiếu Chi Tiết Từng Câu Hỏi &amp; Đáp Án Thí Sinh Đã Chọn</span>
                    </h2>
                    <p class="text-xs text-on-surface-variant mt-0.5">
                        Xem chi tiết lựa chọn của thí sinh so với đáp án chuẩn của đề, phân tích lỗi sai và lời giải thích.
                    </p>
                </div>

                {{-- Stats Quick Badges --}}
                <div class="flex items-center gap-2 shrink-0 flex-wrap">
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-surface-container text-on-surface border border-surface-container-highest font-mono">
                        Tổng: <span x-text="questions.length"></span> câu
                    </span>
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-tertiary/10 text-tertiary border border-tertiary/30 font-mono flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span>
                        <span>Đúng: <strong x-text="correctCount"></strong></span>
                    </span>
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-error/10 text-error border border-error/30 font-mono flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">cancel</span>
                        <span>Sai: <strong x-text="incorrectCount"></strong></span>
                    </span>
                </div>
            </div>

            {{-- Filter Buttons --}}
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
                <button type="button" @click="currentFilter = 'all'" :class="currentFilter === 'all' ? 'bg-inverse-surface text-white font-bold' : 'bg-surface-container hover:bg-surface-container-high text-on-surface-variant font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    Tất Cả (<span x-text="questions.length"></span>)
                </button>
                <button type="button" @click="currentFilter = 'listening'" :class="currentFilter === 'listening' ? 'bg-secondary text-white font-bold' : 'bg-secondary/10 hover:bg-secondary/20 text-secondary font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    🎧 Listening
                </button>
                <button type="button" @click="currentFilter = 'reading'" :class="currentFilter === 'reading' ? 'bg-tertiary text-white font-bold' : 'bg-tertiary/10 hover:bg-tertiary/20 text-tertiary font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    📖 Reading &amp; Grammar
                </button>
                <button type="button" @click="currentFilter = 'writing'" :class="currentFilter === 'writing' ? 'bg-warning text-white font-bold' : 'bg-warning-container hover:bg-warning/20 text-on-warning-container font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    ✍️ Writing
                </button>
                <button type="button" @click="currentFilter = 'speaking'" :class="currentFilter === 'speaking' ? 'bg-error text-white font-bold' : 'bg-error/10 hover:bg-error/20 text-error font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    🗣️ Speaking
                </button>
                <button type="button" @click="currentFilter = 'correct'" :class="currentFilter === 'correct' ? 'bg-tertiary text-white font-bold' : 'bg-tertiary/10 hover:bg-tertiary/20 text-on-tertiary-container font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    ✓ Câu Đúng
                </button>
                <button type="button" @click="currentFilter = 'incorrect'" :class="currentFilter === 'incorrect' ? 'bg-error text-white font-bold' : 'bg-error/10 hover:bg-error/20 text-on-error-container font-semibold'" class="px-3 py-1.5 rounded-lg transition shrink-0 cursor-pointer">
                    ✗ Câu Sai
                </button>
            </div>

            {{-- Questions List --}}
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

                    <div class="p-5 rounded-2xl border transition-all duration-200 {{ $isCorrect ? 'bg-tertiary/5 border-tertiary/30' : ($isIncorrect ? 'bg-error/5 border-error/30' : 'bg-surface-container-low/40 border-surface-container-highest') }}"
                         x-show="shouldShowQuestion('{{ $qSkill }}', {{ $isCorrect ? 'true' : 'false' }}, {{ $isIncorrect ? 'true' : 'false' }})">

                        {{-- Question Header --}}
                        <div class="flex items-center justify-between pb-3 border-b border-surface-container-highest flex-wrap gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="w-7 h-7 rounded-lg bg-inverse-surface text-white font-black text-xs flex items-center justify-center font-mono">
                                    #{{ $idx + 1 }}
                                </span>

                                {{-- Skill Badge --}}
                                @if ($qSkill === 'listening')
                                    <x-ui.badge color="secondary" pill class="uppercase font-bold">🎧 Listening</x-ui.badge>
                                @elseif ($qSkill === 'reading')
                                    <x-ui.badge color="success" pill class="uppercase font-bold">📖 Reading</x-ui.badge>
                                @elseif ($qSkill === 'grammar')
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-200 uppercase">
                                        🔤 Grammar
                                    </span>
                                @elseif ($qSkill === 'writing')
                                    <x-ui.badge color="warning" pill class="uppercase font-bold">✍️ Writing Task</x-ui.badge>
                                @elseif ($qSkill === 'speaking')
                                    <x-ui.badge color="error" pill class="uppercase font-bold">🗣️ Speaking Prompt</x-ui.badge>
                                @endif

                                {{-- Type Badge --}}
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-medium bg-surface-container text-on-surface-variant border border-surface-container-highest">
                                    {{ $qType === 'multiple_choice' ? 'Trắc nghiệm 4 lựa chọn' : ($qType === 'fill_blank' ? 'Điền từ vào chỗ trống' : ($qType === 'essay' ? 'Tự luận Writing' : 'Phỏng vấn Speaking')) }}
                                </span>
                            </div>

                            {{-- Accuracy / Points Badge --}}
                            <div class="flex items-center gap-2">
                                @if ($isCorrect)
                                    <span class="px-3 py-1 rounded-xl text-xs font-black bg-tertiary/10 text-on-tertiary-container border border-tertiary/30 flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[15px] text-tertiary">check_circle</span>
                                        <span>CHÍNH XÁC (+{{ $q['points'] ?? 1 }}đ)</span>
                                    </span>
                                @elseif ($isIncorrect)
                                    <span class="px-3 py-1 rounded-xl text-xs font-black bg-error/10 text-on-error-container border border-error/30 flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[15px] text-error">cancel</span>
                                        <span>CHƯA ĐÚNG (0đ)</span>
                                    </span>
                                @elseif ($isObjective && empty($candidateAnswer))
                                    <span class="px-2.5 py-1 rounded-xl text-xs font-semibold bg-surface-container text-on-surface-variant border border-surface-container-highest">
                                        Chưa có câu trả lời
                                    </span>
                                @endif
                                <span class="text-xs font-mono font-bold text-on-surface-variant/70">({{ $q['points'] ?? 1 }} điểm)</span>
                            </div>
                        </div>

                        {{-- Audio Player if present --}}
                        @if (!empty($q['audio_url']))
                            <div class="mt-3 p-3 bg-secondary/5 border border-secondary/30 rounded-xl flex items-center gap-3">
                                <span class="material-symbols-outlined text-secondary text-xl">headphones</span>
                                <div class="flex-1">
                                    <span class="text-[11px] font-bold text-on-secondary-fixed block mb-1">File Nghe Audio của câu hỏi:</span>
                                    <audio controls class="w-full h-8">
                                        <source src="{{ $q['audio_url'] }}" type="audio/mpeg">
                                        Trình duyệt không hỗ trợ audio player.
                                    </audio>
                                </div>
                            </div>
                        @endif

                        {{-- Passage / Context if present --}}
                        @if (!empty($q['passage']))
                            <div class="mt-3 p-3.5 bg-surface-container/80 border border-surface-container-highest rounded-xl space-y-1">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant block">Đoạn văn đọc hiểu / Bối cảnh:</span>
                                <p class="text-xs text-on-surface leading-relaxed font-serif italic">{{ $q['passage'] }}</p>
                            </div>
                        @endif

                        {{-- Question Title --}}
                        <div class="mt-3">
                            <h3 class="text-sm font-bold text-on-surface leading-snug">
                                {{ $q['title'] ?? 'Câu hỏi trắc nghiệm' }}
                            </h3>
                        </div>

                        {{-- MULTIPLE CHOICE OPTIONS --}}
                        @if ($qType === 'multiple_choice' && !empty($q['options']))
                            <div class="mt-3.5 space-y-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/70 block mb-1">Các lựa chọn &amp; Đối chiếu câu trả lời:</span>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    @foreach ($q['options'] as $opt)
                                        @php
                                            $optKey = $opt['key'] ?? '';
                                            $isThisCorrectAnswer = (strcasecmp($optKey, $correctAnswer) === 0);
                                            $isThisCandidateChoice = (strcasecmp($optKey, $candidateAnswer) === 0);
                                        @endphp

                                        <div class="p-3 rounded-xl border transition flex items-start justify-between gap-2.5
                                            @if ($isThisCorrectAnswer && $isThisCandidateChoice)
                                                bg-tertiary/10 border-tertiary text-on-tertiary-container ring-2 ring-tertiary/20 font-bold
                                            @elseif ($isThisCorrectAnswer)
                                                bg-tertiary/5 border-tertiary/30 text-on-tertiary-container font-bold
                                            @elseif ($isThisCandidateChoice)
                                                bg-error/10 border-error/30 text-on-error-container font-bold
                                            @else
                                                bg-surface-container-lowest border-surface-container-highest text-on-surface-variant
                                            @endif
                                        ">
                                            <div class="flex items-start gap-2.5">
                                                <span class="w-6 h-6 rounded-lg flex items-center justify-center font-black text-xs font-mono shrink-0
                                                    @if ($isThisCorrectAnswer)
                                                        bg-tertiary text-white
                                                    @elseif ($isThisCandidateChoice)
                                                        bg-error text-white
                                                    @else
                                                        bg-surface-container text-on-surface-variant
                                                    @endif
                                                ">
                                                    {{ $optKey }}
                                                </span>
                                                <span class="mt-0.5 leading-relaxed">{{ $opt['text'] ?? '' }}</span>
                                            </div>

                                            {{-- Badges on the right of each option --}}
                                            <div class="shrink-0 flex flex-col items-end gap-1">
                                                @if ($isThisCandidateChoice && $isThisCorrectAnswer)
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-tertiary text-white flex items-center gap-1 shadow-2xs">
                                                        <span class="material-symbols-outlined text-[12px]">done_all</span>
                                                        <span>Thí sinh chọn (Đúng)</span>
                                                    </span>
                                                @elseif ($isThisCandidateChoice)
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-error text-white flex items-center gap-1 shadow-2xs">
                                                        <span class="material-symbols-outlined text-[12px]">close</span>
                                                        <span>Thí sinh chọn (Sai)</span>
                                                    </span>
                                                @elseif ($isThisCorrectAnswer)
                                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-tertiary/10 text-on-tertiary-container border border-tertiary/30 flex items-center gap-1">
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

                        {{-- FILL BLANK QUESTION --}}
                        @if ($qType === 'fill_blank')
                            <div class="mt-3.5 p-3.5 bg-surface-container-lowest border border-surface-container-highest rounded-xl space-y-2 text-xs">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="p-2.5 rounded-lg border {{ $isCorrect ? 'bg-tertiary/5 border-tertiary/30 text-on-tertiary-container' : 'bg-error/5 border-error/30 text-on-error-container' }}">
                                        <span class="text-[10px] font-bold uppercase tracking-wider block mb-1 text-on-surface-variant">Câu trả lời của thí sinh:</span>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-black text-sm">{{ $candidateAnswer ?: '(Bỏ trống / Chưa điền)' }}</span>
                                            @if ($isCorrect)
                                                <span class="material-symbols-outlined text-tertiary text-base">check_circle</span>
                                            @else
                                                <span class="material-symbols-outlined text-error text-base">cancel</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="p-2.5 bg-tertiary/10 border border-tertiary/30 rounded-lg text-on-tertiary-container">
                                        <span class="text-[10px] font-bold uppercase tracking-wider block mb-1 text-on-tertiary-container">Đáp án chuẩn của đề bài:</span>
                                        <span class="font-mono font-black text-sm text-tertiary">{{ $correctAnswer }}</span>
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- ESSAY WRITING TASK --}}
                        @if ($qSkill === 'writing' || $qType === 'essay')
                            <div class="mt-3.5 space-y-3">
                                <div class="p-4 bg-surface-container-lowest border border-warning/30 rounded-xl space-y-2 shadow-2xs">
                                    <div class="flex items-center justify-between border-b border-warning/20 pb-2">
                                        <span class="text-xs font-bold text-on-warning-container flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-warning text-[16px]">edit_document</span>
                                            <span>Toàn văn Bài làm Writing của Thí sinh:</span>
                                        </span>
                                        <span class="text-[11px] font-mono font-bold text-on-warning-container bg-warning/5 px-2 py-0.5 rounded-md">
                                            {{ str_word_count($submission->writing_content ?? '') }} từ
                                        </span>
                                    </div>
                                    <div class="p-3 bg-warning/5 rounded-lg text-xs text-on-surface leading-relaxed font-serif whitespace-pre-wrap">
                                        {{ $submission->writing_content ?: 'Thí sinh không nhập nội dung bài viết.' }}
                                    </div>
                                </div>

                                @if (!empty($q['rubric_note']))
                                    <div class="p-3 bg-surface-container-low border border-surface-container-highest rounded-xl text-[11px] text-on-surface-variant flex items-start gap-2">
                                        <span class="material-symbols-outlined text-warning text-base shrink-0 mt-0.5">rule</span>
                                        <div>
                                            <strong class="text-on-surface">Hướng dẫn chấm Writing:</strong> {{ $q['rubric_note'] }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- SPEAKING PROMPT TASK --}}
                        @if ($qSkill === 'speaking' || $qType === 'speaking_prompt')
                            <div class="mt-3.5 space-y-3">
                                @if (!empty($q['cue_points']))
                                    <div class="p-3.5 bg-error/5 border border-error/30 rounded-xl space-y-1.5 text-xs">
                                        <span class="font-bold text-on-error-container uppercase text-[10px] block">Gợi ý chủ đề phỏng vấn (Cue Points):</span>
                                        <div class="text-on-error-container leading-relaxed whitespace-pre-line font-medium pl-1">
                                            {{ $q['cue_points'] }}
                                        </div>
                                    </div>
                                @endif

                                @if ($submission->speaking_audio_url)
                                    <div class="p-3 bg-surface-container-lowest border border-error/30 rounded-xl flex items-center gap-3">
                                        <span class="material-symbols-outlined text-error text-xl">mic</span>
                                        <div class="flex-1">
                                            <span class="text-xs font-bold text-on-surface block mb-1">File ghi âm bài nói của thí sinh:</span>
                                            <audio controls class="w-full h-8">
                                                <source src="{{ $submission->speaking_audio_url }}" type="audio/mpeg">
                                            </audio>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- EXPLANATION & TRANSCRIPT EVIDENCE --}}
                        @if (!empty($q['explanation']))
                            <div class="mt-3 p-3 bg-warning/5 border border-warning/25 rounded-xl flex items-start gap-2 text-xs">
                                <span class="material-symbols-outlined text-primary-container text-base shrink-0 mt-0.5">lightbulb</span>
                                <div>
                                    <strong class="text-on-warning-container">Giải thích chi tiết &amp; Dẫn chứng bài làm:</strong>
                                    <p class="text-on-warning-container leading-relaxed mt-0.5">{{ $q['explanation'] }}</p>
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
