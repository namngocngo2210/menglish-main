<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Chuyển giai đoạn CRM bị từ chối. `forbidden` = sai vai trò (403),
 * còn lại là vi phạm luật nghiệp vụ (422).
 */
class CrmStageTransitionException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $forbidden = false)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, true);
    }

    public function status(): int
    {
        return $this->forbidden ? 403 : 422;
    }
}
