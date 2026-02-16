#!/bin/bash

# Stop Laravel development environment
echo "🛑 Stopping Laravel development environment..."

# Stop queue worker
./queue-stop.sh

echo "ℹ️  Web server can be stopped with Ctrl+C in your terminal"
echo "✅ Development environment shutdown complete"