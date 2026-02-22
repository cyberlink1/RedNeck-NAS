# AI Instructions and Project Overview

This document is intended for the AI assistant and future maintainers to
understand the purpose of the files in this workspace, how they interact, and
which ones need to be kept in sync when changes are made.

## Project Purpose

The repository implements a simple web-based NAS management interface using PHP
and JavaScript. It allows authenticated system users to:

- view and manipulate LVM physical volumes, volume groups, logical volumes and
  MD RAID arrays
- initialize raw disks as LVM physical volumes
- remove logical volumes and volume groups
- format logical volumes with arbitrary filesystems
- manage NFS exports (add/remove lines in `/etc/exports`)

Authentication is performed against `/etc/shadow` using `getent` and various
helpers (Python/Perl/PAM). The web UI runs under a web server user (e.g.
`www-data`) with limited `sudo` privileges.

## Key Files

### PHP pages

- `functions.php`: utility functions for authentication and running shell
  commands. Also handles logging and error reporting. This file must include any
  changes to how commands are executed (e.g. `sudo -n`) and the helpers used for
  authentication.

- `login.php`, `dashboard.php`, `logout.php`: user-facing pages. `login.php` displays error messages gathered from `functions.php`; `dashboard.php` provides navigation and now acts as the container for the various feature *views*.

  The actual functionality previously split across `lvm.php`, `nfs.php` and `mounts.php` has been moved into the `views/` directory. Each view is included by `dashboard.php` based on a `?view=` query parameter (e.g. `dashboard.php?view=lvm`). The old standalone pages still exist but simply redirect to the appropriate dashboard view and are considered deprecated.

- `lvm.php`: main LVM/RAID UI. (the implementation now lives in `views/lvm.php`; the legacy `lvm.php` file simply forwards to `dashboard.php?view=lvm`). Contains logic to list and manage disks, PVs, VGs, LVs, and RAIDs. When adding features (e.g. format LV, remove VG) update both the form portions and the POST handlers at the top of the view file. The RAID create/remove handlers now automatically zero superblocks on devices; if you introduce other operations that modify underlying devices remember to keep metadata cleanup in mind.  The RAID creation form itself now builds a drop-down of the first five unused `/dev/mdX` names and offers named level choices (Striped, Mirrored, RAID5, RAID6) instead of raw numbers.  Attempting to remove an array that is currently used as an LVM PV will now produce a friendly warning telling you to remove LVs/VGs first; the PHP code does a quick `pvs` check before calling `mdadm` and skips the stop/remove entirely until the PV is gone.  (The earlier auto‑wipe attempt could still leave the VG active and produce a "Cannot get exclusive access" error.)  The UI now always appends a "RAID array /dev/mdX removed successfully" message once the operation completes.  LV creation has been hardened too—`lvcreate` is called with `-y -Z y`, which wipes any old filesystem signatures and zeroes the start of a fresh volume.  Formatting LVs now returns only a small success/failure message instead of dumping raw `mkfs` output; the code first scans the mkfs output for a UUID and only invokes `/usr/sbin/blkid` as a fallback.  Any "sudo: a password is required" messages or `(exit N)` status lines are ignored.  A new section of the UI lets the user mount a selected logical volume under `/export/<name>` (creating the directory first) or unmount an existing `/export/*` mount; this requires `mount`, `umount`, `mkdir` (and optionally `rmdir`) in sudoers.  The create logic also filters out a handful of benign mdadm warnings (e.g. “Unrecognised md component device”, “Defaulting to version …”) so users aren’t confused by harmless output. Keep the helper functions (`list_disks`, etc.) in sync with new command usage.

- `nfs.php`: NFS export management; now handled by `views/nfs.php` and accessed via the dashboard (legacy `nfs.php` redirects). Simple form to append/remove lines in `/etc/exports` and reload via `exportfs`.

- `mounts.php`: new page for mounting logical volumes (the view is now in `views/mounts.php`). Similar structure to `lvm.php` but only handles `mount`/`umount` requests; populates selectors from `lvs` and the current `/export/*` mount list. Requires sudo permissions for `mount`, `umount`, `mkdir` (and optionally `rmdir`). It includes the global confirmation modal and loads `assets/js/app.js` just like the other pages so messages appear as popups; don’t forget to add those snippets if you copy the page elsewhere.

### Assets

- `assets/css/style.css`: additional CSS. Mostly minimal; modifications here
  should complement Bootstrap styling in the HTML pages. **All custom
  styling must go here; there should never be inline `<style>` blocks or
  `style="…"` attributes in the PHP pages.**

- `assets/js/app.js`: JavaScript helpers for UI behaviour (e.g. RAID device
  parsing). Update when new client-side interactions are needed. **All
  executable JavaScript belongs in this file (or other JS modules you add);
  pages should not contain inline `<script>` tags with logic or event
  handlers.**

### Scripts

- `deploy.sh`: copies the workspace to a remote host via `rsync`. If new files
  are added to the project, ensure `--exclude` patterns remain appropriate.

- `install.sh`: bootstraps a fresh Debian host by installing required packages
  and writing a sudoers drop-in. When the set of sudo commands changes (e.g.
  adding support for `pvremove`), update this script accordingly.

### Documentation

- `README.md`: user-facing documentation. It describes installation,
  requirements, debugging tips, and sudoers configuration. When adding new
  features or commands, update this README in parallel with code changes so the
  instructions remain accurate.

- `AI.md`: this file (you are reading it) contains instructions for the AI to
  understand the repository layout and what to maintain.

## Updating Guidelines

Whenever functionality is added or changed:

1. **Code first**: implement the PHP/JS changes, test manually or via deploy.
   *Remember that frontend tweaks belong in the central CSS/JS assets, not
   inline in the page.*
2. **Update sudoers**: if new shell commands are invoked with `sudo`, add them to
   both the README example and `install.sh` (the `/etc/sudoers.d/` template).
3. **Update UI**: add new form elements or pages and corresponding handlers.  When implementing a new menu item it should be created as a view under `views/` and pulled in by `dashboard.php`; update the nav links there and deprecate any previous standalone page.
4. **Adjust scripts**: modify `deploy.sh` excludes or `install.sh` packages if
   new dependencies are required.
5. **Document**: edit `README.md` with usage instructions, debugging notes,
   and any new requirements.
6. **Reflect in AI.md**: if the change introduces new file types or behaviours,
   add a brief note here to keep the AI aware.

### Example

- Added LV formatting action:
  - Modified `lvm.php` (POST handler and form). Added `mkfs` to sudoers.
  - Updated README to mention `mkfs` and new UI instructions.
  - Updated `install.sh` to include `/usr/bin/mkfs`.
  - Added description in this `AI.md` section.

Keeping the documentation and scripts in sync ensures that future humans and
automation understand the intended configuration and behaviour of the system.