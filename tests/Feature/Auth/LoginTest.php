<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'test@gocode.local',
            'password' => bcrypt('secret123'),
        ]);
    }

    public function test_rejects_login_with_missing_fields(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_rejects_login_with_invalid_credentials(): void
    {
        $this->postJson('/api/login', [
            'email' => 'test@gocode.local',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_logs_in_and_returns_authenticated_user(): void
    {
        $this->postJson('/api/login', [
            'email' => 'test@gocode.local',
            'password' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('data.email', 'test@gocode.local')
            ->assertJsonStructure(['data' => ['id', 'name', 'email']]);
    }

    public function test_returns_401_on_protected_routes_when_unauthenticated(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_returns_current_user_on_me_endpoint(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'test@gocode.local');
    }

    public function test_logs_out_successfully(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out']);
    }

    public function test_cannot_access_protected_routes_after_logout(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/logout')
            ->assertOk();

        // actingAs() fixe l'utilisateur sur le guard Web en mémoire pour tout le test.
        // On purge les guards pour simuler un navigateur qui a perdu sa session.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/me')->assertUnauthorized();
    }
}
