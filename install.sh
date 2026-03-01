#!/bin/bash

# Bootstrapping script for a Debian 13 (Trixie) host to run the
# LVM/RAID & NFS web interface.  Installs packages and configures sudoers.
#
# Run as root:    sudo bash install.sh
#
set -euo pipefail

# core packages; Samba is intentionally omitted – install it yourself if you need the shares view
REQUIRED_PKGS=(sudo pamtester lvm2 php php-cli apache2 php-common mdadm util-linux parted gdisk smartmontools nfs-kernel-server)
# apache2/php packages above are typical; adjust if using nginx
# add nfs-kernel-server so exportfs and related utilities are available

echo "Updating package lists..."
apt-get update

echo "Installing required packages: ${REQUIRED_PKGS[*]}"
apt-get install -y "${REQUIRED_PKGS[@]}"

# ensure www-data exists (usually provided by web server package)
if ! id www-data &>/dev/null; then
    echo "www-data user not found; creating"
    useradd -r -d /var/www -s /usr/sbin/nologin www-data
fi

# the web UI only allows authentication for accounts in the 'nfs' group;
# create that group if it doesn't already exist so administrators can
# add their users later.
if ! getent group nfs &>/dev/null; then
    echo "creating nfs group"
    groupadd nfs
fi

# Create a sudoers drop‑in so we don't edit /etc/sudoers directly
SUDOERS_FILE="/etc/sudoers.d/nfs-webui"
cat <<'EOFS' > "$SUDOERS_FILE"
# sudo permissions for NFS/LVM web interface
# allow systemctl so the UI can reload systemd after fstab edits
Defaults:www-data !requiretty
www-data ALL=(ALL) NOPASSWD: \
    /usr/bin/getent, /usr/bin/nsenter, /sbin/mdadm, /usr/sbin/mdadm, \
    /sbin/vgcreate, /sbin/vgextend, /sbin/lvcreate, /sbin/lvextend, \
    /sbin/lvrename, /sbin/lvconvert, /sbin/lvremove, /sbin/vgremove, \
    /sbin/pvcreate, /sbin/pvremove, /sbin/pvck, /sbin/pvrepair, /sbin/pvdisplay, /sbin/pvresize, /sbin/pvmove, \  # PV maintenance utilities used by the detail modal
    /sbin/vgs, /sbin/lvs, \
    /sbin/exportfs, /usr/sbin/exportfs, /usr/bin/lsblk, /usr/bin/mkfs*, \
    /sbin/mkfs*, /usr/sbin/mkfs*, /usr/sbin/blkid, /bin/mount, /bin/umount, \
    /bin/mkdir, /bin/rmdir, /bin/chown, /bin/chmod, /usr/sbin/parted, /usr/sbin/sgdisk, \
    /usr/sbin/smartctl, /usr/sbin/wipefs, /usr/bin/tee, /usr/bin/pamtester, \
    /usr/bin/python3, /usr/bin/perl, /bin/echo, /bin/cat, /bin/grep, \
    /bin/mv, /bin/systemctl*

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

# deploy web UI files to the document root
WEBROOT=/var/www/html
echo "deploying web files to ${WEBROOT}"
# ensure the directory exists
mkdir -p "$WEBROOT"
# remove stock index that comes with a fresh apache install
if [ -e "$WEBROOT/index.html" ]; then
    echo "removing default index.html from ${WEBROOT}"
    rm -f "$WEBROOT/index.html"
fi
# copy the contents of the current repository
# assume install.sh is located at the top level of the web UI tree
SRC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
cp -r "$SRC_DIR"/* "$WEBROOT/"
# adjust ownership so apache can read/write if necessary
chown -R www-data:www-data "$WEBROOT"
rm -r "$WEBROOT/.git" "$WEBROOT/.github" 2>/dev/null || true
rm -r "$WEBROOT/*.md" "$WEBROOT/*.txt" 2>/dev/null || true
rm -r "$WEBROOT/*.sh" 2>/dev/null || true

# ensure there is a configuration file; if it doesn't exist create a
# template with reasonable defaults.  The file is owned by the web user and
# is ignored by git, so site customizations won't be accidentally committed.
CONFIG_FILE="$WEBROOT/config.php"
if [ ! -e "$CONFIG_FILE" ]; then
    echo "creating default config.php in ${WEBROOT}"
    cat <<'EOF' > "$CONFIG_FILE"
<?php
// See config.php in the repository root for documentation; adjust values
// here or copy your own config.php and add it to .gitignore.

/* default configuration exported by installer */
$CONFIG = [];
$CONFIG['mount_base'] = '/export';
$CONFIG['login_group'] = 'nfs';
$CONFIG['trusted_proxies'] = [];
$CONFIG['proxy_header_scheme'] = 'X-Forwarded-Proto';
$CONFIG['proxy_header_host'] = 'X-Forwarded-Host';
$CONFIG['cookie_secure'] = false;
$CONFIG['exports_file'] = '/etc/exports';
$CONFIG['base_url'] = '';
EOF
    chown www-data:www-data "$CONFIG_FILE"
    chmod 644 "$CONFIG_FILE"
else
    # existing file: ensure permissions are sane
    chown www-data:www-data "$CONFIG_FILE"
    chmod 644 "$CONFIG_FILE"
fi

cat <<'EOF'

Installation complete.

Next steps:
 1. Ensure file permissions allow www-data to read the files.
 2. Configure firewall to allow HTTP/HTTPS and SSH.
 3. Add your user account to the 'nfs' group to allow authentication in the web UI:
    sudo usermod -aG nfs <username>
 4. Visit http://<host>/ in a browser and log in with a system account.

EOF
