# Frieren on Linux

The Linux backend targets Kali and Debian-family systems using systemd, NetworkManager, and apt. It keeps the existing React panel and API while replacing OpenWrt-specific system access with native Linux operations. OpenWrt remains the default platform.

## Development Setup

Install PHP 8.1 or newer with `sqlite3`, `curl`, `session`, and `posix` support, Node.js 22 or newer, and Corepack/Yarn. Optional host integrations use `NetworkManager`, `iproute2`, `iw`, `wireless-tools`, `traceroute`, `dnsutils`, `usbutils`, and `ttyd`.

From the repository root, install the frontend dependencies and run the local API and Vite servers in separate terminals:

```sh
cd frieren-front
corepack yarn install
VITE_DEV_PROXY_TARGET=http://127.0.0.1:8000 corepack yarn dev --host 127.0.0.1
```

```sh
cd frieren-back
FRIEREN_SYSTEM_FAMILY=Linux \
FRIEREN_MODULE_EXTRA_ROOT="$PWD/../../frieren-modules" \
FRIEREN_USER=your-linux-user \
FRIEREN_PASSWORD_HASH='your-password_hash-output' \
FRIEREN_CONFIG_FILE="$HOME/.config/frieren/config.json" \
FRIEREN_DATA_ROOT="$HOME/.local/share/frieren" \
php -S 127.0.0.1:8000 -t .
```

Open the URL printed by Vite. Generate `FRIEREN_PASSWORD_HASH` with PHP's `password_hash()` and keep the hash in a user-readable-only environment file for long-running services. There is no default Linux password. Do not expose the PHP development server to a network; use a properly secured web server and TLS for remote access.

`FRIEREN_SYSTEM_FAMILY=Linux` selects the Linux helpers. `FRIEREN_MODULE_ROOT` can override the writable module-install directory, `FRIEREN_MODULE_EXTRA_ROOT` can add one or more read-only module source roots (colon-separated on Linux), and `FRIEREN_DATA_ROOT` selects the writable directory for captures and other module data. `FRIEREN_CONFIG_FILE` selects the private JSON file used for Frieren-only panel preferences. If unset, data and preferences live under `$XDG_DATA_HOME/frieren` / `$XDG_CONFIG_HOME/frieren`, or their respective `$HOME/.local/share/frieren` and `$HOME/.config/frieren` defaults.

### Companion Modules

Clone `xchwarze/frieren-modules` next to the Frieren checkout, then set `FRIEREN_MODULE_EXTRA_ROOT=/path/to/frieren-modules` on the PHP server. Frieren resolves modules from the companion repository's `module/public/` layout without copying them into the writable install directory. In this Linux port, `demo`, `hcxdumptool`, `nmap`, `tcpdump`, `wigle`, and `wpaonlinecrack` declare Linux support. Nmap/XML views need `php-xml`; hcxdumptool capture requires a compatible monitor-mode adapter and suitable privileges. `dnsspoof`, `proxyhelper`, and `usbstorage` remain OpenWrt-only because they directly manage dnsmasq/UCI, host-wide router firewall policy, or OpenWrt mount configuration.

To expose a companion module through dynamic routing, build its UMD bundle so `dist/module.umd.js` exists. From the module's directory, install its locked dependencies and point its common alias at the Frieren frontend source:

```sh
corepack yarn install --immutable
VITE_COMMON_ALIAS=/path/to/frieren/frieren-front/src corepack yarn build
```

The Vite development server serves those built bundles from the extra module root. A production web server should publish the same bundle at `/modules/<module-name>/module.umd.js` alongside the configured PHP extra root.

## Linux Feature Mapping

- Dashboard statistics and system details read Linux `/proc`, `/sys`, and `/etc/os-release`.
- Services and logs use systemd and `journalctl`; USB and filesystem views use `lsusb` and `df`.
- Network profiles and station/AP Wi-Fi use NetworkManager; AP profiles use NetworkManager shared-hotspot mode. Diagnostics use Linux networking tools.
- Package inventory uses dpkg/apt. Install, remove, system-setting changes, and service control require suitable host permissions. Apt background actions use `sudo -n`; configure narrowly scoped noninteractive sudo access before enabling them.
- Terminal access uses `ttyd` on `127.0.0.1:5001` by default, limiting it to the local browser. Install `ttyd` separately if needed.
- Module discovery and Frieren panel preferences work from the checkout. The OpenWrt firmware updater is disabled on Linux.

Linux is not a router platform, so DHCP server leases/static reservations, monitor mode, OpenWrt radio controls, raw UCI wireless editing, SD-card module installation, and OpenWrt firmware updates are not implemented. AP mode uses a private shared hotspot rather than an OpenWrt bridge/network zone. Unsupported changes fail safely or return empty lists; they do not write OpenWrt configuration files or modify unrelated NetworkManager profiles.

## Security and Permissions

Frieren exposes privileged host controls. Keep it bound to loopback for development. For deployment, use a dedicated service account, TLS, restricted access, and carefully scoped polkit/sudo rules; do not run a publicly reachable PHP/Vite development server or grant broad `NOPASSWD: ALL`. Changes made through systemd, NetworkManager, hostname/time settings, package operations, shutdown, or reboot affect the Linux host itself.