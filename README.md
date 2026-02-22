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
    /usr/bin/getent, /sbin/mdadm, /usr/sbin/mdadm, /sbin/vgcreate, /sbin/lvcreate, /sbin/lvremove, /sbin/vgremove, /sbin/pvcreate, /sbin/pvremove, \
    /sbin/pvs, /sbin/vgs, /sbin/lvs, /sbin/exportfs, /usr/bin/lsblk, /usr/bin/mkfs, /usr/sbin/blkid, /bin/mount, /bin/umount, /bin/mkdir, /bin/rmdir, \
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
   
   A new section of the UI lists existing `/dev/md*` arrays; you can select one
   and remove it (the script stops and removes the array).  Disks that belong to
   an array are automatically excluded from the "Available Disks" list, but the
   RAID device itself is also shown in that panel and may be initialised as a PV
   (useful if you want a volume on top of the array).  Destroying the array frees
   its members for later use.

   The removal step now also runs `mdadm --zero-superblock` on each former member
   so they truly appear unused; without this you may see a warning like
   "/dev/sda appears to be part of a raid array" when creating a new array.  The
   UI also suppresses the harmless "error opening /dev/mdX: No such file or
   directory" message that `mdadm --remove` can emit immediately after stopping
   an array.  Attempting to delete an array that still has an LVM PV will now
   abort early with a clear warning asking you to remove any logical volumes and
   volume groups first; the array stop is not attempted until the LVM stack is
   gone.  (Previous behaviour attempted to remove the array anyway and could
   result in a misleading "Cannot get exclusive access" error.)  When building a
   new RAID the form now filters out a few additional harmless messages such as
   `Unrecognised md component device` and the "Defaulting to version" line so
   the feedback area only shows meaningful results.  If you stop arrays manually
   outside the UI you'll need to zero the superblock yourself (or the RAID‑creation
   form will do it automatically now before it attempts to build the new array).
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

* The frontend has been refactored so that all custom CSS lives in `assets/css/style.css` and all interactive behavior is implemented in `assets/js/app.js`.  Pages no longer contain inline `<style>` blocks or `<script>` tags with executable code; common helpers such as the confirmation modal handler and initial‑message display are centralized in `app.js`.
* Confirmation dialogs now appear for destructive operations across the UI: LV/VG/RAID removal, LV formatting, mounts/unmounts on the Mounts page, and NFS export deletions.  Buttons have been given IDs or names so the shared script can attach handlers.
* PV initialization messages are rendered into a hidden `<div id="initialMessage" style="display:none">…</div>` so they are never visible until the JavaScript turns them into a modal.  This avoids showing notices on the page if the script fails to run or is cached.
* The disk‑listing logic now filters out `/dev/md*` devices that have already been turned into physical volumes, preventing them from reappearing in the "Available Disks" list after `pvcreate`.


- **Dashboard**: Choose between LVM/RAID management, NFS exports, or filesystem mount/unmount operations (the new "Mounts" page lets you mount logical volumes under `/export`).  Note that the LV must contain a valid filesystem before mounting – format using the LVM page first if necessary.  The installer now includes `util-linux` so that `nsenter` is available; without it mount/umount requests may be confined to Apache’s private namespace.- **LVM/RAID**: View current physical volumes, volume groups, logical volumes. Create new VGs, LVs, or RAID arrays; the RAID form now offers a pulldown of five unused `/dev/mdX` device names and presents named raid levels such as “Striped (0)”, “Mirrored (1)”, etc.  When selecting disks for a new array it no longer lists any existing `/dev/md*` devices.  Logical-volume creation will automatically wipe any leftover filesystem signatures and zero the start of the new LV (lvcreate is called with `-y -Z y`), avoiding prompts or aborts on recycled devices. Formatting an LV now produces a simple success/failure notice; when successful the new filesystem's UUID is also displayed (provided `/usr/sbin/blkid` can be run via sudo without a password prompt – add it to your sudoers drop-in if you want the UUID displayed.  Without that entry the modal will simply say "Logical volume formatted successfully (UUID lookup failed…)".)  The code also ignores any extraneous `sudo:` errors or `(exit N)` lines that might be appended.  Mount operations on the new Mounts page are verbose: the dialog shows `mount -v` output and the matching line from `mount` so you can tell whether the kernel actually performed the request.  Earlier releases could mistakenly treat a successful mount as a failure and dump the raw output – the logic has been simplified so that any line returned by `grep` of the requested point is considered a success, eliminating those false negatives.  The success text no longer includes a note about the host namespace; if a private namespace is suspected diagnostics are still captured but not shown unless the mount fails.  Unmounts now always display an explicit status message (`Unmount failed` etc.) even when the only command output is an exit‑code line.  The checks for both mounts and unmounts strip the spurious "(exit N)" line that `grep` produces when there are no matches, so a vanished entry is treated as success even if the command itself returns non‑zero.  This avoids misleading "entry still present" warnings when the filesystem really is gone.  To work around Apache’s private mount namespace the code may prefix `mount`/`umount` with `nsenter -t 1 -m`; this requires `/usr/bin/nsenter` be allowed in sudoers or the mount will still only affect the webserver’s own namespace.  If PHP is running in a private mount namespace the popup will include `df` output and warn that the mount isn’t visible to the host; in that case mounting via the web UI isn’t possible.  Unmounts perform a similar verification and will explicitly report if the entry remains after running `umount`. When removing or formatting a logical volume you select it by its short name, but the system actually operates on the full device path (e.g. `/dev/mapper/vg-lv`).
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

