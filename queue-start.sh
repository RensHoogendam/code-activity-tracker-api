#!/bin/bash

# Start Laravel Queue Worker as daemon
echo "Starting Laravel queue worker..."

# Check if already running
if pgrep -f "queue:work" > /dev/null; then
    echo "⚠️  Queue worker is already running:"
    ps aux | grep "queue:work" | grep -v grep
    exit 1
fi

# Start the worker
nohup php artisan queue:work --daemon > storage/logs/queue.log 2>&1 &
WORKER_PID=$!

echo "✅ Queue worker started with PID: $WORKER_PID"
echo "📝 Logs are being written to: storage/logs/queue.log"

# Save PID to file for easy stopping
echo $WORKER_PID > storage/logs/queue.pid

echo "🚀 Queue worker is now running in background"
echo "   To stop it, run: ./queue-stop.sh"
echo "   To check status: ./queue-status.sh"