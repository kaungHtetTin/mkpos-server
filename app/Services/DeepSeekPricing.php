<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeepSeekPricing
{
    public const SOURCE = 'https://api-docs.deepseek.com/quick_start/pricing/';

    private const CACHE_KEY = 'ai.deepseek.pricing.v2';

    public function get(): array
    {
        $saved = Cache::get(self::CACHE_KEY);
        if ($saved && now()->timestamp - strtotime($saved['fetched_at']) < 21600) {
            return $saved + ['stale' => false];
        }

        // Also prevents repeated slow requests during an outage.
        if (Cache::add(self::CACHE_KEY.'.attempt', true, now()->addMinutes(10))) {
            try {
                $response = Http::connectTimeout(3)->timeout(6)->get(self::SOURCE)->throw();
                $rates = $this->parse($response->body());
                $saved = ['source_url' => self::SOURCE, 'fetched_at' => now()->toIso8601String(), 'rates' => $rates];
                Cache::put(self::CACHE_KEY, $saved, now()->addDays(30));

                return $saved + ['stale' => false];
            } catch (Throwable) {
                // Keep the last validated snapshot; never substitute invented rates.
            }
        }

        return ($saved ?? ['source_url' => self::SOURCE, 'fetched_at' => null, 'rates' => []]) + ['stale' => true];
    }

    public function parse(string $html): array
    {
        if (strlen($html) > 2_000_000) {
            throw new RuntimeException('Pricing document is too large.');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//table') as $table) {
            // Expand merged cells so model columns cannot silently shift.
            $grid = [];
            foreach ($xpath->query('.//tr', $table) as $r => $row) {
                $c = 0;
                foreach ($xpath->query('./td|./th', $row) as $cell) {
                    while (isset($grid[$r][$c])) {
                        $c++;
                    }
                    $copy = $cell->cloneNode(true);
                    foreach (iterator_to_array($copy->getElementsByTagName('sup')) as $sup) {
                        $sup->parentNode->removeChild($sup);
                    }
                    $text = trim(preg_replace('/\s+/u', ' ', $copy->textContent));
                    $rows = max(1, (int) $cell->getAttribute('rowspan'));
                    $cols = max(1, (int) $cell->getAttribute('colspan'));
                    if ($rows > 100 || $cols > 100) {
                        throw new RuntimeException('Unexpected pricing table spans.');
                    }
                    for ($dr = 0; $dr < $rows; $dr++) {
                        for ($dc = 0; $dc < $cols; $dc++) {
                            $grid[$r + $dr][$c + $dc] = $text;
                        }
                    }
                    $c += $cols;
                }
            }
            if (! isset($grid[0]) || ($grid[0][0] ?? '') !== 'MODEL') {
                continue;
            }
            $rates = [];
            foreach ($grid[0] as $column => $model) {
                if (! preg_match('/^deepseek-[a-z0-9._-]+$/', $model)) {
                    continue;
                }
                $rate = [];
                foreach (array_slice($grid, 1) as $row) {
                    $labels = array_slice($row, 0, $column);
                    if (! in_array('PEAK', $labels, true)) {
                        continue;
                    }
                    $label = implode(' ', $labels);
                    $kind = str_contains($label, '1M INPUT TOKENS')
                        ? (str_contains($label, 'CACHE HIT') ? 'cached_input' : (str_contains($label, 'CACHE MISS') ? 'input' : null))
                        : (str_contains($label, '1M OUTPUT TOKENS') ? 'output' : null);
                    if ($kind && preg_match('/^\$([0-9]+(?:\.[0-9]+)?)$/', $row[$column] ?? '', $match)) {
                        $rate[$kind] = (float) $match[1];
                    }
                }
                if (! isset($rate['input'], $rate['output'], $rate['cached_input']) || $rate['input'] <= 0 || $rate['output'] <= 0 || $rate['cached_input'] < 0 || $rate['cached_input'] > $rate['input']) {
                    throw new RuntimeException('Missing or invalid model pricing.');
                }
                $rates[$model] = $rate;
            }
            if ($rates) {
                // Officially documented legacy Flash aliases.
                if (isset($rates['deepseek-flash'])) {
                    foreach (['deepseek-v4-flash', 'deepseek-v4-flash-vision-exp'] as $alias) {
                        if (str_contains($html, $alias)) {
                            $rates[$alias] = $rates['deepseek-flash'];
                        }
                    }
                }

                return $rates;
            }
        }
        throw new RuntimeException('Official pricing table was not recognized.');
    }
}
