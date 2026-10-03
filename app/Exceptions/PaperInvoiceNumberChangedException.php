<?php

namespace App\Exceptions;

use RuntimeException;

/** Số hóa đơn giấy kế tiếp của chi nhánh đã khác số người lập phiếu thấy trên form (có người vừa lập phiếu khác). */
class PaperInvoiceNumberChangedException extends RuntimeException
{
    public function __construct(public readonly string $expected, public readonly string $actual)
    {
        parent::__construct("Số hóa đơn giấy {$expected} vừa được cấp cho phiếu khác. Số kế tiếp của chi nhánh là {$actual}: ghi số {$actual} lên hóa đơn giấy, chụp lại ảnh rồi gửi lại.");
    }
}
