#!/bin/bash

# Stop Laravel Queue Worker
echo "Stopping Laravel queue worker..."

# Check if PID file exists
if [ -f storage/logs/queue.pid ]; then
    PID=$(cat storage/logs/queue.pid)
    if ps -p $PID > /dev/null 2>&1; then
        echo "Stopping queue worker with PID: $PID"
        kill $PID
        rm storage/logs/queue.pid
        echo "✅ Queue worker stopped successfully"
    else
        echo "⚠️  Process with PID $PID not found"
        rm storage/logs/queue.pid
    fi
else
    # Fallback: find and kill any queue:work processes
    QUEUE_PIDS=$(pgrep -f "queue:work")
    if [ -n "$QUEUE_PIDS" ]; then
        echo "Found running queue workers with PIDs: $QUEUE_PIDS"
        echo $QUEUE_PIDS | xargs kill
        echo "✅ Queue workers stopped"
    else
        echo "ℹ️  No queue workers are currently running"
    fi
fi