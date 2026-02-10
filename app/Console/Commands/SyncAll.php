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

        $this->info('Starting complete Bitbucket sync...');
        $this->newLine();

        // Step 1: Sync repositories
        $this->info('Step 1: Syncing repositories...');
        $repoResult = $this->call('bitbucket:sync-repositories', [
            '--force' => $force
        ]);
        
        if ($repoResult !== 0) {
            $this->error('Repository sync failed! Aborting.');
            return 1;
        }
        
        $this->newLine();

        // Step 2: Sync commits
        $this->info('Step 2: Syncing commits...');
        $commitResult = $this->call('bitbucket:sync-commits', [
            '--days' => $days,
            '--force' => $force
        ]);
        
        if ($commitResult !== 0) {
            $this->error('Commit sync failed!');
        }
        
        $this->newLine();

        // Step 3: Sync pull requests
        $this->info('Step 3: Syncing pull requests...');
        $prResult = $this->call('bitbucket:sync-pull-requests', [
            '--days' => $days,
            '--force' => $force
        ]);
        
        if ($prResult !== 0) {
            $this->error('Pull request sync failed!');
        }

        $this->newLine();

        if ($repoResult === 0 && $commitResult === 0 && $prResult === 0) {
            $this->info('✅ All sync operations completed successfully!');
            return 0;
        } else {
            $this->warn('⚠️  Some sync operations encountered errors. Check logs for details.');
            return 1;
        }
    }
}