<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DebtReminderRule extends Model
{
    use HasFactory;

    protected $table = 'debt_reminder_rules';

    protected $fillable = [
        'milestone_key',
        'title',
        'template_content',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
