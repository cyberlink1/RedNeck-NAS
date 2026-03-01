# Installation Instructions

This document describes how to set up the **RNN web UI** on a host.  You can
install it on a **fresh Debian 13 (Trixie)** system or drop it into an
*existing* web server (any distro) that's already serving PHP.  The
`install-v2.sh` script in the repository handles both cases interactively; it
is the recommended installer.  If you prefer a non‑interactive, Debian‑only
bootstrap the older `install.sh` is also included for reference.

---

## Prerequisites

- A Unix‑like host with root privileges (the installer must run as `root`).
- **Fresh install:** Debian 13 is assumed; package names may differ on other
  distros.
- **Existing web server:** PHP (CLI and apache/nginx module) must already be
  installed and working.  You are responsible for adapting package commands to
your distribution when not on Debian.

The UI requires the following functionality to be callable via `sudo` from the
web server user (`www-data` by default):

- `lvm2`, `mdadm`, `parted`, `exportfs`, `mount`/`umount`, etc.  See the
  `sudoers` snippet written by the installer for the complete list.

In both scenarios the installer creates a supplemental `nfs` group; users
added to this group may log in to the web interface.

---

## 1. Fresh Debian Installation

1. Install a minimal Debian 13 system.
   - During the Debian installer choose **Web server** and **SSH server** only.
   - Do **not** install a desktop environment.

2. Copy the repository contents to the new host (e.g. using `scp` or git).

3. Become root and run the fresh‑install script:
   ```bash
   sudo su -
   cd /path/to/RedNeck-NAS
   ./install-v2.sh
   ```
   - When prompted answer `y` to the "fresh Debian install" question.
   - The script will run `apt-get update` and install the required packages:
     `sudo`, `pamtester`, `lvm2`, `php`/`apache2`, `mdadm`, `util-linux`,
     `parted`, `gdisk`, `smartmontools`, `nfs-kernel-server`, etc.

4. The installer will:
   - Ensure the `www-data` user exists.
   - Create the `nfs` group.
   - Write a `/etc/sudoers.d/nfs-webui` drop‑in giving the web user password‑
     less access to the commands the UI needs.
   - Enable and restart Apache.
   - Deploy the web UI files into `/var/www/html`, removing stock `index.html`
     and extraneous metadata.

5. After the script finishes, add your administrative account to the `nfs`
   group:
   ```bash
   sudo usermod -aG nfs <youruser>
   ```

6. Open a browser and navigate to `http://<host>/`.  Log in with a system
   account that belongs to the `nfs` group.

> **Note:** you can still use the original `install.sh` for a fresh Debian
> install; it performs the same steps but assumes `apache2` is already the web
> server and doesn't ask any questions.

---

## 2. Installing on an Existing NFS/Web Server

If you already have a PHP‑capable web server running, `install-v2.sh` lets you
deploy the UI without touching packages and without enabling Apache.

1. Copy the repository contents to the target machine.

2. Run the installer as root:
   ```bash
   sudo bash install-v2.sh
   ```

3. Answer `n` when asked whether this is a fresh Debian install.  You will be
   prompted for:
   - **Web server document root** (default `/var/www/html`)
   - **Web server account** (default `www-data`)
   - **Sudoers file path** (default `/etc/sudoers.d/nfs-webui`)

4. The script verifies that `php` is available.  If not, install PHP via your
   distribution's package manager and rerun the script.

5. It will show a confirmation message summarising the changes and ask you to
   continue.  After you confirm:
   - The specified user is created if it does not exist.
   - The `nfs` group is created if needed.
   - The sudoers file is appended with the same command list as above (so you
     can run the installer multiple times without clobbering your rules).
   - UI files are copied into the chosen document root; an existing
     `index.html` may be removed if you choose.
   - File ownership is adjusted to the web user.

6. Add any administrative accounts to the `nfs` group as shown earlier.

7. Ensure your web server is running and able to serve PHP pages; test by
   browsing to the install location.

> **Tip:** this mode works with Apache, nginx, or any other server that
> understands PHP; the installer only needs read/write access to the web root
> and permission to write the sudoers file.

---

## 3. Post‑Installation Notes

- **Firewall:** open ports 80/443 (HTTP/HTTPS) and 22 (SSH) as appropriate.
- **Site configuration:** a `config.php` file in the web root controls various
  runtime settings (see `config.php` for full documentation).  Common options
  include:
    * `mount_base` – root directory for exported filesystems (default `/export`)
    * `login_group` – UNIX group allowed to authenticate (default `nfs`)
    * `trusted_proxies`, `proxy_header_*` – configure reverse‑proxy support
    * `cookie_secure` / `base_url` for session and redirect handling
    * `exports_file` (usually `/etc/exports`)
  The installer scripts will create a template `config.php` if none exists and
  update ownership/permissions; the file is git‑ignored so you can safely
  tweak it locally.  When rerunning an installer, take care not to overwrite
  your customised file (back it up first).
- **Samba shares:** if you also want the Samba view, install `samba` and
  restart the web server; the menu appears only when `smbd` is present.
- **Upgrades:** redeploy by copying new files over the web root and ensuring
  the sudoers entries are up to date; rerun `install-v2.sh` if needed.
- **Backup:** the application is stateless; configuration lives in the system
  (e.g. `/etc/exports`, `/etc/fstab`, LVM metadata).

The `install-v2.sh` script includes comments and can be read for more details
or customized to suit different distributions.

---

## Troubleshooting

- **Permission errors:** ensure the web user declared in the sudoers file
  matches the account your server uses (check `ps aux | grep apache` or
  `grep -i user /etc/nginx/nginx.conf`).
- **Missing commands:** the UI will show errors if required utilities are not
  installed; use your package manager to add them.
- **Web server not serving PHP:** create a `<?php phpinfo(); ?>` file in the
  document root and visit it in a browser to verify.

Feel free to consult `install-v2.sh` for a full picture of what the
installer does; it's intentionally short and straightforward.
