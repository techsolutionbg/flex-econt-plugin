<?php
declare(strict_types=1);
namespace Flex\Econt;

final class EcontException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status > 0 ? $this->status : 400;
    }
}
