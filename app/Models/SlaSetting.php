<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Giá trị Admin đổi cho một SLA (mặc định nằm ở config/sla.php); cột null = dùng mặc định. */
class SlaSetting extends Model
{
    protected $fillable = ['rule_key', 'value', 'enabled', 'penalty', 'amount', 'ladder', 'updated_by'];

    protected $casts = ['enabled' => 'boolean', 'penalty' => 'boolean', 'amount' => 'decimal:2', 'ladder' => 'array'];
}
