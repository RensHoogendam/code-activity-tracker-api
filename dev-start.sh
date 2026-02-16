#!/bin/bash

# Start Laravel development environment (web server + queue worker)
echo "🚀 Starting Laravel development environment..."

# Check if queue worker is already running
if pgrep -f "queue:work" > /dev/null; then
    echo "✅ Queue worker is already running"
else
    echo "⏳ Starting queue worker daemon..."
    ./queue-start.sh
fi

echo "⏳ Starting Laravel web server..."
echo "📍 Web server will be available at: http://127.0.0.1:8000"
echo "📍 Job status endpoint: http://127.0.0.1:8000/api/bitbucket/refresh-status"
echo
echo "💡 Press Ctrl+C to stop the web server (queue worker will continue running)"
echo "   To stop queue worker: ./queue-stop.sh"
echo

# Start the web server (this will block until Ctrl+C)
php artisan serve