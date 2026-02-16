#!/bin/bash

# Check Laravel Queue Worker Status
echo "Laravel Queue Worker Status"
echo "=========================="

# Check if running
QUEUE_PROCESSES=$(ps aux | grep "queue:work" | grep -v grep)

if [ -n "$QUEUE_PROCESSES" ]; then
    echo "✅ Queue worker is RUNNING:"
    echo "$QUEUE_PROCESSES"
    echo
    
    # Show recent logs if they exist
    if [ -f storage/logs/queue.log ]; then
        echo "📝 Recent queue logs (last 5 lines):"
        echo "-----------------------------------"
        tail -5 storage/logs/queue.log
    fi
else
    echo "❌ Queue worker is NOT running"
    echo
    echo "To start it, run: ./queue-start.sh"
fi

echo
echo "💡 Commands available:"
echo "  ./queue-start.sh  - Start queue worker"
echo "  ./queue-stop.sh   - Stop queue worker"
echo "  ./queue-status.sh - Check this status"