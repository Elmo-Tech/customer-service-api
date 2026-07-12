<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_production_frontend_origin_can_preflight_authentication_requests(): void
    {
        $frontendOrigin = 'https://tickets-sys.testingelmo.com';
        config()->set('cors.allowed_origins', [$frontendOrigin]);

        $response = $this->withHeaders([
            'Origin' => $frontendOrigin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->call('OPTIONS', '/api/v1/admin/auth/login');

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', $frontendOrigin);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
