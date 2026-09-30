<?php

namespace Tests\Unit;

use App\Support\TemporaryPassword;
use PHPUnit\Framework\TestCase;

class TemporaryPasswordTest extends TestCase
{
    public function test_password_is_ten_chars_with_lower_upper_digit_and_special(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $password = TemporaryPassword::generate();
            $this->assertSame(10, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
            // Không có ký tự dễ nhầm khi đọc cho phụ huynh.
            $this->assertDoesNotMatchRegularExpression('/[0O1lIo\s]/', $password);
            $seen[$password] = true;
        }

        $this->assertCount(500, $seen);
    }
}
