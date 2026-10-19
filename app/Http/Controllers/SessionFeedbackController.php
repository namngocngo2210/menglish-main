<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\User;
use App\Support\ReportPeriod;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Danh sách feedback buổi học (chủ dự án 17/10/2026) cho Học vụ / Học thuật / Admin (class_quality.view — giáo viên không thấy):
 * phiếu phụ huynh chấm sao trên cổng học viên và góp ý của Học vụ trong báo cáo ngày, theo tháng của buổi học, lớp trong phạm vi
 * người xem. Lọc theo lớp, giáo viên buổi, số sao / nhóm góp ý / mức đánh giá; ô số liệu tính trên cả tháng (trước lọc sao, nhóm, tìm).
 */
class SessionFeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        [$from, $to] = ReportPeriod::monthRange($month);
        $source = $request->input('source') === ClassSessionFeedback::SOURCE_STAFF ? ClassSessionFeedback::SOURCE_STAFF : ClassSessionFeedback::SOURCE_PARENT;
        $classId = $request->integer('class_id') ?: null;
        $teacherId = $request->integer('teacher_id') ?: null;
        $rating = in_array($request->input('rating'), ['low', '1', '2', '3', '4', '5'], true) ? $request->input('rating') : null;
        $category = array_key_exists((string) $request->input('category'), ClassSessionFeedback::PARENT_CATEGORIES) ? $request->input('category') : null;
        $verdict = array_key_exists((string) $request->input('verdict'), ClassSessionFeedback::VERDICTS) ? $request->input('verdict') : null;
        $search = trim((string) $request->input('search'));

        $inMonth = fn (Builder $q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $scope = ClassSessionFeedback::query()
            ->whereIn('class_id', ClassModel::query()->visibleTo($user)->select('id'))
            ->whereHas('session', $inMonth);
        $base = (clone $scope)->where('source', $source)
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($teacherId, fn ($q) => $q->whereHas('session', fn ($s) => $s->where('teacher_id', $teacherId)));

        $all = (clone $base)->get(['rating', 'verdict', 'content', 'escalation_id']);
        $stats = $source === ClassSessionFeedback::SOURCE_PARENT
            ? [
                'total' => $all->count(),
                'average' => $all->whereNotNull('rating')->isEmpty() ? null : round((float) $all->whereNotNull('rating')->avg('rating'), 1),
                'low' => $all->filter(fn ($f) => $f->rating !== null && $f->rating <= ClassSessionFeedback::LOW_RATING)->count(),
                'commented' => $all->filter(fn ($f) => filled($f->content))->count(),
            ]
            : [
                'total' => $all->count(),
                'attention' => $all->where('verdict', 'attention')->count(),
                'problem' => $all->where('verdict', 'problem')->count(),
                'escalated' => $all->whereNotNull('escalation_id')->count(),
            ];

        $feedback = (clone $base)
            ->with(['session:id,date,start_time,end_time,teacher_id', 'session.teacher:id,name', 'classModel:id,name,code', 'student:id,name', 'author:id,name', 'escalation:id,status'])
            ->when($rating === 'low', fn ($q) => $q->where('rating', '<=', ClassSessionFeedback::LOW_RATING))
            ->when($rating !== null && $rating !== 'low', fn ($q) => $q->where('rating', (int) $rating))
            ->when($category && $source === ClassSessionFeedback::SOURCE_PARENT, fn ($q) => $q->whereJsonContains('categories', $category))
            ->when($verdict && $source === ClassSessionFeedback::SOURCE_STAFF, fn ($q) => $q->where('verdict', $verdict))
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('content', 'like', "%{$search}%")
                ->orWhereHas('student', fn ($st) => $st->where('name', 'like', "%{$search}%"))))
            ->orderByDesc(ClassSession::select('date')->whereColumn('class_sessions.id', 'class_session_feedbacks.class_session_id'))
            ->latest('id')
            ->paginate($request->perPage(30))->withQueryString()
            ->through(fn (ClassSessionFeedback $f) => [
                'id' => $f->id,
                'date' => $f->session?->date?->toDateString(),
                'time' => $f->session?->start_time ? $f->session->start_time->format('H:i').($f->session->end_time ? '–'.$f->session->end_time->format('H:i') : '') : null,
                'class' => $f->classModel?->name,
                'teacher' => $f->session?->teacher?->name,
                'student' => $f->student?->name,
                'author' => $f->author?->name,
                'rating' => $f->rating,
                'categories' => $f->categoryLabels(),
                'verdict' => $f->verdict,
                'verdict_label' => ClassSessionFeedback::VERDICTS[$f->verdict] ?? null,
                'content' => $f->content,
                'escalated' => $f->escalation_id !== null,
                'escalation_resolved' => $f->escalation?->status === 'resolved',
                'sent_at' => ($f->updated_at ?? $f->created_at)?->toIso8601String(),
            ]);

        $sessionIds = (clone $scope)->select('class_session_id');

        return Inertia::render('ClassQuality/SessionFeedback', [
            'source' => $source,
            'month' => $month,
            'feedback' => $feedback,
            'stats' => $stats,
            'filters' => ['class_id' => $classId, 'teacher_id' => $teacherId, 'rating' => $rating, 'category' => $category, 'verdict' => $verdict],
            'months' => ReportPeriod::monthOptions(6),
            'classes' => Ui::options(ClassModel::query()->visibleTo($user)->whereIn('id', (clone $scope)->select('class_id'))->orderBy('name')->get(['id', 'name']), fn (ClassModel $c) => $c->name),
            'teachers' => User::query()->whereIn('id', ClassSession::query()->whereIn('id', $sessionIds)->whereNotNull('teacher_id')->select('teacher_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            'categories' => Ui::options(ClassSessionFeedback::PARENT_CATEGORIES),
            'verdicts' => Ui::options(ClassSessionFeedback::VERDICTS),
            'lowRating' => ClassSessionFeedback::LOW_RATING,
        ]);
    }
}
