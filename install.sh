#!/bin/bash

# Bootstrapping script for a Debian 13 (Trixie) host to run the
# LVM/RAID & NFS web interface.  Installs packages and configures sudoers.
#
# Run as root:    sudo bash install.sh
#
set -euo pipefail

REQUIRED_PKGS=(sudo libxcrypt2 pamtester lvm2 php php-cli apache2 php-common)
# apache2/php packages above are typical; adjust if using nginx

echo "Updating package lists..."
apt-get update

echo "Installing required packages: ${REQUIRED_PKGS[*]}"
apt-get install -y "${REQUIRED_PKGS[@]}"

# ensure www-data exists (usually provided by web server package)
if ! id www-data &>/dev/null; then
    echo "www-data user not found; creating"
    useradd -r -d /var/www -s /usr/sbin/nologin www-data
fi

# Create a sudoers drop‑in so we don't edit /etc/sudoers directly
SUDOERS_FILE="/etc/sudoers.d/nfs-webui"
cat <<'EOFS' > "$SUDOERS_FILE"
# sudo permissions for NFS/LVM web interface
Defaults:www-data !requiretty
www-data ALL=(ALL) NOPASSWD: \
    /usr/bin/getent, /sbin/mdadm, /sbin/vgcreate, /sbin/lvcreate, /sbin/lvremove, /sbin/vgremove, /sbin/pvcreate, \
    /sbin/pvs, /sbin/vgs, /sbin/lvs, /sbin/exportfs, /usr/bin/lsblk, /usr/bin/mkfs, \
    /usr/bin/pamtester, /usr/bin/python3, /usr/bin/perl, \
    /bin/echo, /bin/cat, /bin/grep
# allow lookups with arguments
www-data ALL=(ALL) NOPASSWD: /usr/bin/getent shadow *
EOFS
chmod 440 "$SUDOERS_FILE"

echo "Sudoers entry written to $SUDOERS_FILE"

# enable and start apache
if systemctl is-enabled apache2 &>/dev/null; then
    echo "apache2 already enabled"
else
    systemctl enable apache2
fi
systemctl restart apache2

cat <<'EOF'

Installation complete.

Next steps:
 1. Place the web UI directory under /var/www/html or use the provided deploy.sh.
 2. Ensure file permissions allow www-data to read the files.
 3. Configure firewall to allow HTTP/HTTPS and SSH.
 4. Visit http://<host>/ in a browser and log in with a system account.

EOF
