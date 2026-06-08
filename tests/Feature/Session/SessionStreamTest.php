<?php

namespace Tests\Feature\Session;

use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionStreamTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = User::factory()->create();
        $this->project = Project::factory()->create();
    }

    // --- helpers ---

    private function makeSession(string $status = 'idle'): Session
    {
        return Session::factory()->for($this->project)->create(['status' => $status]);
    }

    /** Insère un message agent dans la session. */
    private function insertMessage(Session $session, string $eventType, array $payload = []): Message
    {
        $dbType = match ($eventType) {
            'message' => 'text',
            'done'    => 'status',
            default   => $eventType,
        };
        $role = match ($eventType) {
            'message', 'plan'                        => 'agent',
            'tool_call', 'terminal', 'file_change'   => 'tool',
            default                                  => 'system',
        };

        return Message::create([
            'session_id' => $session->id,
            'role'       => $role,
            'type'       => $dbType,
            'content'    => json_encode($payload),
            'meta'       => ['event_type' => $eventType, 'timestamp' => now()->toIso8601String()],
        ]);
    }

    /** Capture le contenu streamé depuis l'URL. */
    private function streamContent(string $url, array $headers = []): string
    {
        return $this->actingAs($this->user)
            ->withHeaders($headers)
            ->get($url)
            ->streamedContent();
    }

    // --- auth & routing ---

    public function test_unauthenticated_request_returns_401(): void
    {
        $session = $this->makeSession();

        $this->get("/api/sessions/{$session->id}/stream")->assertUnauthorized();
    }

    public function test_invalid_session_id_returns_404(): void
    {
        $this->actingAs($this->user)
            ->get('/api/sessions/00000000-0000-0000-0000-000000000000/stream')
            ->assertNotFound();
    }

    // --- headers ---

    public function test_response_has_sse_headers(): void
    {
        $session = $this->makeSession('done');
        $this->insertMessage($session, 'done');

        $response = $this->actingAs($this->user)
            ->get("/api/sessions/{$session->id}/stream");

        $response->assertOk();
        $this->assertStringContainsString(
            'text/event-stream',
            $response->headers->get('Content-Type', '')
        );
        // Symfony peut ajouter 'private' à Cache-Control ; on vérifie les directives essentielles
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $response->assertHeader('X-Accel-Buffering', 'no');
    }

    // --- stream content ---

    public function test_streams_existing_messages_then_closes_on_done(): void
    {
        $session = $this->makeSession();

        $msg  = $this->insertMessage($session, 'message', ['text' => 'Hello']);
        $done = $this->insertMessage($session, 'done');

        $content = $this->streamContent("/api/sessions/{$session->id}/stream");

        // Message normal
        $this->assertStringContainsString("id: {$msg->id}", $content);
        $this->assertStringContainsString("event: message", $content);
        $this->assertStringContainsString('"text":"Hello"', $content);

        // Événement done — le flux doit s'arrêter là
        $this->assertStringContainsString("id: {$done->id}", $content);
        $this->assertStringContainsString("event: done", $content);
    }

    public function test_stream_closes_on_error_event(): void
    {
        $session = $this->makeSession();

        $error = $this->insertMessage($session, 'error', ['message' => 'Boom']);

        $content = $this->streamContent("/api/sessions/{$session->id}/stream");

        $this->assertStringContainsString("id: {$error->id}", $content);
        $this->assertStringContainsString("event: error", $content);
    }

    public function test_each_event_contains_full_wire_format(): void
    {
        $session = $this->makeSession();
        $this->insertMessage($session, 'done');

        $content = $this->streamContent("/api/sessions/{$session->id}/stream");

        // Chaque ligne data: doit contenir la structure wire (type, session_id, timestamp, payload)
        preg_match('/^data: (.+)$/m', $content, $matches);
        $this->assertNotEmpty($matches[1]);

        $decoded = json_decode($matches[1], true);
        $this->assertArrayHasKey('type', $decoded);
        $this->assertArrayHasKey('session_id', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertArrayHasKey('payload', $decoded);
        $this->assertSame($session->id, $decoded['session_id']);
    }

    public function test_resumes_from_last_event_id(): void
    {
        $session = $this->makeSession();

        $before = $this->insertMessage($session, 'message', ['text' => 'Before cursor']);
        $after  = $this->insertMessage($session, 'message', ['text' => 'After cursor']);
        $this->insertMessage($session, 'done');

        $content = $this->streamContent(
            "/api/sessions/{$session->id}/stream",
            ['Last-Event-ID' => (string) $before->id]
        );

        // Le message avant le curseur ne doit pas être rejoué
        $this->assertStringNotContainsString("id: {$before->id}", $content);
        // Le message après doit l'être
        $this->assertStringContainsString("id: {$after->id}", $content);
    }

    public function test_closes_cleanly_when_session_is_terminal_with_no_messages(): void
    {
        // Session marquée done mais sans message done en base (cas dégradé)
        $session = $this->makeSession('done');

        $content = $this->streamContent("/api/sessions/{$session->id}/stream");

        // Le commentaire SSE de fermeture doit être émis
        $this->assertStringContainsString(': stream-closed', $content);
    }

    public function test_multiple_event_types_are_streamed_correctly(): void
    {
        $session = $this->makeSession();

        $log  = $this->insertMessage($session, 'log', ['text' => 'Building...']);
        $term = $this->insertMessage($session, 'terminal', ['output' => 'make build']);
        $done = $this->insertMessage($session, 'done');

        $content = $this->streamContent("/api/sessions/{$session->id}/stream");

        $this->assertStringContainsString("event: log", $content);
        $this->assertStringContainsString("event: terminal", $content);
        $this->assertStringContainsString("event: done", $content);
        // Vérification de l'ordre des ids
        $this->assertGreaterThan(strpos($content, "id: {$log->id}"), strpos($content, "id: {$term->id}"));
        $this->assertGreaterThan(strpos($content, "id: {$term->id}"), strpos($content, "id: {$done->id}"));
    }
}
