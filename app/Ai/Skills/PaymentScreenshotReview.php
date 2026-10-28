<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class PaymentScreenshotReview
{
    public static function instructions(string $part = 'platform'): string
    {
        return SkillCatalog::text('payment-screenshot-'.$part);
    }
}
