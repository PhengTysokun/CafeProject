<?php

declare(strict_types=1);

namespace KHQR\Models;

class TimestampData
{
    public ?string $creationTimestamp;

    public ?string $expirationTimestamp;

    public function __construct(?string $creationTimestamp, ?string $expirationTimestamp)
    {
        $this->creationTimestamp = $creationTimestamp;
        $this->expirationTimestamp = $expirationTimestamp;
    }
}
