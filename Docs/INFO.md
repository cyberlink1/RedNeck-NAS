# RNN (RedNeck NAS)

RNN is a lightweight web control plane for Linux storage.

It does not maintain its own configuration database.  It reads the live
system state and executes native Linux commands to manage:

- MD RAID (`mdadm`)
- LVM (PV, VG, LV, thin pools, snapshots)
- Disk partitioning and wiping
- Filesystems (`mkfs`, `mount`, `umount`)
- `/etc/fstab` entries (systemd `daemon-reload` automatically)
- NFS exports (`/etc/exports`)
- Samba shares (`/etc/samba/smb.conf`) – only available if Samba is installed; options may be edited in the UI (read only, guest ok, etc.)

The system itself remains the single source of truth.  If something changes
via SSH, the UI reflects it immediately.

A small `config.php` file in the webroot controls runtime settings such as
the mount base, login group, trusted proxies, cookie behaviour,
exports file path and an optional base URL.  The installer (`install-v2.sh`)
will prompt for these values when first run, writing a template and
marking it git‑ignored so site customisations persist.

## Requirements

RNN relies on a handful of standard packages; the interactive installer
will also ask you for the mount base, NFS login group, any trusted reverse
proxies, secure-cookie preferences and the path to `/etc/exports` so that
its default configuration matches your environment.


- Linux system with:
  - `mdadm`
  - `lvm2` tools
  - `nfs-utils` / `nfs-kernel-server`
  - `util-linux` (`lsblk`, `mount`, `nsenter`, etc.)
  - `parted` / `sgdisk` / `wipefs`
  - `smartmontools`
- PHP 7.4+
- Web server (Apache or Nginx)
- `sudo` configured for required storage commands
- Unix group for login (defaults to `nfs`, configurable during install)

Only users in the configured **login group** may log in (the installer
prompts for the name).  Authentication uses system ` /etc/passwd` and
`/etc/shadow`.

## Design Goals

- No database
- No background daemons
- No duplicated config model
- Minimal JavaScript
- Small footprint (~360 KB total)
- Direct execution of native Linux tools
- Simple, readable, auditable code

## Behavior

RNN:

- Queries the live system for all states
- Writes directly to system config files when required
- Uses `sudo -n` (non‑interactive)
- Avoids hidden state or caching
- Fails cleanly if privileges are missing

All destructive actions require confirmation.

## Security

This interface executes system commands.  It must only be accessible to
trusted administrators.

- Use HTTPS.
- Restrict sudo permissions carefully.
- Limit membership of the `nfs` group.

## Warning

This tool performs real storage operations.  Incorrect use can destroy data.

**Use at your own risk.**

## Configuration

When you deploy the UI the installer creates `config.php` with sensible
defaults.  You can edit that file later to tweak the values described above;
changes take effect immediately and the file is deliberately ignored by git.

## Testing

You can run with the PHP built-in server:

```bash
php -S localhost:8000 -t /path/to/rnn
```

End.