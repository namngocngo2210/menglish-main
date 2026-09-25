<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use HasFactory;

    protected $table = 'ticket_messages';

    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'message',
        'attachment_path',
        'is_internal_note',
    ];

    protected $casts = [
        'is_internal_note' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getAttachmentListAttribute(): array
    {
        if (empty($this->attachment_path)) {
            return [];
        }
        $decoded = json_decode($this->attachment_path, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [$this->attachment_path];
    }
}
