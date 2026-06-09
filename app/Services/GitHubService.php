<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GitHubService
{
    /**
     * Vérifie qu'un PAT GitHub est valide en appelant l'API /user.
     */
    public function validatePat(string $pat): bool
    {
        $response = Http::withToken($pat)
            ->withHeaders(['User-Agent' => 'gocode/1.0'])
            ->get('https://api.github.com/user');

        return $response->successful();
    }

    /**
     * Construit l'URL HTTPS authentifiée pour les opérations git.
     * Format : https://<token>@github.com/<owner>/<repo>.git
     *
     * @throws \InvalidArgumentException si l'URL n'est pas une URL GitHub reconnue
     */
    public function buildAuthenticatedUrl(string $repoUrl, string $pat): string
    {
        $path = $this->extractGitHubPath($repoUrl);

        if ($path === null) {
            throw new \InvalidArgumentException("URL GitHub non reconnue : {$repoUrl}");
        }

        return "https://{$pat}@github.com/{$path}.git";
    }

    /**
     * Extrait le chemin owner/repo depuis une URL GitHub (HTTPS ou SSH).
     * Retourne null si l'URL n'est pas reconnue.
     */
    public function extractGitHubPath(string $url): ?string
    {
        // https://github.com/owner/repo ou https://github.com/owner/repo.git
        if (preg_match('~^https?://github\.com/([^/]+/[^/?#]+?)(?:\.git)?(?:[/?#].*)?$~', $url, $m)) {
            return $m[1];
        }

        // git@github.com:owner/repo.git
        if (preg_match('~^git@github\.com:([^/]+/[^/]+?)(?:\.git)?$~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Liste les dépôts de l'utilisateur authentifié (triés par date de mise à jour).
     *
     * @return list<array{id: int, full_name: string, name: string, description: string|null, private: bool, html_url: string, language: string|null, updated_at: string, default_branch: string}>
     *
     * @throws \RuntimeException si l'appel API échoue
     */
    public function listRepos(string $pat, int $page = 1, int $perPage = 30, string $search = ''): array
    {
        if ($search !== '') {
            // Recherche dans les repos de l'utilisateur via l'API search
            $response = Http::withToken($pat)
                ->withHeaders(['User-Agent' => 'gocode/1.0'])
                ->get('https://api.github.com/search/repositories', [
                    'q'        => "{$search} user:".($this->getAuthenticatedUser($pat)['login'] ?? ''),
                    'sort'     => 'updated',
                    'per_page' => $perPage,
                    'page'     => $page,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('GitHub API error: '.$response->body());
            }

            $items = $response->json('items', []);
        } else {
            $response = Http::withToken($pat)
                ->withHeaders(['User-Agent' => 'gocode/1.0'])
                ->get('https://api.github.com/user/repos', [
                    'sort'      => 'updated',
                    'per_page'  => $perPage,
                    'page'      => $page,
                    'affiliation' => 'owner,collaborator,organization_member',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('GitHub API error: '.$response->body());
            }

            $items = $response->json() ?? [];
        }

        return array_map(fn ($r) => [
            'id'             => $r['id'],
            'full_name'      => $r['full_name'],
            'name'           => $r['name'],
            'description'    => $r['description'],
            'private'        => $r['private'],
            'html_url'       => $r['html_url'],
            'clone_url'      => $r['clone_url'],
            'language'       => $r['language'],
            'updated_at'     => $r['updated_at'],
            'default_branch' => $r['default_branch'],
        ], $items);
    }

    /**
     * Retourne le profil de l'utilisateur authentifié.
     *
     * @return array{login: string, avatar_url: string, name: string|null}
     */
    public function getAuthenticatedUser(string $pat): array
    {
        $response = Http::withToken($pat)
            ->withHeaders(['User-Agent' => 'gocode/1.0'])
            ->get('https://api.github.com/user');

        return $response->json() ?? [];
    }

    /**
     * Pousse le contenu d'un dossier local vers GitHub via la Git Data API.
     * Contourne le bug Windows "getaddrinfo() thread failed to start" en évitant
     * tout subprocess réseau — les appels HTTP passent par le curl de PHP.
     *
     * @throws \RuntimeException
     */
    public function pushDirectory(string $pat, string $repoFullName, string $localPath, string $branch = 'main', ?string $message = null): void
    {
        $http = Http::withToken($pat)->withHeaders(['User-Agent' => 'gocode/1.0']);

        // Récupérer le SHA du dernier commit sur la branche (null si repo vide)
        $refResp    = $http->get("https://api.github.com/repos/{$repoFullName}/git/refs/heads/{$branch}");
        $parentSha  = $refResp->successful() ? $refResp->json('object.sha') : null;
        $parentTree = null;

        if ($parentSha) {
            $commitResp  = $http->get("https://api.github.com/repos/{$repoFullName}/git/commits/{$parentSha}");
            $parentTree  = $commitResp->json('tree.sha');
        }

        // Collecter les fichiers (hors .git)
        $files = $this->collectFiles($localPath);

        if (empty($files)) {
            throw new \RuntimeException('Aucun fichier à pousser.');
        }

        // Créer les blobs
        $treeItems = [];
        foreach ($files as $relativePath => $absolutePath) {
            $content  = base64_encode(file_get_contents($absolutePath));
            $blobResp = $http->post("https://api.github.com/repos/{$repoFullName}/git/blobs", [
                'content'  => $content,
                'encoding' => 'base64',
            ]);

            if (! $blobResp->successful()) {
                throw new \RuntimeException("Erreur création blob pour {$relativePath}: " . $blobResp->body());
            }

            $treeItems[] = [
                'path' => str_replace('\\', '/', $relativePath),
                'mode' => '100644',
                'type' => 'blob',
                'sha'  => $blobResp->json('sha'),
            ];
        }

        // Créer le tree
        $treePayload = ['tree' => $treeItems];
        if ($parentTree) {
            $treePayload['base_tree'] = $parentTree;
        }

        $treeResp = $http->post("https://api.github.com/repos/{$repoFullName}/git/trees", $treePayload);

        if (! $treeResp->successful()) {
            throw new \RuntimeException('Erreur création tree: ' . $treeResp->body());
        }

        $treeSha = $treeResp->json('sha');

        // Créer le commit
        $commitPayload = [
            'message' => $message ?: 'sync: push changes',
            'tree'    => $treeSha,
            'author'  => ['name' => 'gocode', 'email' => 'gocode@local'],
        ];

        if ($parentSha) {
            $commitPayload['parents'] = [$parentSha];
        }

        $newCommitResp = $http->post("https://api.github.com/repos/{$repoFullName}/git/commits", $commitPayload);

        if (! $newCommitResp->successful()) {
            throw new \RuntimeException('Erreur création commit: ' . $newCommitResp->body());
        }

        $newCommitSha = $newCommitResp->json('sha');

        // Mettre à jour ou créer la ref de branche
        if ($refResp->successful()) {
            $updateResp = $http->patch(
                "https://api.github.com/repos/{$repoFullName}/git/refs/heads/{$branch}",
                ['sha' => $newCommitSha, 'force' => false]
            );

            if (! $updateResp->successful()) {
                throw new \RuntimeException('Erreur mise à jour branche: ' . $updateResp->body());
            }
        } else {
            $createResp = $http->post(
                "https://api.github.com/repos/{$repoFullName}/git/refs",
                ['ref' => "refs/heads/{$branch}", 'sha' => $newCommitSha]
            );

            if (! $createResp->successful()) {
                throw new \RuntimeException('Erreur création branche: ' . $createResp->body());
            }
        }
    }

    /**
     * Retourne tous les fichiers d'un dossier (hors .git), indexés par chemin relatif.
     *
     * @return array<string, string>
     */
    private function collectFiles(string $basePath): array
    {
        $result  = [];
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($absolute, strlen($basePath)), '/');

            // Exclure .git et tout ce qu'il contient
            if (str_starts_with($relative, '.git/') || $relative === '.git') {
                continue;
            }

            $result[$relative] = $absolute;
        }

        return $result;
    }

    /**
     * Crée un nouveau dépôt sur le compte de l'utilisateur authentifié.
     *
     * @return array{html_url: string, clone_url: string, full_name: string, private: bool}
     *
     * @throws \RuntimeException si la création échoue
     */
    public function createRepo(string $pat, string $name, bool $private = false, ?string $description = null): array
    {
        $payload = ['name' => $name, 'private' => $private, 'auto_init' => false];

        if ($description) {
            $payload['description'] = $description;
        }

        $response = Http::withToken($pat)
            ->withHeaders(['User-Agent' => 'gocode/1.0'])
            ->post('https://api.github.com/user/repos', $payload);

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->body();
            throw new \RuntimeException("GitHub API error: {$message}");
        }

        $repo = $response->json();

        return [
            'html_url'   => $repo['html_url'],
            'clone_url'  => $repo['clone_url'],
            'full_name'  => $repo['full_name'],
            'private'    => $repo['private'],
        ];
    }

    /**
     * Retourne le chemin de clone local pour un projet donné.
     */
    public function getClonePath(int|string $projectId): string
    {
        return storage_path("app/repos/{$projectId}");
    }
}
