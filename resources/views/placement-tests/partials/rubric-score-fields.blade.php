{{--
    Ô nhập điểm test đầu vào theo thang điểm khối lớp (BA Q2) — dùng chung cho hồ sơ khách CRM và màn chấm bài.
    Tổng = Nghe + Đọc & Viết + Nói (điểm thô theo thang khối) → lớp đề xuất; chọn lại lớp được;
    nhận xét từng kỹ năng gợi ý theo băng điểm (sửa được). Speaking luôn nhập tay.
    Props: $submission (?PlacementTestSubmission), $defaultGroup (?string, khối đoán theo mã đề)
--}}
@php
    $Rubric = \App\Services\PlacementRubricService::class;

    $sub = $submission ?? null;
    $rubricConfig = $Rubric::clientConfig();
    $initialGroup = old('grade_group', $sub?->grade_group ?? ($defaultGroup ?? null) ?? array_key_first($rubricConfig['groups']));
    $fmt = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    $initial = [
        'group' => $initialGroup,
        'listening' => old('listening_score', $fmt($sub?->listening_score)),
        // Bài nộp online: phần Đọc trắc nghiệm đã tự chấm (reading_score) làm gợi ý cho ô Đọc & Viết.
        'reading_writing' => old('reading_writing_score', $fmt($sub?->reading_writing_score ?? $sub?->reading_score)),
        'speaking' => old('speaking_score', $fmt($sub?->speaking_score)),
        'chosen' => old('chosen_class', $sub?->chosen_class),
        'comments' => [
            'listening' => old('listening_comment', $sub?->listening_comment),
            'reading_writing' => old('reading_writing_comment', $sub?->reading_writing_comment),
            'speaking' => old('speaking_comment', $sub?->speaking_comment),
        ],
    ];
    $skillStyles = [
        'listening' => 'text-indigo-700 border-indigo-200 bg-indigo-50/60',
        'reading_writing' => 'text-emerald-700 border-emerald-200 bg-emerald-50/60',
        'speaking' => 'text-rose-700 border-rose-200 bg-rose-50/60',
    ];
@endphp

@once
    <script>
        window.rubricScoreForm = function (config, initial) {
            return {
                config,
                group: initial.group,
                scores: { listening: initial.listening, reading_writing: initial.reading_writing, speaking: initial.speaking },
                chosen: initial.chosen || '',
                comments: {
                    listening: initial.comments.listening || '',
                    reading_writing: initial.comments.reading_writing || '',
                    speaking: initial.comments.speaking || '',
                },
                // Nhận xét người chấm đã tự sửa thì không ghi đè bằng gợi ý nữa.
                edited: {
                    listening: !!initial.comments.listening,
                    reading_writing: !!initial.comments.reading_writing,
                    speaking: !!initial.comments.speaking,
                },
                init() {
                    ['listening', 'reading_writing', 'speaking'].forEach((skill) => {
                        this.$watch(() => this.suggestion(skill), (text) => {
                            if (!this.edited[skill]) this.comments[skill] = text || '';
                        });
                        if (!this.edited[skill]) this.comments[skill] = this.suggestion(skill) || '';
                    });
                },
                get rubric() { return this.config.groups[this.group] || null; },
                get hasRubric() { return !!(this.rubric && this.rubric.has_rubric); },
                max(skill) { return this.rubric ? this.rubric.max[skill] : 10; },
                num(skill) {
                    const v = parseFloat(this.scores[skill]);
                    return Number.isFinite(v) ? v : null;
                },
                get complete() { return ['listening', 'reading_writing', 'speaking'].every((s) => this.num(s) !== null); },
                get total() {
                    return Math.round(['listening', 'reading_writing', 'speaking'].reduce((sum, s) => sum + (this.num(s) || 0), 0) * 10) / 10;
                },
                get maxTotal() { return this.max('listening') + this.max('reading_writing') + this.max('speaking'); },
                get suggestedClass() {
                    if (!this.hasRubric || !this.complete) return null;
                    const t = this.total;
                    const hit = this.rubric.placements.find((p) => (p.lt !== null ? t < p.lt : (p.lte !== null ? t <= p.lte : true)));
                    return hit ? hit.class : null;
                },
                suggestion(skill) {
                    const score = this.num(skill);
                    if (!this.hasRubric || score === null) return '';
                    let text = '';
                    (this.rubric.bands[skill] || []).forEach((band) => { if (score >= band.min) text = band.text; });
                    return text;
                },
                overMax(skill) { const v = this.num(skill); return v !== null && v > this.max(skill); },
                resetComment(skill) { this.edited[skill] = false; this.comments[skill] = this.suggestion(skill) || ''; },
            };
        };
    </script>
@endonce

<div x-data="rubricScoreForm({{ Js::from($rubricConfig) }}, {{ Js::from($initial) }})" class="space-y-3.5 text-xs">
    <div>
        <label class="block font-bold text-gray-700 mb-1">Khối lớp (thang điểm) <span class="text-rose-600">*</span></label>
        <select name="grade_group" x-model="group" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white font-semibold shadow-2xs">
            @foreach ($Rubric::gradeGroups() as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        @error('grade_group')<p class="text-rose-600 mt-1">{{ $message }}</p>@enderror
        <p x-show="!hasRubric" x-cloak class="mt-1.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 px-2.5 py-1.5 font-semibold">
            {{ $Rubric::noRubricNotice() }}. Điểm từng kỹ năng nhập theo thang tạm 0–10, không quy đổi ra lớp.
        </p>
    </div>

    <div class="grid grid-cols-3 gap-2.5">
        @foreach ($Rubric::SKILLS as $skill => $label)
            <div class="p-2.5 rounded-xl border {{ $skillStyles[$skill] }} space-y-1">
                <label class="block text-[10px] font-black uppercase tracking-wide">
                    {{ $label }} <span class="font-mono" x-text="'/' + max('{{ $skill }}')"></span>
                    @if ($skill === 'speaking')<span class="normal-case font-semibold text-[10px] block text-gray-500">Luôn nhập tay</span>@endif
                </label>
                <input type="number" step="0.5" min="0" :max="max('{{ $skill }}')" name="{{ $skill }}_score" x-model="scores.{{ $skill }}" required
                       :class="overMax('{{ $skill }}') ? 'border-rose-400 ring-2 ring-rose-100' : 'border-gray-200'"
                       class="w-full text-center font-mono font-black text-base rounded-lg border bg-white p-1.5 shadow-2xs" />
                @error($skill.'_score')<p class="text-rose-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
        <div class="rounded-xl border border-orange-200 bg-orange-50/60 p-3">
            <div class="text-[10px] font-black uppercase tracking-wide text-orange-800">Tổng điểm</div>
            <div class="font-mono font-black text-xl text-gray-900"><span x-text="complete ? total : '—'"></span><span class="text-sm text-gray-500" x-text="' / ' + maxTotal"></span></div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
            <div class="text-[10px] font-black uppercase tracking-wide text-gray-600">Lớp đề xuất theo thang điểm</div>
            <div class="font-bold text-gray-900" x-text="hasRubric ? (suggestedClass || 'Nhập đủ 3 kỹ năng để tra lớp') : 'Không có — chọn lớp thủ công'"></div>
        </div>
    </div>

    <div>
        <label class="block font-bold text-gray-700 mb-1">
            Lớp xếp cho học viên <span x-show="!hasRubric" class="text-rose-600">*</span>
            <span x-show="hasRubric" class="font-normal text-gray-500">(để trống = theo lớp đề xuất; có thể chọn lại)</span>
        </label>
        <input type="text" name="chosen_class" x-model="chosen" list="rubric-class-options" :required="!hasRubric" maxlength="255"
               :placeholder="suggestedClass || 'Nhập / chọn lớp phù hợp'"
               class="w-full text-xs font-bold text-primary-container rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" />
        <datalist id="rubric-class-options">
            @foreach ($Rubric::classOptions() as $option)
                <option value="{{ $option }}"></option>
            @endforeach
        </datalist>
        @error('chosen_class')<p class="text-rose-600 mt-1">{{ $message }}</p>@enderror
    </div>

    <div class="space-y-2.5">
        <div class="font-bold text-gray-700">Nhận xét từng kỹ năng <span class="font-normal text-gray-500">(gợi ý theo băng điểm — người chấm sửa được)</span></div>
        @foreach ($Rubric::SKILLS as $skill => $label)
            <div>
                <div class="flex items-center justify-between gap-2 mb-0.5">
                    <label class="text-[11px] font-semibold text-gray-600">{{ $label }}</label>
                    <button type="button" x-show="edited.{{ $skill }} && suggestion('{{ $skill }}')" x-cloak @click="resetComment('{{ $skill }}')" class="text-[10px] font-semibold text-primary hover:underline">Dùng lại gợi ý</button>
                </div>
                <textarea name="{{ $skill }}_comment" rows="2" x-model="comments.{{ $skill }}" @input="edited.{{ $skill }} = true" maxlength="3000"
                          class="w-full text-xs text-gray-800 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs leading-relaxed"
                          placeholder="Nhận xét {{ mb_strtolower($label) }}..."></textarea>
            </div>
        @endforeach
        <div>
            <label class="text-[11px] font-semibold text-gray-600 mb-0.5 block">Ghi chú chung / lời khuyên của người chấm</label>
            <textarea name="teacher_comments" rows="2" maxlength="3000" class="w-full text-xs text-gray-800 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" placeholder="Định hướng lộ trình, lưu ý cho tư vấn viên...">{{ old('teacher_comments', $sub?->teacher_comments) }}</textarea>
        </div>
    </div>
</div>
