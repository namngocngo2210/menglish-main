<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Chuyển giai đoạn CRM bị từ chối. `forbidden` = sai vai trò (403),
 * còn lại là vi phạm luật nghiệp vụ (422).
 */
class CrmStageTransitionException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $forbidden = false, public readonly bool $conflict = false)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, true);
    }

    /** Khách Thất bại là trạng thái cuối: mọi đổi giai đoạn bị từ chối 409. */
    public static function terminal(): self
    {
        return new self('Khách đã Thất bại, không thể đổi giai đoạn. Khách quay lại hãy tạo hồ sơ tuyển sinh mới.', false, true);
    }

    public function status(): int
    {
        return $this->conflict ? 409 : ($this->forbidden ? 403 : 422);
    }
}
