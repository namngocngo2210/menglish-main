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
        'listening' => 'bg-indigo-50/50 border-indigo-100 text-indigo-700',
        'reading_writing' => 'bg-emerald-50/50 border-emerald-100 text-emerald-700',
        'speaking' => 'bg-rose-50/50 border-rose-100 text-rose-700',
    ];
@endphp

<div class="space-y-3 text-xs" data-testid="rubric-result">
    <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-200/80 rounded-2xl flex items-center justify-between gap-4 flex-wrap">
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
                    <h4 class="font-black text-gray-900 text-sm">Kết quả test đầu vào</h4>
                    @if ($rubric && ! $rubric['legacy'])
                        <span class="px-2 py-0.5 bg-white border border-amber-300 text-amber-900 rounded-md text-[10px] font-bold shadow-2xs">{{ $rubric['grade_group_label'] }}</span>
                    @endif
                </div>
                <p class="text-amber-950 font-semibold mt-0.5">
                    Lớp xếp: <span class="text-primary-container font-bold">{{ $rubric['chosen_class'] ?? $submission?->recommended_course ?? '—' }}</span>
                </p>
                @if ($rubric && $rubric['overridden'])
                    <p class="text-[11px] text-gray-600">Lớp đề xuất theo thang điểm: <strong>{{ $rubric['suggested_class'] }}</strong> (Học vụ đã chọn lại)</p>
                @elseif ($rubric && ! $rubric['legacy'] && ! $rubric['has_rubric'])
                    <p class="text-[11px] text-amber-800">{{ \App\Services\PlacementRubricService::noRubricNotice() }}</p>
                @endif
            </div>
        </div>
        @if ($submission)
            <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]) }}" target="_blank" class="shrink-0 px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs">
                <span class="material-symbols-outlined text-[15px] text-amber-400">military_tech</span>
                <span>Xem Scorecard</span>
            </a>
        @endif
    </div>

    @if ($rubric && $rubric['legacy'])
        <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-[11px] text-slate-700">
            Bài chấm theo cách cũ (trước khi áp dụng thang điểm khối lớp). Điểm từng kỹ năng:
            Nghe {{ $fmt($submission?->listening_score) }} · Đọc {{ $fmt($submission?->reading_score) }} · Viết {{ $fmt($submission?->writing_score) }} · Nói {{ $fmt($submission?->speaking_score) }}.
            Sửa điểm để chấm lại theo thang điểm mới.
        </div>
    @elseif ($submission)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            @foreach ($skills as $skill => $label)
                <div class="p-2.5 rounded-xl border {{ $skillStyles[$skill] }} space-y-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="font-bold text-gray-800 uppercase text-[10px]">{{ $label }}</span>
                        <span class="text-base font-black font-mono">{{ $fmt($skillScores[$skill]) }}@if ($rubric)<span class="text-[10px] text-gray-500">/{{ $rubric['max'][$skill] }}</span>@endif</span>
                    </div>
                    @if (filled($rubric['comments'][$skill] ?? null))
                        <p class="text-[11px] text-gray-700 leading-relaxed">{{ $rubric['comments'][$skill] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if (filled($submission?->teacher_comments))
        <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
            <div class="flex items-center gap-1 text-[11px] font-bold text-slate-700">
                <span class="material-symbols-outlined text-[14px] text-primary">rate_review</span>
                <span>Ghi chú của người chấm{{ $submission->grader ? ' ('.$submission->grader->name.')' : '' }}:</span>
            </div>
            <p class="text-gray-800 leading-relaxed text-[11px] whitespace-pre-line">{{ $submission->teacher_comments }}</p>
        </div>
    @endif
</div>
