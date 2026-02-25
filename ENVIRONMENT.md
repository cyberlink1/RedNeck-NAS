# Environment Requirements for RNN (RedNeck NAS)

This document describes the software and system environment needed to run the
RNN web control plane.  It complements the content in `Docs/README.md` and the
`install.sh` helper script by providing a concise reference for operators or
packagers.

---

## Supported Operating System

- Primary development and testing target: **Debian 13 (Trixie)**
  - Minimal installation with _Web Server_ and _SSH Server_ task selections.
  - Desktop environment / GNOME not required or desired.
- Other Linux distributions should work with little modification; only
  package names and package manager commands need adjustment.
- Assumes a standard GNU userland (bash, coreutils, util‑linux, etc.).

## Required Packages

The following packages must be installed on the host.  On Debian this set is
specified in `install.sh` as `REQUIRED_PKGS`.

```
sudo pamtester lvm2 php php-cli apache2 php-common mdadm util-linux parted
gdisk smartmontools nfs-kernel-server
```

Notes:

1.  `apache2` may be replaced with `nginx` (or another web server) provided
the PHP integration is configured accordingly and the document root is set to
where the repository content is deployed (`/var/www/html` by default).
2.  `nfs-kernel-server` is pulled in to provide utilities such as `exportfs`.
3.  `pamtester` is optional; the login code will fall back to Python or Perl
   helpers if it is absent.
4.  The `php` packages should include a CLI binary; the web UI relies on
   composer‑less PHP and only uses core functions (no framework dependencies).

Additional utilities invoked by the UI include `mdadm`, `parted`, `sgdisk`,
`smartctl`, `wipefs`, `lsblk`, and the usual mount/umount/mkfs commands – all
part of the standard distribution.

## User & Group Expectations

- The web server runs as `www-data` (Debian default).
- An `nfs` group is required.  Only accounts that are members of this group may
  log in through the UI.  `install.sh` creates the group if it doesn't exist.
- Administrators should add their shell user(s) to `nfs` using
  `sudo usermod -aG nfs <username>`.
- A sudoers drop‑in (`/etc/sudoers.d/nfs-webui`) is created to grant `www-data`
  passwordless access to a narrow list of commands used by the web UI (see the
  script for the full ACL).  The sudo rules also allow the UI to invoke
  `getent` and `pamtester` for authentication and `systemctl` for reloading
  services after `/etc/fstab` edits.

## Web Server Configuration

- Document root defaults to `/var/www/html`.
- The `install.sh` script copies repository contents into the webroot and
  adjusts ownership (recursive `www-data:www-data`).  It strips out Git
  metadata, markdown files, and shell scripts.
- Apache should be enabled and running (`systemctl enable --now apache2`).
- PHP errors are displayed (see `functions.php`), so `display_errors` is
  enabled in runtime; a production deployment may want to set this to `0`.

## Shell & PHP Environment

- `/bin/bash` is assumed for installation and local scripts.
- `php-cli` must be available at `/usr/bin/php` for some sudo commands in the
  sudoers list.  The UI itself runs under the PHP module/handler provided by
  the web server.
- Other interpreters referenced in code: `/usr/bin/python3`, `/usr/bin/perl`.

## Runtime Assumptions

- The web UI does not maintain a database; it queries the live system state
  (e.g. `lsblk`, `vgscan`, `mdadm --detail`) and executes native storage
  management commands via `sudo`.
- The host is expected to be a storage server with access to local block
  devices.
- No background daemon or additional services are required beyond the web
  server and NFS exporter if NFS is used.
- File system mounts and `/etc/fstab` entries are edited by the UI;
  therefore, the underlying `mount`, `umount`, `chown`, etc. utilities must be
  writable by `www-data` via sudo.

---

> **Tip:**  For a production installation, review the sudoers ACL and tighten
> permissions as needed.  Consider running the web site over HTTPS and
> restricting access to the management interface using a firewall or VPN.

This file should be kept up to date whenever the environmental requirements or
configuration steps change.