<?php

namespace App\Services;

use App\Models\Project;
use Symfony\Component\Process\Process;

class GitService
{
    /**
     * Retourne les fichiers modifiés/ajoutés/supprimés via git status --porcelain.
     *
     * @return array{files: list<array{path: string, index: string, worktree: string, status: string}>, clean: bool}
     */
    public function status(Project $project): array
    {
        $output = $this->run($project, ['git', 'status', '--porcelain']);

        $files = [];
        foreach (explode("\n", rtrim($output)) as $line) {
            if ($line === '') {
                continue;
            }
            $index = $line[0];
            $worktree = $line[1];
            $path = ltrim(substr($line, 3));

            $files[] = [
                'path' => $path,
                'index' => $index,
                'worktree' => $worktree,
                'status' => $this->resolveStatus($index, $worktree),
            ];
        }

        return [
            'files' => $files,
            'clean' => empty($files),
        ];
    }

    /**
     * Retourne le diff unifié (tout ou un fichier) vs HEAD.
     * Retourne une chaîne vide si le dépôt n'a pas encore de commit.
     */
    public function diff(Project $project, ?string $file = null): string
    {
        $this->assertGitRepo($project);

        // git diff HEAD échoue s'il n'y a aucun commit — on le détecte d'abord
        $hasCommit = new Process(['git', 'log', '-1', '--oneline'], $project->path);
        $hasCommit->run();
        if (! $hasCommit->isSuccessful()) {
            return '';
        }

        $command = ['git', 'diff', 'HEAD'];
        if ($file !== null) {
            $command[] = '--';
            $command[] = $file;
        }

        $process = new Process($command, $project->path);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('git diff failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * Retourne le nom de la branche courante.
     */
    public function currentBranch(Project $project): string
    {
        return rtrim($this->run($project, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']));
    }

    /**
     * Retourne les derniers commits (hash, message, auteur, date).
     *
     * @return list<array{hash: string, short_hash: string, message: string, author: string, email: string, date: string}>
     */
    public function log(Project $project, int $limit = 20): array
    {
        $format = '%H%x1f%h%x1f%s%x1f%an%x1f%ae%x1f%aI';
        $output = $this->run($project, [
            'git', 'log',
            "--pretty=format:{$format}",
            '-n', (string) $limit,
        ]);

        $commits = [];
        foreach (explode("\n", rtrim($output)) as $line) {
            if ($line === '') {
                continue;
            }
            [$hash, $shortHash, $message, $author, $email, $date] = explode("\x1f", $line, 6);
            $commits[] = [
                'hash' => $hash,
                'short_hash' => $shortHash,
                'message' => $message,
                'author' => $author,
                'email' => $email,
                'date' => $date,
            ];
        }

        return $commits;
    }

    /**
     * Vérifie que le chemin du projet existe et est un dépôt git.
     *
     * @throws \InvalidArgumentException
     */
    private function assertGitRepo(Project $project): void
    {
        if (! is_dir($project->path)) {
            throw new \InvalidArgumentException(
                "Project path does not exist: {$project->path}"
            );
        }

        $check = new Process(['git', 'rev-parse', '--git-dir'], $project->path);
        $check->run();

        if (! $check->isSuccessful()) {
            throw new \InvalidArgumentException(
                "Path is not a git repository: {$project->path}"
            );
        }
    }

    /**
     * Exécute une commande git dans le répertoire du projet.
     * Toujours appelé après assertGitRepo().
     *
     * @throws \InvalidArgumentException si le projet n'est pas un dépôt git valide
     * @throws \RuntimeException si la commande git échoue
     */
    private function run(Project $project, array $command): string
    {
        $this->assertGitRepo($project);

        $process = new Process($command, $project->path);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                sprintf('Git command [%s] failed: %s', implode(' ', $command), $process->getErrorOutput())
            );
        }

        return $process->getOutput();
    }

    /**
     * Clone un dépôt distant dans un répertoire local.
     * L'URL doit contenir le token d'authentification si nécessaire.
     *
     * @throws \InvalidArgumentException si le répertoire cible existe déjà
     * @throws \RuntimeException si le clone échoue
     */
    public function cloneRepo(string $authenticatedUrl, string $localPath): void
    {
        if (is_dir($localPath)) {
            throw new \InvalidArgumentException("Le répertoire cible existe déjà : {$localPath}");
        }

        $parent = dirname($localPath);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        $process = new Process(['git', 'clone', $authenticatedUrl, $localPath]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = preg_replace('#https?://[^@]+@#', 'https://***@', $process->getErrorOutput());
            throw new \RuntimeException('git clone failed: '.$error);
        }
    }

    /**
     * Si le dépôt n'a aucun commit, crée un commit initial vide sur la branche cible.
     * Nécessaire avant tout push sur un repo fraîchement initialisé.
     */
    public function ensureInitialCommit(Project $project, string $branch = 'main'): void
    {
        $this->assertGitRepo($project);

        $hasCommit = new Process(['git', 'log', '-1', '--oneline'], $project->path);
        $hasCommit->run();

        if ($hasCommit->isSuccessful()) {
            return;
        }

        // Configurer la branche principale
        $branchProcess = new Process(['git', 'checkout', '-b', $branch], $project->path);
        $branchProcess->run();

        // Stager tous les fichiers présents
        $add = new Process(['git', 'add', '-A'], $project->path);
        $add->setTimeout(30);
        $add->run();

        // Vérifier s'il y a quelque chose à committer
        $status = new Process(['git', 'status', '--porcelain'], $project->path);
        $status->run();
        $hasChanges = trim($status->getOutput()) !== '';

        $commitArgs = ['git', 'commit', '-m', 'Initial commit'];
        if (! $hasChanges) {
            $commitArgs[] = '--allow-empty';
        }

        $commit = new Process(
            $commitArgs,
            $project->path,
            [
                'GIT_AUTHOR_NAME' => 'gocode',
                'GIT_AUTHOR_EMAIL' => 'gocode@local',
                'GIT_COMMITTER_NAME' => 'gocode',
                'GIT_COMMITTER_EMAIL' => 'gocode@local',
            ],
        );
        $commit->setTimeout(30);
        $commit->run();

        if (! $commit->isSuccessful()) {
            throw new \RuntimeException('Impossible de créer le commit initial : '.$commit->getErrorOutput());
        }
    }

    /**
     * Stage et committe tous les fichiers en attente, s'il y en a.
     * Ne fait rien si le working tree est propre.
     */
    public function commitPendingChanges(Project $project): void
    {
        $this->assertGitRepo($project);

        $status = new Process(['git', 'status', '--porcelain'], $project->path);
        $status->run();

        if (trim($status->getOutput()) === '') {
            return;
        }

        $add = new Process(['git', 'add', '-A'], $project->path);
        $add->setTimeout(30);
        $add->run();

        $commit = new Process(
            ['git', 'commit', '-m', 'chore: sync changes'],
            $project->path,
            [
                'GIT_AUTHOR_NAME' => 'gocode',
                'GIT_AUTHOR_EMAIL' => 'gocode@local',
                'GIT_COMMITTER_NAME' => 'gocode',
                'GIT_COMMITTER_EMAIL' => 'gocode@local',
            ],
        );
        $commit->setTimeout(30);
        $commit->run();

        if (! $commit->isSuccessful()) {
            throw new \RuntimeException('Commit des changements en attente échoué : '.$commit->getErrorOutput());
        }
    }

    /**
     * Stage tous les fichiers modifiés/ajoutés/supprimés.
     */
    public function addAll(Project $project): void
    {
        $this->run($project, ['git', 'add', '-A']);
    }

    /**
     * Crée un commit avec le message donné.
     * Utilise une identité gocode par défaut si aucune n'est configurée dans le dépôt.
     *
     * @throws \RuntimeException si le commit échoue
     */
    public function commit(Project $project, string $message): void
    {
        $this->assertGitRepo($project);

        $process = new Process(
            ['git', 'commit', '-m', $message],
            $project->path,
            [
                'GIT_AUTHOR_NAME' => 'gocode',
                'GIT_AUTHOR_EMAIL' => 'gocode@local',
                'GIT_COMMITTER_NAME' => 'gocode',
                'GIT_COMMITTER_EMAIL' => 'gocode@local',
            ]
        );
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('git commit failed: '.$process->getErrorOutput());
        }
    }

    /**
     * Pousse la branche courante vers le remote GitHub.
     * L'URL authentifiée est passée directement — le token n'est pas persisté dans la config git.
     * Le message d'erreur est nettoyé pour ne pas exposer le token.
     *
     * @throws \RuntimeException si le push échoue
     */
    public function push(Project $project, string $authenticatedUrl, string $branch = 'main'): void
    {
        $this->assertGitRepo($project);

        $process = new Process(
            ['git', 'push', $authenticatedUrl, "HEAD:{$branch}"],
            $project->path,
        );
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = preg_replace('#https?://[^@]+@#', 'https://***@', $process->getErrorOutput());
            throw new \RuntimeException('git push failed: '.$error);
        }
    }

    private function resolveStatus(string $index, string $worktree): string
    {
        if ($index === '?' && $worktree === '?') {
            return 'untracked';
        }
        if ($index === 'A') {
            return 'added';
        }
        if ($index === 'D' || $worktree === 'D') {
            return 'deleted';
        }
        if ($index === 'R') {
            return 'renamed';
        }
        if ($index === 'C') {
            return 'copied';
        }

        return 'modified';
    }
}
