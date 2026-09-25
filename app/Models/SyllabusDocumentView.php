<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Giáo viên / trợ giảng đã "Đánh dấu đã xem" một tài liệu giáo trình. */
class SyllabusDocumentView extends Model
{
    protected $table = 'syllabus_document_views';

    protected $fillable = ['document_id', 'user_id', 'viewed_at'];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SyllabusDocument::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
