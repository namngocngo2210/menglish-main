<?php

namespace App\Services;

use App\Models\BigTest;
use App\Models\Student;
use App\Models\SupportTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Bộ sinh mã chứng từ dùng chung (audit A4 — nguyên nhân gốc 1).
 *
 * Trước đây mỗi nơi sinh mã bằng "đếm số bản ghi + 1", nên bị trùng khi có bản ghi
 * đã xóa (soft delete vẫn giữ unique index) hoặc khi 2 người tạo cùng lúc. Bộ sinh mã
 * này giữ một bộ đếm trong bảng `document_sequences` theo (key, period) và tăng nó
 * trong transaction có khóa dòng (SELECT ... FOR UPDATE trên MySQL; SQLite khóa cả DB
 * khi ghi nên cũng tuần tự), vì vậy hai request đồng thời không bao giờ nhận cùng số.
 *
 * Khởi tạo lười: lần đầu một dãy được dùng, bộ đếm bắt đầu từ số lớn nhất đang có
 * trong dữ liệu cũ (hàm $seed), nên không cần migrate dữ liệu và không đụng mã cũ.
 * Ngoài ra mỗi mã sinh ra còn được kiểm tra "đã tồn tại chưa" (hàm $exists) và bỏ qua
 * số đã dùng — phòng trường hợp có mã được nhập tay trùng định dạng.
 *
 * Định dạng hiện dùng:
 *  - Học viên:   HV-00001        (dãy "student", không reset)
 *  - Big Test:   BT-2026-0001    (dãy "big_test", reset theo năm)
 *  - Ticket:     TK-2026-0001    (dãy "support_ticket", reset theo năm)
 *
 * TODO: Biên bản phạt (Penalty::generateCode, đang đếm theo năm "BB-YYYY-NNN") nên
 * chuyển sang dùng next()/format() của lớp này — module Penalty do nhóm lương/phạt
 * phụ trách nên chưa sửa ở đây. Tương tự với các mã hóa đơn/phiếu thu còn đếm bản ghi.
 */
class DocumentCodeGenerator
{
    public const TABLE = 'document_sequences';

    /** Số lần thử lại khi đụng mã đã tồn tại / xung đột khởi tạo. */
    private const MAX_ATTEMPTS = 50;

    /**
     * Tăng và trả về số tiếp theo của dãy (key, period).
     *
     * @param  callable|null  $seed  trả về số lớn nhất đã dùng (chỉ gọi khi dãy chưa tồn tại)
     */
    public function next(string $key, string $period = '', ?callable $seed = null): int
    {
        return DB::transaction(function () use ($key, $period, $seed) {
            $row = $this->lockedRow($key, $period);

            if (! $row) {
                try {
                    DB::table(self::TABLE)->insert([
                        'key' => $key,
                        'period' => $period,
                        'last_value' => max(0, (int) ($seed ? $seed() : 0)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Request khác vừa khởi tạo cùng dãy — dùng dòng của họ.
                }
                $row = $this->lockedRow($key, $period);
            }

            $next = (int) $row->last_value + 1;

            DB::table(self::TABLE)->where('id', $row->id)->update([
                'last_value' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        }, 3);
    }

    /**
     * Sinh mã theo formatter, bỏ qua số mà $exists báo đã tồn tại.
     *
     * @param  callable(int): string  $formatter
     * @param  callable(string): bool|null  $exists
     */
    public function generate(string $key, string $period, callable $formatter, ?callable $seed = null, ?callable $exists = null): string
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = $formatter($this->next($key, $period, $seed));
            if (! $exists || ! $exists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException("Không sinh được mã chứng từ mới cho dãy {$key} {$period}.");
    }

    public function studentCode(): string
    {
        return $this->generate(
            'student',
            '',
            fn (int $n) => 'HV-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            fn () => $this->maxNumericSuffix(Student::withTrashed()->where('code', 'like', 'HV-%')->pluck('code'), '/^HV-(\d+)$/'),
            fn (string $code) => Student::withTrashed()->where('code', $code)->exists(),
        );
    }

    public function bigTestCode(?int $year = null): string
    {
        $year = (string) ($year ?? now()->year);

        return $this->generate(
            'big_test',
            $year,
            fn (int $n) => "BT-{$year}-".str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            fn () => $this->maxNumericSuffix(BigTest::where('code', 'like', "BT-{$year}-%")->pluck('code'), '/^BT-'.$year.'-(\d+)$/'),
            fn (string $code) => BigTest::where('code', $code)->exists(),
        );
    }

    public function supportTicketCode(?int $year = null): string
    {
        $year = (string) ($year ?? now()->year);

        return $this->generate(
            'support_ticket',
            $year,
            fn (int $n) => "TK-{$year}-".str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            fn () => $this->maxNumericSuffix(SupportTicket::where('code', 'like', "TK-{$year}-%")->pluck('code'), '/^TK-'.$year.'-(\d+)$/'),
            fn (string $code) => SupportTicket::where('code', $code)->exists(),
        );
    }

    private function lockedRow(string $key, string $period): ?object
    {
        return DB::table(self::TABLE)
            ->where('key', $key)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Số lớn nhất trong các mã khớp regex (nhóm 1 là phần số). Mã không khớp (vd. "HV-P0-001",
     * mã ULID từ màn chốt khách) bị bỏ qua.
     *
     * @param  iterable<string>  $codes
     */
    private function maxNumericSuffix(iterable $codes, string $pattern): int
    {
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match($pattern, (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }
}
