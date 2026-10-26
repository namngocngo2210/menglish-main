<?php

namespace App\Services\Practice;

use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptItem;
use App\Support\ExamViolations;
use App\Support\Practice\PracticeCatalog;
use Illuminate\Support\Facades\Storage;

/**
 * Props Inertia của một lượt luyện tập, dùng chung cho cổng học viên (làm bài / kết quả) và màn báo cáo Kết quả luyện tập
 * (xem lại chỉ đọc). Liệt kê trường cụ thể: snapshot có đáp án, chỉ `reviewItems()` (lượt đã nộp) mới gửi đáp án.
 */
class PracticeAttemptProps
{
    /** Đề của một câu (không có đáp án). */
    public function question(PracticeAttemptItem $item): array
    {
        $s = $item->snapshot ?? [];
        $passage = $s['passage'] ?? null;

        return [
            'position' => $item->position,
            'skill' => $item->skill,
            'type' => $item->type,
            'instruction' => $s['instruction'] ?? null,
            'passage' => is_array($passage) ? [
                'content' => $passage['content'] ?? null,
                'image_url' => $this->imageUrl($passage),
            ] : null,
            'image_url' => $this->imageUrl($s),
            'stem' => $s['stem'] ?? '',
            'options' => (object) ($s['options'] ?? []),
            'chosen' => $item->chosen,
        ];
    }

    /** Tổng quan lượt đã nộp: điểm, điểm theo kỹ năng, lý do tự nộp, nhật ký rời bài. */
    public function result(PracticeAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'term_label' => PracticeCatalog::TERMS[$attempt->term] ?? $attempt->term,
            'exam_label' => $attempt->exam?->label(),
            'grade' => $attempt->grade,
            'started_at' => $attempt->started_at->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds($attempt),
            'score' => (float) $attempt->score,
            'correct' => (int) $attempt->correct,
            'total' => (int) $attempt->total,
            'skill_scores' => collect($attempt->skill_scores ?? [])
                ->map(fn (array $s, string $skill) => ['skill' => $skill, 'correct' => (int) $s['correct'], 'total' => (int) $s['total']])
                ->values()->all(),
            'auto_submitted' => $attempt->auto_submitted,
            'submit_reason' => $attempt->submit_reason,
            'violation_count' => (int) $attempt->violation_count,
            'violation_log' => collect($attempt->violation_log ?? [])->map(fn (array $v) => [
                'label' => ExamViolations::TYPES[$v['type'] ?? ''] ?? ($v['type'] ?? ''),
                'at' => $v['at'] ?? null,
            ])->values()->all(),
        ];
    }

    /** Từng câu của lượt đã nộp kèm đáp án đúng / đúng-sai (AttemptReview). Không gọi cho lượt đang làm. */
    public function reviewItems(PracticeAttempt $attempt): array
    {
        $items = $attempt->items()->get(['id', 'attempt_id', 'position', 'skill', 'type', 'snapshot', 'chosen', 'is_correct']);

        return $items->map(fn (PracticeAttemptItem $item) => [
            ...$this->question($item),
            'answer' => $item->snapshot['answer'] ?? null,
            'is_correct' => (bool) $item->is_correct,
            'knowledge' => $item->snapshot['knowledge'] ?? null,
        ])->values()->all();
    }

    /** URL ảnh từ bản chụp: `image_path` (trong kho) → URL; bản chụp cũ lưu sẵn `image_url` thì dùng nguyên. */
    private function imageUrl(array $snapshot): ?string
    {
        $path = $snapshot['image_path'] ?? null;

        return $path ? Storage::disk('public')->url($path) : ($snapshot['image_url'] ?? null);
    }

    /** Thời gian làm bài (giây); lượt chốt muộn (lệnh định kỳ) tính tới hạn nộp. */
    public function durationSeconds(PracticeAttempt $attempt): int
    {
        $end = $attempt->submitted_at ?? now();
        if ($end->greaterThan($attempt->deadline_at)) {
            $end = $attempt->deadline_at;
        }

        return (int) max(0, floor($attempt->started_at->diffInSeconds($end, false)));
    }
}
