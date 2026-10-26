<?php

namespace App\Services\SalesAi;

use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

class Pricing
{
    public const SCALE = 1_000_000;
    public const MODEL = 'deepseek-flash';
    public const INPUT_BUDGET = 240000;
    public const OUTPUT_BUDGET = 9600;

    public static function micros(string $value): int
    {
        return BigDecimal::of($value)->multipliedBy(self::SCALE)->toScale(0, RoundingMode::UNNECESSARY)->toInt();
    }

    public static function amount(int $value): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), self::SCALE).'.'.str_pad((string) (abs($value) % self::SCALE), 6, '0', STR_PAD_LEFT);
    }

    public function snapshot(): array
    {
        $settings = app(AiAssistantSettings::class)->get();
        abort_unless($settings['enabled'] && $settings['configured'], 503, 'Platform AI is unavailable.');
        abort_unless($settings['usd_to_mmk_rate'] !== null && self::micros($settings['usd_to_mmk_rate']) > 0, 503, 'The platform USD to MMK rate is not configured.');
        return app(AiBalanceBook::class)->pricing(self::MODEL) + ['usd_to_mmk_rate' => $settings['usd_to_mmk_rate'], 'markup_percent' => $settings['sales_ai_markup_percent'], 'model' => self::MODEL];
    }

    public function usd(int $input, int $output, ?int $cached, array $pricing): int
    {
        abort_unless($input >= 0 && $output >= 0 && $input <= 20_000_000 && $output <= 20_000_000 && ($cached === null || ($cached >= 0 && $cached <= $input)), 503, 'AI usage could not be verified.');
        $hits = $cached ?? 0;
        return BigInteger::of($input - $hits)->multipliedBy($pricing['input_rate_nanos'])
            ->plus(BigInteger::of($hits)->multipliedBy($pricing['cached_input_rate_nanos']))
            ->plus(BigInteger::of($output)->multipliedBy($pricing['output_rate_nanos']))
            ->dividedBy(1_000_000, RoundingMode::CEILING)->toInt();
    }

    public function mmk(int $usdNanos, array $pricing): int
    {
        $basisPoints = BigDecimal::of($pricing['markup_percent'])->multipliedBy(100)->toScale(0, RoundingMode::UNNECESSARY)->toInt();
        return BigInteger::of($usdNanos)->multipliedBy(self::micros($pricing['usd_to_mmk_rate']))->multipliedBy(10000 + $basisPoints)
            ->dividedBy(10_000_000_000_000, RoundingMode::CEILING)->toInt();
    }

    public function reserve(array $pricing): int
    {
        return max(1, $this->mmk($this->usd(self::INPUT_BUDGET, self::OUTPUT_BUDGET, 0, $pricing), $pricing));
    }
}
