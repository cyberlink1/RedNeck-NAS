#!/bin/bash

# Simple deployment script for the NAS web interface.
# Syncs current workspace to remote host using rsync.

set -euo pipefail

# remote destination; adjust user/host/target as needed
REMOTE_USER="cl"
REMOTE_HOST="192.168.10.151"
REMOTE_DIR="/var/www/html"

# source directory (this script's parent folder)
SRC_DIR="$(dirname "$0")"

echo "Deploying from $SRC_DIR to ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR}..."

# exclude common ignored files
rsync -avz --delete \
    --exclude='.git' \
    --exclude='deploy.sh' \
    --exclude='README.md' \
    "$SRC_DIR/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR}/"

echo "Deployment complete."
