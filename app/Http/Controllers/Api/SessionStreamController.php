<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionStreamController extends Controller
{
    private const POLL_INTERVAL_US = 500_000; // 500 ms
    private const KEEPALIVE_AFTER  = 30;      // 30 × 500 ms = 15 s

    public function __invoke(Request $request, Session $session): StreamedResponse
    {
        $cursor = (int) $request->header('Last-Event-ID', 0);

        return response()->stream(
            callback: function () use ($session, $cursor) {
                // Désactive la compression gzip pour que les données partent byte-à-byte
                @ini_set('zlib.output_compression', 'Off');
                // Pas de limite de temps pour une connexion SSE longue durée
                set_time_limit(0);

                $keepaliveCount = 0;

                while (true) {
                    if (connection_aborted()) {
                        break;
                    }

                    $messages = Message::where('session_id', $session->id)
                        ->where('id', '>', $cursor)
                        ->orderBy('id')
                        ->get();

                    $terminal = false;

                    foreach ($messages as $message) {
                        $cursor    = $message->id;
                        $eventType = $message->meta['event_type'] ?? $message->type;
                        $timestamp = $message->meta['timestamp'] ?? $message->created_at->toIso8601String();
                        $payload   = json_decode($message->content, true) ?? $message->content;

                        $data = json_encode([
                            'type'       => $eventType,
                            'session_id' => $message->session_id,
                            'timestamp'  => $timestamp,
                            'payload'    => $payload,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        echo "id: {$message->id}\n";
                        echo "event: {$eventType}\n";
                        echo "data: {$data}\n\n";
                        // flush() vide le buffer SAPI (output_buffering) vers le socket TCP.
                        // Pas de ob_flush() ici : s'il y a des niveaux ob actifs (tests, middleware),
                        // les echo restent dans le buffer ob le plus récent — ob_get_clean() les capture.
                        flush();

                        if ($eventType === 'done' || $eventType === 'error') {
                            $terminal = true;
                            break;
                        }
                    }

                    if ($terminal) {
                        break;
                    }

                    // Si plus aucun message ET session déjà terminale, ferme proprement
                    if ($messages->isEmpty()) {
                        $fresh = Session::find($session->id);
                        if ($fresh === null || in_array($fresh->status, ['done', 'error'])) {
                            echo ": stream-closed\n\n";
                            flush();
                            break;
                        }
                    }

                    // Keepalive uniquement pendant les périodes creuses (aucun nouveau message)
                    if ($messages->isEmpty()) {
                        $keepaliveCount++;
                        if ($keepaliveCount >= self::KEEPALIVE_AFTER) {
                            echo ": keepalive\n\n";
                            flush();
                            $keepaliveCount = 0;
                        }
                    } else {
                        $keepaliveCount = 0;
                    }

                    usleep(self::POLL_INTERVAL_US);
                }
            },
            status: 200,
            headers: [
                'Content-Type'      => 'text/event-stream; charset=utf-8',
                'Cache-Control'     => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no', // Désactive le buffering nginx
                'Connection'        => 'keep-alive',
            ],
        );
    }
}
