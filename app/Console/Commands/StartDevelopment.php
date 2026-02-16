<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartDevelopment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'start {--port=8000 : The port to run the server on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start Laravel development environment (web server + queue worker)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $port = $this->option('port');
        
        $this->info('🚀 Starting Laravel development environment...');
        $this->newLine();

        // Check if queue worker is already running
        $queueRunning = $this->isQueueWorkerRunning();
        
        if ($queueRunning) {
            $this->info('✅ Queue worker is already running');
        } else {
            $this->info('⏳ Starting queue worker daemon...');
            $this->startQueueWorker();
            
            // Small delay to ensure it starts
            sleep(1);
            
            if ($this->isQueueWorkerRunning()) {
                $this->info('✅ Queue worker started successfully');
            } else {
                $this->error('❌ Failed to start queue worker');
                return Command::FAILURE;
            }
        }

        $this->newLine();
        $this->info("⏳ Starting Laravel web server on port {$port}...");
        $this->info("📍 Server: http://127.0.0.1:{$port}");
        $this->info("📍 Job Status: http://127.0.0.1:{$port}/api/bitbucket/refresh-status");
        $this->newLine();
        $this->comment('💡 Press Ctrl+C to stop the web server (queue worker will continue running)');
        $this->comment('   To stop queue worker: php artisan stop');
        $this->newLine();

        // Start the web server (this will block until Ctrl+C)
        $exitCode = $this->call('serve', ['--port' => $port]);
        
        return $exitCode;
    }

    /**
     * Check if queue worker is running
     */
    private function isQueueWorkerRunning(): bool
    {
        $process = proc_open('pgrep -f "queue:work"', [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes);
        
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            
            return !empty(trim($output));
        }
        
        return false;
    }

    /**
     * Start queue worker in background
     */
    private function startQueueWorker(): void
    {
        $command = 'nohup php artisan queue:work --daemon > storage/logs/queue.log 2>&1 &';
        exec($command);
    }
}
