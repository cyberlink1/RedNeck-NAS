## RNN FAQ

### 0. I created a Disk Partition/Logical Volume, why does it not show up in the Mount pulldown?

**Q:** I created a Disk Partition or Logical Volume, why does it not show up in the Mount pulldown?  

**A:** You must format it first. The pulldown will only display items that can be mounted, so it has to be formatted before it appears.

---

### 1. What Linux distributions does RNN support?

**Q:** What Linux distributions does RNN support?  

**A:** RNN is Linux-focused. It is officially tested on Debian 13 (Trixie) but can run on other distributions if you adjust the installer or install dependencies manually.

---

### 2. Do I need a desktop environment?

**Q:** Do I need a desktop environment?  

**A:** No. RNN is designed for headless servers or minimal installs with a web server and PHP.

---

### 3. Do I need sudo installed before running the installer?

**Q:** Do I need sudo installed before running the installer?  

**A:** No. On minimal Debian installs, you must run the installer as root using `su -`. The installer will install sudo and other required dependencies.

---

### 4. How does authentication work?

**Q:** How does authentication work\\?  

**A:** RNN uses system users (`/etc/passwd` and `/etc/shadow`). Only users in the `nfs` group can log in.

---

### 5. Where do I change default paths and other settings?

**Q:** Where is the configuration for mount base, proxy headers, etc.?

**A:** Look at `config.php` in the web root.  The installer will drop a
template there with sensible defaults; you can edit it and, if you use git,
add it to `.gitignore` so your site‑specific settings aren’t committed.  Key
settings include `mount_base`, `login_group`, `base_url`, and `exports_file`.
Reloading the page picks up any changes immediately.

---

### 6. Is it safe to expose RNN to the internet?

**Q:** Is it safe to expose RNN to the internet?  

**A:** RNN is intended for trusted environments. It has basic CSRF and XSS protection but should be deployed behind a firewall or VPN and ideally with HTTPS.

---

### 7. Can I restrict specific users from performing destructive actions?

**Q:** Can I restrict specific users from performing destructive actions?  

**A:** Currently, all `nfs` group members have full access. Fine-grained user permissions are not implemented.

---

### 8. Can I create RAID LVs directly through RNN?

**Q:** Can I create RAID LVs directly through RNN?  

**A:** Yes. You can create LVs with RAID levels (0, 1, 4, 5, 6) using LVM’s built-in RAID functionality, as well as convert existing LVs between linear and RAID types.

---

### 9. Does RNN format drives automatically?

**Q:** Does RNN format drives automatically?  

**A:** Only when you explicitly choose the format action. All destructive actions require confirmation dialogs.

---

### 10. Can I manage existing arrays and volumes created outside RNN?

**Q:** Can I manage existing arrays and volumes created outside RNN?  

**A:** Yes. RNN detects existing MD RAID arrays and LVM volumes and allows management, formatting, and removal if appropriate.

**Q:** The "Mounts" page title still reads "Existing /export mounts" even though I’ve set `mount_base` to something else in `config.php`.

**A:** The header is generated dynamically from the current configuration value, so whatever the UI thinks the mount base is will be shown there. If the text doesn’t match what you’ve edited it means the running copy of `config.php` hasn’t been updated (the file in the repository may be different from the one deployed under the web root). Check the web‑server’s `config.php` (the same one that is included by `functions.php`) and make sure `$CONFIG['mount_base']` is set correctly; a mismatch will now also be flagged on the page itself for easier debugging.

---

### 11. Does RNN handle mounting at boot?

**Q:** Does RNN handle mounting at boot?  

**A:** Yes. You can select “Mount at boot” when creating or editing mounts. RNN updates `/etc/fstab` automatically.

---

### 12. When fstab is modified does systemd pick up the changes?

**Q:** After RNN updates `/etc/fstab`, why doesn't `systemctl` recognize the change?  

**A:** The web UI now runs `systemctl daemon-reload` automatically whenever it writes or removes a line, so systemd is immediately aware of new or removed mount units. The installer adds `/bin/systemctl` to the sudoers drop‑in; if you modify sudoers manually, make sure `systemctl` is permitted.

---

### 13. How are NFS exports managed?

**Q:** How are NFS exports managed?  

**A:** You can create, edit, and delete exports. RNN updates `/etc/exports` and runs `exportfs -ra`. Comments associated with export lines are also cleaned up.
---

### 14. How are Samba shares managed\?

**Q:** How are Samba shares managed\?  

**A:** If Samba is installed, a “Samba Shares” view appears. Shares are listed in a table; click a row to open a modal that lets you rename, change the path, add a comment or specify arbitrary share options (key/value pairs such as `read only = yes`, `guest ok = yes`, etc.), or delete the share. New shares are created via a similar dialog. RNN updates `/etc/samba/smb.conf` and restarts `smbd` via `systemctl` after any change. The menu option and dashboard card only appear when the Samba daemon (`smbd`) is present on the host.
---

### 15. Login fails even though my password is correct

**Q:** Login fails even though my password is correct  

**A:** Ensure your user is in the `nfs` group and that the PHP process can access `/etc/shadow` via sudo. See the README for sudoers setup.

---

### 16. RNN cannot see my disks or arrays

**Q:** RNN cannot see my disks or arrays  

**A:** Make sure all dependencies (`lsblk`, `mdadm`, `lvm2`, etc.) are installed and included in sudoers for passwordless execution.

---

### 17. I get permission errors when creating LVs or arrays

**Q:** I get permission errors when creating LVs or arrays  

**A:** RNN uses `sudo -n` for destructive commands. Ensure the relevant commands are listed in `/etc/sudoers` with the correct paths.

---

### 18. How large is RNN?

**Q:** How large is RNN?  

**A:** The deployed footprint is only about 340K, small enough to fit on a 5.25 inch floppy.

---

### 19. Can RNN run without a database or background services?

**Q:** Can RNN run without a database or background services?  

**A:** Yes. RNN is lightweight and fully PHP + standard Linux utilities.

---

### 20. Can I customize the interface?

**Q:** Can I customize the interface?  

**A:** Yes. CSS lives in `assets/css/style.css` and JavaScript has been split between `assets/js/functions.js` (shared helpers) and per-view files such as `disks.js`, `raid.js`, `lvm.js`, `mounts.js`, `nfs.js` and `samba.js`. The old `app.js` is now deprecated.