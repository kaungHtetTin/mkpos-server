<?php

namespace Tests\Feature;

use App\Services\DeepSeekPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepSeekPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/deepseek-pricing.html'));
    }

    public function test_fetches_official_table_and_refreshes_cached_rates_after_six_hours(): void
    {
        $html = $this->fixture();
        Http::fake([DeepSeekPricing::SOURCE => Http::sequence()
            ->push($html)->push(str_replace('$0.3<', '$0.4<', $html))]);
        $service = new DeepSeekPricing;
        $first = $service->get();
        $this->assertSame(['cached_input' => 0.006, 'input' => 0.3, 'output' => 1.2], $first['rates']['deepseek-flash']);
        $this->assertSame(['cached_input' => 0.044, 'input' => 1.32, 'output' => 3.96], $first['rates']['deepseek-v4-pro']);
        $this->assertSame($first['rates']['deepseek-flash'], $first['rates']['deepseek-v4-flash']);
        $this->assertFalse($first['stale']);
        $this->assertSame($first, $service->get());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === DeepSeekPricing::SOURCE && ! $request->hasHeader('Authorization'));
        $this->travel(7)->hours();
        $next = $service->get();
        $this->assertSame(0.4, $next['rates']['deepseek-flash']['input']);
        $this->assertNotSame($first['fetched_at'], $next['fetched_at']);
        Http::assertSentCount(2);
    }

    public function test_failed_or_unrecognized_response_keeps_last_good_rates_and_retries_later(): void
    {
        Http::fake([DeepSeekPricing::SOURCE => Http::sequence()->push($this->fixture())
            ->push('<html>Pricing page changed</html>')->push('Unavailable', 503)->push($this->fixture())]);
        $service = new DeepSeekPricing;
        $first = $service->get();
        $this->travel(7)->hours();
        $stale = $service->get();
        $this->assertTrue($stale['stale']);
        $this->assertSame($first['rates'], $stale['rates']);
        $this->assertSame($first['fetched_at'], $stale['fetched_at']);
        $service->get();
        Http::assertSentCount(2);
        $this->travel(11)->minutes();
        $this->assertTrue($service->get()['stale']);
        $this->travel(11)->minutes();
        $this->assertFalse($service->get()['stale']);
    }

    public function test_first_fetch_failure_returns_no_rates_and_never_invents_prices(): void
    {
        Http::fake([DeepSeekPricing::SOURCE => Http::failedConnection()]);
        $result = (new DeepSeekPricing)->get();
        $this->assertTrue($result['stale']);
        $this->assertNull($result['fetched_at']);
        $this->assertSame([], $result['rates']);
    }

    public function test_missing_price_or_changed_currency_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DeepSeekPricing)->parse(str_replace('$0.3<', 'EUR 0.3<', $this->fixture()));
    }

    public function test_missing_cache_hit_price_is_not_silently_replaced_with_an_uncached_rate(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DeepSeekPricing)->parse(str_replace('$0.006<', 'Unavailable<', $this->fixture()));
    }
}
