<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Đoạn văn / bài chung của một nhóm câu hỏi (điền từ, đọc hiểu, sắp xếp câu). */
class QuestionBankPassage extends Model
{
    protected $fillable = [
        'exam_id', 'grade', 'term', 'skill', 'type', 'instruction', 'content', 'image_path',
        'source_school', 'source_year', 'source_file', 'source_key',
    ];

    protected $casts = [
        'grade' => 'integer',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class, 'passage_id')->orderBy('position');
    }
}
