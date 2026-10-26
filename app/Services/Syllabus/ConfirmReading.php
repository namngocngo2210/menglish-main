<?php

namespace App\Services\Syllabus;

use App\Models\SyllabusAssignment;
use App\Models\SyllabusAssignmentRead;
use App\Models\SyllabusDocument;
use App\Models\SyllabusDocumentView;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Bắt GV đọc trước khi XÁC NHẬN chặng (docs/kho-syllabus.md §7.3 bước 2, chủ dự án 26/10/2026): trang "Đọc & xác nhận"
 * mở đủ OVERVIEW_MIN_SECONDS giây và mọi tài liệu chặng đã "Đánh dấu đã xem" (mỗi tài liệu mở đủ DOCUMENT_MIN_SECONDS giây).
 * GV chỉ xem bài giảng buổi hôm nay (quyết định 7) nên phần đọc là tổng quan chặng + tài liệu chặng, không gồm nội dung buổi.
 */
class ConfirmReading
{
    public const OVERVIEW_MIN_SECONDS = 30;

    public const DOCUMENT_MIN_SECONDS = 10;

    /** Tài liệu chặng phải mở: gắn đúng chặng được giao (không tính tài liệu buổi / tài liệu chung), người xác nhận xem được. */
    public function requiredDocuments(SyllabusAssignment $assignment, User $user): Collection
    {
        if (! $assignment->stage_id) {
            return collect();
        }

        return SyllabusDocument::query()
            ->where('curriculum_id', $assignment->curriculum_id)
            ->where('stage_id', $assignment->stage_id)
            ->whereNull('syllabus_lesson_id')
            ->visibleTo($user)
            ->orderBy('title')
            ->get();
    }

    /** GV mở trang "Đọc & xác nhận": ghi mốc lần mở đầu tiên (mở lại không tính lại). */
    public function open(SyllabusAssignment $assignment, User $user): SyllabusAssignmentRead
    {
        return SyllabusAssignmentRead::firstOrCreate(
            ['syllabus_assignment_id' => $assignment->id, 'user_id' => $user->id],
            ['opened_at' => now()],
        );
    }

    /**
     * @return array{opened_at: ?CarbonInterface, read_seconds: int, overview_done: bool, documents: Collection, documents_done: bool}
     */
    public function status(SyllabusAssignment $assignment, User $user): array
    {
        $read = $assignment->reads()->where('user_id', $user->id)->first();
        $readSeconds = $read ? max(0, (int) $read->opened_at->diffInSeconds(now())) : 0;
        $documents = $this->requiredDocuments($assignment, $user);
        $views = SyllabusDocumentView::where('user_id', $user->id)->whereIn('document_id', $documents->pluck('id'))->get()->keyBy('document_id');

        return [
            'opened_at' => $read?->opened_at,
            'read_seconds' => $readSeconds,
            'overview_done' => $read !== null && $readSeconds >= self::OVERVIEW_MIN_SECONDS,
            'documents' => $documents->map(fn (SyllabusDocument $doc) => ['document' => $doc, 'viewed_at' => $views->get($doc->id)?->viewed_at]),
            'documents_done' => $documents->every(fn (SyllabusDocument $doc) => $views->has($doc->id)),
        ];
    }

    /** Điều kiện còn thiếu để được XÁC NHẬN (rỗng = đủ). */
    public function missing(array $status): array
    {
        $missing = [];
        if (! $status['overview_done']) {
            $missing[] = 'Đọc tổng quan chặng ít nhất '.self::OVERVIEW_MIN_SECONDS.' giây.';
        }
        $left = $status['documents']->whereNull('viewed_at');
        if ($left->isNotEmpty()) {
            $missing[] = 'Mở và đánh dấu đã xem tài liệu chặng: '.$left->map(fn ($row) => $row['document']->title)->implode(', ').'.';
        }

        return $missing;
    }

    /** Bằng chứng đọc lưu vào `confirm_evidence` lúc xác nhận (Học thuật xem ở Giao chặng / tab Syllabus của lớp). */
    public function evidence(array $status): array
    {
        return [
            'read_seconds' => $status['read_seconds'],
            'opened_at' => $status['opened_at']?->toIso8601String(),
            'documents' => $status['documents']->map(fn ($row) => [
                'id' => $row['document']->id,
                'title' => $row['document']->title,
                'viewed_at' => $row['viewed_at']?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** "Đọc tổng quan 2 phút 5 giây · mở 3/3 tài liệu chặng" — null khi xác nhận trước khi có bước bắt đọc. */
    public static function summary(?array $evidence): ?string
    {
        if (! $evidence) {
            return null;
        }
        $seconds = (int) ($evidence['read_seconds'] ?? 0);
        $time = $seconds >= 60 ? intdiv($seconds, 60).' phút'.($seconds % 60 ? ' '.($seconds % 60).' giây' : '') : $seconds.' giây';
        $documents = collect($evidence['documents'] ?? []);
        $docs = $documents->isEmpty() ? 'chặng không có tài liệu' : 'mở '.$documents->whereNotNull('viewed_at')->count().'/'.$documents->count().' tài liệu chặng';

        return "Đọc tổng quan {$time} · {$docs}";
    }

    public static function documentOpenKey(User $user, SyllabusDocument $document): string
    {
        return "syllabus-doc-open:{$user->id}:{$document->id}";
    }

    /** Mở xem tài liệu: ghi mốc (giữ mốc cũ nếu đã mở trước đó) để "Đánh dấu đã xem" đòi đủ DOCUMENT_MIN_SECONDS giây. */
    public static function markDocumentOpened(User $user, SyllabusDocument $document): void
    {
        Cache::add(self::documentOpenKey($user, $document), now()->getTimestamp(), now()->addHours(12));
    }

    /** Số giây đã mở tài liệu (null = chưa mở). */
    public static function documentOpenSeconds(User $user, SyllabusDocument $document): ?int
    {
        $openedAt = Cache::get(self::documentOpenKey($user, $document));

        return $openedAt === null ? null : now()->getTimestamp() - (int) $openedAt;
    }
}
