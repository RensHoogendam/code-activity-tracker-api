<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class SyncAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bitbucket:sync-all 
                            {--days=14 : Number of days to sync commits and PR data for}
                            {--repository=* : Specific repository to sync (full_name). Can be specified multiple times.}
                            {--author= : Specific author email/username to sync activity for (defaults to BITBUCKET_AUTHOR_EMAIL env)}
                            {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all data from Bitbucket (repositories, commits, and pull requests)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');
        $force = $this->option('force');
        $repositories = $this->option('repository');
        $author = $this->option('author') ?? env('BITBUCKET_AUTHOR_EMAIL');

        $this->info('Starting complete Bitbucket sync...');
        if ($author) {
            $this->info("Syncing activity for author: {$author}");
        }
        if (!empty($repositories)) {
            $this->info("Filtering by repositories: " . implode(', ', $repositories));
        }
        $this->newLine();

        // Step 1: Sync repositories
        // We always sync the list of repositories to ensure we have the latest metadata
        $this->info('Step 1: Syncing repositories...');
        $repoResult = $this->call('bitbucket:sync-repositories', [
            '--force' => $force
        ]);
        
        if ($repoResult !== 0) {
            $this->error('Repository sync failed! Aborting.');
            return 1;
        }
        
        $this->newLine();

        // Step 2 & 3: Sync commits and PRs
        $commitErrors = 0;
        $prErrors = 0;

        if (!empty($repositories)) {
            foreach ($repositories as $repo) {
                $this->info("--- Processing Repository: {$repo} ---");
                
                // Sync commits for this repo
                $commitResult = $this->call('bitbucket:sync-commits', [
                    '--days' => $days,
                    '--force' => $force,
                    '--author' => $author,
                    '--repository' => $repo
                ]);
                if ($commitResult !== 0) $commitErrors++;

                // Sync PRs for this repo
                $prResult = $this->call('bitbucket:sync-pull-requests', [
                    '--days' => $days,
                    '--force' => $force,
                    '--repository' => $repo
                ]);
                if ($prResult !== 0) $prErrors++;
                
                $this->newLine();
            }
        } else {
            // Step 2: Sync commits for all
            $this->info('Step 2: Syncing commits for all active repositories...');
            $commitResult = $this->call('bitbucket:sync-commits', [
                '--days' => $days,
                '--force' => $force,
                '--author' => $author
            ]);
            if ($commitResult !== 0) $commitErrors++;
            
            $this->newLine();

            // Step 3: Sync pull requests for all
            $this->info('Step 3: Syncing pull requests for all active repositories...');
            $prResult = $this->call('bitbucket:sync-pull-requests', [
                '--days' => $days,
                '--force' => $force
            ]);
            if ($prResult !== 0) $prErrors++;
        }

        if ($repoResult === 0 && $commitErrors === 0 && $prErrors === 0) {
            $this->info('✅ All sync operations completed successfully!');
            return 0;
        } else {
            $this->warn('⚠️  Some sync operations encountered errors. Check logs for details.');
            return 1;
        }
    }
}
