<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateGitHubSettingsRequest;
use App\Services\GitHubService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly GitHubService $github,
    ) {}

    public function githubShow(): JsonResponse
    {
        $pat = $this->settings->getEncrypted('github.pat');

        return response()->json(['data' => [
            'configured'  => $pat !== null,
            'pat_preview' => $pat ? substr($pat, 0, 4).'****'.substr($pat, -4) : null,
        ]]);
    }

    public function githubUpdate(UpdateGitHubSettingsRequest $request): JsonResponse
    {
        $pat = $request->input('pat');

        if (! $this->github->validatePat($pat)) {
            return response()->json(['message' => 'PAT invalide ou sans permission de lecture.'], 422);
        }

        $this->settings->setEncrypted('github.pat', $pat);

        return response()->json(['data' => [
            'configured'  => true,
            'pat_preview' => substr($pat, 0, 4).'****'.substr($pat, -4),
        ]]);
    }

    public function githubRepos(Request $request): JsonResponse
    {
        $pat = $this->settings->getEncrypted('github.pat');

        if (! $pat) {
            return response()->json(['message' => 'PAT GitHub non configuré.'], 422);
        }

        try {
            $repos = $this->github->listRepos(
                $pat,
                (int) $request->input('page', 1),
                (int) $request->input('per_page', 30),
                (string) $request->input('search', ''),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $repos]);
    }
}
