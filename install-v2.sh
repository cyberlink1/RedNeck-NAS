#!/bin/bash

# enhanced installer for the NFS/LVM web UI
# supports fresh Debian installs or deployment into an
# existing web server environment

set -euo pipefail

# helper for reading a value with a default
read_with_default() {
    local prompt="$1" default="$2" var
    read -p "$prompt [$default]: " var
    echo "${var:-$default}"
}

echo "This script will install the NFS web UI."

# determine whether this is a fresh Debian install or being dropped into
# an already-running web server (other distributions are allowed for the
# latter case)
while true; do
    read -p "Is this a fresh Debian install? (y/n): " yn
    case "$yn" in
        [Yy]*) FRESH=1 ; break ;; 
        [Nn]*) FRESH=0 ; break ;; 
        *) echo "Please answer y or n." ;; 
    esac
done

# defaults
WEBROOT="/var/www/html"
WWWUSER="www-data"
SUDOERS_FILE="/etc/sudoers.d/nfs-webui"

if [ "$FRESH" -eq 0 ]; then
    echo "Installing into existing web server..."
    # gather configuration details from user
    WEBROOT=$(read_with_default "Web server document root" "$WEBROOT")
    WWWUSER=$(read_with_default "Web server account" "$WWWUSER")
    SUDOERS_FILE=$(read_with_default "Sudoers file path" "$SUDOERS_FILE")

    # ensure PHP is available -- we can't manage packages on non-Debian
    if ! command -v php &>/dev/null; then
        echo "\nERROR: PHP not found on the system."
        echo "This script cannot install PHP for you when deploying into"
        echo "an existing server; please install php/php-cli via your distro"
        echo "package manager and re-run the script."
        exit 1
    else
        echo "PHP is present on the system."
    fi
fi

# gather site configuration values (mount base, login group, etc.)
# prompt the user for each value and then confirm before proceeding.
echo "Configuring site-specific settings (defaults shown in brackets)"
mount_base=$(read_with_default "NFS mount base directory" "/export")
login_group=$(read_with_default "Login group" "nfs")
trusted_proxies=$(read_with_default "Trusted proxies (comma-separated)" "")
proxy_scheme=$(read_with_default "Header name for original scheme" "X-Forwarded-Proto")
proxy_host=$(read_with_default "Header name for original host" "X-Forwarded-Host")
cookie_secure=$(read_with_default "cookie_secure (false/true/strict)" "false")
exports_file=$(read_with_default "NFS exports file path" "/etc/exports")
base_url=$(read_with_default "Base URL for UI (empty for relative)" "")

# show summary and confirm
clear
cat <<SUMMARY
Planned installation configuration:

  mode          = $( [ $FRESH -eq 1 ] && echo "fresh Debian" || echo "existing server" )
  webroot       = $WEBROOT
  web user      = $WWWUSER
  sudoers file  = $SUDOERS_FILE

  mount_base    = $mount_base
  login_group   = $login_group
  trusted_proxies = $trusted_proxies
  proxy_header_scheme = $proxy_scheme
  proxy_header_host   = $proxy_host
  cookie_secure = $cookie_secure
  exports_file  = $exports_file
  base_url      = $base_url

SUMMARY

read -p "Proceed with these settings and perform installation? (y/N): " proceed
case "$proceed" in
    [Yy]*) ;; 
    *) echo "installation cancelled."; exit 0 ;;
esac

# continue with actual installation steps
if [ "$FRESH" -eq 1 ]; then
    echo "Performing fresh install..."
    # core packages, apache2 included for a fresh host
    REQUIRED_PKGS=(sudo pamtester lvm2 php php-cli apache2 php-common mdadm util-linux parted gdisk smartmontools nfs-kernel-server)
    echo "Updating package lists..."
    apt-get update
    echo "Installing required packages: ${REQUIRED_PKGS[*]}"
    apt-get install -y "${REQUIRED_PKGS[@]}"
else
    # warn about copying files and making sudoers updates for existing webserver
    echo
    echo "The script will now copy UI files into '$WEBROOT' and create"
    echo "the sudoers file at '$SUDOERS_FILE'."
    read -p "Continue with these changes? (y/N): " cont
    case "$cont" in
        [Yy]*) ;;  # proceed
        *) echo "aborting per user request."; exit 0 ;;
    esac
fi

# summary and confirmation block should occur before making any changes

# ensure the www user exists so we can own the files
if ! id "$WWWUSER" &>/dev/null; then
    echo "User $WWWUSER not found; creating account."
    useradd -r -d /var/www -s /usr/sbin/nologin "$WWWUSER"
fi

# create the nfs group if needed
if ! getent group nfs &>/dev/null; then
    echo "creating nfs group"
    groupadd nfs
fi

# write sudoers drop‑in (append to avoid clobbering existing rules)
if [ -e "$SUDOERS_FILE" ]; then
    echo "note: $SUDOERS_FILE already exists, appending new rules."
else
    touch "$SUDOERS_FILE"
fi
cat <<EOFS >> "$SUDOERS_FILE"
# sudo permissions for RNN web interface
# allow systemctl so the UI can reload systemd after fstab edits
Defaults:$WWWUSER !requiretty
$WWWUSER ALL=(ALL) NOPASSWD: \
    /usr/bin/getent, /usr/bin/nsenter, /sbin/mdadm, /usr/sbin/mdadm, \
    /sbin/vgcreate, /sbin/vgextend, /sbin/lvcreate, /sbin/lvextend, \
    /sbin/lvrename, /sbin/lvconvert, /sbin/lvremove, /sbin/vgremove, \
    /sbin/pvs, /sbin/pvcreate, /sbin/pvremove, /sbin/pvck, /sbin/pvrepair, \
    /sbin/pvdisplay, /sbin/pvresize, /sbin/pvmove, \
    /sbin/vgs, /sbin/lvs, /sbin/exportfs, /usr/sbin/exportfs, \
    /usr/bin/lsblk, /usr/bin/mkfs*, /sbin/mkfs*, /usr/sbin/mkfs*, \
    /usr/sbin/blkid, /bin/mount, /bin/umount, /bin/mkdir, /bin/rmdir, \
    /bin/chown, /bin/chmod, /usr/sbin/parted, /usr/sbin/sgdisk, \
    /usr/sbin/smartctl, /usr/sbin/wipefs, /usr/bin/tee, /usr/bin/pamtester, \
    /usr/bin/python3, /usr/bin/perl, /bin/echo, /bin/cat, /bin/grep, \
    /bin/mv, /bin/systemctl*

# allow lookups with arguments
$WWWUSER ALL=(ALL) NOPASSWD: /usr/bin/getent shadow *
EOFS
chmod 440 "$SUDOERS_FILE"

echo "Sudoers entry appended to $SUDOERS_FILE"

# sanity check: ensure the sudoers file actually contains the LVM tools we
# rely on from the PHP pages.  If it does not, alert the administrator so
# they can rerun the installer or add the missing lines manually.
required=(pvdisplay pvcreate pvs vgs lvs lsblk)
missing=()
for cmd in "${required[@]}"; do
    if ! grep -q "${cmd}" "$SUDOERS_FILE"; then
        missing+=("$cmd")
    fi
done
if [ ${#missing[@]} -ne 0 ]; then
    echo "WARNING: sudoers file $SUDOERS_FILE is missing entries for: ${missing[*]}"
    echo "You may need to rerun the installer or add them manually."
fi

# deploy web UI files to the chosen document root
echo "deploying web files to ${WEBROOT}"
mkdir -p "$WEBROOT"
if [ -e "$WEBROOT/index.html" ]; then
    echo "A default index.html exists in ${WEBROOT}."
    read -p "Remove it before copying new files? (y/N): " remove
    case "$remove" in
        [Yy]*)
            echo "removing default index.html from ${WEBROOT}"
            rm -f "$WEBROOT/index.html"
            ;;
        *)
            echo "keeping existing index.html";;
    esac
fi
SRC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
cp -r "$SRC_DIR"/* "$WEBROOT/"
chown -R "$WWWUSER":"$WWWUSER" "$WEBROOT"
rm -r "$WEBROOT/.git" "$WEBROOT/.github" 2>/dev/null || true
rm -r "$WEBROOT"/*.md "$WEBROOT"/*.txt 2>/dev/null || true
rm -r "$WEBROOT"/*.sh 2>/dev/null || true

# configuration file handling similar to install.sh
CONFIG_FILE="$WEBROOT/config.php"
if [ ! -e "$CONFIG_FILE" ]; then
    echo "creating default config.php in ${WEBROOT}"
    cat <<EOF > "$CONFIG_FILE"
<?php
// Generated by installer.  Edit or drop in your own copy and add to
// .gitignore if you don't want it committed.

\$CONFIG = [];
\$CONFIG['mount_base'] = '$mount_base';
\$CONFIG['login_group'] = '$login_group';
\$CONFIG['trusted_proxies'] = [
EOF
    # write trusted_proxies array entries if any
    if [ -n "$trusted_proxies" ]; then
        IFS=','
        for pr in $trusted_proxies; do
            echo "\"$(echo $pr | xargs)\"," >> "$CONFIG_FILE"
        done
        unset IFS
    fi
    cat <<EOF >> "$CONFIG_FILE"
];
\$CONFIG['proxy_header_scheme'] = '$proxy_scheme';
\$CONFIG['proxy_header_host'] = '$proxy_host';
\$CONFIG['cookie_secure'] = $cookie_secure;
\$CONFIG['exports_file'] = '$exports_file';
\$CONFIG['base_url'] = '$base_url';
EOF
    chown "$WWWUSER":"$WWWUSER" "$CONFIG_FILE"
    chmod 644 "$CONFIG_FILE"
else
    chown "$WWWUSER":"$WWWUSER" "$CONFIG_FILE"
    chmod 644 "$CONFIG_FILE"
fi

cat <<'EOF'

Installation complete.

Important notes:
  * the web server must be running and PHP available for the UI to work
  * add your account to the 'nfs' group to gain login access:
      sudo usermod -aG nfs <username>

EOF
