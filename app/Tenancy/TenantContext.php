<?php

namespace App\Tenancy;

use LogicException;

class TenantContext
{
    private ?int $businessId = null;

    public function set(int $businessId): void
    {
        $this->businessId = $businessId;
    }

    public function clear(): void
    {
        $this->businessId = null;
    }

    public function id(): ?int
    {
        return $this->businessId;
    }

    public function requireId(): int
    {
        if ($this->businessId === null) {
            throw new LogicException('A business context is required for this operation.');
        }

        return $this->businessId;
    }
}
