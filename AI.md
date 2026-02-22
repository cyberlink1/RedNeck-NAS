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

- `login.php`, `dashboard.php`, `logout.php`: user-facing pages. `login.php` displays error messages gathered from `functions.php`; `dashboard.php` provides navigation and now acts as the container for the various feature *views*. When no view parameter is given the dashboard displays summary cards showing current logical volumes and exports instead of the old welcome banner. The navbar order has been updated to RAID, LVM, Mounts, NFS Exports to reflect the separated RAID functionality. The application branding has been changed — the navbar title and HTML `<title>` now read "RNN (Red Neck NAS)" instead of the earlier "Storage Admin."

  The actual functionality previously split across `lvm.php`, `nfs.php` and `mounts.php` has been moved into the `views/` directory. Each view is included by `dashboard.php` based on a `?view=` query parameter (e.g. `dashboard.php?view=lvm`). The old standalone pages still exist but simply redirect to the appropriate dashboard view and are considered deprecated.

- `lvm.php`: main LVM UI (implementation now in `views/lvm.php`). The legacy `lvm.php` simply forwards to `dashboard.php?view=lvm`; RAID-specific functionality has been split out to its own view. The LVM view contains logic to list and manage disks, PVs, VGs, LVs and associated operations (initialize PVs, create/remove VGs/LVs, format or delete LVs). When adding features update both the form portions and the POST handlers at the top of the view file. Disk‑enumeration helpers such as `list_disks()` are still shared, as the LVM view needs them for PV initialization.
- `disks.php` (new): redirect to dashboard `?view=disks`. The real implementation is in `views/disks.php`, which provides a disk‑focused interface: listing disks with model/serial, viewing partition table, creating/deleting partitions (via `parted`), wiping disks (GPT + superblocks with `sgdisk`/`wipefs`), displaying SMART health/temperature, and issuing an identify/locate signal by writing to `/sys/block/.../device/locate` if available. The listing is now a table showing device path, name/model, size and a status column (member of RAID/LVM with actual `/dev/mdN` names or the OS disk); the previous dropdown has been replaced for easier scanning.  Clicking a row brings up a modal populated via AJAX with the relevant card set (partition table, other actions) for that disk; the modal is effectively a preview and does not automatically reload the page.  All action buttons now appear in a single row in the footer of the info modal, alongside the Close button – previous implementations used separate cards for “Partition Operations” and “Other Actions”.  The Create Partition button becomes disabled once the disk contains four partitions; the server also refuses any further creation requests with a warning message.  When the size modal is submitted the AJAX helper takes care of updating the preview directly, so the earlier double‑refresh bug (where the hidden‑event handler also triggered a second fetch) has been removed.  The command now computes the start offset in MiB while leaving the user‑supplied size string untouched – this lets you continue to type `1G`/`500M` etc. without confusing `parted`.  Before invoking `parted` the code also checks the disk’s remaining space (queried via `lsblk`) and will reject sizes that would exceed what’s left, producing a friendly warning rather than the “end before start” error.  When the size string is parsable we also compute the actual end as start+size in MiB and pass that explicit MiB endpoint to `parted`, eliminating rounding/interpretation mismatches that used to cause the error.  The partition length itself is no longer required to exceed the start offset – only that it be a positive number and not larger than the remaining space.  Partition creation and deletion are invoked through separate submodals rather than inline forms.  The delete dialog now presents a pull‑down list of existing partitions (number plus full info line) fetched from the server; the format dialog uses the same list and also lets you choose a filesystem type from a dropdown.  The disk details view shows **Create Partition** and **Delete Partition** buttons (the latter only if the disk has existing partitions); clicking either hides the info modal and opens a smaller dialog where you supply either a size (`1G`, `500M`, `2T`, etc.) or the partition number.  These submodals submit via AJAX and, on completion, hide themselves and refresh the info modal contents so the user can continue working.  Confirmation prompts still protect destructive actions.  The preview is shown in a dedicated `infoModal` so it doesn’t conflict with the confirmation modal used elsewhere.  All forms in the modal submit via AJAX (the JS intercepts the submit event, posts with `ajax=1` and replaces the modal body with the response) so taking an action updates the popup rather than appending content to the main disks listing.  The AJAX handler will attempt to create a partition directly; if `parted` complains about an “unrecognised disk label” the view will create a GPT label on the fly and retry.  The request is sent directly to `views/disks.php?ajax=1&disk=<device>` so the dashboard header is not included.  The PHP view now accepts GET for the selected disk and requires `functions.php`/login when invoked standalone.  The JS event handler lives in `assets/js/app.js` and is bound unconditionally (it no longer returns early if a `raidForm` is absent).  Confirmation dialogs triggered from inside the preview modal wait for the info popup to finish hiding before appearing, so they no longer get lost behind the preview or leave the screen greyed out.  Be careful with braces in `app.js` – an earlier missing `}` caused a syntax error when the modal attempt executed, resulting in `Unexpected token ')'` at the end of the DOMContentLoaded handler. The table still omits CD‑ROM/loop/partition devices (and permanently filters out `/dev/sr*` optical drives) but includes MD arrays; the view also detects if the selected device is part of an MD array, is itself an MD device, or is already an LVM PV and disables partitioning/wipe actions in those cases (a warning message is shown). Add any new action forms and corresponding POST handlers here. The dashboard navigation now includes a "Disks" entry.
- `raid.php`: new page that used to be part of the combined LVM/RAID UI. It now redirects to `dashboard.php?view=raid`. The real implementation lives in `views/raid.php`, which handles RAID creation and removal exclusively. When updating raid functionality modify that view and ensure the redirect file is kept for compatibility.

- `nfs.php`: NFS export management; now handled by `views/nfs.php` and accessed via the dashboard (legacy `nfs.php` redirects). Simple form to append/remove lines in `/etc/exports` and reload via `exportfs`.

- `mounts.php`: new page for mounting logical volumes (the view is now in `views/mounts.php`). Similar structure to `lvm.php` but only handles `mount`/`umount` requests; populates selectors from `lvs` and the current `/export/*` mount list. The code now filters out the standalone `(exit N)` lines produced by `grep` so the dropdown and any summaries don’t display that spurious output when no mounts exist. Requires sudo permissions for `mount`, `umount`, `mkdir` (and optionally `rmdir`). It includes the global confirmation modal and loads `assets/js/app.js` just like the other pages so messages appear as popups; don’t forget to add those snippets if you copy the page elsewhere.

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
  adding support for `pvremove` or disk utilities), update this script accordingly.  The new disk view requires `parted`, `gdisk`/`sgdisk`, `smartmontools` and
  `wipefs` packages; these are added along with the appropriate sudoers entries.

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