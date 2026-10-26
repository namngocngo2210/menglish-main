<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bài giảng PowerPoint của 1 buổi (docs/kho-syllabus.md §7.4): file nằm trên disk riêng tư, trình chiếu trong app bằng trình
 * xem Office Online (iframe) — giữ hiệu ứng, liên kết nhảy slide, trigger; không chạy macro VBA. Trình xem của Microsoft tải
 * file qua link công khai có chữ ký và hạn dùng (route slides.public), không cần đăng nhập.
 */
class SyllabusLessonSlide extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'syllabus_slides';

    public const EXTENSIONS = ['pptx', 'ppt'];

    public const MAX_KB = 102400; // 100 MB

    /** Loại PPT của buổi (thay cho cột Link PPT / Link PPT GVNN). */
    public const KINDS = ['ppt' => 'PPT', 'ppt_foreign' => 'PPT GVNN'];

    /** Link công khai cho trình xem Office còn hạn bấy nhiêu phút (đủ để trình chiếu hết buổi, mở lại trang thì có link mới). */
    public const PUBLIC_MINUTES = 240;

    public const EMBED_URL = 'https://view.officeapps.live.com/op/embed.aspx?src=';

    protected $fillable = ['syllabus_lesson_id', 'kind', 'file_path', 'original_name', 'size_bytes', 'uploaded_by'];

    protected $casts = ['size_bytes' => 'integer'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(SyllabusLesson::class, 'syllabus_lesson_id');
    }

    public function getLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function getSizeHumanAttribute(): string
    {
        return round($this->size_bytes / 1048576, 1).' MB';
    }

    /**
     * Link file cho trình xem Office, theo APP_URL (địa chỉ công khai, không theo host của request đang mở trang). Chữ ký gồm
     * đường dẫn file nên thay file thì link cũ hết hiệu lực.
     */
    public function publicUrl(): string
    {
        $expires = now()->addMinutes(self::PUBLIC_MINUTES)->getTimestamp();
        $extension = pathinfo($this->file_path, PATHINFO_EXTENSION) ?: 'pptx';

        return rtrim((string) config('app.url'), '/').route('slides.public', ['slide' => $this->id, 'expires' => $expires,
            'signature' => $this->signature($expires), 'name' => "slide.{$extension}"], false);
    }

    public function embedUrl(): string
    {
        return self::EMBED_URL.rawurlencode($this->publicUrl());
    }

    public function hasValidSignature(int $expires, string $signature): bool
    {
        return $expires >= now()->getTimestamp() && hash_equals($this->signature($expires), $signature);
    }

    private function signature(int $expires): string
    {
        return hash_hmac('sha256', "{$this->id}|{$this->file_path}|{$expires}", (string) config('app.key'));
    }

    /** Người xem Kho syllabus / syllabus của lớp (Học thuật, Học vụ, Quản lý) xem được mọi bài giảng. */
    public static function userSeesAll(User $user): bool
    {
        return $user->can('syllabus.library_view') || $user->can('syllabus.library') || $user->can('syllabus.class_edit') || $user->can('syllabus.manage');
    }

    /**
     * GV / GVNN / TG chỉ xem bài giảng của chặng ĐANG MỞ thuộc lớp mình dạy / trợ giảng / dạy thay, hoặc chặng được giao cho
     * mình (như tài liệu buổi, SyllabusDocument::visibleTo).
     */
    public function isVisibleTo(User $user): bool
    {
        if (self::userSeesAll($user)) {
            return true;
        }
        $stageId = $this->lesson?->unit?->stage_id;

        return $stageId && SyllabusAssignment::open()->where('stage_id', $stageId)
            ->where(fn ($who) => $who->where('user_id', $user->id)
                ->orWhereHas('classModel', fn ($class) => $class->where('teacher_id', $user->id)
                    ->orWhere('assistant_id', $user->id)
                    ->orWhere('foreign_teacher_id', $user->id)
                    ->orWhereHas('sessions', fn ($session) => $session->where('teacher_id', $user->id)
                        ->orWhere('foreign_teacher_id', $user->id)
                        ->orWhere('assistant_id', $user->id))))
            ->exists();
    }

    /** @return array{id: int, kind: string, label: string, name: string, size_human: string, url: string} */
    public function payload(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'label' => $this->label, 'name' => $this->original_name,
            'size_human' => $this->size_human, 'url' => route('syllabus.slides.show', $this->id)];
    }

    /** @return array<string, array> kind => payload của các PPT đã tải lên của buổi */
    public static function payloadsFor(SyllabusLesson $lesson): array
    {
        return $lesson->slides->mapWithKeys(fn (self $slide) => [$slide->kind => $slide->payload()])->all();
    }
}
