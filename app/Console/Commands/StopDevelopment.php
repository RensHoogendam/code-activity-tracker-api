<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StopDevelopment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stop';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stop Laravel development environment (queue worker)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🛑 Stopping Laravel development environment...');
        $this->newLine();

        // Check if queue worker is running
        $queuePids = $this->getQueueWorkerPids();
        
        if (empty($queuePids)) {
            $this->comment('ℹ️  No queue workers are currently running');
            return Command::SUCCESS;
        }

        $this->info('⏳ Stopping queue worker(s)...');
        
        // Stop all queue workers
        foreach ($queuePids as $pid) {
            $this->info("   Stopping queue worker with PID: {$pid}");
            exec("kill {$pid}");
        }

        // Brief pause to allow graceful shutdown
        sleep(1);

        // Verify they stopped
        $remainingPids = $this->getQueueWorkerPids();
        
        if (empty($remainingPids)) {
            $this->info('✅ Queue worker(s) stopped successfully');
            
            // Clean up PID file if it exists
            $pidFile = storage_path('logs/queue.pid');
            if (file_exists($pidFile)) {
                unlink($pidFile);
            }
        } else {
            $this->error('⚠️  Some queue workers may still be running');
            $this->comment('   Try: killall php');
        }

        $this->newLine();
        $this->comment('ℹ️  Web server should be stopped with Ctrl+C in the terminal where it\'s running');
        $this->info('🏁 Development environment shutdown complete');
        
        return Command::SUCCESS;
    }

    /**
     * Get PIDs of running queue workers
     */
    private function getQueueWorkerPids(): array
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
            
            $pids = array_filter(explode("\n", trim($output)));
            return $pids;
        }
        
        return [];
    }
}
