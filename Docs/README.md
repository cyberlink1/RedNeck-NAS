## RNN (RedNeck NAS)

![Dashboard](/Screen%20Shots/Dashboard.png)

RNN is a lightweight web control plane for Linux storage.

It does not maintain its own configuration database.
It reads the live system state and executes native Linux
commands to manage:

  - MD RAID (mdadm)
  - LVM (PV, VG, LV, thin pools, snapshots)
  - Disk partitioning and wiping
  - Filesystems (mkfs, mount, umount)
  - /etc/fstab entries
  - NFS exports (/etc/exports)
  - Samba shares (/etc/samba/smb.conf)  # menu appears only when Samba is installed; share options (read only, guest ok, etc.) can be set in the create/edit dialog; click a share to edit/remove (modal dialogs)

The system itself remains the single source of truth.
If something changes via SSH, the UI reflects it immediately.

## Why I Built RNN

I’ve worked in IT for over 35 years as a Linux administrator and engineer.

Over that time I’ve repeatedly needed a simple NAS solution, both for my home lab and for small development teams.

The typical options usually fall into three categories.

### NAS Appliances (TrueNAS, Unraid, etc.)

NAS appliances are powerful and polished. They solve a lot of problems and they solve them well.

For small environments, though, they can feel heavier than necessary. They introduce their own management layers, abstractions, and operational patterns. That is useful in larger or more structured environments, but sometimes it is more than the situation requires.

### Admin Panels (Cockpit, Webmin)

General administration tools work and are useful.

The issue is that they are not built around a storage workflow. You can manage disks, RAID, LVM, mounts, and exports, but it feels like you are navigating system utilities rather than working through a cohesive NAS process.

They expose functionality. They do not guide the storage lifecycle.

### The Command Line

For most experienced Linux administrators, the command line is the default solution:

- `mdadm`
- `lvm2`
- `mount`
- `/etc/exports`
- Samba shares (`/etc/samba/smb.conf`) (requires installing Samba manually)


It works. It is reliable. It is usually the right answer.

However, there are situations where a web interface makes sense:

- A small dev team needs limited control
- You want to avoid constant storage requests
- A home lab needs quick adjustments
- You want visibility without SSH access

In those cases, handing someone shell access is not always ideal.

## The Gap RNN Fills

There is a gap between full NAS appliances and raw command-line tooling.

RNN is designed to sit in that space.

It provides:

- RAID management
- LVM management
- Mount control
- NFS exports

Without adding:

- A database
- A background service layer
- A new storage abstraction
- An opinionated operating system model

It does not replace Linux storage tools. It provides a clean, workflow-focused interface on top of them.

## The Goal

RNN is not trying to compete with enterprise NAS platforms.

It is built for:

- Home labs
- Small dev teams
- Internal storage servers
- Engineers who already understand Linux

It gives you NAS functionality without appliance-level complexity.

Sometimes you do not need a storage distribution.

You just need a straightforward web interface on top of Linux.

## Quick Start
Install Debian 13 (Trixie)

During installation:
   - ❌ Uncheck Desktop Environment
   - ❌ Uncheck GNOME
   - ✅ Select Web Server
   - ✅ Select SSH Server

Finish installation and reboot.

- SCP repo contents to server.
- Debian minimal does not install sudo by default.
- su -
- cd /home/(username where you uploaded repo)
- ./install-v2.sh
- After the web UI is deployed a `config.php` file will exist in the document root.  Edit this file to adjust mount base, login group, proxy settings, base
URL, etc.  
- usermod -aG nfs youruser
- optionally install Samba (`apt install samba`) if you want the Samba Shares view (menu appears only when `smbd` is present); you can configure share options via the UI
- open browser to http://server-ip/

The install.sh is less than 100 lines and designed to run on Debian 13 Trixie. Though the only changes needed to run on another distro is the package names and package managment install. 