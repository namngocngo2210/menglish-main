<?php

namespace App\Models;

use App\Services\PlacementRubricService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementTestSubmission extends Model
{
    use HasFactory;

    /** Bài nộp chờ Học vụ chấm (Writing/Speaking hoặc duyệt lại điểm tự động). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_GRADED = 'graded';

    protected $table = 'placement_test_submissions';

    protected $fillable = [
        'placement_test_id',
        'customer_id',
        'student_id',
        'candidate_name',
        'candidate_phone',
        'candidate_email',
        'grade_group',
        'listening_score',
        'reading_score',
        'writing_score',
        'speaking_score',
        'reading_writing_score',
        'overall_score',
        'total_score',
        'cefr_level',
        'writing_content',
        'answers',
        'speaking_audio_url',
        'recommended_course',
        'teacher_comments',
        'suggested_class',
        'chosen_class',
        'listening_comment',
        'reading_writing_comment',
        'speaking_comment',
        'grader_id',
        'status',
    ];

    protected $casts = [
        'listening_score' => 'decimal:1',
        'reading_score' => 'decimal:1',
        'writing_score' => 'decimal:1',
        'speaking_score' => 'decimal:1',
        'overall_score' => 'decimal:1',
        'reading_writing_score' => 'decimal:1',
        'total_score' => 'decimal:1',
        'answers' => 'array',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class, 'placement_test_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    /**
     * Ghi kết quả chấm theo thang điểm khối lớp (BA Q2): tổng = Nghe + Đọc&Viết + Nói,
     * lớp đề xuất tra theo tổng (server tính, không nhận từ client), lớp chọn lại (bắt buộc với khối chưa có thang),
     * nhận xét từng kỹ năng: người chấm sửa được, để trống thì lấy gợi ý theo băng điểm.
     *
     * @param  array{grade_group: string, listening_score: float|int|string, reading_writing_score: float|int|string, speaking_score: float|int|string,
     *     chosen_class?: ?string, listening_comment?: ?string, reading_writing_comment?: ?string, speaking_comment?: ?string, teacher_comments?: ?string}  $data
     */
    public function applyRubricGrade(array $data): void
    {
        $group = $data['grade_group'];
        $listening = (float) $data['listening_score'];
        $readingWriting = (float) $data['reading_writing_score'];
        $speaking = (float) $data['speaking_score'];
        $evaluation = PlacementRubricService::evaluate($group, $listening, $readingWriting, $speaking);

        $comments = [];
        foreach (array_keys(PlacementRubricService::SKILLS) as $skill) {
            $typed = trim((string) ($data["{$skill}_comment"] ?? ''));
            $comments[$skill] = $typed !== '' ? $typed : $evaluation['comments'][$skill];
        }
        $chosen = trim((string) ($data['chosen_class'] ?? '')) ?: $evaluation['suggested_class'];
        $note = trim((string) ($data['teacher_comments'] ?? '')) ?: null;

        $this->fill([
            'grade_group' => $group,
            'listening_score' => $listening,
            'reading_writing_score' => $readingWriting,
            'speaking_score' => $speaking,
            'total_score' => $evaluation['total'],
            // overall_score giữ để các màn cũ nhận biết "đã có điểm": nay bằng tổng điểm theo thang khối.
            'overall_score' => $evaluation['total'],
            'suggested_class' => $evaluation['suggested_class'],
            'chosen_class' => $chosen,
            'recommended_course' => $chosen,
            'listening_comment' => $comments['listening'],
            'reading_writing_comment' => $comments['reading_writing'],
            'speaking_comment' => $comments['speaking'],
            'teacher_comments' => $note,
        ]);
    }

    public function hasRubricGrade(): bool
    {
        return $this->grade_group !== null && $this->total_score !== null;
    }

    /** Lớp xếp cuối cùng: lớp Học vụ chọn lại, nếu không thì lớp đề xuất. */
    public function finalClass(): ?string
    {
        return $this->chosen_class ?: ($this->suggested_class ?: $this->recommended_course);
    }

    public function classWasOverridden(): bool
    {
        return $this->suggested_class !== null && $this->chosen_class !== null && $this->chosen_class !== $this->suggested_class;
    }

    /** Tóm tắt điểm dạng "31/40 · STARTERS (...)" — dùng cho CRM (test_score), thông báo, danh sách. */
    public function scoreSummary(): ?string
    {
        if ($this->hasRubricGrade()) {
            $total = rtrim(rtrim(number_format((float) $this->total_score, 1, '.', ''), '0'), '.');
            $max = PlacementRubricService::maxTotal($this->grade_group);

            return "{$total}/{$max}".($this->finalClass() ? ' · '.$this->finalClass() : '');
        }
        if ($this->overall_score === null) {
            return null;
        }

        // Bài chấm theo cách cũ (trước BA Q2) — hiển thị nguyên giá trị đã lưu.
        return (string) $this->overall_score.($this->recommended_course ? ' · '.$this->recommended_course : '');
    }

    /** Khối lớp dùng để chấm: đã lưu, nếu chưa thì đoán theo mã đề. */
    public function resolvedGradeGroup(): string
    {
        return $this->grade_group ?: PlacementRubricService::detectGradeGroup($this->test?->code);
    }

    public function isPending(): bool
    {
        return $this->status !== self::STATUS_GRADED;
    }
}
