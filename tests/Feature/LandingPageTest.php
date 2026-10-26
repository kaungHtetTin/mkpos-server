<?php

namespace Tests\Feature;

use App\Http\Controllers\LandingController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_office_features_and_administrator_guide_are_public(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="office"', false)
            ->assertSee('A team of administrators')
            ->assertSee('Know who changed what')
            ->assertSee('Flexible bank books')
            ->assertSee('Founder profit shares')
            ->assertSee('Financial history preserved')
            ->assertSee(route('documentation.show', 'office'), false);
        $this->get('/documentation/office')->assertOk()
            ->assertSee('MKPOS Office administration')
            ->assertSee('Active shares must total 100%.')
            ->assertSee('Existing receipts keep their original book')
            ->assertSee('php artisan office:financial-snapshot')
            ->assertSee('Unsafe rollback is blocked');
    }

    public function test_public_pages_use_office_branding_and_validated_color_fallback(): void
    {
        DB::table('platform_branding')->where('id', 1)->update([
            'primary_color' => '#4f46e5', 'logo_path' => 'platform-branding/test-logo.png',
            'icon_path' => 'platform-branding/test-icon.webp', 'favicon_path' => 'platform-branding/test-favicon.png',
        ]);
        foreach (['/', '/documentation/office', '/plans', '/tutorials'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('--theme-primary: #4f46e5;', false)
                ->assertSee('content="#4f46e5"', false)
                ->assertSee('/api/branding/assets/logo?v=', false)
                ->assertSee('/api/branding/assets/icon?v=', false)
                ->assertSee('/api/branding/assets/favicon?v=', false)
                ->assertDontSee('platform-branding/test-logo.png');
        }
        DB::table('platform_branding')->where('id', 1)->update(['primary_color' => 'red;/*']);
        $this->get('/')->assertOk()->assertSee('--theme-primary: #0e684e;', false)->assertDontSee('red;/*', false);
    }

    public function test_public_links_use_configured_app_subdomains(): void
    {
        config()->set('clients.web_url', 'https://app.mkposmyanmar.com');
        config()->set('clients.mobile_url', 'https://mobile.mkposmyanmar.com');

        $this->get('/')
            ->assertOk()
            ->assertSee('https://app.mkposmyanmar.com/signup', false)
            ->assertSee('https://app.mkposmyanmar.com/login', false)
            ->assertSee('https://mobile.mkposmyanmar.com/login', false)
            ->assertDontSee('/app/#/signup', false);
    }

    public function test_public_home_page_presents_mkpos_and_links_to_the_business_app(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Sales, stock and credit.')
            ->assertSee('All in one place.')
            ->assertSee('/app/#/signup', false)
            ->assertSee('/app/#/login', false)
            ->assertSee('landing/landing.css', false)
            ->assertSee('Android download coming soon');
    }

    public function test_old_business_spa_entry_point_redirects_to_the_subdomain(): void
    {
        $this->get('/app/')->assertRedirect('https://app.mkposmyanmar.com/');
    }

    public function test_customer_shop_entry_point_serves_the_existing_customer_app(): void
    {
        $response = $this->get('/shop/')->assertOk();
        $this->assertSame(realpath(public_path('shop/index.html')), $response->baseResponse->getFile()->getRealPath());
    }

    public function test_product_guide_is_public_and_documents_platform_limits(): void
    {
        $this->get('/documentation')->assertOk()->assertSee('Documentation');
        foreach (LandingController::TOPICS as $key => $info) {
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
            $expected = DB::table('subscription_plans')
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
