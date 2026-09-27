<?php

namespace App\Models;

use Database\Seeders\GradeTestsSeeder;
use Database\Seeders\SpeakingTestsSeeder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlacementTest extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'placement_tests';

    protected $fillable = [
        'code',
        'title',
        'description',
        'target_level',
        'duration_minutes',
        'questions_count',
        'questions',
        'is_active',
        'is_preset',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'questions_count' => 'integer',
        'questions' => 'array',
        'is_active' => 'boolean',
        'is_preset' => 'boolean',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(PlacementTestSubmission::class, 'placement_test_id');
    }

    /**
     * Thêm các đề trong bộ đề mẫu của trung tâm mà DB chưa có (theo mã). Đề đã có hoặc đã xóa mềm giữ nguyên.
     *
     * @return int số đề đã thêm
     */
    public static function installMissingPresets(): int
    {
        $added = 0;

        foreach ([...GradeTestsSeeder::tests(), ...SpeakingTestsSeeder::tests()] as $t) {
            if (self::withTrashed()->where('code', $t['code'])->exists()) {
                continue;
            }

            self::create([
                'code' => $t['code'],
                'title' => $t['title'],
                'target_level' => $t['target_level'],
                'duration_minutes' => $t['duration_minutes'],
                'questions_count' => count($t['questions']),
                'questions' => $t['questions'],
                'is_active' => true,
                'is_preset' => true,
            ]);
            $added++;
        }

        return $added;
    }
}
