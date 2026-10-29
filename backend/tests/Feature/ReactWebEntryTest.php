<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReactWebEntryTest extends TestCase
{
    public function test_backend_web_entrypoints_redirect_to_react(): void
    {
        config()->set('app.frontend_url', 'https://drjunie.online');

        $this->get('/')->assertRedirect('https://drjunie.online/');
        $this->get('/login')->assertRedirect('https://drjunie.online/login');
        $this->get('/register')->assertRedirect('https://drjunie.online/register');
        $this->get('/login?return_to=%2Fcheckout')
            ->assertRedirect('https://drjunie.online/login?returnTo=%2Fcheckout');
        $this->get('/login?return_to=https%3A%2F%2Fevil.example')
            ->assertRedirect('https://drjunie.online/login');
        $this->get('/shop')->assertRedirect('https://drjunie.online/products');
        $this->get('/shop/junie-serum')->assertRedirect('https://drjunie.online/products/junie-serum');
        $this->get('/admin/products')->assertRedirect('https://drjunie.online/admin');
        $this->get('/dealer/orders')->assertRedirect('https://drjunie.online/dealer');
        $this->get('/account/profile')->assertRedirect('https://drjunie.online/account');
    }

    public function test_legacy_blade_actions_are_no_longer_routable(): void
    {
        $this->post('/login')->assertStatus(405);
        $this->post('/register')->assertStatus(405);
        $this->post('/logout')->assertNotFound();
        $this->get('/blade/admin')->assertNotFound();
        $this->get('/blade/dealer')->assertNotFound();
    }

    public function test_unauthenticated_api_request_returns_json_unauthorized(): void
    {
        $this->getJson('/api/user')->assertUnauthorized()->assertJsonStructure(['message']);
    }
}
