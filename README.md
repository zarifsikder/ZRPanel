<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=12,20,24&height=200&section=header&text=ZRPanel&fontSize=80&fontColor=ffffff&animation=fadeIn&fontAlignY=38&desc=A%20full%20cPanel%20%2B%20WHM%20style%20hosting%20panel%20for%20Android&descAlignY=58&descSize=18" width="100%"/>

# ⚡ ZRPanel

### **A full cPanel + WHM–style hosting control panel that runs on your Android phone.**

Everything installs in one command — MariaDB, the PHP panel, the File Browser
file manager and a Cloudflare tunnel for a public HTTPS URL.

<br/>

[![PHP](https://img.shields.io/badge/PHP-8+-777bb4?logo=php&logoColor=white&style=for-the-badge)](https://php.net)
[![MariaDB](https://img.shields.io/badge/DB-MariaDB-4479a1?logo=mariadb&logoColor=white&style=for-the-badge)](https://mariadb.org)
[![Termux](https://img.shields.io/badge/runs-Termux%2FAndroid-orange?logo=android&logoColor=white&style=for-the-badge)](https://termux.dev)
[![Cloudflare](https://img.shields.io/badge/Cloudflare%20Tunnel-f38020?logo=cloudflare&logoColor=white&style=for-the-badge)](https://www.cloudflare.com/products/tunnel/)
[![Install](https://img.shields.io/badge/install-curl%20%7C%20bash-0e63be?style=for-the-badge&logo=gnubash&logoColor=white)](https://raw.githubusercontent.com/zarifsikder/zenpanel/main/install.sh)

<br/>

```
Requires: Android 8+ · Free · No cloud server · No monthly fees
```

</div>

---

<div align="center">

## 🚀 Quick Install

</div>

Open **Termux** and paste:

```bash
curl -fsSL https://raw.githubusercontent.com/zarifsikder/zenpanel/main/install.sh | bash
```

> **It installs the runtimes, clones the panel, creates the `panel` database with
> all tables, seeds your WHM admin account, installs the file manager and starts
> everything.**

> [!TIP]
> Done? Open **http://localhost:8080/whm** and log in with the admin credentials
> the installer prints at the end.

---

<div align="center">

## 🔄 Update

</div>

Keep the panel on the latest version from anywhere:

```bash
curl -fsSL https://raw.githubusercontent.com/zarifsikder/zenpanel/main/update.sh | bash
```

<details>
<summary>Already installed? Just run the updater on-device instead.</summary>

```bash
~/storage/downloads/hosting/update.sh
```

</details>

> The updater pulls the latest code, syncs the database schema, re-removes the
> installer-only files (`install.sh`, `README.md`) and restarts things if they
> were stopped. Your `config.local.php`, `tunnel_data/` and `user_data/` are
> never touched.

---

<div align="center">

## 🏗️ Architecture

```mermaid
flowchart LR
    T[📱 Android · Termux] --> P[⚙️ ZRPanel PHP :8080]
    P <--> D[(🗄️ MariaDB)]
    P --> F[📁 File Browser :8090]
    P -.-> E{☁️ cloudflared}
    E <--> C[🌐 Cloudflare Edge]
    C --> W[🔒 HTTPS public hostname]

    style T fill:#1f2328,color:#fff,stroke:#7c5cff,stroke-width:2px
    style P fill:#777bb4,color:#fff,stroke:#7c5cff,stroke-width:2px
    style D fill:#4479a1,color:#fff,stroke:#22d3ee,stroke-width:2px
    style F fill:#1a7f37,color:#fff,stroke:#22d3ee,stroke-width:2px
    style E fill:#f38020,color:#fff,stroke:#f38020,stroke-width:2px
    style C fill:#f6821f,color:#fff,stroke:#f38020,stroke-width:2px
    style W fill:#0e63be,color:#fff,stroke:#0e63be,stroke-width:2px
```

</div>

---

<div align="center">

## 📖 Table of Contents

</div>

| | Section | | Section |
|:---:|:---|:---:|:---|
| 🚀 | [Quick install](#-quick-install) | 🧩 | [Repository layout](#-repository-layout) |
| 🏗️ | [Architecture](#️-architecture) | 🔒 | [Security notes](#-security-notes) |
| ✨ | [Features](#-features) | 🌐 | [Cloudflare setup](#-cloudflare-setup) |
| 🧰 | [Requirements](#-requirements) | 🔑 | [Env vars](#-env-vars) |
| 📦 | [Install](#-install) | ▶️ | [Start / stop](#️-start--stop) |

---

<div align="center">

## ✨ Features

</div>

<table>
<tr>
  <th align="center" width="33%">👤 &nbsp; Client Panel</th>
  <th align="center" width="33%">🛠️ &nbsp; Admin Panel</th>
  <th align="center" width="33%">🔌 &nbsp; Extras</th>
</tr>
<tr>
<td valign="top">

- 🌐 Domains, subdomains, addon domains
- 🧭 DNS manager + track DNS
- 🔐 SSL/TLS & custom certificates
- 💾 Backups & restore, FTP, cron
- 🐘 PHP version + `php.ini` editor
- 🚫 IP blocker, ModSecurity, malware
- 🛡️ Directory protection, error pages
- 🔑 2FA, password security, bandwidth
- ⚛️ WP Toolkit · Node.js · Python apps

</td>
<td valign="top">

- 👥 Accounts, packages, suspend
- 📊 Server health & resource monitor
- 🖥️ System editor
- 🌩️ **Server tunnel manager** — change
  the Cloudflare token & tunnel domain live
- 🎛️ Per-customer tunnel control
- 🔑 API keys
- 🔄 Reset admin password anytime

</td>
<td valign="top">

- 🚀 **Web Apps** — deploy AI-generated
  React/TS projects (ZIP, git, or dir)
- 📁 **File Manager** — `/file-manager`,
  KODExplorer, behind panel login
- 🌐 **Cloudflare DNS** — records, proxied
  CNAME to tunnel, TLS
- 🔌 **Public REST API** `/api/v1` with keys
- 🎚️ **/features** runtime switches
- 💻 Secret terminal · maintenance mode

</td>
</tr>
</table>

---

<div align="center">

## 🧰 Requirements

</div>

<table>
<tr>
<td width="60" align="center">📱</td>
<td>

**Android 8+** with [Termux](https://f-droid.org/packages/com.termux/) and the
`termux-setup-storage` permission (shares `~/storage/downloads`).

</td>
</tr>
<tr>
<td align="center">☁️</td>
<td>

A **Cloudflare account** — only needed for the public tunnel / DNS.

</td>
</tr>
</table>

---

<div align="center">

## 📦 Install

</div>

The one-liner above runs `install.sh`, which:

```mermaid
graph LR
    A[1️⃣ Install packages] --> B[2️⃣ Clone repo]
    B --> C[3️⃣ Setup MariaDB]
    C --> D[4️⃣ Generate secrets]
    D --> E[5️⃣ Write launchers]
    E --> F[6️⃣ Start everything]

    style A fill:#7c5cff,color:#fff
    style B fill:#8b5cf6,color:#fff
    style C fill:#22d3ee,color:#000
    style D fill:#f472b6,color:#fff
    style E fill:#f38020,color:#fff
    style F fill:#22c55e,color:#fff
```

1. `pkg install`s **PHP, MariaDB, cloudflared**, git & helpers; downloads the
   official **File Browser** binary for your CPU.
2. Clones the repository into `~/storage/downloads/hosting`.
3. Starts **MariaDB**, creates the **`panel`** database and builds the schema —
   all tables **and** the **WHM admin account** in one shot.
4. Generates per-install secrets into the git-ignored `config.local.php`
   (Cloudflare token/zone/tunnel, `/features` admin password, shell key).
5. Writes the **`~/start`** / **`~/off`** launchers and starts everything.

> [!NOTE]
> You may be asked to paste your **Cloudflare tunnel token** — press Enter to
> skip, then add it later from **WHM → Cloudflare Tunnels** or the CLI below.

---

<div align="center">

## ▶️ Start / Stop

</div>

```bash
~/start    # start MariaDB + panel + File Browser + tunnel (idempotent)
~/off      # stop everything the launcher started
```

### 🌍 Access Points

| Access | URL | Status |
|:---|:---|:---:|
| 🖥️ Locally | `http://localhost:8080` | ![Live](https://img.shields.io/badge/-live-22c55e?style=flat-square) |
| 🔐 WHM admin | `http://localhost:8080/whm` | ![Live](https://img.shields.io/badge/-live-22c55e?style=flat-square) |
| 🌐 Publicly | your Cloudflare hostname | ![Tunnel](https://img.shields.io/badge/-tunnel-f38020?style=flat-square) |

### 🔑 First Login

1. Open **http://localhost:8080/whm** — the installer already created your
   **admin account** and printed the credentials at the end.
2. Reset it anytime: **WHM → Accounts → Reset Password**.
3. Create your first customer account, package and domain.
4. Point the customer's DNS at the tunnel CNAME or the server IP.

> [!TIP]
> Set your own admin credentials at install time with `ZENPANEL_ADMIN_USER` /
> `ZENPANEL_ADMIN_PASS`.

---

<div align="center">

## 🌐 Cloudflare Setup

</div>

Provide these when installing (or later via **WHM → Cloudflare Tunnels →
Server Tunnel**):

| 🔑 Value | 📖 How to get it |
|:---|:---|
| `CF_API_TOKEN` | Cloudflare dashboard → My Profile → API Tokens (Zone: DNS:Edit) |
| `CF_ZONE_ID` | Cloudflare dashboard → your domain → Overview |
| `CF_TUNNEL_ID` | `cloudflared tunnel list` |
| Tunnel connector token | `cloudflared tunnel token <name>` → save to `tunnel_data/panel/connector.token` |
| `SITE_DOMAIN` | public hostname the panel answers on (e.g. `panel.example.com`) |

> [!TIP]
> The **token** and **tunnel domain** can be changed from **WHM → Cloudflare
> Tunnels → Server Tunnel** at any time — it restarts cloudflared with the new
> credentials and stores the domain in the database **and** `config.local.php`.

Have a token already? Skip the install prompt and wire it in now:

```bash
echo -n "$YOUR_TUNNEL_TOKEN" > ~/storage/downloads/hosting/tunnel_data/panel/connector.token
~/start
```

---

<div align="center">

## 🔑 Environment Variables

</div>

All optional — sensible defaults / prompts otherwise:

| ⚙️ Env var | 📝 Purpose |
|:---|:---|
| `ZENPANEL_REPO_URL` | git URL to clone (defaults to this repo) |
| `ZENPANEL_CF_TOKEN` | Cloudflare API token |
| `ZENPANEL_CF_ZONE` | Cloudflare zone id |
| `ZENPANEL_CF_TUNNEL_ID` | Cloudflare tunnel id |
| `ZENPANEL_CF_TUNNEL_TOKEN` | Cloudflare tunnel connector token |
| `ZENPANEL_SERVER_IP` | public IP advertised in DNS records |
| `ZENPANEL_SITE_DOMAIN` | panel tunnel domain (e.g. `panel.example.com`) |
| `ZENPANEL_ADMIN_USER` | WHM admin username (default `admin`) |
| `ZENPANEL_ADMIN_PASS` | WHM admin password (default: generated & printed) |
| `ZENPANEL_PORT` | panel HTTP port (default `8080`) |
| `ZENPANEL_SKIP_FILEBROWSER=1` | skip the File Browser download |
| `ZENPANEL_NO_START=1` | install but don't auto-start |

**Non-interactive install with everything:**

```bash
curl -fsSL https://raw.githubusercontent.com/zarifsikder/zenpanel/main/install.sh \
  | ZENPANEL_REPO_URL=https://github.com/zarifsikder/zenpanel.git \
    ZENPANEL_CF_TOKEN=cfat_... \
    ZENPANEL_CF_ZONE=... \
    ZENPANEL_CF_TUNNEL_ID=... \
    ZENPANEL_CF_TUNNEL_TOKEN=... \
    bash
```

---

<div align="center">

## 🧩 Repository Layout

</div>

```
📦 zenpanel/
├── 🔀 router.php            front controller (routing, compression, minification)
├── ⚙️ config.php            PDO, feature flags, per-install secrets loader
├── 📥 install.sh            the installer (target of the curl one-liner)
├── 🔄 update.sh             the updater (target of the curl update one-liner)
├── 🚀 scripts/start         canonical launcher (wired to ~/start by the installer)
├── 👤 cpanel/               customer control panel
├── 🛠️ whm/                  admin panel
├── 🔌 api/v1/               public REST API
├── 🚀 webapps.php           AI-project deployer + proxy
├── 📁 file-manager/         KODExplorer file manager (served at /file-manager)
├── 🎚️ feature_flags.php     runtime feature switches (/features)
├── 🎨 assets/               CSS/JS/theme
└── 📚 docs/                 setup guides
```

---

<div align="center">

## 🔒 Security Notes

</div>

> [!WARNING]
> **Do NOT expose this on an unauthenticated public network** — it is a full
> control panel with shell access.

- 🔐 `config.local.php`, `tunnel_data/`, `user_data/`, `*.pem` and database
  files are **git-ignored** — per-install secrets never reach the repository.
- 🔄 The installer rotates the `/features` password and the secret shell key on
  every install (and prints the one-time `/features` password at the end).
- 🛡️ Always enable **2FA** on your admin account after first login.

---

<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=12,20,24&height=120&section=footer&text=Made%20with%20%E2%9D%A4%EF%B8%8F%20for%20Termux&fontSize=24&fontColor=ffffff&animation=twinkling" width="100%"/>

### **⭐ Star this repo if ZRPanel helped you!**

[![Install](https://img.shields.io/badge/🚀_Install_ZRPanel-0e63be?style=for-the-badge)](#-quick-install)
[![Issues](https://img.shields.io/badge/🐛_Report_Issue-red?style=for-the-badge)](https://github.com/zarifsikder/zenpanel/issues)

</div>
