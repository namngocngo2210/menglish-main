<?php

namespace App\Exceptions;

use RuntimeException;

/** Không còn số hóa đơn nào để cấp (dải chi nhánh và dải mặc định đều hết hoặc ngừng dùng). */
class InvoiceRangeExhaustedException extends RuntimeException {}
