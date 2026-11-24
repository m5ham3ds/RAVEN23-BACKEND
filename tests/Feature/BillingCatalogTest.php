<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    public function test_plan_catalog_is_public_but_purchases_remain_disabled_until_approved(): void
    {
        $this->getJson('/api/v1/billing/plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('purchases_enabled', false)
            ->assertJsonPath('plans', [])
            ->assertJsonMissingPath('wallet');

        $this->getJson('/api/v1/billing/plans?purchases_enabled=true&amount_cents=1')
            ->assertOk()
            ->assertJsonPath('purchases_enabled', false)
            ->assertJsonPath('plans', []);
    }
}
