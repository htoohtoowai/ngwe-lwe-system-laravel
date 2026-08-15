<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin Inertia page tests');
        parent::setUp();
    }

    public function test_admin_master_and_report_pages_are_inertia_web_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pages = [
            '/admin/companies' => 'admin/Companies',
            '/admin/accounts' => 'admin/Accounts',
            '/admin/fees' => 'admin/Fees',
            '/admin/exchange-rates' => 'admin/ExchangeRates',
            '/admin/reports' => 'admin/Reports',
        ];

        foreach ($pages as $uri => $component) {
            $this->actingAs($admin)->get($uri)->assertOk()->assertInertia(
                fn (Assert $page) => $page->component($component)
            );
        }
    }
}
