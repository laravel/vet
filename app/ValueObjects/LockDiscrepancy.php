<?php

declare(strict_types=1);

namespace App\ValueObjects;

interface LockDiscrepancy
{
    public function message(): string;
}
