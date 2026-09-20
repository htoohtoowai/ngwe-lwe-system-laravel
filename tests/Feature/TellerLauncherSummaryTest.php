<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TellerLauncherSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('teller launcher summary tests');
        parent::setUp();
    }

    public function test_teller_dashboard_exposes_mobile_launcher_summary_props(): void
    {
        $teller = User::factory()->create(['role' => 'teller']);

        $this->actingAs($teller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('teller/Launcher')
                    ->where('role', 'teller')
                    ->where('notificationCount', 0)
                    ->where('pendingCashInCount', 0)
                    ->has('floats', 0)
                    ->has('recent', 0)
            );
    }
}
