<?php

namespace Tests\Unit;

use App\Support\SpreadsheetCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadsheetCellTest extends TestCase
{
    #[DataProvider('cells')]
    public function test_formula_cells_are_neutralised_and_plain_values_kept(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, SpreadsheetCell::safe($input));
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function cells(): array
    {
        return [
            'công thức' => ['=HYPERLINK("http://x")', "'=HYPERLINK(\"http://x\")"],
            'at' => ['@SUM(1+1)', "'@SUM(1+1)"],
            'cộng lệnh' => ['+cmd|x', "'+cmd|x"],
            'trừ công thức' => ['-2+3', "'-2+3"],
            'số âm kèm đơn vị' => ['-1.500.000 đ', '-1.500.000 đ'],
            'số âm' => ['-5.000', '-5.000'],
            'phần trăm âm' => ['-12%', '-12%'],
            'số điện thoại +84' => ['+84912345678', '+84912345678'],
            'chữ thường' => ['Nguyễn Văn A', 'Nguyễn Văn A'],
            'rỗng' => ['', ''],
            'số' => [12, 12],
            'null' => [null, null],
        ];
    }
}
