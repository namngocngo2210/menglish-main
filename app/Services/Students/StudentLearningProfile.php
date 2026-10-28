<?php

namespace App\Services\Students;

use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\BigTestResult;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\HomeworkSubmission;
use App\Models\MiniTestScore;
use App\Models\PracticeAttempt;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Hai tab "Kết quả học tập" và "Feedback" của hồ sơ học viên (chủ dự án 28/10/2026), gom từ dữ liệu sẵn có:
 *   Kết quả: Big Test (mọi trạng thái, kèm nhãn), mini test, bài tập về nhà đã chấm / có nhận xét, đề luyện tập đã nộp.
 *   Feedback: nhận xét của GV theo buổi (báo cáo buổi / nhận xét buổi — bỏ bản "Lưu nháp"), và — chỉ người có quyền
 *   class_quality.view như màn Feedback buổi học — phụ huynh chấm sao từng buổi + feedback chặng trên cổng học viên.
 */
class StudentLearningProfile
{
    /** Màn cổng học viên "Phụ huynh gửi feedback" (feedback theo chặng). */
    public const STAGE_FEEDBACK_SCREEN = '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback';

    public function results(Student $student): array
    {
        $bigTests = BigTestResult::with('bigTest:id,title,scheduled_at')
            ->where('student_id', $student->id)
            ->latest('id')
            ->get()
            ->sortByDesc(fn (BigTestResult $r) => $r->bigTest?->scheduled_at?->timestamp ?? 0)
            ->map(fn (BigTestResult $r) => [
                'id' => $r->id,
                'title' => $r->bigTest?->title ?? 'Big Test',
                'date' => $r->bigTest?->scheduled_at?->format('d/m/Y'),
                'is_absent' => (bool) $r->is_absent,
                'listening' => $r->listening_score,
                'reading' => $r->reading_score,
                'writing' => $r->writing_score,
                'speaking' => $r->speaking_score,
                'overall' => $r->overall_score,
                'note' => $r->progress_note,
                'status' => $r->status,
                'status_label' => $r->status_label,
            ])->values()->all();

        $miniTests = MiniTestScore::with('classModel:id,name')
            ->where('student_id', $student->id)
            ->orderByDesc('test_date')->orderByDesc('id')
            ->get()
            ->map(fn (MiniTestScore $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'date' => $m->test_date ? \Illuminate\Support\Carbon::parse($m->test_date)->format('d/m/Y') : null,
                'class_name' => $m->classModel?->name,
                'score' => $m->score,
                'max_score' => $m->max_score,
                'skills' => collect((array) $m->skill_scores)
                    ->filter(fn ($v) => filled($v))
                    ->map(fn ($v, $k) => (MiniTestScore::SKILLS[$k] ?? $k).' '.$v)
                    ->values()->implode(' · '),
                'note' => $m->note,
            ])->values()->all();

        $homework = HomeworkSubmission::with('homework:id,title')
            ->where('student_id', $student->id)
            ->latest('submitted_at')->latest('id')
            ->get();
        $homeworkRows = $homework
            ->filter(fn (HomeworkSubmission $s) => $s->score !== null || filled($s->feedback))
            ->map(fn (HomeworkSubmission $s) => [
                'id' => $s->id,
                'title' => $s->homework?->title ?? 'Bài tập',
                'basket' => HomeworkSubmission::BASKETS[$s->basket][0] ?? null,
                'date' => ($s->reviewed_at ?? $s->submitted_at)?->format('d/m/Y'),
                'score' => $s->score,
                'feedback' => $s->feedback,
            ])->values()->all();

        $practice = PracticeAttempt::with('exam:id,title,code')
            ->where('student_id', $student->id)
            ->where('status', '!=', PracticeAttempt::IN_PROGRESS)
            ->latest('submitted_at')->latest('id')
            ->get()
            ->map(fn (PracticeAttempt $a) => [
                'id' => $a->id,
                'title' => $a->exam?->title ?? $a->exam?->code ?? 'Đề luyện tập',
                'date' => $a->submitted_at?->format('d/m/Y'),
                'score' => $a->score,
                'correct' => $a->correct,
                'total' => $a->total,
            ])->values()->all();

        return [
            'big_tests' => $bigTests,
            'mini_tests' => $miniTests,
            'homework' => $homeworkRows,
            'homework_submitted' => $homework->count(),
            'practice' => $practice,
        ];
    }

    /**
     * @param  Collection<int, ClassSession>  $sessions  buổi học trong lộ trình của học viên (đã nạp classModel, teacher)
     */
    public function feedback(Student $student, Collection $sessions, bool $withParent): array
    {
        $codes = $sessions->filter(fn (ClassSession $s) => $s->date)
            ->mapWithKeys(fn (ClassSession $s) => [$s->id => [TeacherPortalController::remarkRecordCode($s), $s->class_id.'-'.$s->date->toDateString()]]);
        $records = $codes->isEmpty() ? collect() : AcademicRecord::where('module', 'teacher_remarks')
            ->where('status', '!=', 'draft') // nhận xét "Lưu nháp" chưa gửi
            ->whereIn('record_code', $codes->flatten()->all())
            ->get()->keyBy('record_code');

        $remarks = $sessions->filter(fn (ClassSession $s) => $codes->has($s->id))
            ->map(function (ClassSession $s) use ($records, $codes, $student) {
                [$code, $legacy] = $codes->get($s->id);
                $record = $records->get($code) ?? $records->get($legacy);
                $remark = array_filter((array) data_get($record?->data, (string) $student->id, []), fn ($v) => filled($v));
                if ($remark === []) {
                    return null;
                }
                $remark = StudentPortalController::normalizeRemark($remark);

                return [
                    'session_id' => $s->id,
                    'sort' => $s->date->toDateString().' '.($s->start_time?->format('H:i') ?? ''),
                    'date' => $s->date->format('d/m/Y'),
                    'class_name' => $s->classModel?->name,
                    'teacher' => $s->teacher?->name ?? $s->classModel?->teacher?->name,
                    'monsters' => $remark['monsters'] ?? null,
                    'grammar' => $remark['grammar'] ?? null,
                    'hw_score' => $remark['hw_score'] ?? null,
                    'attitude' => $remark['attitude'] ?? null,
                    'result' => $remark['result'] ?? null,
                    'comment' => $remark['comment'] ?? null,
                ];
            })
            ->filter()
            ->sortByDesc('sort')
            ->map(fn (array $r) => collect($r)->except('sort')->all())
            ->values()->all();

        $data = ['remarks' => $remarks, 'parent' => null];
        if (! $withParent) {
            return $data;
        }

        $ratings = ClassSessionFeedback::with(['session:id,date,start_time,teacher_id', 'session.teacher:id,name', 'classModel:id,name', 'author:id,name'])
            ->where('source', ClassSessionFeedback::SOURCE_PARENT)
            ->where('student_id', $student->id)
            ->get()
            ->sortByDesc(fn (ClassSessionFeedback $f) => $f->session?->date?->timestamp ?? 0)
            ->values();
        $stage = AcademicRecord::where('screen_key', self::STAGE_FEEDBACK_SCREEN)
            ->where('data->student_id', (string) $student->id)
            ->latest()
            ->get();

        $data['parent'] = [
            'average' => $ratings->whereNotNull('rating')->isEmpty() ? null : round((float) $ratings->whereNotNull('rating')->avg('rating'), 1),
            'low' => $ratings->filter(fn ($f) => $f->rating !== null && $f->rating <= ClassSessionFeedback::LOW_RATING)->count(),
            'sessions' => $ratings->map(fn (ClassSessionFeedback $f) => [
                'id' => $f->id,
                'date' => $f->session?->date?->format('d/m/Y'),
                'class_name' => $f->classModel?->name,
                'teacher' => $f->session?->teacher?->name,
                'rating' => $f->rating,
                'categories' => $f->categoryLabels(),
                'content' => $f->content,
                'author' => $f->author?->name,
            ])->all(),
            'stages' => $stage->map(fn (AcademicRecord $r) => [
                'id' => $r->id,
                'stage_name' => data_get($r->data, 'stage_name'),
                'class_name' => data_get($r->data, 'class_name'),
                'rating' => (int) data_get($r->data, 'muc_do_hai_long', 0) ?: null,
                'categories' => (array) data_get($r->data, 'categories', []),
                'content' => data_get($r->data, 'noi_dung_feedback') ?: null,
                'date' => data_get($r->data, 'submitted_at') ?? $r->created_at?->format('d/m/Y H:i'),
            ])->values()->all(),
        ];

        return $data;
    }
}
