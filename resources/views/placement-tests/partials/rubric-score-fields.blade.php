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
        'listening' => 'text-secondary border-secondary/20 bg-secondary/5',
        'reading_writing' => 'text-tertiary border-tertiary/20 bg-tertiary/5',
        'speaking' => 'text-primary border-primary/20 bg-primary/5',
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
                percent(skill) { const v = this.num(skill); return v === null ? 0 : Math.max(0, Math.min(100, Math.round(v / this.max(skill) * 100))); },
                // Nhận xét đang khớp gợi ý theo thang điểm (chưa sửa tay).
                get synced() { return this.hasRubric && this.complete && ['listening', 'reading_writing', 'speaking'].every((s) => !this.edited[s]); },
                // "Tự động tạo nhận xét & Xếp lớp": áp lại gợi ý cho cả 3 kỹ năng và lớp đề xuất.
                applyAll() {
                    ['listening', 'reading_writing', 'speaking'].forEach((s) => this.resetComment(s));
                    if (this.suggestedClass) this.chosen = this.suggestedClass;
                },
                resetComment(skill) { this.edited[skill] = false; this.comments[skill] = this.suggestion(skill) || ''; },
            };
        };
    </script>
@endonce

<div x-data="rubricScoreForm({{ Js::from($rubricConfig) }}, {{ Js::from($initial) }})" class="space-y-md font-body-small text-body-small" data-rubric-form>
    {{-- Mockup kh_i_test_online: "Select Khối / Thang điểm tự động" + "Tự động tạo nhận xét & Xếp lớp" --}}
    <div class="flex flex-col gap-sm rounded-lg bg-surface-container-low p-md sm:flex-row sm:items-end">
        <div class="flex-1">
            <label class="mb-xs flex items-center gap-xs font-label text-label uppercase text-on-surface-variant">
                <span class="material-symbols-outlined text-[16px]">school</span>Khối lớp / Thang điểm tự động <span class="text-error">*</span>
            </label>
            <select name="grade_group" x-model="group" required class="w-full rounded-lg border-outline-variant bg-surface-container-lowest font-body-base text-body-base">
                @foreach ($Rubric::gradeGroups() as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('grade_group')<p class="mt-xs text-error">{{ $message }}</p>@enderror
        </div>
        <button type="button" @click="applyAll()" :disabled="!hasRubric"
                class="inline-flex items-center justify-center gap-xs rounded-lg bg-secondary px-md py-sm font-body-medium text-body-medium text-white hover:opacity-90 disabled:opacity-50">
            <span class="material-symbols-outlined text-[18px]">auto_awesome</span>Tự động tạo nhận xét &amp; Xếp lớp
        </button>
    </div>
    <p x-show="!hasRubric" x-cloak class="rounded-lg border-l-4 border-amber-500 bg-amber-50 px-md py-sm font-semibold text-amber-900">
        {{ $Rubric::noRubricNotice() }}. Điểm từng kỹ năng nhập theo thang tạm 0–10, không quy đổi ra lớp.
    </p>

    {{-- Ô điểm từng kỹ năng: điểm / tối đa, thanh tiến độ, nhận xét gợi ý --}}
    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
        @foreach ($Rubric::SKILLS as $skill => $label)
            <div class="space-y-sm rounded-lg border p-md {{ $skillStyles[$skill] }}">
                <div class="flex items-center justify-between gap-xs">
                    <label for="score_{{ $skill }}" class="font-label text-label uppercase">
                        Điểm {{ $label }}
                        @if ($skill === 'speaking')<span class="block normal-case text-on-surface-variant">(Nhập tay)</span>@endif
                    </label>
                    <span class="font-code text-code" x-text="'/ ' + max('{{ $skill }}')"></span>
                </div>
                <input id="score_{{ $skill }}" type="number" step="0.5" min="0" :max="max('{{ $skill }}')" name="{{ $skill }}_score" x-model="scores.{{ $skill }}" @if ($skill === 'speaking') :required="hasRubric" @else required @endif
                       :class="overMax('{{ $skill }}') ? 'border-error ring-2 ring-error/20' : 'border-outline-variant'"
                       class="w-full rounded-lg border bg-surface-container-lowest p-sm text-center font-code text-h3" />
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-container-high">
                    <div class="h-full rounded-full bg-current transition-all" :style="'width: ' + percent('{{ $skill }}') + '%'"></div>
                </div>
                <p class="min-h-[2.5rem] font-caption text-caption italic text-on-surface-variant" x-text="suggestion('{{ $skill }}') || 'Nhận xét {{ mb_strtolower($label) }} sẽ tự động sinh...'"></p>
                @error($skill.'_score')<p class="text-error">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>

    {{-- Nhận xét gợi ý (tự động theo thang điểm) — người chấm sửa được --}}
    <div class="space-y-sm rounded-lg border border-surface-container-highest p-md">
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <span class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-secondary">auto_awesome</span>Nhận xét gợi ý (Tự động theo Thang điểm)
            </span>
            <span x-show="synced" class="inline-flex items-center gap-xs rounded-full bg-tertiary/10 px-sm py-0.5 font-caption text-caption font-bold text-tertiary">
                <span class="material-symbols-outlined text-[14px]">check_circle</span>Đã đồng bộ Thang điểm
            </span>
        </div>
        @foreach ($Rubric::SKILLS as $skill => $label)
            <div>
                <div class="mb-0.5 flex items-center justify-between gap-sm">
                    <label for="comment_{{ $skill }}" class="font-caption text-caption font-semibold text-on-surface-variant">{{ $label }}</label>
                    <button type="button" x-show="edited.{{ $skill }} && suggestion('{{ $skill }}')" x-cloak @click="resetComment('{{ $skill }}')" class="font-caption text-caption font-semibold text-primary hover:underline">Dùng lại gợi ý</button>
                </div>
                <textarea id="comment_{{ $skill }}" name="{{ $skill }}_comment" rows="2" x-model="comments.{{ $skill }}" @input="edited.{{ $skill }} = true" maxlength="3000"
                          class="w-full rounded-lg border-outline-variant bg-surface-container-lowest font-body-small text-body-small leading-relaxed"
                          placeholder="Nhận xét {{ mb_strtolower($label) }}..."></textarea>
            </div>
        @endforeach
        <div>
            <label for="teacher_comments" class="mb-0.5 block font-caption text-caption font-semibold text-on-surface-variant">Ghi chú chung / lời khuyên của người chấm</label>
            <textarea id="teacher_comments" name="teacher_comments" rows="2" maxlength="3000" class="w-full rounded-lg border-outline-variant bg-surface-container-lowest font-body-small text-body-small" placeholder="Định hướng lộ trình, lưu ý cho tư vấn viên...">{{ old('teacher_comments', $sub?->teacher_comments) }}</textarea>
        </div>
        <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">info</span>CM/Tư vấn viên có thể chỉnh sửa bổ sung nội dung này trước khi lưu.</p>
    </div>

    {{-- Tổng điểm hệ thống + Đề xuất xếp lớp tự động --}}
    <div class="flex flex-col gap-md rounded-lg bg-inverse-surface p-md text-inverse-on-surface sm:flex-row sm:items-center">
        <div class="flex items-center gap-md">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-h3 text-h3 text-white" x-text="complete ? total : '—'"></div>
            <div>
                <p class="font-label text-label uppercase opacity-80">Tổng điểm hệ thống</p>
                <p class="font-h2 text-h2"><span x-text="complete ? total : '—'"></span> <span class="font-body-medium text-body-medium opacity-70" x-text="'/ ' + maxTotal + ' điểm'"></span></p>
            </div>
        </div>
        <div class="hidden h-10 w-px bg-white/20 sm:block"></div>
        <div class="flex-1">
            <p class="font-label text-label uppercase opacity-80">Đề xuất xếp lớp tự động</p>
            <p class="flex items-center gap-xs font-body-semibold text-body-semibold">
                <span class="material-symbols-outlined text-[18px]">school</span>
                <span x-text="hasRubric ? (suggestedClass || 'Nhập đủ 3 kỹ năng để tra lớp') : 'Không có — chọn lớp thủ công'"></span>
            </p>
        </div>
    </div>

    <div>
        <label for="chosen_class" class="mb-xs block font-body-medium text-body-medium text-on-surface">
            Lớp xếp cho học viên <span x-show="!hasRubric" class="text-error">*</span>
            <span x-show="hasRubric" class="font-body-small text-body-small text-on-surface-variant">(để trống = theo lớp đề xuất; có thể chọn lại)</span>
        </label>
        <input id="chosen_class" type="text" name="chosen_class" x-model="chosen" list="rubric-class-options" :required="!hasRubric" maxlength="255"
               :placeholder="suggestedClass || 'Nhập / chọn lớp phù hợp'"
               class="w-full rounded-lg border-outline-variant bg-surface-container-lowest font-body-medium text-body-medium text-primary" />
        <datalist id="rubric-class-options">
            @foreach ($Rubric::classOptions() as $option)
                <option value="{{ $option }}"></option>
            @endforeach
        </datalist>
        @error('chosen_class')<p class="mt-xs text-error">{{ $message }}</p>@enderror
    </div>
</div>
