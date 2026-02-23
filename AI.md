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

This code only runs on a test server, you can not run the code or check logs on the dev desktop. If you want a log entry ask the Dev to provide it.
The dev can check logs and/or run console commans in the browser. If you need any of that to diagnose an issue ask.
any time you add a new shell command that will require root access, update the install.sh and the README.md sudo lines to include this command.
All js must be in js files, NO inline JavaScript!
All CSS must be in the site CSS file, NO inline CSS!
Always lint your code.
Do not do git functions without being asked.
