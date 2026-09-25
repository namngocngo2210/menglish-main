<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Homework extends Model
{
    use HasFactory;

    protected $table = 'homeworks';

    /**
     * Hạng mục bài tập (mockup "Giao bài tập về nhà") — khóa trùng với homework_type khi học viên nộp bài
     * (StudentPortalController::submitHomework): key => [nhãn, icon, gợi ý yêu cầu].
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
