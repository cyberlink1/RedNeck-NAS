# LVM/Raid and NFS Management Interface

This repository contains a simple PHP + JavaScript web UI to manage LVM/RAID configurations and NFS exports. Login is handled using the system's `/etc/passwd` and `/etc/shadow` information.

## Requirements

- PHP 7.4+ with CLI/web support
- Web server (Apache, Nginx) configured to serve this directory
- `sudo` privileges or root access for the PHP process to run `getent shadow` and LVM/NFS commands
- LVM utilities (`pvs`, `vgs`, `lvs`, `vgcreate`, `lvcreate` etc.)
- `mdadm` for RAID creation
- NFS utils (`exportfs`)

## Installation

1. Place the directory under your web server's document root or configure a virtual host.
2. Ensure the PHP process can read `/etc/shadow` (typically running as root or via sudo).  The code invokes `sudo getent shadow …`, and **sudoers entries must match the command path only**; arguments are not considered.  In other words, the previous example with `/usr/bin/getent shadow` did *not* match when the script added the username argument (`cl`), which is why you were still prompted for a password.  You should instead permit the `getent` binary itself (or allow any argument with a wildcard):
   ```
www-data ALL=(ALL) NOPASSWD: \
    /usr/bin/getent, /sbin/mdadm, /sbin/vgcreate, /sbin/lvcreate, /sbin/lvremove, /sbin/vgremove, /sbin/pvcreate, \
    /sbin/pvs, /sbin/vgs, /sbin/lvs, /sbin/exportfs, /usr/bin/lsblk, /usr/bin/mkfs, \
    /usr/bin/pamtester, /usr/bin/python3, /usr/bin/perl, /bin/echo, \
    /bin/cat, /bin/grep
   # or more narrowly: /usr/bin/getent shadow *, /usr/bin/lsblk
   ```
   Make sure that each command is on a single logical line (line breaks with `\`
   are allowed but must end the sudoers line properly); mis‑formatted entries may
   be ignored, causing sudo to prompt for a password.  Once the PHP process can
   run the listed commands password‑lessly, the interface uses `sudo -n` so it
   will fail cleanly instead of hanging on a prompt.

   The LVM and RAID pages now run `pvs`, `vgs`, `lvs`, `vgcreate`, `lvcreate` and
   `mdadm` under `sudo` as well, so those binaries must be included if you intend
   to manage storage from the interface.  Missing entries lead to warnings such as
   "Running as a non-root user. Functionality may be unavailable." in the UI.
   Additionally, the front end uses `lsblk` (also via `sudo`) to enumerate raw
   disks when offering drives for initialization or RAID creation; without sudo
   the list may come back empty and you'll see "No raw disks detected." even
   on machines with available devices.  Only *whole* disks without any
   existing partitions are shown – devices with partitions (e.g. `/dev/sdb1`)
   are deliberately filtered out to avoid accidental data loss.  If you need to
   use a disk with partitions, clear them first (e.g. with `wipefs` or `fdisk`).

   **Note:** some distributions enable `Defaults requiretty` which prevents sudo
   from running without an interactive terminal.  If your login still fails with
   status 2 even though `sudo -u www-data getent shadow cl` works, add the
   following to your sudoers file:
   ```
   Defaults:www-data !requiretty
   ```
3. Adjust file permissions if needed.
4. Navigate to `login.php` in your browser and authenticate with a system user.

> **Debugging login issues**
>
> - If the login form simply reloads with "Login failed" and the webserver logs show no errors, it's likely the PHP process cannot access `/etc/shadow`. The page will now display additional details like "getent failed" or "user not found".
> - Try running `getent shadow username` as the same user the webserver runs as (e.g. `sudo -u www-data getent shadow youruser`).
- If authentication still fails even though the password is correct, the system may be using a hashing algorithm that PHP's `crypt()` doesn’t support (e.g. `yescrypt`/`$y$`).  The page will now display the first part of the stored hash and the computed value; if the two differ wildly or the computed string is empty, that’s the issue.  In that case the code attempts a second check using Python’s `crypt` via `sudo` (glibc/libxcrypt may support the algorithm), so ensure `python3` is also allowed in your sudoers entry if you rely on this fallback.  If Python isn’t installed or its `crypt` module is missing the script then falls back to Perl (which has `crypt` built in), so allowing `/usr/bin/perl` is also recommended if you want maximum compatibility.
> - Check PHP error reporting is enabled (the code now sets `display_errors`), and inspect the browser output for PHP warnings.
> - You can also tail the webserver error log while submitting the form to see any runtime messages.

## Usage

- **Dashboard**: Choose between LVM/RAID management and NFS exports.
- **LVM/RAID**: View current physical volumes, volume groups, logical volumes. Create new VGs, LVs, or RAID arrays.
- **NFS**: List current exports, add or remove exports. Changes are applied immediately via `exportfs -ra`.

> ⚠️ All operations are potentially destructive. Use with care.

## Security Notes

- This interface executes shell commands; ensure proper escaping and restrict access to trusted administrators.
- Consider running under HTTPS and enforcing strong authentication policies.
- The current implementation is minimal and meant as a starting point.

## Extending

- Add form validation and error handling.
- Implement removal of VGs/LVs/RAIDs.
- Provide AJAX-based updates and progress indicators.
- Integrate with a proper authentication system if desired.

```bash
# Example: start a PHP built-in server for testing
php -S localhost:8000 -t /path/to/this/project
```

