<?php

namespace Tests\Unit;

use App\Support\ExamViolations;
use PHPUnit\Framework\TestCase;

class ExamViolationsTest extends TestCase
{
    public function test_json_string_keeps_known_types_and_drops_unknown(): void
    {
        $raw = json_encode([
            ['type' => 'hidden', 'at' => '2026-10-24T01:00:00Z'],
            ['type' => 'screenshot', 'at' => '2026-10-24T01:00:05Z'],
            ['type' => 'fullscreen_exit', 'at' => '2026-10-24T01:00:10Z'],
        ]);

        $this->assertSame([
            ['type' => 'hidden', 'at' => '2026-10-24T01:00:00Z'],
            ['type' => 'fullscreen_exit', 'at' => '2026-10-24T01:00:10Z'],
        ], ExamViolations::sanitizeLog($raw));
    }

    public function test_log_is_capped_at_fifty_entries(): void
    {
        $raw = json_encode(array_fill(0, 60, ['type' => 'blur', 'at' => '2026-10-24T01:00:00Z']));

        $this->assertCount(50, ExamViolations::sanitizeLog($raw));
    }

    public function test_non_json_or_empty_input_gives_null(): void
    {
        $this->assertNull(ExamViolations::sanitizeLog('not json'));
        $this->assertNull(ExamViolations::sanitizeLog(null));
        $this->assertNull(ExamViolations::sanitizeLog(''));
        $this->assertNull(ExamViolations::sanitizeLog('[]'));
        $this->assertNull(ExamViolations::sanitizeLog(json_encode([['type' => 'unknown']])));
    }

    public function test_array_input_is_accepted_and_at_is_truncated(): void
    {
        $log = ExamViolations::sanitizeLog([
            ['type' => 'blur', 'at' => str_repeat('x', 60)],
            ['type' => 'hidden'],
            'garbage',
        ]);

        $this->assertSame([
            ['type' => 'blur', 'at' => str_repeat('x', 40)],
            ['type' => 'hidden', 'at' => ''],
        ], $log);
    }

    public function test_valid_types_and_limit(): void
    {
        $this->assertSame(3, ExamViolations::MAX);
        $this->assertSame(['hidden', 'blur', 'fullscreen_exit'], array_keys(ExamViolations::TYPES));
        $this->assertTrue(ExamViolations::isValidType('fullscreen_exit'));
        $this->assertFalse(ExamViolations::isValidType('copy'));
    }
}
