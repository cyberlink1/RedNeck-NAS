# RNN (Red Neck NAS) – LVM/Raid and NFS Management Interface

This repository contains a simple PHP + JavaScript web UI to manage LVM/RAID configurations and NFS exports. Login is handled using the system's `/etc/passwd` and `/etc/shadow` information.

## Requirements

- PHP 7.4+ with CLI/web support
- Web server (Apache, Nginx) configured to serve this directory
- `sudo` privileges or root access for the PHP process to run `getent shadow` and LVM/NFS commands
- LVM utilities (`pvs`, `vgs`, `lvs`, `vgcreate`, `lvcreate` etc.)
- `mdadm` for RAID creation
- NFS utils (`exportfs`, provided by `nfs-kernel-server`/`nfs-common`)
- `nsenter` (from `util-linux`; used to inspect the host's mount namespace so all `/export/*` mounts are detected)
- `parted`, `gdisk`/`sgdisk` and `wipefs` for disk partitioning and wiping
- `smartmontools` for SMART status

## Installation

1. Place the directory under your web server's document root or configure a virtual host.
2. Ensure the PHP process can read `/etc/shadow` (typically running as root or via sudo).  The code invokes `sudo getent shadow …`, and **sudoers entries must match the command path only**; arguments are not considered.  In other words, the previous example with `/usr/bin/getent shadow` did *not* match when the script added the username argument (`cl`), which is why you were still prompted for a password.  You should instead permit the `getent` binary itself (or allow any argument with a wildcard):
   ```
www-data ALL=(ALL) NOPASSWD: \
    /usr/bin/getent, /sbin/mdadm, /usr/sbin/mdadm, /sbin/vgcreate, /sbin/vgextend, /sbin/lvcreate, /sbin/lvextend, /sbin/lvrename, /sbin/lvconvert, /sbin/lvremove, /sbin/vgremove, /sbin/pvcreate, /sbin/pvremove, \
    /sbin/pvs, /sbin/vgs, /sbin/lvs, /sbin/exportfs, /usr/bin/lsblk, /usr/bin/mkfs*, /sbin/mkfs*, /usr/sbin/mkfs*, /usr/sbin/blkid, /bin/mount, /bin/umount, /bin/mkdir, /bin/rmdir, \
    /usr/sbin/exportfs, \
    /usr/sbin/parted, /usr/sbin/sgdisk, /usr/sbin/smartctl, /usr/sbin/wipefs, /usr/bin/tee, \  # tee needed for adding fstab entries
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
   an array are automatically excluded from the combined device/physical-volume
   table shown lower on the LVM page, but the RAID device itself is still shown
   and may be initialised as a PV (useful if you want a volume on top of the
   array).  Destroying the array frees its members for later use.

   The removal step now also runs `mdadm --zero-superblock` on each former member
   so they truly appear unused; without this you may see a warning like
   "/dev/sda appears to be part of a raid array" when creating a new array.  The
   UI also suppresses the harmless "error opening /dev/mdX: No such file or
   directory" message that `mdadm --remove` can emit immediately after stopping
   an array.  Attempting to delete an array that still has an LVM PV will now
   abort early with a clear warning asking you to remove any logical volumes and
   volume groups first; the array stop is not attempted until the LVM stack is
   gone.  (Previous behaviour attempted to remove the array anyway and could
   result in a misleading "Cannot get exclusive access" error.)  If the RAID
   device happens to contain residual PV metadata but isn’t actually part of a
   group, the interface will automatically run `pvremove` on it before proceeding
   so you don’t have to clean up manually.  When building a
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
* A full‑screen spinner overlay (`#spinnerOverlay`) is now defined in the LVM view and controlled via JavaScript.  It remains hidden by default and is shown automatically whenever any form is submitted, giving visual feedback during long-running operations like formatting or extending volume groups.  The overlay will no longer appear before confirmation dialogs, and the LV modal is reopened automatically after any format/remove operation so you won’t lose context.
* Formatting of logical volumes is now forced (`mkfs -F`) to avoid interactive confirmation prompts that would otherwise block the PHP process; the RAID page already used the same flag.  The format dialog also prints the exact `mkfs` commands it executed in the result modal so you can verify what was run if the device appears unchanged.  Additionally, the server now detects if a selected LV is currently mounted (using `lsblk` rather than a crude `mount` grep) and skips it, warning you to unmount before attempting to format.
* Thin‑provisioned LVs are created without the `-Z y` zeroing option (which is only valid when a thin pool itself is being created); leaving the pool field blank still works by using the default pool.
* The Logical Volumes modal now includes a **Filesystem** column showing the current filesystem type (determined via `blkid`) so you can see at a glance which volumes are already formatted.
* When creating a logical volume you can now choose between various types (linear, raid0/1/4/5/6/10) via a drop‑down; the selected value is passed as `--type` to `lvcreate` (additional raid parameters such as stripe count must still be supplied manually if needed).  A new "Thin provisioned" checkbox lets you create a thin LV; the pool is assumed to be named `thin` in the VG and the command will include `--thinpool thin` automatically.  Thin LVs use the `-V` (virtual size) argument.
* A dedicated **Thin pool** button appears in the **Manage Volume Groups** modal. Select one volume group, click the button and enter the desired pool size; the thin pool LV will be created inside that VG with the fixed name `thin` (no name prompt needed). The command uses `lvcreate --type thin-pool` with zeroing enabled. The special `thin` pool LV is deliberately **not** shown in the Logical Volumes table since it’s managed separately.
* The Logical Volumes table now includes a **Type** column that shows the current LV type (linear or raid level).  Behind the scenes the script queries `lvs` with the `lv_layout` field (falling back to the first character of `lv_attr`).  The command uses a pipe (`|`) separator so empty fields (such as a missing path for the special thin‑pool LV) don’t shift columns, and the thin‑pool itself is deliberately omitted from the list.
* The LV modal now provides **Extend**, **Rename** and **Convert** buttons.  Select a single volume in the table and use the appropriate button to:
  * extend it to a larger size (`lvextend`);
  * rename it within the same VG (`lvrename`);
  * change its type between linear and various RAID levels (`lvconvert --type …`).
    The convert form omits the current layout and will report if you attempt a no‑op.  When converting to RAID1 the UI automatically adds `-m1` (mirror count); conversions to RAID0 default to two stripes.  Converting a RAID back to linear decrements the mirror count (`-m-1`), which removes the last mirror and returns the LV to linear.  More complex layouts may require manual adjustment.  Actions are confirmed before submission and the LV modal re‑opens afterwards.
* Each LV row also has a **Snap** button. Clicking it opens a dedicated modal that lists existing snapshots for that LV in a table (choose one using the radio button).  You can then delete or roll back the selected snapshot.  A **Create** button in the same modal brings up a secondary dialog where you enter the new snapshot’s name and size.  Size values returned by `lvs` are in bytes; the interface now converts them to a human‑readable form (k/M/G etc.) for display.  The snapshot listing is refreshed each time you open the modal (if the AJAX call returns HTML instead of data – for example when you’ve been logged out – the table will display an error message instead of spurious rows).  LVM may report origins using just the volume name rather than the full path; the code now matches either form when looking up snapshots.  Note that snapshots themselves are **not** shown in the Logical Volumes table – they are filtered out so you manage them only via the snapshot modal.
* Confirmation dialogs now appear for destructive operations across the UI: LV/VG/RAID removal, LV formatting, mounts/unmounts on the Mounts page, and NFS export deletions.  Buttons have been given IDs or names so the shared script can attach handlers.  When a confirmation is triggered from within a modal (e.g. the RAID creation dialog or disk info popup), the parent modal is automatically hidden so the confirm box is always on top; once the user clicks OK the form submits and the page reloads.  Any output message returned from the server is shown in a follow‑up confirmation dialog on the refreshed RAID view – the creation modal does not reappear unless you explicitly open it again.
* PV initialization messages are rendered into a hidden `<div id="initialMessage" style="display:none">…</div>` so they are never visible until the JavaScript turns them into a modal.  This avoids showing notices on the page if the script fails to run or is cached.
* The disk‑listing logic now filters out `/dev/md*` devices that have already been turned into physical volumes, preventing them from reappearing in the "Available Disks" list after `pvcreate`.


- **Dashboard**: This is now the single entry point; each menu choice is implemented as a "view" loaded under `dashboard.php` (e.g. `dashboard.php?view=lvm`, `?view=nfs`, `?view=mounts`, `?view=disks`).  The `views/` directory contains the PHP fragments for each feature, and the former standalone pages (`lvm.php`, `nfs.php`, `mounts.php`, `disks.php`) simply redirect here and are considered deprecated.  You can still hit them and will be forwarded automatically.  When first logging in (or when no `view=` parameter is supplied) the dashboard displays a row of statistic cards: total physical drives (excluding the OS/root disk), total RAID arrays, total logical volumes, total `/export` mounts, and total NFS exports.  The mount count is derived directly from `/proc/1/mounts` (with a fallback to `nsenter`+`mount|grep`), ensuring the number reflects the host’s actual mount table even when the PHP process is confined to its own namespace.  Each card shows a large count number to give an at-a-glance summary.  (Previously only logical volumes and mounts were shown.)  The mounts card still uses `col-md-auto` and accompanying CSS so its width expands as needed to accommodate the output; this prevents a horizontal scrollbar inside the card (note that the overall page may scroll if the card becomes wider than the viewport).  The Mounts view itself now presents an explicit table of existing `/export` mounts (device, point and current options), with an easy **Create mount** button in the top‑right corner; unmount actions are available per row.  Clicking any row (outside of the unmount button) will open an **Edit mount** dialog pre‑populated with the current options and fstab status, allowing you to tweak `rw/ro/noexec/nosuid/nodev` flags or toggle the “mount at boot” checkbox.  The creation form opens a modal in which the **Device** pulldown lists any block node (physical drive, `/dev/md*`, or LV) that currently has a filesystem and isn’t already mounted.  The list explicitly excludes the OS root disk/partition and any device that’s already an LVM physical volume (whether or not it’s part of a VG), so you won’t accidentally pick a raw PV or the boot device.  You can also specify filesystem options (rw, ro, noexec, nosuid, nodev) and there’s a **Mount at boot** checkbox – checking it will append an appropriate line to `/etc/fstab` (the backend uses `sudo -n tee -a /etc/fstab`, so `/usr/bin/tee` must be permitted in sudoers) using the detected filesystem type.  The navbar now reads RAID, LVM, Mounts, NFS Exports, Disks; select the appropriate section (RAID and LVM are split into separate views).  Note that the LV must contain a valid filesystem before mounting – format using the LVM view first if necessary.  The installer now includes `util-linux` so that `nsenter` is available; without it mount/umount requests may be confined to Apache’s private namespace.
- **RAID**: Create and delete MD RAID arrays. The RAID view now resembles the disk panel – a table lists any existing `/dev/md*` arrays (device, level, size and member columns) with a **Create RAID** button positioned above the table on the right. Clicking the button opens a modal dialog containing the familiar creation form.  The server will pipe a `y` to `mdadm --create` to answer prompts automatically (some builds lack any `--yes` option) and uses modern `--metadata=1.2` so bitmaps/metadata are placed at the end of the devices.Clicking on an array row pops up another modal showing `mdadm --detail` output plus buttons for partitioning, formatting, rebuilding, or removing the array. The **Partition** button will switch to the disk‑view interface for that RAID device (the AJAX call sends `raid=1` so partitioning isn’t disabled) allowing you to create/delete partitions just as you would on a normal disk; the other actions submit back to the RAID view as before. Each action is confirmed before submission and returns you to the RAID view. The interface still filters disks to exclude existing `/dev/md*` devices when building a new array, wipes superblocks before creation, and automatically zeros members when removing an array. Individual arrays also offer **Add disk**, **Add spare**, and **Fail/Remove member** buttons – **Add disk** lets you pick an unused disk and start a rebuild (resizing the array), **Add spare** merely attaches a hot‑spare without growing (the spare button is disabled for RAID0 and RAID1 devices), and **Fail/Remove member** marks a specific member as failed and removes it.  Output from `mdadm` is scrubbed to hide routine warnings (e.g. “Unrecognised md component device” or GPT messages) so that only a success‑or‑failure summary is displayed, keeping the UI clean. The add‑disk handler will refuse devices smaller than a single member of the array (not the total array size) – an array made from two 32 GiB drives still only accepts new disks at least 32 GiB in size.  This mirrors mdadm’s own requirement and prevents “Invalid argument” failures.  The interface now also detects the array level and attempts to perform the appropriate `mdadm --grow` operation after adding the disk.  In other words, pressing **Add disk** on RAID0/1/5/6 arrays will automatically call `--add` and then `--grow` with the new device count; for RAID0 it even invokes the single command `mdadm --grow /dev/mdX --raid-devices=N --add /dev/sdY` (mirroring the CLI syntax).  The backend retries with `--force` when mdadm complains about needing a spare, and will wipe/zero the device if residual metadata is detected.  You no longer need to grow manually in nearly all common cases.  Only exotic levels (RAID10, linear, etc.) are still excluded from automated growth. The rebuild button will additionally try to add the first unused disk it can find if you don’t pick one manually; if no spare exists a notice is shown and you can always perform a custom `mdadm --add` yourself. Attempting to remove an array that’s still used as an LVM physical volume will display a friendly warning.
- **Disks**: A new management section lists physical devices in a table; clicking a row opens a pop‑up showing its partition table and status.  You can create/delete partitions, wipe a disk, check SMART, or format a partition via secondary dialogs.  All interactions occur via AJAX and use centralized confirmation/result modals; the interface hides destructive buttons when the device is in use or is a CD/DVD.  (See the code and earlier paragraphs for full implementation details.)

  When a device is part of an MD array or is itself an MD device the partitioning (“Create”/“Delete”) and wipe actions are disabled and a warning is displayed.  Similarly, any drive that’s an LVM PV normally disables those buttons; however if the PV isn’t currently in a volume group the **Wipe disk** button is still shown so you can clear its metadata. SMART/temperature checks and the identify/locate button remain available for all devices. Confirmation dialogs protect destructive operations.

- **LVM**: View and manage physical volumes, volume groups, and logical volumes. The upper‑left pane now shows a single table combining uninitialised disks and existing PVs; each row includes device path, model (RAID devices are labelled “Raid Device”), volume group and size, and disks not yet allocated include a checkbox for creating a new PV. A **Volume groups** button at the top of the page opens a modal window listing all VGs (name, size, free space); the first column contains checkboxes so you can select one or more groups. A single **Extend** button sits between the Create and Remove controls; clicking it when one or more groups are checked opens a dialog showing each chosen VG alongside the pool of unused physical volumes. Tick the PVs you want to add under each group and submit – the server loops through each VG and runs `vgextend`. When you click **Remove Group**, a confirmation modal enumerates the selected VGs and warns that data will be lost; accepting submits the removal. The backend now leaves the underlying physical volumes intact (their LVM metadata is preserved), so they remain visible in the top table and may be assigned to another group later.  Within the VG modal you can also launch a dialog to create a new VG.

  A new **Logical volumes** button sits beside the VG control. Clicking it reveals a modal containing the same information as the standalone logical‑volumes card; action buttons for create, format and remove appear in the footer next to the Close button. The **Create** button spawns a second modal with the familiar create‑LV form. **Format** now presents a dialog listing all selected volumes (checkboxes are provided in the LV table) and offers a filesystem dropdown populated automatically from `/sbin/mkfs.*`; choose the type and submit to format all checked LVs. Remove continues to work on the first chosen volume. All sub‑dialogs confirm before submitting. The list of LVs updates on reload and the parent modal is restored after you close any sub‑dialog.

The logical‑volumes card will expand horizontally as needed to accommodate long names, preventing a nested horizontal scrollbar. Initialize PVs from available disks, create VGs, and carve out LVs (the `lvcreate` command uses `-y -Z y` to erase signatures). Format or delete LVs, with filesystem UUIDs shown when available. Logical volumes must be formatted before mounting via the Mounts view. Removal and formatting actions occur here; details on mount/unmount behaviour are described in the dashboard section above.
- **NFS**: List current exports in a compact table showing only the export directory and the underlying device.  Click any row to open an **Edit export** dialog which displays all clients/options for that export; you can add, modify or delete individual client entries from within the dialog.  A separate **Create export** button above the table launches the familiar add‑export modal where you may enter an optional comment and select a directory before building a new client list.  The “Client entry” modal has been widened so that the many option fields do not spill outside the dialog.  In both the creation form and the **Edit export** dialog the clients/options are chosen via a mixture of radio buttons and drop‑down selectors so mutually exclusive flags cannot be combined.  You select `rw`/`ro` from a dropdown, then choose one of the squash modes, one of sync/async, subtree‑check vs no‑subtree‑check and wdelay vs no‑wdelay from dropdowns; remaining boolean flags (`noaccess`, `crossmnt`, `nohide`) stay as checkboxes, and numeric fields permit anonuid/anongid/fsid.  When an export is created or edited the UI constructs a valid `/etc/exports` line and the backend updates the file and runs `exportfs -ra`.      
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

