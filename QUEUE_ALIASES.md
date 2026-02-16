# Laravel Queue Management Aliases
alias q-start='./queue-start.sh'
alias q-stop='./queue-stop.sh'  
alias q-status='./queue-status.sh'
alias q-restart='./queue-stop.sh && sleep 1 && ./queue-start.sh'

# Or add to package.json scripts:
# "queue:start": "./queue-start.sh",
# "queue:stop": "./queue-stop.sh", 
# "queue:status": "./queue-status.sh"