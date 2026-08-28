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
}
