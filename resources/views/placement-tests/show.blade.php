<x-app-layout>
    <x-ui.page-header :title="$test->title" :back="route('placement-tests.index')">
        <x-slot:badges>
            <span class="px-2 py-0.5 rounded-md bg-secondary/10 text-secondary font-mono font-bold text-[11px]">{{ $test->code }}</span>
            @if ($test->is_preset)
                <span class="px-2 py-0.5 rounded-md bg-surface-container text-on-surface-variant border border-surface-container-highest text-[10px] font-bold flex items-center gap-1">
                    <span class="material-symbols-outlined text-[12px]">lock</span>
                    <span>Đề mẫu hệ thống (Khóa sửa)</span>
                </span>
            @else
                <span class="px-2 py-0.5 rounded-md bg-tertiary/10 text-tertiary border border-tertiary/30 text-[10px] font-bold flex items-center gap-1">
                    <span class="material-symbols-outlined text-[12px]">edit</span>
                    <span>Đề tạo tay (Tùy biến)</span>
                </span>
            @endif
        </x-slot:badges>
        <x-slot:actions>
            {{-- Copy Portal Link --}}
            <x-ui.button variant="secondary" size="sm" icon="content_copy" onclick="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from(route('portal.test.take', $test->code)) }}); window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Đã sao chép link làm bài thi.', type: 'success' } }));">Sao chép Link</x-ui.button>

            {{-- Open Portal --}}
            <x-ui.button size="sm" icon="open_in_new" :href="route('portal.test.take', $test->code)" target="_blank">Cổng làm bài</x-ui.button>

            @if (!$test->is_preset)
                <x-ui.button variant="success" size="sm" icon="edit" :href="route('placement-tests.edit', $test->id)">Sửa đề</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        {{-- Overview Stats Card --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-ui.stat-card label="Cấp độ mục tiêu" :value="$test->target_level" />
            <x-ui.stat-card label="Thời lượng làm bài" :value="$test->duration_minutes . ' phút'" tone="secondary" />
            <x-ui.stat-card label="Tổng số câu hỏi" :value="$test->questions_count . ' câu'" tone="primary" />
            <a href="#submissions-list" class="block rounded-xl transition hover:ring-2 hover:ring-tertiary/30">
                <x-ui.stat-card label="Lượt thí sinh đã thi" :value="$test->submissions->count() . ' lượt'" tone="success" icon="arrow_downward" hint="(Bấm xem điểm)" />
            </a>
        </div>

        {{-- Candidate Submissions & Scores Table for this Test --}}
        <x-ui.data-table id="submissions-list" class="scroll-mt-6">
            <x-slot:header>
                <span class="font-bold text-xs text-on-surface uppercase tracking-wider flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-tertiary text-base">fact_check</span>
                    <span>Danh Sách Thí Sinh Đã Thi Bộ Đề Này ({{ $test->submissions->count() }})</span>
                </span>
                <span class="text-[11px] text-on-surface-variant/70">Điểm số chi tiết 4 kỹ năng &amp; Khóa học xếp lớp</span>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Thí sinh</th>
                        <th>Số điện thoại</th>
                        <th class="text-center">Listening</th>
                        <th class="text-center">Reading</th>
                        <th class="text-center">Writing</th>
                        <th class="text-center">Speaking</th>
                        <th class="text-center">Overall (Band)</th>
                        <th>Khóa học đề xuất</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($test->submissions as $sub)
                        <tr>
                            <td>
                                <div class="font-bold text-on-surface flex items-center gap-1.5">
                                    <span>{{ $sub->candidate_name }}</span>
                                    @if ($sub->customer)
                                        <a href="{{ route('crm.customers.show', $sub->customer->id) }}" class="px-1.5 py-0.2 rounded bg-secondary/10 hover:bg-secondary/20 text-secondary font-normal text-[10px] inline-flex items-center gap-0.5" title="Mở hồ sơ Lead trong CRM">
                                            <span>Lead CRM</span>
                                            <span class="material-symbols-outlined text-[11px]">open_in_new</span>
                                        </a>
                                    @endif
                                </div>
                                <div class="text-[10px] text-on-surface-variant/70 font-mono mt-0.5">
                                    {{ $sub->created_at ? $sub->created_at->format('H:i d/m/Y') : 'Vừa xong' }}
                                </div>
                            </td>
                            <td class="font-mono text-on-surface-variant">{{ $sub->candidate_phone }}</td>
                            <td class="text-center font-mono font-bold text-secondary">{{ $sub->listening_score }}</td>
                            <td class="text-center font-mono font-bold text-tertiary">{{ $sub->reading_score }}</td>
                            <td class="text-center font-mono font-bold text-purple-600">{{ $sub->writing_score }}</td>
                            <td class="text-center font-mono font-bold text-error">{{ $sub->speaking_score }}</td>
                            <td class="text-center">
                                <x-ui.badge color="primary" pill :dot="false" class="font-mono font-black">
                                    @if ($sub->isPending()) Chờ chấm @else {{ $sub->scoreSummary() ?? '—' }} @endif
                                </x-ui.badge>
                            </td>
                            <td class="font-semibold text-primary">{{ $sub->recommended_course }}</td>
                            <td class="text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.button variant="secondary" size="sm" icon="description" :href="\Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $sub->id])" target="_blank">Phiếu điểm</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" icon="edit_note" :href="route('placement-tests.results.show', $sub->id)">{{ $sub->isPending() ? 'Chấm bài' : 'Chấm lại' }}</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9"><x-ui.empty-state icon="assignment_late" title="Chưa có thí sinh nào nộp bài thi cho bộ đề này." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        {{-- Question List View --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-5 md:p-6 space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-surface-container-highest">
                <div>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-secondary text-base">format_list_numbered</span>
                        <span>Chi Tiết Toàn Bộ Câu Hỏi &amp; Đáp Án Chuẩn (Answer Keys)</span>
                    </h2>
                    <p class="text-[11px] text-on-surface-variant">Giáo viên và Học vụ có thể xem trước nội dung, hình ảnh, audio và đáp án đối soát.</p>
                </div>
            </div>

            @php
                $questions = is_array($test->questions) ? $test->questions : [];
            @endphp

            <div class="space-y-5">
                @forelse ($questions as $idx => $q)
                    <div class="p-4 rounded-xl border border-surface-container-highest/90 bg-surface-container-low/40 space-y-3">
                        {{-- Question Header --}}
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded-md bg-inverse-surface text-white font-mono font-bold text-[11px]">Câu {{ $idx + 1 }}</span>
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-bold {{ match($q['skill'] ?? '') {
                                    'listening' => 'bg-secondary/10 text-secondary border border-secondary/30',
                                    'reading' => 'bg-tertiary/10 text-tertiary border border-tertiary/30',
                                    'grammar' => 'bg-info/10 text-info border border-info/30',
                                    'writing' => 'bg-purple-50 text-purple-700 border border-purple-200',
                                    'speaking' => 'bg-error/10 text-error border border-error/30',
                                    default => 'bg-surface-container text-on-surface-variant'
                                } }}">
                                    {{ ucfirst($q['skill'] ?? 'General') }}
                                </span>
                                <span class="text-[11px] text-on-surface-variant/70 font-mono">({{ $q['type'] ?? 'multiple_choice' }})</span>
                            </div>
                            <span class="text-xs font-mono font-semibold text-on-surface-variant">{{ $q['points'] ?? 1 }} điểm</span>
                        </div>

                        {{-- Question Title --}}
                        <div class="text-xs md:text-sm font-bold text-on-surface leading-snug">
                            {{ $q['title'] ?? '' }}
                        </div>

                        {{-- Passage if exists --}}
                        @if (!empty($q['passage']))
                            <div class="p-3 bg-tertiary/5 border border-tertiary/20 rounded-xl text-xs leading-relaxed text-on-surface font-medium italic">
                                <strong>Đoạn văn / Ngữ cảnh:</strong> "{{ $q['passage'] }}"
                            </div>
                        @endif

                        {{-- Audio player if exists --}}
                        @if (!empty($q['audio_url']))
                            <div class="p-3 bg-secondary/10 border border-secondary/30 rounded-xl space-y-2 shadow-2xs">
                                <div class="flex items-center justify-between text-[11px] font-bold text-on-secondary-fixed">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base text-secondary">volume_up</span>
                                        <span>File Audio Listening: {{ basename($q['audio_url']) }}</span>
                                    </div>
                                    <a href="{{ $q['audio_url'] }}" target="_blank" class="text-[10px] text-secondary hover:underline flex items-center gap-0.5 font-normal">
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

                        {{-- Illustration Image if exists --}}
                        @if (!empty($q['image_url']))
                            <div class="my-2 p-2 bg-surface-container-lowest rounded-xl border border-surface-container-highest max-w-md mx-auto">
                                <img src="{{ $q['image_url'] }}" alt="Question illustration" class="max-h-56 mx-auto object-contain rounded-lg">
                            </div>
                        @endif

                        {{-- Options / Choices --}}
                        @if (!empty($q['options']))
                            <div class="grid grid-cols-1 {{ count($q['options']) > 2 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2' }} gap-2 text-xs">
                                @foreach ($q['options'] as $opt)
                                    @php
                                        $isCorrect = (string)($opt['key'] ?? '') === (string)($q['correct_answer'] ?? '');
                                    @endphp
                                    <div class="p-2.5 rounded-xl border {{ $isCorrect ? 'bg-tertiary/10 border-tertiary/30 ring-2 ring-tertiary/30' : 'bg-surface-container-lowest border-surface-container-highest' }} flex flex-col justify-between">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="flex items-center gap-1.5">
                                                <span class="w-4.5 h-4.5 rounded-full {{ $isCorrect ? 'bg-tertiary text-white' : 'bg-surface-container text-on-surface-variant' }} font-bold text-[11px] flex items-center justify-center font-mono shrink-0">
                                                    {{ $opt['key'] }}
                                                </span>
                                                <span class="font-medium text-on-surface text-xs">{{ $opt['text'] }}</span>
                                            </div>
                                            @if ($isCorrect)
                                                <span class="text-[10px] font-bold px-1.5 py-0.2 rounded bg-tertiary text-white flex items-center gap-0.5">
                                                    <span class="material-symbols-outlined text-[11px]">check</span>
                                                    <span>Đúng</span>
                                                </span>
                                            @endif
                                        </div>
                                        @if (!empty($opt['image_url']))
                                            <img src="{{ $opt['image_url'] }}" alt="{{ $opt['key'] }}" class="max-h-28 rounded-lg border border-surface-container-highest object-contain bg-surface-container-low p-1 mx-auto mt-1.5">
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Fill blank answer or Explanation --}}
                        @if (!empty($q['correct_answer']))
                            <div class="p-2.5 bg-warning/5 border border-warning/30 rounded-xl text-xs flex items-center justify-between">
                                <div>
                                    <span class="font-bold text-on-warning-container">Đáp án chuẩn (Key):</span>
                                    <span class="font-mono font-black text-on-warning-container text-xs ml-1">{{ $q['correct_answer'] }}</span>
                                    @if (!empty($q['explanation']))
                                        <span class="text-on-surface-variant italic ml-2 text-[11px]">({{ $q['explanation'] }})</span>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Speaking Cue Points --}}
                        @if (!empty($q['cue_points']))
                            <div class="p-2.5 bg-error/10 border border-error/30 rounded-xl text-xs text-on-error-container whitespace-pre-line font-mono">
                                <strong>Gợi ý phỏng vấn Speaking:</strong>
                                {{ $q['cue_points'] }}
                            </div>
                        @endif
                    </div>
                @empty
                    <x-ui.empty-state icon="quiz" title="Đề thi chưa có câu hỏi nào." />
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
