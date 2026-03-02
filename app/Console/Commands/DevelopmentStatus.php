<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DevelopmentStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Laravel development environment status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Laravel Development Environment Status');
        $this->info('==========================================');
        $this->newLine();

        // Check queue worker status
        $queuePids = $this->getQueueWorkerPids();
        
        if (!empty($queuePids)) {
            $this->info('✅ Queue Worker: RUNNING');
            foreach ($queuePids as $pid) {
                $this->comment("   📍 PID: {$pid}");
            }
        } else {
            $this->error('❌ Queue Worker: NOT RUNNING');
        }

        $this->newLine();

        // Check web server (this is harder to detect reliably, so we'll just give guidance)
        $projectName = basename(base_path());
        $this->comment('🌐 Web Server Status:');
        $this->comment("   📍 Herd URL: http://{$projectName}.test");
        $this->comment('   📍 PHP Serve: http://127.0.0.1:8000');
        $this->comment('   📍 Stop serve with Ctrl+C in its terminal');

        $this->newLine();

        // Show recent queue logs if available
        $queueLogFile = storage_path('logs/queue.log');
        if (file_exists($queueLogFile)) {
            $this->comment('📝 Recent Queue Activity (last 3 lines):');
            $this->comment('------------------------------------------');
            
            $lines = file($queueLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $recentLines = array_slice($lines, -3);
            
            foreach ($recentLines as $line) {
                $this->comment('   ' . $line);
            }
        }

        $this->newLine();
        $this->comment('💡 Available Commands:');
        $this->comment('   php artisan start   - Start development environment');
        $this->comment('   php artisan stop    - Stop queue worker');
        $this->comment('   php artisan status  - Check this status');

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
