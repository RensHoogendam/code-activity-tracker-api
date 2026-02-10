<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Models\PullRequest;
use App\Services\BitbucketService;
use Illuminate\Console\Command;

class SyncPullRequests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bitbucket:sync-pull-requests 
                            {--days=14 : Number of days to sync pull requests for}
                            {--repository= : Specific repository to sync (full_name)}
                            {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync pull requests from Bitbucket to local database';

    /**
     * Execute the console command.
     */
    public function handle(BitbucketService $bitbucketService)
    {
        $days = $this->option('days');
        $repositoryFilter = $this->option('repository');
        $forceSync = $this->option('force');

        $this->info("Syncing pull requests from Bitbucket (last {$days} days)...");

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

            $totalPrs = 0;
            $totalCreated = 0;
            $totalUpdated = 0;
            $totalErrors = 0;

            $this->info("Processing " . count($repositories) . " repositories...");

            foreach ($repositories as $repository) {
                $this->info("Syncing pull requests for: {$repository->full_name}");

                try {
                    // Fetch pull requests from Bitbucket API
                    $pullRequests = $bitbucketService->fetchPullRequestsForRepository($repository->full_name, $days);
                    $repositoryPrs = count($pullRequests);
                    $totalPrs += $repositoryPrs;

                    $this->info("  Found {$repositoryPrs} pull requests");

                    $created = 0;
                    $updated = 0;

                    foreach ($pullRequests as $prData) {
                        try {
                            // Find existing PR by bitbucket_id and repository
                            $existingPr = PullRequest::where('repository_id', $repository->id)
                                                    ->where('bitbucket_id', $prData['id'])
                                                    ->first();

                            if ($existingPr) {
                                // Update existing pull request
                                $existingPr->update([
                                    'title' => $prData['title'],
                                    'author_display_name' => $prData['author'],
                                    'created_on' => $prData['created_on'],
                                    'updated_on' => $prData['updated_on'],
                                    'state' => $prData['state'],
                                    'ticket' => $prData['ticket'],
                                    'bitbucket_data' => $prData,
                                    'last_fetched_at' => now()
                                ]);
                                $updated++;
                            } else {
                                // Create new pull request
                                PullRequest::create([
                                    'repository_id' => $repository->id,
                                    'bitbucket_id' => $prData['id'],
                                    'title' => $prData['title'],
                                    'author_display_name' => $prData['author'],
                                    'created_on' => $prData['created_on'],
                                    'updated_on' => $prData['updated_on'],
                                    'state' => $prData['state'],
                                    'ticket' => $prData['ticket'],
                                    'bitbucket_data' => $prData,
                                    'last_fetched_at' => now()
                                ]);
                                $created++;
                            }

                        } catch (\Exception $e) {
                            $this->error("    Error syncing PR {$prData['id']}: " . $e->getMessage());
                            $totalErrors++;
                        }
                    }

                    $totalCreated += $created;
                    $totalUpdated += $updated;

                    $this->info("  Result: {$created} created, {$updated} updated");

                    // Small delay to avoid rate limiting
                    if (!$repositoryFilter) {
                        $this->info("  Waiting 1 second to avoid rate limiting...");
                        sleep(1);
                    }

                } catch (\Exception $e) {
                    $this->error("Error syncing repository {$repository->full_name}: " . $e->getMessage());
                    $totalErrors++;
                }
            }

            $this->info("\nSync completed!");
            $this->info("Total pull requests processed: {$totalPrs}");
            $this->info("Total created: {$totalCreated}");
            $this->info("Total updated: {$totalUpdated}");
            $this->info("Total errors: {$totalErrors}");
            $this->info("Total pull requests in database: " . PullRequest::count());

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}