<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tài liệu giáo trình (file thật). File nằm trên disk private, chỉ đọc qua
 * route syllabus.documents.file sau khi kiểm tra đối tượng được xem.
 */
class SyllabusDocument extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'syllabus_documents';

    /** Đuôi file cho phép (theo nội dung): tài liệu, slide, ảnh, audio, video. */
    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp3', 'wav', 'm4a', 'ogg',
        'mp4', 'mov', 'webm', 'm4v',
    ];

    public const MAX_KB = 102400; // 100 MB

    protected $fillable = [
        'curriculum_id',
        'stage_id',
        'title',
        'stage_name',
        'file_path',
        'original_name',
        'extension',
        'mime_type',
        'size_bytes',
        'visible_to_teachers',
        'visible_to_assistants',
        'downloadable',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'visible_to_teachers' => 'boolean',
        'visible_to_assistants' => 'boolean',
        'downloadable' => 'boolean',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SyllabusStage::class, 'stage_id');
    }

    /** Lượt "Đánh dấu đã xem" của giáo viên / trợ giảng. */
    public function views(): HasMany
    {
        return $this->hasMany(SyllabusDocumentView::class, 'document_id');
    }

    public function viewedBy(User $user): bool
    {
        return $this->views()->where('user_id', $user->id)->exists();
    }

    /** Tên chặng hiển thị: chặng gắn thật, hoặc tên chặng nhập tự do của dữ liệu cũ. */
    public function getStageLabelAttribute(): ?string
    {
        return $this->stage?->label ?? $this->stage_name;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Người quản lý kho tài liệu (upload/quản lý giáo trình) luôn xem được mọi tài liệu. */
    public static function userManages(User $user): bool
    {
        return $user->can('syllabus.upload') || $user->can('syllabus.manage');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (self::userManages($user)) {
            return $query;
        }

        // Tài liệu "dành cho giáo viên" / "dành cho trợ giảng": theo quyền đối tượng Cổng giáo viên / Cổng trợ giảng.
        $isTeacher = $user->can('portal.teacher');
        $isAssistant = $user->can('portal.assistant');

        return $query->where(function (Builder $q) use ($isTeacher, $isAssistant) {
            $q->whereRaw('1 = 0');
            if ($isTeacher) {
                $q->orWhere('visible_to_teachers', true);
            }
            if ($isAssistant) {
                $q->orWhere('visible_to_assistants', true);
            }
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->whereKey($this->id)->visibleTo($user)->exists();
    }

    public function canDownload(User $user): bool
    {
        return self::userManages($user) || ($this->downloadable && $this->isVisibleTo($user));
    }

    public function getKindAttribute(): string
    {
        return match (true) {
            $this->extension === 'pdf' => 'pdf',
            in_array($this->extension, ['doc', 'docx'], true) => 'word',
            in_array($this->extension, ['ppt', 'pptx'], true) => 'slide',
            in_array($this->extension, ['xls', 'xlsx'], true) => 'sheet',
            in_array($this->extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            in_array($this->extension, ['mp3', 'wav', 'm4a', 'ogg'], true) => 'audio',
            in_array($this->extension, ['mp4', 'mov', 'webm', 'm4v'], true) => 'video',
            default => 'file',
        };
    }

    public function getIconAttribute(): string
    {
        return [
            'pdf' => 'picture_as_pdf', 'word' => 'description', 'slide' => 'co_present', 'sheet' => 'table_chart',
            'image' => 'image', 'audio' => 'headphones', 'video' => 'movie',
        ][$this->kind] ?? 'draft';
    }

    /** Có thể xem trực tiếp trên trình duyệt (không cần tải về). */
    public function getInlineViewableAttribute(): bool
    {
        return in_array($this->kind, ['pdf', 'image', 'audio', 'video'], true);
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = (int) $this->size_bytes;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    /** Nhãn đối tượng được xem (Admin / Học vụ / Học thuật luôn xem được). */
    public function getAudienceLabelsAttribute(): array
    {
        return array_values(array_filter([
            'Admin',
            'Học vụ',
            'Học thuật',
            $this->visible_to_teachers ? 'Giáo viên' : null,
            $this->visible_to_assistants ? 'Trợ giảng' : null,
        ]));
    }
}
