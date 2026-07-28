<?php

namespace App\Services\Agent;

use App\Agent\AgentEvent;
use App\Agent\AgentEventDispatcher;
use App\Agent\AgentEventType;
use App\Models\CommandWhitelist;
use App\Models\Project;
use App\Models\Session;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Exécute les outils que les modèles NVIDIA (Kimi, DeepSeek) peuvent appeler
 * via function-calling : lecture/écriture de fichiers, recherche, commandes
 * shell. Remplace les outils intégrés du CLI Claude Code, qui n'existent pas
 * derrière un simple endpoint chat/completions — on les réimplémente ici,
 * sandboxés au dossier du projet, et on émet les mêmes AgentEvents
 * (tool_call / terminal / file_change) que l'ancien ClaudeCodeJob.
 */
class ToolExecutor
{
    private const SKIP_DIRS = ['.git', 'node_modules', 'vendor', 'storage', '.idea', 'dist', 'build'];

    public function __construct(
        private readonly AgentEventDispatcher $dispatcher,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(bool $canWrite): array
    {
        $tools = [
            $this->def('read_file', 'Lit le contenu intégral d\'un fichier texte du projet.', [
                'path' => ['type' => 'string', 'description' => 'Chemin relatif au dossier racine du projet.'],
            ], ['path']),
            $this->def('list_dir', 'Liste les fichiers et sous-dossiers d\'un dossier du projet.', [
                'path' => ['type' => 'string', 'description' => 'Chemin relatif (défaut : racine du projet).'],
            ], []),
            $this->def('glob_search', 'Recherche des fichiers du projet par motif de nom (ex. "**/*.php").', [
                'pattern' => ['type' => 'string', 'description' => 'Motif de type glob (fnmatch), ex. "*.php" ou "app/*/*.php".'],
            ], ['pattern']),
            $this->def('grep_search', 'Recherche une expression régulière dans le contenu des fichiers du projet.', [
                'pattern' => ['type' => 'string', 'description' => 'Expression régulière PCRE (sans délimiteurs).'],
                'path' => ['type' => 'string', 'description' => 'Sous-dossier où limiter la recherche (défaut : racine du projet).'],
            ], ['pattern']),
        ];

        if ($canWrite) {
            $tools[] = $this->def('write_file', 'Crée ou remplace intégralement le contenu d\'un fichier.', [
                'path' => ['type' => 'string', 'description' => 'Chemin relatif au dossier racine du projet.'],
                'content' => ['type' => 'string', 'description' => 'Contenu complet à écrire dans le fichier.'],
            ], ['path', 'content']);

            $tools[] = $this->def('edit_file', 'Remplace une occurrence unique de texte dans un fichier existant.', [
                'path' => ['type' => 'string', 'description' => 'Chemin relatif au dossier racine du projet.'],
                'old_string' => ['type' => 'string', 'description' => 'Texte exact à remplacer (doit apparaître exactement une fois).'],
                'new_string' => ['type' => 'string', 'description' => 'Texte de remplacement.'],
            ], ['path', 'old_string', 'new_string']);

            $tools[] = $this->def('bash_command', 'Exécute une commande shell dans le dossier du projet.', [
                'command' => ['type' => 'string', 'description' => 'Commande shell complète à exécuter.'],
            ], ['command']);
        }

        return $tools;
    }

    /**
     * `git commit` / `git push` exigent une confirmation humaine explicite
     * (garde-fou CLAUDE.md) avant exécution réelle.
     */
    public function isSensitive(string $toolName, array $args): bool
    {
        if ($toolName !== 'bash_command') {
            return false;
        }

        return (bool) preg_match('/^\s*git\s+(commit|push)\b/i', (string) ($args['command'] ?? ''));
    }

    public function run(Session $session, string $toolName, array $args, bool $canWrite, bool $enforceWhitelist): string
    {
        $project = $session->project;

        if (! $project) {
            return 'Projet introuvable pour cette session.';
        }

        try {
            return match ($toolName) {
                'read_file' => $this->readFile($project, $args),
                'list_dir' => $this->listDir($project, $args),
                'glob_search' => $this->globSearch($project, $args),
                'grep_search' => $this->grepSearch($project, $args),
                'write_file' => $canWrite ? $this->writeFile($session, $project, $args) : $this->deniedMessage(),
                'edit_file' => $canWrite ? $this->editFile($session, $project, $args) : $this->deniedMessage(),
                'bash_command' => $canWrite ? $this->bashCommand($session, $project, $args, $enforceWhitelist) : $this->deniedMessage(),
                default => "Outil inconnu : {$toolName}.",
            };
        } catch (Throwable $e) {
            return "Erreur lors de l'exécution de l'outil [{$toolName}] : {$e->getMessage()}";
        }
    }

    // -------------------------------------------------------------------------
    // Outils en lecture
    // -------------------------------------------------------------------------

    private function readFile(Project $project, array $args): string
    {
        $full = $this->resolvePath($project, (string) ($args['path'] ?? ''), mustExist: true);

        if ($full === null) {
            return 'Fichier introuvable ou en dehors du dossier du projet.';
        }

        if (is_dir($full)) {
            return 'Ce chemin est un dossier — utilise list_dir pour l\'explorer.';
        }

        $size = filesize($full);
        if ($size !== false && $size > 200_000) {
            return "Fichier trop volumineux ({$size} octets) pour être lu entièrement.";
        }

        $content = file_get_contents($full);

        return $content === '' ? '(fichier vide)' : $content;
    }

    private function listDir(Project $project, array $args): string
    {
        $relative = (string) ($args['path'] ?? '.');
        $full = $this->resolvePath($project, $relative === '' ? '.' : $relative, mustExist: true);

        if ($full === null || ! is_dir($full)) {
            return 'Dossier introuvable ou en dehors du dossier du projet.';
        }

        $entries = scandir($full) ?: [];
        $lines = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP_DIRS, true)) {
                continue;
            }

            $lines[] = is_dir($full.DIRECTORY_SEPARATOR.$entry) ? "{$entry}/" : $entry;
        }

        sort($lines);

        return $lines === [] ? '(dossier vide)' : implode("\n", $lines);
    }

    private function globSearch(Project $project, array $args): string
    {
        $pattern = (string) ($args['pattern'] ?? '');

        if ($pattern === '') {
            return 'Motif manquant.';
        }

        $root = realpath($project->path);

        if ($root === false) {
            return 'Dossier du projet introuvable.';
        }

        $results = [];

        foreach ($this->walkProject($root) as $path) {
            $relative = $this->toRelative($root, $path);

            if (fnmatch($pattern, $relative) || fnmatch($pattern, basename($path))) {
                $results[] = $relative;

                if (count($results) >= 200) {
                    break;
                }
            }
        }

        return $results === [] ? 'Aucun fichier trouvé.' : implode("\n", $results);
    }

    private function grepSearch(Project $project, array $args): string
    {
        $pattern = (string) ($args['pattern'] ?? '');

        if ($pattern === '') {
            return 'Motif manquant.';
        }

        $root = realpath($project->path);
        $base = $this->resolvePath($project, (string) ($args['path'] ?? '.') ?: '.', mustExist: true);

        if ($root === false || $base === null) {
            return 'Dossier du projet introuvable.';
        }

        $regex = '#'.str_replace('#', '\#', $pattern).'#i';

        if (@preg_match($regex, '') === false) {
            return 'Expression régulière invalide.';
        }

        $results = [];

        foreach ($this->walkProject($base) as $path) {
            if (filesize($path) > 500_000) {
                continue;
            }

            $lines = @file($path);

            if ($lines === false) {
                continue;
            }

            $relative = $this->toRelative($root, $path);

            foreach ($lines as $num => $line) {
                if (@preg_match($regex, $line) === 1) {
                    $results[] = sprintf('%s:%d: %s', $relative, $num + 1, trim($line));

                    if (count($results) >= 200) {
                        return implode("\n", $results);
                    }
                }
            }
        }

        return $results === [] ? 'Aucune correspondance.' : implode("\n", $results);
    }

    /** @return iterable<string> */
    private function walkProject(string $base): iterable
    {
        if (is_file($base)) {
            yield $base;

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            foreach (self::SKIP_DIRS as $skip) {
                if (str_contains($path, "/{$skip}/")) {
                    continue 2;
                }
            }

            yield $file->getPathname();
        }
    }

    private function toRelative(string $root, string $path): string
    {
        $root = str_replace('\\', '/', $root);
        $path = str_replace('\\', '/', $path);

        return ltrim(str_replace($root, '', $path), '/');
    }

    // -------------------------------------------------------------------------
    // Outils en écriture (mode execute uniquement)
    // -------------------------------------------------------------------------

    private function writeFile(Session $session, Project $project, array $args): string
    {
        $relative = (string) ($args['path'] ?? '');
        $content = (string) ($args['content'] ?? '');

        if ($relative === '') {
            return 'Chemin de fichier manquant.';
        }

        $full = $this->resolvePath($project, $relative, mustExist: false);

        if ($full === null) {
            return 'Chemin invalide ou en dehors du dossier du projet.';
        }

        $this->setStatus($session, 'building');

        $existed = file_exists($full);
        file_put_contents($full, $content);

        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::FileChange,
            $session->id,
            [
                'file' => $relative,
                'additions' => $this->countLines($content),
                'deletions' => 0,
                'diff' => $this->buildWriteDiff($relative, $content),
                'error' => false,
            ],
        ));

        return ($existed ? "Fichier {$relative} remplacé." : "Fichier {$relative} créé.").' ('.$this->countLines($content).' lignes)';
    }

    private function editFile(Session $session, Project $project, array $args): string
    {
        $relative = (string) ($args['path'] ?? '');
        $old = (string) ($args['old_string'] ?? '');
        $new = (string) ($args['new_string'] ?? '');

        if ($relative === '' || $old === '') {
            return 'Paramètres path/old_string manquants.';
        }

        $full = $this->resolvePath($project, $relative, mustExist: true);

        if ($full === null || is_dir($full)) {
            return 'Fichier introuvable ou en dehors du dossier du projet.';
        }

        $current = file_get_contents($full);
        $occurrences = substr_count($current, $old);

        if ($occurrences === 0) {
            return "old_string introuvable dans {$relative}.";
        }

        if ($occurrences > 1) {
            return "old_string apparaît {$occurrences} fois dans {$relative} — fournis un extrait unique et plus spécifique.";
        }

        $updated = str_replace($old, $new, $current);

        $this->setStatus($session, 'building');
        file_put_contents($full, $updated);

        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::FileChange,
            $session->id,
            [
                'file' => $relative,
                'additions' => $this->countLines($new),
                'deletions' => $this->countLines($old),
                'diff' => $this->buildEditDiff($relative, $old, $new),
                'error' => false,
            ],
        ));

        return "Fichier {$relative} modifié.";
    }

    private function bashCommand(Session $session, Project $project, array $args, bool $enforceWhitelist): string
    {
        $command = trim((string) ($args['command'] ?? ''));

        if ($command === '') {
            return 'Commande vide.';
        }

        if ($enforceWhitelist && ! $this->matchesWhitelist($project, $command)) {
            $this->dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Terminal,
                $session->id,
                ['command' => $command, 'output' => 'Commande refusée : hors whitelist du projet.', 'error' => true],
            ));

            return 'Commande refusée : hors whitelist du projet. Utilise une commande autorisée.';
        }

        $this->setStatus($session, 'running');
        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::ToolCall,
            $session->id,
            ['tool' => 'Bash', 'command' => $command],
        ));

        $process = Process::fromShellCommandline($command, $project->path, null, null, 120);

        try {
            $process->run();
        } catch (Throwable $e) {
            $message = "Erreur d'exécution : {$e->getMessage()}";

            $this->dispatcher->dispatch(AgentEvent::make(
                AgentEventType::Terminal,
                $session->id,
                ['command' => $command, 'output' => $message, 'error' => true],
            ));

            return $message;
        }

        $output = trim($process->getOutput().$process->getErrorOutput());
        $isError = ! $process->isSuccessful();

        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Terminal,
            $session->id,
            ['command' => $command, 'output' => $output, 'error' => $isError],
        ));

        if ($output !== '') {
            return $output;
        }

        return $isError ? 'Commande terminée en erreur (aucune sortie).' : 'Commande exécutée avec succès (aucune sortie).';
    }

    private function matchesWhitelist(Project $project, string $command): bool
    {
        $patterns = CommandWhitelist::query()
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereNull('project_id'))
            ->pluck('pattern');

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim(substr($pattern, 0, -1));

                if (str_starts_with($command, $prefix)) {
                    return true;
                }
            } elseif ($command === $pattern) {
                return true;
            }
        }

        return false;
    }

    private function deniedMessage(): string
    {
        return 'Outil non disponible dans ce mode (lecture seule). Utilise read_file/list_dir/glob_search/grep_search uniquement.';
    }

    // -------------------------------------------------------------------------
    // Sandbox de chemins
    // -------------------------------------------------------------------------

    private function resolvePath(Project $project, string $relative, bool $mustExist): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            $relative = '.';
        }

        if (str_contains($relative, '..')) {
            return null;
        }

        $root = realpath($project->path);

        if ($root === false) {
            return null;
        }

        $full = $relative === '.' ? $root : $root.DIRECTORY_SEPARATOR.$relative;

        if ($mustExist) {
            $real = realpath($full);

            if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root))) {
                return null;
            }

            return $real;
        }

        $dir = dirname($full);

        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $realDir = realpath($dir);

        if ($realDir === false || ! str_starts_with(str_replace('\\', '/', $realDir), str_replace('\\', '/', $root))) {
            return null;
        }

        return $realDir.DIRECTORY_SEPARATOR.basename($full);
    }

    // -------------------------------------------------------------------------
    // Diffs & status
    // -------------------------------------------------------------------------

    private function setStatus(Session $session, string $status): void
    {
        if ($session->status === $status) {
            return;
        }

        $session->update(['status' => $status]);
        $this->dispatcher->dispatch(AgentEvent::make(
            AgentEventType::Status,
            $session->id,
            ['status' => $status],
        ));
    }

    private function buildWriteDiff(string $file, string $content): string
    {
        $lines = $this->countLines($content);
        $diff = "--- /dev/null\n+++ b/{$file}\n@@ -0,0 +1,{$lines} @@\n";

        foreach (explode("\n", $content) as $line) {
            $diff .= '+'.$line."\n";
        }

        return rtrim($diff);
    }

    private function buildEditDiff(string $file, string $old, string $new): string
    {
        $diff = "--- a/{$file}\n+++ b/{$file}\n@@ ... @@\n";

        foreach (explode("\n", $old) as $line) {
            $diff .= '-'.$line."\n";
        }

        foreach (explode("\n", $new) as $line) {
            $diff .= '+'.$line."\n";
        }

        return rtrim($diff);
    }

    private function countLines(string $text): int
    {
        return max(1, substr_count($text, "\n") + 1);
    }

    /**
     * @param  array<string, array<string, string>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function def(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
