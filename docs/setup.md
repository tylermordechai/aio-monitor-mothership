# Server setup

How to build the mothership VM from this repo (MS-OPS-07). The decisions behind each step are M-033 to M-039, and M-043 to M-049 for step 9 and the simulator in [decisions.md](decisions.md).

- **The VM:** Ubuntu Server 26.04.1 LTS at **192.168.0.191** on Unraid (2 vCPU, 8 GB, 100 GB, bridged), user `aioadmin` with sudo. Clean snapshot: `M0-off`.
- **The network:** the owner's PC and the test servers are on 192.168.0.0/24. Nothing else may reach the VM (M-025).
- **How it is run:** the owner runs each script over SSH and pastes the output back. Claude doesn't log in to the VM.
- **Settings:** `setup/env.sh` (IP, LAN, versions). Nothing secret is in the repo. Passwords and keys are created on the VM, under `/etc/aio-mothership/`.

## The scripts

Every script says at the top what it does, whether it needs sudo and whether it reboots. Each ends with checks that print `PASS` or `FAIL` and a final `RESULT: PASS` or `RESULT: FAIL`. All are safe to run again.

| # | Script | sudo | Reboots | What it does |
| --- | --- | --- | --- | --- |
| 1 | `01-ssh-key.sh <pubkey>` | no | no | Adds the PC's public key for `aioadmin` |
| 2 | `02-ssh-hardening.sh --key-login-confirmed` | yes | no | Keys only: password and root login off. **Only after key login works** |
| 3 | `03-os-updates.sh` | yes | no (says if needed) | All updates now; automatic security updates, no automatic reboot (MS-SEC-12) |
| 4 | `04-time.sh` | yes | no | UTC, NTP sync |
| 5 | `05-firewall.sh` | yes | no | ufw: 22, 443, 8443 from 192.168.0.0/24 only |
| 6 | `06-mariadb.sh` | yes | no | MariaDB 11.8 from Ubuntu, hardened; database `aio`; migrations (**the schema gate**); 3 app users (MS-SEC-04) |
| 7 | `07-pki.sh` | yes | no | Agent CA, web CA (192.168.0.0/24 only), both server certificates (IP SAN) |
| 8 | `08-apache-php.sh` | yes | no | Apache and PHP 8.5-FPM; vhosts 8443 (TLS 1.3, client cert optional) and 443; health pages |
| 9 | `09-app.sh [--production]` | yes | no | Milestone 2: Composer packages, `app.ini`, the ingest state directory, the `aio_test` database; checks the agent API and runs the tests |

**Snapshots:** take an Unraid snapshot after step 2 (`M1-ssh`), after step 6 (`M1-db`) and after step 8 (`M1-web`), with the VM shut down if you want them consistent. They're for rolling back during setup, not backups (M-026).

## Running a script and sending back the output

Over SSH on the VM, from the repo:

```bash
cd /opt/aio-mothership
mkdir -p ~/setup-logs
sudo bash setup/03-os-updates.sh 2>&1 | tee ~/setup-logs/03.log
```

Paste the whole output back, from the `=====` banner to `RESULT:`. The scripts never print passwords or private keys (MS-SEC-10), so the output is safe to paste.

## Step 0: get the repo onto the VM

The first login is with the password, from the PC (PowerShell):

```powershell
ssh aioadmin@192.168.0.191
```

On first connection SSH shows the VM's host key fingerprint. Compare it with the VM console (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the Unraid VNC console) before typing `yes`.

On the VM:

```bash
sudo apt-get update && sudo apt-get install -y git
sudo install -d -o aioadmin -g aioadmin /opt/aio-mothership
```

**While the repo is public:**

```bash
git clone https://github.com/tylermordechai/aio-monitor-mothership.git /opt/aio-mothership
```

**If the repo becomes private: a read-only deploy key.** The VM gets a key that can only read this one repo. It can't push, and it can't read the agent repo or anything else on the account.

1. On the VM, make the key (no passphrase, since it's used non-interactively and only reads):
   ```bash
   ssh-keygen -t ed25519 -N "" -C "aio-mothership VM, read-only deploy key" -f ~/.ssh/aio_deploy
   cat ~/.ssh/aio_deploy.pub
   ```
2. On GitHub: the repo, then **Settings → Deploy keys → Add deploy key**. Paste the `.pub` line, title it "aio-mothership VM", and leave **Allow write access unticked**.
3. On the VM, tell SSH to use that key for GitHub:
   ```bash
   cat >> ~/.ssh/config <<'EOF'
   Host github-aio
       HostName github.com
       User git
       IdentityFile ~/.ssh/aio_deploy
       IdentitiesOnly yes
   EOF
   chmod 600 ~/.ssh/config
   ssh -T git@github-aio
   ```
   At the first connection, check that the host key fingerprint is GitHub's published one (GitHub Docs, "GitHub's SSH key fingerprints"; for ed25519 it is `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`). The answer should be "Hi tylermordechai/aio-monitor-mothership! You've successfully authenticated, but GitHub does not provide shell access."
4. Clone, or switch an existing clone to the key:
   ```bash
   git clone git@github-aio:tylermordechai/aio-monitor-mothership.git /opt/aio-mothership
   # or, for an existing clone:
   git -C /opt/aio-mothership remote set-url origin git@github-aio:tylermordechai/aio-monitor-mothership.git
   ```
5. Check that it really is read-only: `git -C /opt/aio-mothership push --dry-run` must fail with a permission error.

**Updating the VM's copy:** `git -C /opt/aio-mothership pull --ff-only`. Nobody commits on the VM; changes are made on the PC, pushed, then pulled.

## Step 1: SSH key login (`01-ssh-key.sh`)

On the **PC** (PowerShell). Make a key if you don't have one; a passphrase is recommended:

```powershell
ssh-keygen -t ed25519 -C "owner PC to aio-mothership"
scp $env:USERPROFILE\.ssh\id_ed25519.pub aioadmin@192.168.0.191:owner-key.pub
```

On the **VM** (still the password session):

```bash
cd /opt/aio-mothership
mkdir -p ~/setup-logs
bash setup/01-ssh-key.sh ~/owner-key.pub 2>&1 | tee ~/setup-logs/01.log
```

**Check:** the fingerprint it prints matches `ssh-keygen -lf $env:USERPROFILE\.ssh\id_ed25519.pub` on the PC. Then, **keeping the password session open**, test from a second PowerShell window that the key alone works:

```powershell
ssh -o PreferredAuthentications=publickey -o PasswordAuthentication=no aioadmin@192.168.0.191 "echo KEY LOGIN OK"
```

It must print `KEY LOGIN OK` without asking for the aioadmin password (it may ask for your key's passphrase). Tell Claude it worked before step 2.

## Step 2: password login off (`02-ssh-hardening.sh`)

Only after step 1's test printed `KEY LOGIN OK`:

```bash
sudo bash setup/02-ssh-hardening.sh --key-login-confirmed 2>&1 | tee ~/setup-logs/02.log
```

**Check, from the PC, with the old session still open:**

```powershell
ssh aioadmin@192.168.0.191 "echo STILL OK"
ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no aioadmin@192.168.0.191
```

The first must print `STILL OK`. The second must be refused with `Permission denied (publickey)`.

**If you are locked out:** use the VM's console in Unraid (VNC). The console login still takes the password. Then run `sudo rm /etc/ssh/sshd_config.d/10-aio-mothership.conf && sudo systemctl reload ssh`.

## Step 3: updates (`03-os-updates.sh`)

```bash
sudo bash setup/03-os-updates.sh 2>&1 | tee ~/setup-logs/03.log
```

If it ends with `FAIL: reboot required`, run `sudo reboot`, log in again, and run it once more. It should then pass. After step 8 the dashboard health page shows `"reboot_required"` too, and milestone 4 makes it a health check. Security updates install themselves daily, and **nothing reboots on its own** (M-039).

## Step 4: time (`04-time.sh`)

```bash
sudo bash setup/04-time.sh 2>&1 | tee ~/setup-logs/04.log
```

## Step 5: firewall (`05-firewall.sh`)

```bash
sudo bash setup/05-firewall.sh 2>&1 | tee ~/setup-logs/05.log
```

The script refuses to start if any SSH session comes from outside 192.168.0.0/24, so it can't cut you off. **Check from the PC:** `Test-NetConnection 192.168.0.191 -Port 22` succeeds (443 and 8443 only answer after step 8). **If you are locked out:** run `sudo ufw disable` on the Unraid console.

## Step 6: MariaDB and the schema gate (`06-mariadb.sh`)

```bash
sudo bash setup/06-mariadb.sh 2>&1 | tee ~/setup-logs/06.log
```

- **The schema gate** is the line `SCHEMA GATE: PASS` (M-017). Paste the whole output back either way.
- **If a migration fails:** Claude fixes the migration file on the PC and pushes. You run `git pull --ff-only`, then `sudo bash setup/06-mariadb.sh --recreate-db`. That option drops and recreates the empty `aio` database, and is refused once any agent is enrolled.
- **Passwords:** each app user's password is in `/etc/aio-mothership/db/<user>.cnf` (root-only). No one needs to know them. To sign in by hand as one of them: `sudo mariadb --defaults-extra-file=/etc/aio-mothership/db/dashboard.cnf aio`. As root: `sudo mariadb`.

## Step 7: certificates (`07-pki.sh`)

```bash
sudo bash setup/07-pki.sh 2>&1 | tee ~/setup-logs/07.log
```

- It prints the CA fingerprints, which are also in `/etc/aio-mothership/pki/public/fingerprints.txt`.
- The **agent CA SHA-256** is the value agents pin, delivered as an MSI property (M-002).
- The **web CA SHA-1 thumbprint** is what Windows shows when you import it (next section).

## Step 8: Apache and PHP (`08-apache-php.sh`)

```bash
sudo bash setup/08-apache-php.sh 2>&1 | tee ~/setup-logs/08.log
```

**Check from the PC** (PowerShell; 8443 needs no client certificate for the health page):

```powershell
Test-NetConnection 192.168.0.191 -Port 443
Test-NetConnection 192.168.0.191 -Port 8443
```

Both must report `TcpTestSucceeded : True`. Then open `https://192.168.0.191/health.php` in the browser. Once the web CA is imported (below), there should be no warning, and the page shows `"status":"ok"`.

## Importing the web CA on the PC

The dashboard's certificate comes from the mothership's own **web CA** (M-024). Windows trusts it once you import that CA's certificate. The CA can only vouch for addresses in 192.168.0.0/24, so trusting it can't make any other site look genuine.

1. Copy the certificate (public; no secret) to the PC:
   ```powershell
   scp aioadmin@192.168.0.191:/etc/aio-mothership/pki/public/web-ca.crt $env:USERPROFILE\Downloads\aio-web-ca.crt
   ```
2. Check its thumbprint against the `web-ca SHA-1 thumbprint` line from step 7:
   ```powershell
   (New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 "$env:USERPROFILE\Downloads\aio-web-ca.crt").Thumbprint
   ```
3. Import it for your Windows user only (no admin rights needed). Windows shows a security warning naming "AIO Monitor Web CA (192.168.0.0/24 only)" and the same thumbprint; check it, then choose **Yes**:
   ```powershell
   Import-Certificate -FilePath "$env:USERPROFILE\Downloads\aio-web-ca.crt" -CertStoreLocation Cert:\CurrentUser\Root
   ```
4. Restart the browser and open `https://192.168.0.191/health.php`. Edge and Chrome use the Windows store. **Firefox** has its own: either set `security.enterprise_roots.enabled` to `true` in `about:config`, or import the file under Settings → Privacy & Security → Certificates → View Certificates → Authorities.

**To remove it later:**

```powershell
Get-ChildItem Cert:\CurrentUser\Root | Where-Object Subject -like '*AIO Monitor Web CA*' | Remove-Item
```

Don't import the **agent CA** on the PC. Only agents use it, and they pin it themselves.

## Step 9: the PHP application (`09-app.sh`, milestone 2)

After a `git pull` that brings new migrations, grants or vhost changes, re-run the earlier scripts first. They are safe to re-run and change only what differs:

```bash
cd /opt/aio-mothership && git pull --ff-only
sudo bash setup/06-mariadb.sh 2>&1 | tee ~/setup-logs/06.log     # new migrations, grants, credential file modes
sudo bash setup/08-apache-php.sh 2>&1 | tee ~/setup-logs/08.log  # vhost changes
sudo bash setup/09-app.sh 2>&1 | tee ~/setup-logs/09.log
```

- **09 installs** Composer and three PHP extensions (`xml`, `mbstring`, `curl`) from Ubuntu, then the Composer packages. On this dev VM that includes PHPUnit (M-044); `--production` leaves it out.
- **`/etc/aio-mothership/app.ini`** is written once and never overwritten, so edits stay.
- **Its checks** call the agent API with no certificate (401), with an unregistered certificate (403), with the wrong method (405) and on an unknown path (404). Then they run the unit and integration tests. The integration tests rebuild the `aio_test` database from the migrations each time; they never touch `aio`.

## Milestone 2: the simulator

All on the VM, as `aioadmin` with sudo (the tools read the test keys and `maint.cnf`).

```bash
cd /opt/aio-mothership
sudo php tools/dev-agents.php create 10                      # 10 simulated hosts, SIM-0001..
sudo php tools/simulator/simulate.php --agents=10 --duration=120 --faults
sudo php tools/reconcile.php                                 # RESULT: 0 LOST
sudo php tools/measure.php latency                           # server-side times for the last run
sudo php tools/measure.php size                              # MB per host per day (MS-DAT-06)
sudo php tools/dev-agents.php delete                         # removes every simulator row and file
```

- **Simulated agents** are in host group `simulator`, with certificates from the agent CA (M-049). `delete` removes only them.
- **`--days=N`** (or `--day` for one) makes N days of records per agent with past timestamps and sends them as fast as the server takes them. Use it with a few agents (`--agents=4 --laptops=2 --day`) for the size figure.
- **The gate run** (M-050): `--agents=4 --laptops=2 --duration=3600 --faults`, 2 servers and 2 laptops. `--laptops=N` makes the last N agents Windows 11 laptops. The simulator prints progress every 10 s and a summary at the end, written to `/var/lib/aio-mothership/sim/runs/<time>/summary.json`.

## Step 10: the dashboard (`10-dashboard.sh`, milestone 5)

After the `git pull`, re-run 06, 08 and 09 first (migration 0003, the analyst user and the new grants; the dashboard vhost; the tests), then 10:

```bash
sudo bash setup/10-dashboard.sh 2>&1 | tee ~/setup-logs/10.log
sudo php tools/users.php add <your-name> --role=admin --display="Your Name"   # once; the password is asked twice, hidden
```

- **10 installs** the `aio-flow-rollup` timer, which every minute turns new flow records into connections per hour (`flow_conn_hourly`, M-054).
- **Its checks:** the dashboard pool can read `dashboard.cnf` and `analyst.cnf` and nothing else; the sessions directory; the Cytoscape.js file's SHA-256; and, over HTTPS, the sign-in page, the redirects when signed out, a sign-in without the CSRF token refused, the cookie flags and the security headers.
- **Accounts** (`tools/users.php`, MS-SEC-06..08): `add`, `list`, `role`, `password`, `unlock` (clears a lockout), `disable`, `enable`. Every change is in `audit_log`.
- **Sign in:** `https://192.168.0.191/` in the browser on the PC (the web CA is already trusted).

## Step 11: enrolment (`11-enrolment.sh`, milestone 3)

After the `git pull`, re-run 06 (new migrations and grants), then 09 (it installs `vendor/` and runs the tests), then 11:

```bash
cd /opt/aio-mothership && git pull --ff-only
sudo bash setup/06-mariadb.sh 2>&1 | tee ~/setup-logs/06.log
sudo bash setup/09-app.sh 2>&1 | tee ~/setup-logs/09.log
sudo bash setup/11-enrolment.sh 2>&1 | tee ~/setup-logs/11.log
```

- **The signing service (M-060):** `aio-signer.socket` listens on `/run/aio-signer.sock` (660 root:aio-ingest). Each request starts one `aio-signer@` copy as the user `aio-signer`, the only non-root user that can read the agent CA key. It has no network and no database, and the only place it can write is its own private `/tmp`. The agent API asks it to sign and never reads the key itself (MS-SEC-02).
- **Its checks:** the key and socket permissions; the ingest pool can reach the signer, the dashboard pool and `www-data` can't, and none of them can read the key; the signer won't run as root; and real signing through the socket as `aio-ingest`, where a good CSR gets the §2.3 profile and chains to the agent CA, while an RSA key, a tampered CSR and a wrong CN are refused. Nothing is written to the database: those test certificates are thrown away.
- **The signer's log:** `journalctl -u 'aio-signer@*' --since today`. Each line names the agent ID, serial and outcome, never the CSR or a key.
- **Tokens and the CA export (M-061), checked by 11:** `bin/agent-ca.php` gives the same fingerprint as `fingerprints.txt` and works without sudo; a token made by `bin/token-create.php` has the contract format, is stored only as its SHA-256 and is audited without the token; list, cancel, a second cancel refused, an 8-day deployment token refused; the token CLI doesn't work without root. The one test token is cancelled at once and never printed.
- **The enrol endpoint (M-062), checked by 11:** the ingest database user may only add audit rows. Then `tools/enrol-check.php` enrols a throwaway agent (`AIO-ENROL-CHECK`, group `setup-check`) through the real API on 8443, sends a heartbeat with its new certificate, checks that the used token is refused (403) and that a second token for the same agent ID gets 409, revokes the agent and checks its heartbeat is refused (403 `agent_revoked`), then deletes the agent, its host and its rows. The audit rows of the run stay.
- **Renewal (M-064), in the same live run:** the throwaway agent renews over 8443. The same key is refused (400), a new key gets a new certificate (200), and both the renewed and the old certificate work until the revocation, after which both are refused and the agent can't renew.
- **Config (M-065), in the same live run:** `GET /api/v1/config` gives the group's settings with an ETag equal to the heartbeat's `config_etag`, 304 with `If-None-Match`, and 401 without a certificate.
- **Revoke and unrevoke (M-066), in the same live run:** the throwaway agent is revoked with `bin/agent.php` (refused without a reason), both its certificates and its renewal are refused, it is unrevoked and works again, and both changes are in the audit log.

### Enrolment tokens and the agent CA (M-021, M-061)

```bash
sudo php bin/token-create.php create                                  # manual, 24 h, group "default"
sudo php bin/token-create.php create --kind=deployment --days=7 --note="Intune pilot" > token.txt
sudo php bin/token-create.php create --hostname=SRV-WEB-01            # only that host may use it
sudo php bin/token-create.php create --reenroll=<agent ID>            # an agent that lost its certificate (§2.5)
sudo php bin/token-create.php list            # open tokens; --all shows used, expired and cancelled ones
sudo php bin/token-create.php cancel <ID>
php bin/agent-ca.php                          # the agent CA PEM and its SHA-256, for the installer
php bin/agent-ca.php --pem > agent-ca.crt
php bin/agent-ca.php --sha256
```

- The token is shown once and only its SHA-256 is stored. With `> token.txt`, only the token goes into the file and the details stay on the screen. Treat a token like a password until it is used or expires; delete `token.txt` when the installer has it.
- Every create and cancel is in the audit log as "cli:<your login>", without the token.

### Revoking an agent (M-066)

```bash
sudo php bin/agent.php list                     # every agent: status, host, group, last check-in
sudo php bin/agent.php list --status=revoked
sudo php bin/agent.php show <agent ID>           # the agent and its certificates
sudo php bin/agent.php revoke <agent ID> --reason="lost laptop, ticket 123"
sudo php bin/agent.php unrevoke <agent ID> --reason="revoked by mistake"
sudo php bin/agent.php revoke-cert <serial> --reason="key copied"   # one certificate, for good
```

- A revoked agent is refused on its next request (403 `agent_revoked`). It keeps its queue and tries once an hour, so an unrevoke loses nothing.
- Every change needs a reason and is in the audit log as "cli:<your login>".

## Milestone 5: 7 days of simulator data

```bash
sudo php tools/dev-agents.php delete                       # start clean
sudo php tools/dev-agents.php create 4
sudo php tools/simulator/simulate.php --agents=4 --laptops=2 --days=7    # about 15 to 20 minutes
sudo php tools/jobs/flow-rollup.php --all                  # catch the hourly table up now, not over the next hour
```

- **`--days=N`** makes N days of records per agent with past timestamps (`--day` is `--days=1`). The simulated hosts talk to each other, to an unmonitored domain controller, file and update server, to printers and to the internet (M-054).
- Then live data on top, so the agents are checking in: `--agents=4 --laptops=2 --duration=3600` in tmux.

### The gate: page times (MS-UI-08, M-050)

```bash
sudo php tools/page-times.php <your user name>              # asks for your dashboard password, hidden
sudo php tools/page-times.php <your user name> --runs=5     # more runs per page
```

- Signs in over HTTPS like a browser and loads the home page and `/api/map` for each range, and every host's tabs (the time-based tabs for each range), `--runs` times each (default 3). Then signs out; both are in the audit log.
- Prints, per kind of page, the median and slowest whole request (as curl saw it: TLS, Apache, PHP, database) and the server's own time (`X-Render-Ms`), then `GATE … PASS` when every page answered 200 in under 5 s. The command-line reveal is not timed (each one writes an audit row).

## What ends up where on the VM

| Path | What | Who can read it |
| --- | --- | --- |
| `/opt/aio-mothership` | This repo (read-only clone) | everyone |
| `/etc/aio-mothership/db/{ingest,dashboard,analyst,maint}.cnf` | Database credentials | `maint`: root only (600). `ingest`, `dashboard`: root and that PHP pool's group (640, M-047). `analyst`: root and the dashboard pool (640, M-053). The directory is 711 |
| `/etc/aio-mothership/db/test.cnf` | `aio_test` credentials (integration tests, no real data) | root and `aioadmin` (640) |
| `/etc/aio-mothership/app.ini` | App settings (limits, back-pressure), no secrets | everyone |
| `/var/lib/php/sessions-aio-dashboard/` | Dashboard sessions (user ID and CSRF token, no password) | `aio-dashboard` (700) |
| `/etc/systemd/system/aio-flow-rollup.{service,timer}` | Connections-per-hour job, every minute (M-054) | from `setup/templates` |
| `/etc/systemd/system/aio-signer.socket`, `aio-signer@.service` | The agent CA signing service (M-060) | from `setup/templates` |
| `/run/aio-signer.sock` | The signing service socket | root and group `aio-ingest` (660) |
| `/run/aio-ingest/` | Ingest slot and rate lock files (tmpfs) | `aio-ingest` (700) |
| `/var/lib/aio-mothership/sim/` | Simulated agents' keys, state and run summaries (test only) | root (700) |
| `/etc/aio-mothership/pki/agent-ca/ca.key` | Agent CA key | root and group `aio-signer` (440) |
| `/etc/aio-mothership/pki/web-ca/ca.key` | Web CA key | root only (400) |
| `/etc/aio-mothership/pki/server/*.key` | Server keys for 8443 and 443 | root only (400); Apache reads them at start |
| `/etc/aio-mothership/pki/public/` | CA certificates and fingerprints | everyone |
| `/etc/mysql/mariadb.conf.d/90-aio-mothership.cnf` | MariaDB settings | from `setup/templates` |
| `/etc/apache2/sites-available/aio-*.conf`, `/etc/php/8.5/fpm/pool.d/aio-*.conf` | vhosts and PHP pools | from `setup/templates` |
| `/var/lib/mariadb` | MariaDB data (Ubuntu 26.04's path) | mysql |
| `/var/log/aio-mothership/migrations/` | Output of each migration run | root |
| `/var/log/apache2/aio-*.log` | Access and error logs per vhost | root, adm |

The CA keys and app config are backed up before the pilot (M-031). The rest of the backups come before ready to ship (M-026).
