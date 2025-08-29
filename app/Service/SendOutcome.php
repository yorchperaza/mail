<?php

declare(strict_types=1);

namespace App\Service;

final class SendOutcome
{
    public function __construct(
        public readonly int   $httpStatus, // 200/201/202/4xx/5xx etc
        public readonly array $data        // payload to return
    )
    {
    }
}
