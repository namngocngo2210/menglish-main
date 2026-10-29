<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Homework extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'homeworks';

    /**
     * Hạng mục bài tập (mockup "Giao bài tập về nhà") — khóa trùng với homework_type của bài nộp tự do cũ (AcademicRecord, đã gỡ
     * khỏi cổng); bài nộp mới theo giỏ (basketOf): key => [nhãn, icon, gợi ý yêu cầu].
     */
    public const CATEGORIES = [
        'video' => ['Quay video', 'videocam', 'Nhập chi tiết yêu cầu bài tập (bắt buộc)...'],
        'vocabulary' => ['Viết từ vựng', 'edit_document', 'Nhập danh sách từ vựng cần viết, số lần chép phạt (bắt buộc)...'],
        'workbook' => ['Workbook', 'menu_book', 'Nhập số trang, bài tập cụ thể trong Workbook (bắt buộc)...'],
        'extra_book' => ['Sách bổ trợ', 'library_books', 'Nhập tên sách và bài tập cần làm (bắt buộc)...'],
        'quiz' => ['Quiz', 'psychology_alt', 'Nhập chủ đề Quiz hoặc link bài tập (bắt buộc)...'],
        'bgd_book' => ['Sách bộ giáo dục', 'school', 'Nhập chi tiết bài tập sách giáo khoa...'],
    ];

    protected $fillable = [
        'class_id',
        'class_session_id',
        'user_id',
        'title',
        'description',
        'class_note',
        'youtube_url',
        'quizizz_url',
        'audio_path',
        'items',
        'due_date',
        'due_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'due_at' => 'datetime',
        'items' => 'array',
    ];

    /** Giỏ nộp bài của hạng mục (docs/kho-syllabus.md §10.1): video → video; quiz → Quizizz; còn lại → giấy. */
    public static function basketOf(string $category): string
    {
        return match ($category) {
            'video' => 'video',
            'quiz' => 'quizizz',
            default => 'paper',
        };
    }

    /**
     * Giỏ của bài tập theo thứ tự giấy / Quizizz / video: từ hạng mục đã giao, có link Quizizz thì có giỏ Quizizz.
     *
     * @return list<string>
     */
    public function baskets(): array
    {
        $baskets = collect(array_keys(array_filter($this->items ?? [], fn ($text) => filled($text))))->map(self::basketOf(...));
        if (filled($this->quizizz_url)) {
            $baskets->push('quizizz');
        }

        return array_values(array_filter(array_keys(HomeworkSubmission::BASKETS), fn (string $b) => $baskets->contains($b)));
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
