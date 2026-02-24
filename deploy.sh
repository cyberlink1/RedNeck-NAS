#!/bin/bash

# Simple deployment script for the NAS web interface.
# Syncs current workspace to remote host using rsync.

set -euo pipefail

# remote destination; adjust user/host/target as needed
REMOTE_USER="cl"
REMOTE_HOST="192.168.10.153"
REMOTE_DIR="/var/www/html"

# source directory (this script's parent folder)
SRC_DIR="$(dirname "$0")"

echo "Deploying from $SRC_DIR to ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR}..."

# prepare rsync exclude file (patterns may be customized)
EXCLUDE_FILE="$SRC_DIR/.rsync.exclude"
if [[ ! -f "$EXCLUDE_FILE" ]]; then
    cat <<'EOF' > "$EXCLUDE_FILE"
*.txt
*.sh
*.md
.git
EOF
fi

# sync with deletion of remote files that no longer exist locally
rsync -avz --delete --exclude-from="$EXCLUDE_FILE" \
    "$SRC_DIR/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR}/"

echo "Deployment complete."
