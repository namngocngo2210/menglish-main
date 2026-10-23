<?php

namespace App\Services\Practice;

use App\Models\ClassModel;
use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Models\Student;
use App\Models\User;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Báo cáo Kết quả luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §7). Chỉ tính lượt đã nộp của học viên trong phạm vi
 * người xem (`Student::visibleTo`); tổng hợp bằng GROUP BY, số câu truy vấn không phụ thuộc số dòng.
 *
 * Bộ lọc (đã chuẩn hoá bằng `filters()`): grade, term, class_id, student_id, from, to (Y-m-d, tính cả ngày, theo submitted_at),
 * q (tìm theo tên / mã học viên, trong phạm vi người xem), mine (chỉ học viên các lớp người xem là giáo viên / trợ giảng / GVNN — mục "Kết quả luyện tập" ở Cổng Giáo viên).
 */
class PracticeReport
{
    public const PER_PAGE = 20;

    public const QUESTION_PER_PAGE = 30;

    public const MIN_QUESTION_ATTEMPTS = 10;

    private const FILTER_KEYS = ['grade', 'term', 'class_id', 'student_id', 'q', 'from', 'to', 'mine'];

    private const SEARCH_MAX_LENGTH = 100;

    /**
     * Bộ lọc từ input người dùng: giá trị ngoài danh sách / sai định dạng / lớp ngoài phạm vi người xem → null (bỏ qua).
     *
     * @param  array<string, mixed>  $input
     * @return array{grade: ?int, term: ?string, class_id: ?int, student_id: ?int, q: ?string, from: ?string, to: ?string, mine: bool}
     */
    public function filters(User $viewer, array $input): array
    {
        $int = fn (string $key) => is_scalar($input[$key] ?? null) && ctype_digit((string) $input[$key]) ? (int) $input[$key] : null;
        $grade = $int('grade');
        $term = is_string($input['term'] ?? null) ? $input['term'] : null;
        $classId = $int('class_id');
        $search = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, self::SEARCH_MAX_LENGTH) : '';

        return [
            'grade' => in_array($grade, PracticeCatalog::GRADES, true) ? $grade : null,
            'term' => array_key_exists((string) $term, PracticeCatalog::TERMS) ? $term : null,
            'class_id' => $classId !== null && ClassModel::query()->visibleTo($viewer)->whereKey($classId)->exists() ? $classId : null,
            'student_id' => $int('student_id'),
            'q' => $search !== '' ? $search : null,
            'from' => $this->date($input['from'] ?? null),
            'to' => $this->date($input['to'] ?? null),
            'mine' => filter_var($input['mine'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Thẻ tổng quan: số lượt, điểm TB, số học viên đã luyện, điểm TB theo kỹ năng (thang 10, thứ tự kỹ năng trong đề;
     * null = chưa có câu của kỹ năng đó). 2 truy vấn (+1 lấy lớp của giáo viên khi phạm vi "của tôi").
     *
     * @return array{attempts: int, avg_score: ?float, students: int, skills: array<string, ?float>}
     */
    public function summary(User $viewer, array $filters): array
    {
        $attempts = $this->attempts($viewer, $filters);

        $totals = (clone $attempts)->toBase()
            ->selectRaw('count(*) as attempts, avg(practice_attempts.score) as avg_score, count(distinct practice_attempts.student_id) as students')
            ->first();

        $skills = PracticeAttemptItem::query()->toBase()
            ->whereIn('attempt_id', (clone $attempts)->select('practice_attempts.id'))
            ->selectRaw('skill, '.$this->correctSql('is_correct').' as correct, count(*) as total')
            ->groupBy('skill')
            ->get()
            ->keyBy('skill');

        return [
            'attempts' => (int) $totals->attempts,
            'avg_score' => $this->round($totals->avg_score),
            'students' => (int) $totals->students,
            'skills' => collect(PracticeCatalog::SKILLS)->mapWithKeys(fn (string $skill) => [
                $skill => isset($skills[$skill]) && $skills[$skill]->total > 0
                    ? round($skills[$skill]->correct / $skills[$skill]->total * 10, 2)
                    : null,
            ])->all(),
        ];
    }

    /**
     * Bảng theo học viên, mới luyện gần nhất trước, 20 dòng / trang. Số truy vấn cố định: đếm + trang, điểm lần gần nhất,
     * kỹ năng theo học viên, hồ sơ + lớp hiện tại.
     *
     * @return LengthAwarePaginator<int, array{student_id: int, name: string, code: ?string, class_name: ?string, attempts: int, avg_score: ?float, last_score: ?float, last_submitted_at: ?string, weakest_skill: ?string, violation_attempts: int}>
     */
    public function studentRows(User $viewer, array $filters): LengthAwarePaginator
    {
        $attempts = $this->attempts($viewer, $filters);

        $page = (clone $attempts)->toBase()
            ->selectRaw('practice_attempts.student_id, count(*) as attempts, avg(practice_attempts.score) as avg_score, '
                .'max(practice_attempts.submitted_at) as last_submitted_at, '
                .'sum(case when practice_attempts.violation_count > 0 then 1 else 0 end) as violation_attempts')
            ->groupBy('practice_attempts.student_id')
            ->orderByDesc('last_submitted_at')
            ->orderByDesc('practice_attempts.student_id')
            ->paginate(self::PER_PAGE);

        $ids = collect($page->items())->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return $page;
        }

        $ofPage = fn () => (clone $attempts)->whereIn('practice_attempts.student_id', $ids);
        $lastScores = $this->lastScores($ofPage);
        $weakest = $this->weakestSkills($ofPage);
        $students = Student::query()->whereKey($ids)->with('currentClass:id,name')
            ->get(['id', 'name', 'code', 'current_class_id'])->keyBy('id');

        return $page->through(function ($row) use ($lastScores, $weakest, $students) {
            $id = (int) $row->student_id;
            $student = $students->get($id);

            return [
                'student_id' => $id,
                'name' => $student?->name ?? '',
                'code' => $student?->code,
                'class_name' => $student?->currentClass?->name,
                'attempts' => (int) $row->attempts,
                'avg_score' => $this->round($row->avg_score),
                'last_score' => $this->round($lastScores[$id] ?? null),
                'last_submitted_at' => $row->last_submitted_at ? Carbon::parse($row->last_submitted_at)->toIso8601String() : null,
                'weakest_skill' => $weakest[$id] ?? null,
                'violation_attempts' => (int) $row->violation_attempts,
            ];
        });
    }

    /**
     * Bộ lọc phân tích câu hỏi (không phụ thuộc người xem): grade, term, skill (trong danh mục), type (chuỗi).
     *
     * @param  array<string, mixed>  $input
     * @return array{grade: ?int, term: ?string, skill: ?string, type: ?string}
     */
    public function questionFilters(array $input): array
    {
        $grade = is_scalar($input['grade'] ?? null) && ctype_digit((string) $input['grade']) ? (int) $input['grade'] : null;
        $term = is_string($input['term'] ?? null) ? $input['term'] : null;
        $skill = is_string($input['skill'] ?? null) ? $input['skill'] : null;
        $type = is_string($input['type'] ?? null) ? mb_substr(trim($input['type']), 0, self::SEARCH_MAX_LENGTH) : '';

        return [
            'grade' => in_array($grade, PracticeCatalog::GRADES, true) ? $grade : null,
            'term' => array_key_exists((string) $term, PracticeCatalog::TERMS) ? $term : null,
            'skill' => in_array($skill, PracticeCatalog::SKILLS, true) ? $skill : null,
            'type' => $type !== '' ? $type : null,
        ];
    }

    /**
     * Phân tích câu hỏi: câu có tỉ lệ sai cao nhất trước (rồi nhiều lượt làm hơn), chỉ tính câu của lượt đã nộp và có
     * ít nhất $minAttempts lượt làm; câu bỏ trống tính là sai. Câu đã sửa (`edited_at`) chỉ tính lượt bắt đầu sau lần
     * sửa cuối — lượt cũ chấm theo bản chụp trước khi sửa (vd. khoá đáp án sai). Mọi học viên (công cụ chất lượng câu
     * hỏi, không theo phạm vi người xem). Số truy vấn cố định: đếm + trang, đáp án sai nhiều nhất của các câu trong trang.
     *
     * @param  array<string, mixed>  $filters  từ `questionFilters()`
     * @return LengthAwarePaginator<int, array{question_id: int, stem: string, grade: int, term: string, skill: string, type: string, answer: string, attempts: int, wrong: int, wrong_rate: float, top_wrong_choice: ?string, edited_at: ?string}>
     */
    public function questionStats(array $filters, int $minAttempts = self::MIN_QUESTION_ATTEMPTS): LengthAwarePaginator
    {
        $f = array_merge(['grade' => null, 'term' => null, 'skill' => null, 'type' => null], $filters);
        $wrongSql = 'sum(case when practice_attempt_items.is_correct = 1 then 0 else 1 end)';

        $stats = $this->gradedItemsSinceEdit()
            ->groupBy('practice_attempt_items.question_id')
            ->havingRaw('count(*) >= ?', [max(1, $minAttempts)])
            ->selectRaw("practice_attempt_items.question_id as stat_question_id, count(*) as attempts, {$wrongSql} as wrong");

        $page = DB::table('question_bank_questions as q')
            ->joinSub($stats, 'stats', 'stats.stat_question_id', '=', 'q.id')
            ->when($f['grade'], fn ($query, int $grade) => $query->where('q.grade', $grade))
            ->when($f['term'], fn ($query, string $term) => $query->where('q.term', $term))
            ->when($f['skill'], fn ($query, string $skill) => $query->where('q.skill', $skill))
            ->when($f['type'], fn ($query, string $type) => $query->where('q.type', $type))
            ->orderByRaw('(stats.wrong * 1.0 / stats.attempts) desc')
            ->orderByDesc('stats.attempts')
            ->orderBy('q.id')
            ->select('q.id', 'q.stem', 'q.grade', 'q.term', 'q.skill', 'q.type', 'q.answer', 'q.edited_at', 'stats.attempts', 'stats.wrong')
            ->paginate(self::QUESTION_PER_PAGE);

        $ids = collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $topWrong = $ids === [] ? [] : $this->topWrongChoices($ids);

        return $page->through(fn ($row) => [
            'question_id' => (int) $row->id,
            'stem' => (string) $row->stem,
            'grade' => (int) $row->grade,
            'term' => $row->term,
            'skill' => $row->skill,
            'type' => $row->type,
            'answer' => $row->answer,
            'attempts' => (int) $row->attempts,
            'wrong' => (int) $row->wrong,
            'wrong_rate' => round($row->wrong / $row->attempts, 4),
            'top_wrong_choice' => $topWrong[(int) $row->id] ?? null,
            'edited_at' => $row->edited_at ? Carbon::parse($row->edited_at)->toDateString() : null,
        ]);
    }

    /** Câu (có câu hỏi gốc) của lượt đã nộp; câu đã sửa chỉ tính lượt tạo sau lần sửa cuối. */
    private function gradedItemsSinceEdit(): QueryBuilder
    {
        return DB::table('practice_attempt_items')
            ->join('practice_attempts', 'practice_attempts.id', '=', 'practice_attempt_items.attempt_id')
            ->join('question_bank_questions as edited_q', 'edited_q.id', '=', 'practice_attempt_items.question_id')
            ->where('practice_attempts.status', PracticeAttempt::SUBMITTED)
            ->where(fn (QueryBuilder $q) => $q->whereNull('edited_q.edited_at')
                ->orWhereColumn('practice_attempts.created_at', '>', 'edited_q.edited_at'));
    }

    /**
     * Đáp án chọn sai nhiều nhất của từng câu (bỏ trống không tính; bằng nhau → theo thứ tự chữ cái).
     *
     * @param  list<int>  $questionIds
     * @return array<int, string>
     */
    private function topWrongChoices(array $questionIds): array
    {
        $rows = $this->gradedItemsSinceEdit()
            ->whereIn('practice_attempt_items.question_id', $questionIds)
            ->whereNotNull('practice_attempt_items.chosen')
            ->where(fn ($q) => $q->where('practice_attempt_items.is_correct', 0)->orWhereNull('practice_attempt_items.is_correct'))
            ->groupBy('practice_attempt_items.question_id', 'practice_attempt_items.chosen')
            ->selectRaw('practice_attempt_items.question_id, practice_attempt_items.chosen, count(*) as picks')
            ->get();

        return $rows->groupBy('question_id')
            ->map(fn (Collection $choices) => $choices->sortBy(fn ($c) => [-$c->picks, $c->chosen])->first()->chosen)
            ->mapWithKeys(fn (string $choice, $questionId) => [(int) $questionId => $choice])
            ->all();
    }

    /** Danh sách lượt đã nộp (đã lọc), nộp gần nhất trước — trang một học viên. */
    public function attemptList(User $viewer, array $filters): LengthAwarePaginator
    {
        return $this->attempts($viewer, $filters)
            ->orderByDesc('practice_attempts.submitted_at')
            ->orderByDesc('practice_attempts.id')
            ->paginate(self::PER_PAGE, ['id', 'student_id', 'grade', 'term', 'started_at', 'deadline_at', 'submitted_at',
                'score', 'correct', 'total', 'violation_count', 'auto_submitted', 'submit_reason']);
    }

    /** Lượt đã nộp của học viên trong phạm vi người xem, theo bộ lọc. */
    private function attempts(User $viewer, array $filters): Builder
    {
        $f = array_merge(array_fill_keys(self::FILTER_KEYS, null), $filters);

        // Tính tập học viên một lần (phạm vi "của tôi" truy vấn danh sách lớp) rồi clone cho các câu tổng hợp.
        $students = Student::query()->visibleTo($viewer)
            ->when($f['class_id'], fn (Builder $q, int $classId) => $q->inClasses([$classId]))
            ->when($f['mine'], fn (Builder $q) => $q->inClasses($this->ownClassIds($viewer)))
            // Tìm tên / mã: thêm điều kiện AND vào tập học viên đã giới hạn phạm vi, không bao giờ nới phạm vi.
            ->when($f['q'], fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('students.name', 'like', "%{$search}%")
                ->orWhere('students.code', 'like', "%{$search}%")))
            ->select('students.id');

        return PracticeAttempt::query()
            ->where('practice_attempts.status', PracticeAttempt::SUBMITTED)
            ->whereIn('practice_attempts.student_id', $students)
            ->when($f['student_id'], fn (Builder $q, int $id) => $q->where('practice_attempts.student_id', $id))
            ->when($f['grade'], fn (Builder $q, int $grade) => $q->where('practice_attempts.grade', $grade))
            ->when($f['term'], fn (Builder $q, string $term) => $q->where('practice_attempts.term', $term))
            ->when($f['from'], fn (Builder $q, string $from) => $q->where('practice_attempts.submitted_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($f['to'], fn (Builder $q, string $to) => $q->where('practice_attempts.submitted_at', '<', Carbon::parse($to)->addDay()->startOfDay()));
    }

    /**
     * Điểm lượt nộp gần nhất của từng học viên (trùng thời điểm → lượt có id lớn hơn).
     *
     * @param  callable(): Builder  $ofPage
     * @return array<int, mixed>
     */
    private function lastScores(callable $ofPage): array
    {
        $latest = $ofPage()->toBase()
            ->selectRaw('practice_attempts.student_id as latest_student_id, max(practice_attempts.submitted_at) as latest_at')
            ->groupBy('practice_attempts.student_id');

        return $ofPage()->toBase()
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('practice_attempts.student_id', '=', 'latest.latest_student_id')
                ->on('practice_attempts.submitted_at', '=', 'latest.latest_at'))
            ->orderBy('practice_attempts.id')
            ->get(['practice_attempts.student_id', 'practice_attempts.score'])
            ->mapWithKeys(fn ($row) => [(int) $row->student_id => $row->score])
            ->all();
    }

    /**
     * Kỹ năng tỉ lệ đúng thấp nhất của từng học viên (cộng mọi câu trong các lượt đã lọc); bằng nhau → kỹ năng đứng trước
     * trong đề. Học viên không có câu nào → không có trong kết quả.
     *
     * @param  callable(): Builder  $ofPage
     * @return array<int, string>
     */
    private function weakestSkills(callable $ofPage): array
    {
        $rows = PracticeAttemptItem::query()->toBase()
            ->join('practice_attempts', 'practice_attempts.id', '=', 'practice_attempt_items.attempt_id')
            ->whereIn('practice_attempt_items.attempt_id', $ofPage()->select('practice_attempts.id'))
            ->selectRaw('practice_attempts.student_id, practice_attempt_items.skill, '
                .$this->correctSql('practice_attempt_items.is_correct').' as correct, count(*) as total')
            ->groupBy('practice_attempts.student_id', 'practice_attempt_items.skill')
            ->get();

        return $rows->groupBy('student_id')
            ->map(fn (Collection $skills) => $skills
                ->sortBy(fn ($s) => [$s->correct / $s->total, PracticeCatalog::skillOrder($s->skill), $s->skill])
                ->first()->skill)
            ->mapWithKeys(fn (string $skill, $studentId) => [(int) $studentId => $skill])
            ->all();
    }

    /** @return list<int> lớp người xem là giáo viên / trợ giảng / GVNN */
    private function ownClassIds(User $viewer): array
    {
        return ClassModel::query()
            ->where(fn (Builder $q) => $q->where('teacher_id', $viewer->id)
                ->orWhere('assistant_id', $viewer->id)
                ->orWhere('foreign_teacher_id', $viewer->id))
            ->pluck('id')->all();
    }

    private function correctSql(string $column): string
    {
        return "sum(case when {$column} = 1 then 1 else 0 end)";
    }

    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
