<?php

namespace Tests\Feature\Session;

use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Jobs exécutés synchronement (pas de worker nécessaire)
        config(['queue.default' => 'sync']);
        // Aucun délai entre événements mock → tests instantanés
        config(['agent.drivers.mock.delay_ms' => 0]);

        $this->user = User::factory()->create();
        // Chemin réel : SessionController::store refuse (422) un path inexistant
        $this->project = Project::factory()->create(['path' => sys_get_temp_dir()]);
    }

    public function test_requires_authentication_to_access_session_endpoints(): void
    {
        $session = Session::factory()->for($this->project)->idle()->create();

        $this->getJson("/api/projects/{$this->project->id}/sessions")->assertUnauthorized();
        $this->postJson("/api/projects/{$this->project->id}/sessions")->assertUnauthorized();
        $this->getJson("/api/sessions/{$session->id}")->assertUnauthorized();
        $this->postJson("/api/sessions/{$session->id}/instruction")->assertUnauthorized();
    }

    public function test_creates_session_and_emits_initial_status_event(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/sessions", [
                'title' => 'Test Session',
                'mode' => 'read',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'project_id', 'title', 'mode', 'status', 'created_at',
            ]])
            ->assertJsonPath('data.mode', 'read')
            ->assertJsonPath('data.project_id', $this->project->id);

        // MockDriver::startSession() émet un événement status idle
        $this->assertDatabaseHas('messages', [
            'session_id' => $response->json('data.id'),
            'role' => 'system',
            'type' => 'status',
        ]);
    }

    public function test_lists_sessions_for_project(): void
    {
        Session::factory()->count(3)->for($this->project)->create();

        $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->project->id}/sessions")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_shows_session_with_messages(): void
    {
        $session = Session::factory()->for($this->project)->idle()->create();
        Message::factory()->count(2)->for($session)->create();

        $this->actingAs($this->user)
            ->getJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'messages']])
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_sends_instruction_in_execute_mode_and_persists_expected_messages(): void
    {
        // 1. Création de session
        $sessionId = $this->actingAs($this->user)
            ->postJson("/api/projects/{$this->project->id}/sessions", [
                'title' => 'Execute session',
                'mode' => 'execute',
            ])
            ->assertCreated()
            ->json('data.id');

        // 2. Envoi d'une instruction en mode execute
        $this->actingAs($this->user)
            ->postJson("/api/sessions/{$sessionId}/instruction", [
                'instruction' => 'Ajoute un endpoint de recherche aux sessions',
                'mode' => 'execute',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId);

        // Le message utilisateur doit être persisté avant d'appeler le driver
        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'user',
            'type' => 'text',
            'content' => 'Ajoute un endpoint de recherche aux sessions',
        ]);

        // Événements émis par MockAgentJob en mode execute (scénario success)
        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'system',
            'type' => 'status',
        ]);

        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'agent',
            'type' => 'plan',
        ]);

        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'system',
            'type' => 'log',
        ]);

        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'tool',
            'type' => 'terminal',
        ]);

        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'tool',
            'type' => 'tool_call',
        ]);

        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'tool',
            'type' => 'file_change',
        ]);

        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'system',
            'type' => 'cost',
        ]);

        // Le job s'arrête sur la confirmation_request (attend une action humaine)
        $this->assertDatabaseHas('messages', [
            'session_id' => $sessionId,
            'role' => 'system',
            'type' => 'confirmation_request',
        ]);

        // La session doit être en attente de confirmation
        $this->assertDatabaseHas('agent_sessions', [
            'id' => $sessionId,
            'status' => 'awaiting_confirmation',
        ]);
    }

    public function test_patches_session_mode(): void
    {
        $session = Session::factory()->for($this->project)->idle()->create(['mode' => 'read']);

        $this->actingAs($this->user)
            ->patchJson("/api/sessions/{$session->id}", ['mode' => 'execute'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'execute');

        $this->assertDatabaseHas('agent_sessions', [
            'id' => $session->id,
            'mode' => 'execute',
        ]);
    }

    public function test_stops_running_session(): void
    {
        $session = Session::factory()->for($this->project)->running()->create();

        $this->actingAs($this->user)
            ->postJson("/api/sessions/{$session->id}/stop")
            ->assertOk()
            ->assertJsonPath('data.status', 'idle');

        $this->assertDatabaseHas('agent_sessions', [
            'id' => $session->id,
            'status' => 'idle',
        ]);
    }

    public function test_send_instruction_updates_mode_when_provided(): void
    {
        $session = Session::factory()->for($this->project)->idle()->create(['mode' => 'read']);

        $this->actingAs($this->user)
            ->postJson("/api/sessions/{$session->id}/instruction", [
                'instruction' => 'Explique le code',
                'mode' => 'plan',
            ])
            ->assertOk();

        $this->assertDatabaseHas('agent_sessions', [
            'id' => $session->id,
            'mode' => 'plan',
        ]);
    }

    public function test_send_instruction_returns_422_without_instruction(): void
    {
        $session = Session::factory()->for($this->project)->idle()->create();

        $this->actingAs($this->user)
            ->postJson("/api/sessions/{$session->id}/instruction", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['instruction']);
    }
}
