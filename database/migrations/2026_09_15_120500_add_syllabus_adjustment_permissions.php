<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('syllabus.propose_adjustment', 'web');
        Permission::findOrCreate('syllabus.approve_adjustment', 'web');
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'syllabus.propose_adjustment',
            'syllabus.approve_adjustment',
        ])->where('guard_name', 'web')->delete();
    }
};
