<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_public_home_page_presents_mkpos_and_links_to_the_business_app(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Simple point of sale.')
            ->assertSee('Complete business control.')
            ->assertSee('/app/#/signup', false)
            ->assertSee('/app/#/login', false)
            ->assertSee('landing/landing.css', false)
            ->assertSee('Android download coming soon');
    }

    public function test_business_spa_entry_point_is_unchanged(): void
    {
        $this->get('/app/')->assertOk();
    }

    public function test_product_guide_is_public_and_documents_platform_limits(): void
    {
        $this->get('/documentation')->assertOk()->assertSee('MKPOS documentation');
        foreach (\App\Http\Controllers\LandingController::TOPICS as $key => $info) {
            $response = $this->get('/documentation/'.$key)->assertOk()->assertSee($info[0])
                ->assertSee('aria-label="On this page"', false);
            preg_match_all('/<h2 id="([^"]+)">/', $response->getContent(), $sections);
            $this->assertGreaterThanOrEqual(4, count($sections[1]), $key);
            $this->assertSame(count($sections[1]), count(array_unique($sections[1])), $key);
            foreach ($sections[1] as $section) {
                $response->assertSee('href="#'.$section.'"', false);
            }
        }
        $this->get('/')->assertOk()->assertDontSee('Typing an IP address or COM port does not create a direct connection.');
        $this->get('/documentation/printing')->assertOk()->assertSee('Typing an IP address or COM port does not create a direct connection.');
        $this->get('/documentation/unknown')->assertNotFound();
        $this->get('/documentation/products')->assertSee('enter -2')->assertSee('it is not an update-existing-products operation.');
        $this->get('/documentation/sales')->assertSee('Printing failed does not mean the sale failed.');
        $this->get('/documentation/data')->assertSee('Accounts, passwords, staff roles and subscription records');
    }

    public function test_package_page_uses_published_billing_plans(): void
    {
        $this->get('/plans')->assertOk()->assertViewHas('plans', function ($plans) {
            $expected = \Illuminate\Support\Facades\DB::table('subscription_plans')
                ->where('is_active', true)->where('is_public', true)->where('is_system', false)
                ->orderBy('sort_order')->orderBy('price')->pluck('name');
            return $plans->pluck('name')->all() === $expected->all();
        })->assertSee('Package plans')->assertSee('Free trial');
        $this->view('landing.plans', ['plans' => collect()])->assertSee('No paid packages are currently published.');
        $this->view('landing.plans', ['plans' => collect([(object) [
            'name' => 'Example package', 'description' => 'Sample description', 'price' => 12345,
            'currency' => 'MMK', 'duration_days' => 90, 'features' => '["Sample feature"]',
        ]])])->assertSee('12,345 MMK')->assertSee('90 days')->assertSee('Sample feature');
    }
}
