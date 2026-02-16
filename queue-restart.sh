#!/bin/bash

# Restart Laravel Queue Worker
echo "🔄 Restarting Laravel queue worker..."

# Stop existing worker
./queue-stop.sh

# Brief pause
sleep 1

# Start new worker
./queue-start.sh

echo "🔄 Queue worker restarted successfully!"