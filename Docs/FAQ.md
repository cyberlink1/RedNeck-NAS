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

**Q:** How does authentication work?  

**A:** RNN uses system users (`/etc/passwd` and `/etc/shadow`). Only users in the `nfs` group can log in.

---

### 5. Is it safe to expose RNN to the internet?

**Q:** Is it safe to expose RNN to the internet?  

**A:** RNN is intended for trusted environments. It has basic CSRF and XSS protection but should be deployed behind a firewall or VPN and ideally with HTTPS.

---

### 6. Can I restrict specific users from performing destructive actions?

**Q:** Can I restrict specific users from performing destructive actions?  

**A:** Currently, all `nfs` group members have full access. Fine-grained user permissions are not implemented.

---

### 7. Can I create RAID LVs directly through RNN?

**Q:** Can I create RAID LVs directly through RNN?  

**A:** Yes. You can create LVs with RAID levels (0, 1, 4, 5, 6) using LVM’s built-in RAID functionality, as well as convert existing LVs between linear and RAID types.

---

### 8. Does RNN format drives automatically?

**Q:** Does RNN format drives automatically?  

**A:** Only when you explicitly choose the format action. All destructive actions require confirmation dialogs.

---

### 9. Can I manage existing arrays and volumes created outside RNN?

**Q:** Can I manage existing arrays and volumes created outside RNN?  

**A:** Yes. RNN detects existing MD RAID arrays and LVM volumes and allows management, formatting, and removal if appropriate.

---

### 10. Does RNN handle mounting at boot?

**Q:** Does RNN handle mounting at boot?  

**A:** Yes. You can select “Mount at boot” when creating or editing mounts. RNN updates `/etc/fstab` automatically.

---

### 11. When fstab is modified does systemd pick up the changes?

**Q:** After RNN updates `/etc/fstab`, why doesn't `systemctl` recognize the change?  

**A:** The web UI now runs `systemctl daemon-reload` automatically whenever it writes or removes a line, so systemd is immediately aware of new or removed mount units. The installer adds `/bin/systemctl` to the sudoers drop‑in; if you modify sudoers manually, make sure `systemctl` is permitted.

---

### 12. How are NFS exports managed?

**Q:** How are NFS exports managed?  

**A:** You can create, edit, and delete exports. RNN updates `/etc/exports` and runs `exportfs -ra`. Comments associated with export lines are also cleaned up.

---

### 12. Login fails even though my password is correct

**Q:** Login fails even though my password is correct  

**A:** Ensure your user is in the `nfs` group and that the PHP process can access `/etc/shadow` via sudo. See the README for sudoers setup.

---

### 13. RNN cannot see my disks or arrays

**Q:** RNN cannot see my disks or arrays  

**A:** Make sure all dependencies (`lsblk`, `mdadm`, `lvm2`, etc.) are installed and included in sudoers for passwordless execution.

---

### 14. I get permission errors when creating LVs or arrays

**Q:** I get permission errors when creating LVs or arrays  

**A:** RNN uses `sudo -n` for destructive commands. Ensure the relevant commands are listed in `/etc/sudoers` with the correct paths.

---

### 15. How large is RNN?

**Q:** How large is RNN?  

**A:** The deployed footprint is only about 340K, small enough to fit on a 5.25 inch floppy.

---

### 16. Can RNN run without a database or background services?

**Q:** Can RNN run without a database or background services?  

**A:** Yes. RNN is lightweight and fully PHP + standard Linux utilities.

---

### 17. Can I customize the interface?

**Q:** Can I customize the interface?  

**A:** Yes. CSS and JS are in `assets/css/style.css` and `assets/js/app.js`. You can modify them or add themes.