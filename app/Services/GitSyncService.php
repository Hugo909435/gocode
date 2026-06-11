<?php

namespace App\Services;

use App\Models\Project;

/**
 * Orchestration de la synchronisation GitHub d'un projet (pull + push via API).
 *
 * L'état de la dernière synchro (carte path => blob SHA) est stocké dans
 * projects.metadata['git_sync'] et sert d'ancêtre commun pour distinguer
 * « modifié localement » de « modifié côté GitHub ». Stratégie de conflit :
 * le local gagne toujours (le travail de Claude Code est prioritaire).
 *
 * Flux : pull à l'ouverture de session (code à jour avant les modifs) +
 * pull automatique au début de chaque push (pas de divergence silencieuse).
 */
class GitSyncService
{
    public function __construct(
        private readonly GitHubService $github,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Récupère les changements distants vers le dossier local.
     *
     * @return array{status: string, updated: list<string>, deleted: list<string>, conflicts: list<string>}
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function pull(Project $project): array
    {
        [$token, $repoPath, $branch] = $this->context($project);

        $result = $this->github->pullDirectory($token, $repoPath, $project->path, $branch, $this->baseTree($project));

        $this->saveBase($project, $branch, $result['head_sha'], $result['base']);

        return [
            'status' => $result['status'],
            'updated' => $result['updated'],
            'deleted' => $result['deleted'],
            'conflicts' => $result['conflicts'],
        ];
    }

    /**
     * Pull puis push : les changements distants sont d'abord rapatriés
     * (le local gagne en cas de conflit), puis l'état local est poussé.
     *
     * @return array{branch: string, remote: string, status: string, pushed: int, deleted: int, pull: array}
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function push(Project $project, ?string $message): array
    {
        [$token, $repoPath, $branch] = $this->context($project);

        $pull = $this->github->pullDirectory($token, $repoPath, $project->path, $branch, $this->baseTree($project));

        $push = $this->github->pushDirectory($token, $repoPath, $project->path, $branch, $message);

        $this->saveBase($project, $branch, $push['head_sha'], $push['tree']);

        return [
            'branch' => $branch,
            'remote' => $project->git_remote,
            'status' => $push['status'],
            'pushed' => $push['pushed'],
            'deleted' => $push['deleted'],
            'pull' => [
                'updated' => $pull['updated'],
                'deleted' => $pull['deleted'],
                'conflicts' => $pull['conflicts'],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, owner/repo, branche]
     *
     * @throws \InvalidArgumentException
     */
    private function context(Project $project): array
    {
        if (! $project->git_remote) {
            throw new \InvalidArgumentException('Ce projet n\'a pas de remote GitHub configuré.');
        }

        if (! $project->path || ! is_dir($project->path)) {
            throw new \InvalidArgumentException("Chemin du projet invalide : {$project->path}");
        }

        $token = $this->settings->getEncrypted('github.pat');

        if (! $token) {
            throw new \InvalidArgumentException('Aucun token GitHub configuré dans les paramètres.');
        }

        $repoPath = $this->github->extractGitHubPath($project->git_remote);

        if (! $repoPath) {
            throw new \InvalidArgumentException("URL GitHub non reconnue : {$project->git_remote}");
        }

        return [$token, $repoPath, $project->default_branch ?: 'main'];
    }

    /**
     * @return array<string, string>
     */
    private function baseTree(Project $project): array
    {
        return $project->metadata['git_sync']['tree'] ?? [];
    }

    /**
     * @param  array<string, string>  $tree
     */
    private function saveBase(Project $project, string $branch, ?string $headSha, array $tree): void
    {
        $metadata = $project->metadata ?? [];
        $metadata['git_sync'] = [
            'branch' => $branch,
            'head_sha' => $headSha,
            'tree' => $tree,
            'synced_at' => now()->toIso8601String(),
        ];

        $project->update(['metadata' => $metadata]);
    }
}
