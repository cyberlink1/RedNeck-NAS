# RNN (Red Neck NAS) – LVM/Raid and NFS Management Interface

This repository contains a simple PHP + JavaScript web UI to manage LVM/RAID configurations and NFS exports. Login is handled using the system's `/etc/passwd` and `/etc/shadow` information.

## Requirements

- PHP 7.4+ with CLI/web support
- Web server (Apache, Nginx) configured to serve this directory
- `sudo` privileges or root access for the PHP process to run `getent shadow` and LVM/NFS commands
- LVM utilities (`pvs`, `vgs`, `lvs`, `vgcreate`, `lvcreate` etc.)
- `mdadm` for RAID creation
- NFS utils (`exportfs`)
- `parted`, `gdisk`/`sgdisk` and `wipefs` for disk partitioning and wiping
- `smartmontools` for SMART status

## Installation

1. Place the directory under your web server's document root or configure a virtual host.
2. Ensure the PHP process can read `/etc/shadow` (typically running as root or via sudo).  The code invokes `sudo getent shadow …`, and **sudoers entries must match the command path only**; arguments are not considered.  In other words, the previous example with `/usr/bin/getent shadow` did *not* match when the script added the username argument (`cl`), which is why you were still prompted for a password.  You should instead permit the `getent` binary itself (or allow any argument with a wildcard):
   ```
www-data ALL=(ALL) NOPASSWD: \
    /usr/bin/getent, /sbin/mdadm, /usr/sbin/mdadm, /sbin/vgcreate, /sbin/lvcreate, /sbin/lvremove, /sbin/vgremove, /sbin/pvcreate, /sbin/pvremove, \
    /sbin/pvs, /sbin/vgs, /sbin/lvs, /sbin/exportfs, /usr/bin/lsblk, /usr/bin/mkfs, /usr/sbin/blkid, /bin/mount, /bin/umount, /bin/mkdir, /bin/rmdir, \
    /usr/sbin/parted, /usr/sbin/sgdisk, /usr/sbin/smartctl, /usr/sbin/wipefs, /usr/bin/tee, \
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

* The frontend has been refactored so that all custom CSS lives in `assets/css/style.css` and all interactive behavior is implemented in `assets/js/app.js`.  Pages no longer contain inline `<style>` blocks or `<script>` tags with executable code; common helpers such as the confirmation modal handler and initial‑message display are centralized in `app.js`.  New disk‑view actions (partition deletion, wipe) also use the shared confirmation dialog.
* Confirmation dialogs now appear for destructive operations across the UI: LV/VG/RAID removal, LV formatting, mounts/unmounts on the Mounts page, and NFS export deletions.  Buttons have been given IDs or names so the shared script can attach handlers.
* PV initialization messages are rendered into a hidden `<div id="initialMessage" style="display:none">…</div>` so they are never visible until the JavaScript turns them into a modal.  This avoids showing notices on the page if the script fails to run or is cached.
* The disk‑listing logic now filters out `/dev/md*` devices that have already been turned into physical volumes, preventing them from reappearing in the "Available Disks" list after `pvcreate`.


- **Dashboard**: This is now the single entry point; each menu choice is implemented as a "view" loaded under `dashboard.php` (e.g. `dashboard.php?view=lvm`, `?view=nfs`, `?view=mounts`, `?view=disks`).  The `views/` directory contains the PHP fragments for each feature, and the former standalone pages (`lvm.php`, `nfs.php`, `mounts.php`, `disks.php`) simply redirect here and are considered deprecated.  You can still hit them and will be forwarded automatically.  When first logging in (or when no `view=` parameter is supplied) the dashboard shows a pair of summary cards listing the current logical volumes and any active `/export` mounts instead of the old welcome banner.  The mounts card now uses `col-md-auto` and accompanying CSS so its width expands as needed to accommodate the output; this prevents a horizontal scrollbar inside the card (note that the overall page may scroll if the card becomes wider than the viewport).  The navbar now reads RAID, LVM, Mounts, NFS Exports, Disks; select the appropriate section (RAID and LVM are split into separate views).  Note that the LV must contain a valid filesystem before mounting – format using the LVM view first if necessary.  The installer now includes `util-linux` so that `nsenter` is available; without it mount/umount requests may be confined to Apache’s private namespace.
- **RAID**: Create and delete MD RAID arrays. The RAID view provides a simple form offering a dropdown of five unused `/dev/mdX` names and named levels (Striped, Mirrored, RAID5, RAID6). It filters disks to exclude existing `/dev/md*` devices, wipes superblocks before creation, and automatically zeros members when removing an array. Attempting to remove an array that’s still used as an LVM physical volume will display a friendly warning. Existing arrays are listed for convenient removal.
- **Disks**: New disk management menu. The view now presents devices in a full‑width table rather than a dropdown. Each row shows the device path, its model/serial "name", size, and a status column indicating RAID/LVM membership (it will list the specific `/dev/mdN` array when present) or if it’s the OS disk. Non‑disk entries such as CD‑ROMs, loops and partitions are omitted (MD arrays are included); devices named `/dev/sr*` are filtered out entirely since they can’t be partitioned. Click a row to see a pop‑up containing the full set of cards (partition table, create partition form, delete partition form *if the disk has partitions*, and other actions) for that disk.  The data is loaded via AJAX directly from `views/disks.php?ajax=1&disk=<device>`; this bypasses the dashboard wrapper so only the card HTML is returned.  The preview appears in a **separate info modal** (id `infoModal`) so it doesn’t interfere with the global confirmation modal used for destructive actions.  All action forms within the modal are submitted via AJAX and the returned HTML replaces the modal contents; the server-side handler detects `ajax=1` in either GET or POST requests and returns **only** the card fragment, so the disks table (or any other surrounding page HTML) never appears in the popup.  The JavaScript tracks which submit button you click so the appropriate `create_part`, `delete_part` or `wipe_disk` parameter is included – previously the “Create Partition” form was ignored when posted via AJAX.  Moreover, the confirmation callbacks for delete‑partition and wipe actually post the form using the same AJAX helper (rather than calling `form.submit()`), ensuring the modal stays open and only its contents are refreshed.  Confirmation dialogs are shown only after any open info modal has fully hidden, guaranteeing they stack correctly and avoiding the “behind the preview”/greyed‑out backdrop problem.  The script also reuses the same Bootstrap modal instance when refreshing the contents; this prevents multiple backdrop layers from building up when you perform several actions in succession.  When creating a partition the handler will automatically initialise a GPT label on unlabelled disks to avoid the “unrecognised disk label” error.  The preview modal does not reload the page on close (forms inside still submit normally); use the Close button when done.

  When a device is part of an MD array, is itself an MD device, or is already used as an LVM PV, the partitioning (“Create”/“Delete”) and wipe actions are disabled and a warning is displayed. SMART/temperature checks and the identify/locate button remain available for all devices. Confirmation dialogs protect destructive operations.

- **LVM**: View and manage physical volumes, volume groups, and logical volumes. Initialize PVs from available disks, create VGs, and carve out LVs (the `lvcreate` command uses `-y -Z y` to erase signatures). Format or delete LVs, with filesystem UUIDs shown when available. Logical volumes must be formatted before mounting via the Mounts view. Removal and formatting actions occur here; details on mount/unmount behaviour are described in the dashboard section above.
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

