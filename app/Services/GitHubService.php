<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

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
                    'q' => "{$search} user:".($this->getAuthenticatedUser($pat)['login'] ?? ''),
                    'sort' => 'updated',
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('GitHub API error: '.$response->body());
            }

            $items = $response->json('items', []);
        } else {
            $response = Http::withToken($pat)
                ->withHeaders(['User-Agent' => 'gocode/1.0'])
                ->get('https://api.github.com/user/repos', [
                    'sort' => 'updated',
                    'per_page' => $perPage,
                    'page' => $page,
                    'affiliation' => 'owner,collaborator,organization_member',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('GitHub API error: '.$response->body());
            }

            $items = $response->json() ?? [];
        }

        return array_map(fn ($r) => [
            'id' => $r['id'],
            'full_name' => $r['full_name'],
            'name' => $r['name'],
            'description' => $r['description'],
            'private' => $r['private'],
            'html_url' => $r['html_url'],
            'clone_url' => $r['clone_url'],
            'language' => $r['language'],
            'updated_at' => $r['updated_at'],
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
     * État distant d'une branche : SHA de tête + carte path → blob SHA.
     * Retourne null si la branche n'existe pas encore (repo vide ou tout neuf).
     *
     * @return array{head_sha: string, tree_sha: string, tree: array<string, string>}|null
     */
    public function getRemoteTree(string $pat, string $repoFullName, string $branch): ?array
    {
        $http = Http::withToken($pat)->withHeaders(['User-Agent' => 'gocode/1.0']);

        $refResp = $http->get("https://api.github.com/repos/{$repoFullName}/git/refs/heads/{$branch}");

        if (! $refResp->successful()) {
            return null;
        }

        $headSha = $refResp->json('object.sha');

        $commitResp = $http->get("https://api.github.com/repos/{$repoFullName}/git/commits/{$headSha}");

        if (! $commitResp->successful()) {
            throw new \RuntimeException('Erreur lecture commit distant: '.$commitResp->body());
        }

        $treeSha = $commitResp->json('tree.sha');

        $treeResp = $http->get("https://api.github.com/repos/{$repoFullName}/git/trees/{$treeSha}", ['recursive' => 1]);

        if (! $treeResp->successful()) {
            throw new \RuntimeException('Erreur lecture tree distant: '.$treeResp->body());
        }

        $tree = [];
        foreach ($treeResp->json('tree', []) as $item) {
            if (($item['type'] ?? '') === 'blob') {
                $tree[$item['path']] = $item['sha'];
            }
        }

        return ['head_sha' => $headSha, 'tree_sha' => $treeSha, 'tree' => $tree];
    }

    /**
     * Télécharge le contenu brut d'un blob distant.
     */
    public function downloadBlob(string $pat, string $repoFullName, string $sha): string
    {
        $resp = Http::withToken($pat)
            ->withHeaders(['User-Agent' => 'gocode/1.0'])
            ->get("https://api.github.com/repos/{$repoFullName}/git/blobs/{$sha}");

        if (! $resp->successful()) {
            throw new \RuntimeException("Erreur téléchargement blob {$sha}: ".$resp->body());
        }

        return base64_decode($resp->json('content') ?? '');
    }

    /**
     * SHA-1 git d'un contenu (objet blob) — permet de comparer un fichier local
     * au tree distant sans aucune commande git.
     */
    public function gitBlobSha(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    /**
     * Synchronise le dossier local depuis GitHub (équivalent pull, via API —
     * pas de subprocess réseau, cf. bug Windows getaddrinfo).
     *
     * Stratégie « le local gagne » : un fichier modifié localement depuis la
     * dernière synchro n'est jamais écrasé par la version distante — il partira
     * tel quel au prochain push. $baseTree (path => blob SHA au dernier sync)
     * sert d'ancêtre commun pour distinguer « modifié ici » de « modifié là-bas ».
     *
     * @param  array<string, string>  $baseTree
     * @return array{status: string, head_sha: ?string, updated: list<string>, deleted: list<string>, conflicts: list<string>, base: array<string, string>}
     */
    public function pullDirectory(string $pat, string $repoFullName, string $localPath, string $branch, array $baseTree = []): array
    {
        $remote = $this->getRemoteTree($pat, $repoFullName, $branch);

        if ($remote === null) {
            return ['status' => 'empty_remote', 'head_sha' => null, 'updated' => [], 'deleted' => [], 'conflicts' => [], 'base' => $baseTree];
        }

        $updated = [];
        $deleted = [];
        $conflicts = [];
        $newBase = $remote['tree'];

        foreach ($remote['tree'] as $path => $remoteSha) {
            $absolute = $this->toAbsolute($localPath, $path);
            $localSha = is_file($absolute) ? $this->gitBlobSha(file_get_contents($absolute)) : null;
            $baseSha = $baseTree[$path] ?? null;

            if ($localSha === $remoteSha) {
                continue; // déjà identique
            }

            if ($localSha === $baseSha) {
                // Inchangé localement depuis le dernier sync → on prend la version distante
                $dir = dirname($absolute);
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($absolute, $this->downloadBlob($pat, $repoFullName, $remoteSha));
                $updated[] = $path;
            } else {
                // Modifié des deux côtés → le local gagne ; la base reste divergente
                // pour que le fichier soit toujours considéré « modifié localement ».
                $conflicts[] = $path;
                if ($baseSha !== null) {
                    $newBase[$path] = $baseSha;
                } else {
                    unset($newBase[$path]);
                }
            }
        }

        // Fichiers supprimés côté GitHub depuis le dernier sync
        foreach ($baseTree as $path => $baseSha) {
            if (isset($remote['tree'][$path])) {
                continue;
            }

            $absolute = $this->toAbsolute($localPath, $path);

            if (! is_file($absolute)) {
                continue;
            }

            if ($this->gitBlobSha(file_get_contents($absolute)) === $baseSha) {
                unlink($absolute);
                $deleted[] = $path;
            } else {
                $conflicts[] = $path;
                $newBase[$path] = $baseSha;
            }
        }

        return [
            'status' => 'ok',
            'head_sha' => $remote['head_sha'],
            'updated' => $updated,
            'deleted' => $deleted,
            'conflicts' => $conflicts,
            'base' => $newBase,
        ];
    }

    /**
     * Pousse le contenu d'un dossier local vers GitHub via la Git Data API.
     * Contourne le bug Windows "getaddrinfo() thread failed to start" en évitant
     * tout subprocess réseau — les appels HTTP passent par le curl de PHP.
     *
     * Seuls les blobs qui diffèrent du distant sont uploadés, et les fichiers
     * supprimés localement sont retirés du repo (sha null dans le tree).
     *
     * @return array{status: string, head_sha: ?string, tree: array<string, string>, pushed: int, deleted: int}
     *
     * @throws \RuntimeException
     */
    public function pushDirectory(string $pat, string $repoFullName, string $localPath, string $branch = 'main', ?string $message = null): array
    {
        $http = Http::withToken($pat)->withHeaders(['User-Agent' => 'gocode/1.0']);

        $remote = $this->getRemoteTree($pat, $repoFullName, $branch);
        $parentSha = $remote['head_sha'] ?? null;
        $parentTree = $remote['tree_sha'] ?? null;
        $remoteTree = $remote['tree'] ?? [];

        $files = $this->collectFiles($localPath);

        if (empty($files)) {
            throw new \RuntimeException('Aucun fichier à pousser.');
        }

        $treeItems = [];
        $localTree = [];
        $pushed = 0;

        foreach ($files as $relativePath => $absolutePath) {
            $path = str_replace('\\', '/', $relativePath);
            $content = file_get_contents($absolutePath);
            $localSha = $this->gitBlobSha($content);
            $localTree[$path] = $localSha;

            // Blob identique côté distant → inutile de le re-uploader
            if (($remoteTree[$path] ?? null) === $localSha) {
                continue;
            }

            $blobResp = $http->post("https://api.github.com/repos/{$repoFullName}/git/blobs", [
                'content' => base64_encode($content),
                'encoding' => 'base64',
            ]);

            if (! $blobResp->successful()) {
                throw new \RuntimeException("Erreur création blob pour {$relativePath}: ".$blobResp->body());
            }

            $treeItems[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $blobResp->json('sha'),
            ];
            $pushed++;
        }

        // Propagation des suppressions locales : sha null retire le fichier du tree
        $removed = 0;
        if ($parentTree) {
            foreach ($remoteTree as $path => $sha) {
                if (! isset($localTree[$path])) {
                    $treeItems[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                    $removed++;
                }
            }
        }

        if ($treeItems === []) {
            return ['status' => 'up_to_date', 'head_sha' => $parentSha, 'tree' => $localTree, 'pushed' => 0, 'deleted' => 0];
        }

        // Créer le tree
        $treePayload = ['tree' => $treeItems];
        if ($parentTree) {
            $treePayload['base_tree'] = $parentTree;
        }

        $treeResp = $http->post("https://api.github.com/repos/{$repoFullName}/git/trees", $treePayload);

        if (! $treeResp->successful()) {
            throw new \RuntimeException('Erreur création tree: '.$treeResp->body());
        }

        $treeSha = $treeResp->json('sha');

        // Créer le commit
        $commitPayload = [
            'message' => $message ?: 'sync: push changes',
            'tree' => $treeSha,
            'author' => ['name' => 'gocode', 'email' => 'gocode@local'],
        ];

        if ($parentSha) {
            $commitPayload['parents'] = [$parentSha];
        }

        $newCommitResp = $http->post("https://api.github.com/repos/{$repoFullName}/git/commits", $commitPayload);

        if (! $newCommitResp->successful()) {
            throw new \RuntimeException('Erreur création commit: '.$newCommitResp->body());
        }

        $newCommitSha = $newCommitResp->json('sha');

        // Mettre à jour ou créer la ref de branche
        if ($parentSha) {
            $updateResp = $http->patch(
                "https://api.github.com/repos/{$repoFullName}/git/refs/heads/{$branch}",
                ['sha' => $newCommitSha, 'force' => false]
            );

            if (! $updateResp->successful()) {
                throw new \RuntimeException('Erreur mise à jour branche: '.$updateResp->body());
            }
        } else {
            $createResp = $http->post(
                "https://api.github.com/repos/{$repoFullName}/git/refs",
                ['ref' => "refs/heads/{$branch}", 'sha' => $newCommitSha]
            );

            if (! $createResp->successful()) {
                throw new \RuntimeException('Erreur création branche: '.$createResp->body());
            }
        }

        return ['status' => 'pushed', 'head_sha' => $newCommitSha, 'tree' => $localTree, 'pushed' => $pushed, 'deleted' => $removed];
    }

    private function toAbsolute(string $basePath, string $relativePath): string
    {
        return rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    /**
     * Retourne les fichiers d'un dossier, indexés par chemin relatif.
     *
     * Si le dossier est un dépôt git, utilise `git ls-files` (commande locale,
     * pas de réseau — le bug Windows ne concerne que les subprocess réseau)
     * pour respecter .gitignore : évite de pousser node_modules, vendor, .env…
     * Sinon, repli sur un scan récursif excluant seulement .git.
     *
     * @return array<string, string>
     */
    private function collectFiles(string $basePath): array
    {
        $result = [];
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        if (is_dir("{$basePath}/.git")) {
            $process = new Process(
                ['git', 'ls-files', '--cached', '--others', '--exclude-standard'],
                $basePath,
            );
            $process->run();

            if ($process->isSuccessful()) {
                foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $relative) {
                    if ($relative === '') {
                        continue;
                    }

                    $absolute = "{$basePath}/{$relative}";

                    // ls-files --cached peut lister un fichier supprimé non commité
                    if (is_file($absolute)) {
                        $result[$relative] = $absolute;
                    }
                }

                return $result;
            }
        }

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
            'html_url' => $repo['html_url'],
            'clone_url' => $repo['clone_url'],
            'full_name' => $repo['full_name'],
            'private' => $repo['private'],
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
