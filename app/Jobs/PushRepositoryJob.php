<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class PushRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public readonly int $projectId,
        public readonly string $pushId,
        public readonly ?string $message = null,
    ) {}

    public function handle(SettingsService $settings): void
    {
        $project = Project::findOrFail($this->projectId);
        $log = [];

        try {
            $token = $settings->getEncrypted('github.pat');
            $path = $project->path;
            $remote = $project->git_remote;
            $branch = $project->default_branch ?: 'main';

            if (! $token) {
                throw new \RuntimeException('PAT GitHub non configuré.');
            }
            if (! $path || ! is_dir($path)) {
                throw new \RuntimeException("Chemin invalide : {$path}");
            }
            if (! $remote) {
                throw new \RuntimeException('Remote GitHub non configuré.');
            }

            $authenticatedUrl = preg_replace('#^(https://)#', "https://{$token}@", $remote);

            // 1. git add -A
            $out = $this->git(['git', 'add', '-A'], $path);
            $log[] = 'add: ok';

            // 2. git status --short (pour voir ce qui va être commité)
            $status = $this->gitRaw(['git', 'status', '--short'], $path);
            $log[] = 'status: '.(trim($status) ?: '(rien)');

            // 3. git commit (avec identité passée via -c pour éviter les pb d'env Windows)
            $commitProc = new Process(
                ['git', '-c', 'user.name=gocode', '-c', 'user.email=gocode@local',
                    'commit', '-m', $this->message ?: 'sync: push changes'],
                $path
            );
            $commitProc->setTimeout(30);
            $commitProc->run();
            $log[] = 'commit stdout: '.trim($commitProc->getOutput() ?: '(vide)');
            $log[] = 'commit stderr: '.trim($commitProc->getErrorOutput() ?: '(vide)');

            // 4. Vérifier qu'il existe au moins un commit
            $hasCommit = new Process(['git', 'log', '-1', '--oneline'], $path);
            $hasCommit->run();

            if (! $hasCommit->isSuccessful()) {
                // Repo vide — commit vide pour débloquer le push
                $this->git(
                    ['git', '-c', 'user.name=gocode', '-c', 'user.email=gocode@local',
                        'commit', '--allow-empty', '-m', 'Initial commit'],
                    $path
                );
                $log[] = 'initial empty commit créé';
            } else {
                $log[] = 'dernier commit: '.trim($hasCommit->getOutput());
            }

            // 5. git push
            $pushOut = $this->git(['git', 'push', $authenticatedUrl, "HEAD:{$branch}"], $path, 120);
            $log[] = 'push: ok';

            Cache::put("push.{$this->pushId}", [
                'status' => 'done',
                'branch' => $branch,
                'remote' => $remote,
                'log' => implode("\n", $log),
            ], now()->addMinutes(5));

        } catch (\Exception $e) {
            Cache::put("push.{$this->pushId}", [
                'status' => 'error',
                'message' => $e->getMessage(),
                'log' => implode("\n", $log),
            ], now()->addMinutes(5));
        }
    }

    private function git(array $command, string $cwd, int $timeout = 30): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException('['.implode(' ', $command).'] '.$error);
        }

        return $process->getOutput();
    }

    private function gitRaw(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(10);
        $process->run();

        return $process->getOutput();
    }
}
