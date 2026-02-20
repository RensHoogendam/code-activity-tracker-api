<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Models\Commit;
use App\Services\BitbucketService;
use Illuminate\Console\Command;

class SyncCommits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bitbucket:sync-commits 
                            {--days=14 : Number of days to sync commits for}
                            {--repository= : Specific repository to sync (full_name)}
                            {--author= : Specific author email/username to sync activity for (includes feature branches)}
                            {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync commits from Bitbucket to local database';

    /**
     * Execute the console command.
     */
    public function handle(BitbucketService $bitbucketService)
    {
        $days = (int) $this->option('days');
        $repositoryFilter = $this->option('repository');
        $authorFilter = $this->option('author');
        $forceSync = $this->option('force');

        $this->info("Syncing commits from Bitbucket (last {$days} days)...");
        if ($authorFilter) {
            $this->info("Author filter: {$authorFilter} (will sync across all branches)");
        }

        try {
            // Get repositories to sync
            $query = Repository::active();
            if ($repositoryFilter) {
                $query->where('full_name', $repositoryFilter);
            }
            $repositories = $query->get();

            if ($repositories->isEmpty()) {
                $this->warn('No repositories found to sync');
                return 0;
            }

            $totalCommitsCount = 0;
            $totalCreated = 0;
            $totalUpdated = 0;
            $totalErrors = 0;

            $this->info("Processing " . count($repositories) . " repositories...");

            foreach ($repositories as $repository) {
                $this->info("Syncing commits for: {$repository->full_name}");

                try {
                    $allCommits = [];

                    // 1. Fetch from main branches
                    $mainCommits = $bitbucketService->fetchCommitsForRepository($repository->full_name, $days);
                    $allCommits = array_merge($allCommits, $mainCommits);
                    $this->info("  Found " . count($mainCommits) . " commits from main branches");

                    // 2. If author filter provided, fetch their activity across ALL branches
                    if ($authorFilter) {
                        $authorCommits = $bitbucketService->fetchAuthorActivityForRepository($repository->full_name, $days, $authorFilter);
                        $allCommits = array_merge($allCommits, $authorCommits);
                        $this->info("  Found " . count($authorCommits) . " cross-branch commits for author {$authorFilter}");
                    }

                    // Deduplicate by hash
                    $uniqueCommits = [];
                    foreach ($allCommits as $commit) {
                        $uniqueCommits[$commit['hash']] = $commit;
                    }
                    $commits = array_values($uniqueCommits);

                    $repositoryCommitsCount = count($commits);
                    $totalCommitsCount += $repositoryCommitsCount;

                    if ($repositoryCommitsCount > count($mainCommits)) {
                        $this->info("  Total unique commits to process: {$repositoryCommitsCount}");
                    }

                    $created = 0;
                    $updated = 0;

                    foreach ($commits as $commitData) {
                        try {
                            // Find existing commit by hash
                            $existingCommit = Commit::where('repository_id', $repository->id)
                                                   ->where('hash', $commitData['hash'])
                                                   ->first();

                            if ($existingCommit) {
                                // Update existing commit
                                $existingCommit->update([
                                    'commit_date' => $commitData['date'],
                                    'message' => $commitData['message'],
                                    'author_raw' => $commitData['author_raw'],
                                    'author_username' => $commitData['author_username'],
                                    'ticket' => $commitData['ticket'],
                                    'branch' => $commitData['branch'] ?? $existingCommit->branch,
                                    'bitbucket_data' => array_merge($existingCommit->bitbucket_data ?? [], $commitData),
                                    'last_fetched_at' => now()
                                ]);
                                $updated++;
                            } else {
                                // Create new commit
                                Commit::create([
                                    'repository_id' => $repository->id,
                                    'hash' => $commitData['hash'],
                                    'commit_date' => $commitData['date'],
                                    'message' => $commitData['message'],
                                    'author_raw' => $commitData['author_raw'],
                                    'author_username' => $commitData['author_username'],
                                    'ticket' => $commitData['ticket'],
                                    'branch' => $commitData['branch'] ?? 'main',
                                    'bitbucket_data' => $commitData,
                                    'last_fetched_at' => now()
                                ]);
                                $created++;
                            }

                        } catch (\Exception $e) {
                            $this->error("    Error syncing commit {$commitData['hash']}: " . $e->getMessage());
                            $totalErrors++;
                        }
                    }

                    $totalCreated += $created;
                    $totalUpdated += $updated;

                    $this->info("  Result: {$created} created, {$updated} updated");

                    // Small delay to avoid rate limiting
                    if (!$repositoryFilter && count($repositories) > 1) {
                        sleep(1);
                    }

                } catch (\Exception $e) {
                    $this->error("Error syncing repository {$repository->full_name}: " . $e->getMessage());
                    $totalErrors++;
                }
            }

            $this->info("\nSync completed!");
            $this->info("Total unique commits processed: {$totalCommitsCount}");
            $this->info("Total created: {$totalCreated}");
            $this->info("Total updated: {$totalUpdated}");
            $this->info("Total errors: {$totalErrors}");
            $this->info("Total commits in database: " . Commit::count());

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
