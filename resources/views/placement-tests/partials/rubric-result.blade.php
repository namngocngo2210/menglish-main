{{--
    Khối kết quả test đầu vào theo thang điểm khối lớp (BA Q2) — hiển thị trên hồ sơ khách.
    Props: $submission (?PlacementTestSubmission), $rubric (mảng từ CrmController::rubricSummary), $fallbackScore (?string test_score cũ)
--}}
@php
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    $skills = \App\Services\PlacementRubricService::SKILLS;
    $skillScores = [
        'listening' => $submission?->listening_score,
        'reading_writing' => $submission?->reading_writing_score,
        'speaking' => $submission?->speaking_score,
    ];
    $skillStyles = [
        'listening' => 'bg-secondary/5 border-secondary/20 text-secondary',
        'reading_writing' => 'bg-tertiary/5 border-tertiary/20 text-tertiary',
        'speaking' => 'bg-primary/5 border-primary/20 text-primary',
    ];
@endphp

<div class="space-y-3 text-xs" data-testid="rubric-result">
    <div class="flex flex-wrap items-center justify-between gap-md rounded-xl border border-primary/20 bg-primary-fixed/30 p-md">
        <div class="flex items-center gap-3">
            <div class="w-14 h-12 px-1 rounded-xl bg-primary-container text-white flex flex-col items-center justify-center font-black shadow-sm">
                @if ($rubric && ! $rubric['legacy'])
                    <span class="text-sm leading-none">{{ $fmt($rubric['total']) }}</span>
                    <span class="text-[9px] tracking-wider font-semibold opacity-90">/ {{ $rubric['max_total'] }}</span>
                @else
                    <span class="text-sm leading-none">{{ $submission?->overall_score ?? $fallbackScore ?? '—' }}</span>
                @endif
            </div>
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <h4 class="font-body-semibold text-body-semibold text-on-surface">Kết quả test đầu vào</h4>
                    @if ($rubric && ! $rubric['legacy'])
                        <span class="px-2 py-0.5 bg-surface-container-lowest border border-warning/30 text-on-warning-container rounded-md text-[10px] font-bold shadow-2xs">{{ $rubric['grade_group_label'] }}</span>
                    @endif
                </div>
                <p class="mt-0.5 font-semibold text-on-surface">
                    Đề xuất xếp lớp: <span class="text-primary-container font-bold">{{ $rubric['chosen_class'] ?? $submission?->recommended_course ?? '—' }}</span>
                </p>
                @if ($rubric && $rubric['overridden'])
                    <p class="text-[11px] text-on-surface-variant">Lớp đề xuất theo thang điểm: <strong>{{ $rubric['suggested_class'] }}</strong> (Học vụ đã chọn lại)</p>
                @elseif ($rubric && ! $rubric['legacy'] && ! $rubric['has_rubric'])
                    <p class="text-[11px] text-on-warning-container">{{ \App\Services\PlacementRubricService::noRubricNotice() }}</p>
                @endif
            </div>
        </div>
        @if ($submission)
            <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]) }}" target="_blank" class="shrink-0 px-3.5 py-2 bg-inverse-surface hover:bg-inverse-surface/90 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs">
                <span class="material-symbols-outlined text-[15px] text-warning/70">military_tech</span>
                <span>Xem Scorecard</span>
            </a>
        @endif
    </div>

    @if ($rubric && $rubric['legacy'])
        <div class="p-3 bg-surface-container-low border border-surface-container-highest rounded-xl text-[11px] text-on-surface-variant">
            Bài chấm theo cách cũ (trước khi áp dụng thang điểm khối lớp). Điểm từng kỹ năng:
            Nghe {{ $fmt($submission?->listening_score) }} · Đọc {{ $fmt($submission?->reading_score) }} · Viết {{ $fmt($submission?->writing_score) }} · Nói {{ $fmt($submission?->speaking_score) }}.
            Sửa điểm để chấm lại theo thang điểm mới.
        </div>
    @elseif ($submission)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            @foreach ($skills as $skill => $label)
                <div class="p-2.5 rounded-xl border {{ $skillStyles[$skill] }} space-y-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="font-bold text-on-surface uppercase text-[10px]">{{ $label }}</span>
                        <span class="text-base font-black font-mono">{{ $fmt($skillScores[$skill]) }}@if ($rubric)<span class="text-[10px] text-on-surface-variant">/{{ $rubric['max'][$skill] }}</span>@endif</span>
                    </div>
                    @if (filled($rubric['comments'][$skill] ?? null))
                        <p class="text-[11px] text-on-surface-variant leading-relaxed">{{ $rubric['comments'][$skill] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if (filled($submission?->teacher_comments))
        <div class="p-3 bg-surface-container-low border border-surface-container-highest rounded-xl space-y-1">
            <div class="flex items-center gap-1 text-[11px] font-bold text-on-surface-variant">
                <span class="material-symbols-outlined text-[14px] text-primary">rate_review</span>
                <span>Ghi chú của người chấm{{ $submission->grader ? ' ('.$submission->grader->name.')' : '' }}:</span>
            </div>
            <p class="text-on-surface leading-relaxed text-[11px] whitespace-pre-line">{{ $submission->teacher_comments }}</p>
        </div>
    @endif
</div>
