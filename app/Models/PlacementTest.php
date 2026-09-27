<?php

namespace App\Models;

use App\Services\PlacementRubricService;
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
        'grade_level',
        'duration_minutes',
        'questions_count',
        'questions',
        'is_active',
        'is_preset',
    ];

    /** Cấp độ đề test đầu vào = lớp hiện tại của khách, theo thứ tự hiển thị. */
    public const GRADE_LEVELS = [
        'mau_giao' => 'Mẫu giáo',
        'lop_1' => 'Lớp 1',
        'lop_2' => 'Lớp 2',
        'lop_3' => 'Lớp 3',
        'lop_4' => 'Lớp 4',
        'lop_5' => 'Lớp 5',
        'lop_6' => 'Lớp 6',
        'lop_7' => 'Lớp 7',
        'lop_8' => 'Lớp 8',
        'lop_9' => 'Lớp 9',
    ];

    /** Thang điểm chấm (PlacementRubricService) theo cấp độ; cấp độ không có thang điểm → chấm thủ công. */
    private const LEVEL_RUBRIC_GROUPS = [
        'lop_1' => 'khoi_1_2',
        'lop_2' => 'khoi_2_3',
        'lop_3' => 'khoi_3_4',
        'lop_4' => 'khoi_4_5',
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
                'grade_level' => self::detectGradeLevel($t['code']),
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

    public static function gradeLevelLabel(?string $level): ?string
    {
        return self::GRADE_LEVELS[$level] ?? null;
    }

    public static function rubricGroupForLevel(?string $level): string
    {
        return self::LEVEL_RUBRIC_GROUPS[$level] ?? PlacementRubricService::MANUAL_GROUP;
    }

    /** Cấp độ mặc định theo mã đề: số lớp đầu tiên sau "G" (TEST-G3-G4 → Lớp 3), PRE-G1 → Mẫu giáo. */
    public static function detectGradeLevel(?string $code): ?string
    {
        $code = strtoupper((string) $code);
        if (str_contains($code, 'PRE-G1') || str_contains($code, 'PRE_G1')) {
            return 'mau_giao';
        }
        if (preg_match('/(?:^|[-_])G([1-9])(?:[-_]|$)/', $code, $m)) {
            return 'lop_'.$m[1];
        }

        return null;
    }
}
