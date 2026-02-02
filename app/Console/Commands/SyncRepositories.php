<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Services\BitbucketService;
use Illuminate\Console\Command;

class SyncRepositories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bitbucket:sync-repositories {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync repositories from Bitbucket to local database';

    /**
     * Execute the console command.
     */
    public function handle(BitbucketService $bitbucketService)
    {
        $this->info('Syncing repositories from Bitbucket in chunks...');

        try {
            $allRepos = [];
            $page = 1;
            $perPage = 50; // Process 50 repos at a time
            $totalSynced = 0;
            $totalUpdated = 0;
            $totalErrors = 0;

            do {
                $this->info("Processing chunk {$page} (repos " . (($page-1) * $perPage + 1) . "-" . ($page * $perPage) . ")");
                
                // Get repositories for this chunk
                $repos = $bitbucketService->getRepositoriesChunk($page, $perPage);
                
                if (empty($repos)) {
                    $this->info("No more repositories found. Completed.");
                    break;
                }

                $this->info("Found " . count($repos) . " repositories in this chunk");

                // Process this chunk
                $chunkSynced = 0;
                $chunkUpdated = 0;
                $chunkErrors = 0;

                foreach ($repos as $repoData) {
                    try {
                        $repository = Repository::updateOrCreate(
                            ['full_name' => $repoData['full_name']],
                            [
                                'name' => $repoData['name'],
                                'workspace' => $repoData['workspace'],
                                'bitbucket_updated_on' => $repoData['updated_on'],
                                'is_private' => $repoData['is_private'],
                                'description' => $repoData['description'] ?? null,
                                'language' => $repoData['language'] ?? null,
                                'is_active' => true
                            ]
                        );

                        if ($repository->wasRecentlyCreated) {
                            $chunkSynced++;
                            $this->line("  Created: {$repository->full_name}");
                        } else {
                            $chunkUpdated++;
                            $this->line("  Updated: {$repository->full_name}");
                        }
                    } catch (\Exception $e) {
                        $chunkErrors++;
                        $this->error("  Error syncing {$repoData['full_name']}: " . $e->getMessage());
                    }
                }

                $totalSynced += $chunkSynced;
                $totalUpdated += $chunkUpdated;
                $totalErrors += $chunkErrors;

                $this->info("Chunk {$page} completed: {$chunkSynced} created, {$chunkUpdated} updated, {$chunkErrors} errors");
                
                // Small delay to avoid rate limiting
                if (count($repos) === $perPage) { // Only sleep if there might be more
                    $this->info("Waiting 2 seconds before next chunk...");
                    sleep(2);
                }

                $page++;

            } while (count($repos) === $perPage); // Continue while we're getting full pages

            $this->info("\nSync completed!");
            $this->info("Total created: {$totalSynced} repositories");
            $this->info("Total updated: {$totalUpdated} repositories");
            $this->info("Total errors: {$totalErrors} repositories");
            $this->info("Total in database: " . Repository::count());

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
