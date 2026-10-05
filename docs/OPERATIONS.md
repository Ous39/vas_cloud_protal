# Running VAS Cloud

For whoever looks after the portal. Menu building has its own guide: [docs/ussd/HOW-TO-BUILD-A-MENU.md](ussd/HOW-TO-BUILD-A-MENU.md).

## Every day (2 minutes)

Open **Admin → System Status**. Green means fine. It checks the databases, Hera, Mobius, the USSD service (requests in the last hour, screens that failed to reach Mobius), purchases in the last 24 h, every menu's health, and how old each secret is.
For an uptime monitor use `https://<portal>/portal/health.php` (200 = database reachable, 503 = down). Kubernetes uses it too.

## Releasing a change

Changes go live in three steps, and a bad one cannot slip through:

1. **Commit** your change.
2. Run `.\scripts\release.ps1` — it runs the tests, refuses to continue if anything fails or is uncommitted, builds the image from that commit, tags it `:latest` and `:<commit>`, and pushes both.
   (GitHub Actions does the same on every push to `master`: tests first, image only if they pass. Pull requests get the tests too.)
3. Put it live: `kubectl -n vas-cloud rollout restart deployment/vas-cloud-app`, then `kubectl -n vas-cloud rollout status deployment/vas-cloud-app`.
   Check **System Status → Version** shows the new commit. Pods start one at a time, so there is no downtime (2 replicas, `maxUnavailable: 0`).

**Going back:** `kubectl -n vas-cloud rollout undo deployment/vas-cloud-app` returns to the previous release. Or pin a known good one:
`kubectl -n vas-cloud set image deployment/vas-cloud-app app=ghcr.io/ous39/vas-cloud-app:<commit>`.
Menus are not affected by a rollback — they live in the database and have their own **History → Restore**.

Changing the deployment itself (probes, host aliases) is `kubectl apply -f deploy/k8s/03-app-deployment.yaml` from a machine that has the repository.

## Tests

`php tests/run.php` (needs MySQL; it creates and removes its own data and restores the USSD settings it touches). It covers the menu engine, the building tools, the quiz, purchases and the Shared Bundle calls against a stand-in for Hera, using the real reply shapes we have seen. Run it before any change to `app/lib/bootstrap.php`. Add a test whenever you fix a bug — the file is organised by area.

## Backups and restore

Everything the portal owns lives in the **`vas_portal`** database (users, audit trail, menus and their history, USSD settings, quiz, purchases, secrets). The Hera databases are not the portal's to back up.

- **Back up** (daily is sensible; keep at least 14 days): 
  `mysqldump -h <DB_HOST> -P <DB_PORT> -u <user> -p --single-transaction --routines vas_portal > vas_portal_$(date +%F).sql`
- **Restore:** create an empty `vas_portal`, then `mysql ... vas_portal < vas_portal_<date>.sql`, restart the deployment.
- **The dump contains secrets** (encrypted) — store it as carefully as a password. Set `APP_ENCRYPTION_KEY` in the Kubernetes secret so the encrypted values (Mobius password, Hera headers) can be read after a restore on a new server; without it a new key is generated and they must be entered again.
- Menus can also be saved on their own: **Menu Builder → Export JSON** (and **Import** brings it back).

## Secrets

Never paste keys, passwords or logs containing them into chat or tickets. System Status lists the four secrets and flags any not changed in 90 days. To rotate:

| Secret | How |
|---|---|
| USSD endpoint token | USSD Proxy → New token, then put the new URL in the Mobius PROXY menu(s) |
| Mobius API password | Create/choose a dedicated API user in Mobius; enter it on USSD Proxy → Mobius connection |
| Hera headers (API key) | Get a new key from the Hera owner; USSD Proxy → Buying from the menu → Headers to send |
| Alert cron token | Alert Settings → rotate, then update the CronJob with the command it shows |

Also: change the `admin` password after setting up, give each person their own login, and keep **manage_ussd_menus** (building) and **manage_api_keys** (settings, secrets, status) for people who need them.

## When something is wrong

**Customers get no menu / "connection problem"** — in this order:
1. System Status: is the USSD endpoint "on, Live, answering through Mobius"? Is Mobius "Logged in"?
2. USSD Proxy → Captured requests: do dials arrive? If not, the problem is in Mobius (the PROXY menu's address, or the short code). If they arrive but the last column says `PUSH FAILED`, use **Run diagnostics** on the Mobius connection card.
3. Menu Builder → Menu check for that short code.

**Purchases fail** — USSD Proxy → the purchase table: hover the customer text for Hera's raw reply. `Low balance`/`insufficient` is the customer's balance; HTTP 500/400 is the request shape; "Could not resolve host" is DNS (see the host alias in the deployment).

**A menu change went wrong** — Menu Builder → History → Restore.

**The portal itself is down** — `kubectl -n vas-cloud get pods`, `kubectl -n vas-cloud logs deployment/vas-cloud-app`. Pods "not ready" with a healthy cluster usually means the portal database is unreachable (`/health.php` returns 503).

## Safety rules that are built in

Production Hera databases are read-only from the portal; nothing is ever deleted (menus, versions and old items are archived, purchases are a permanent ledger); every change is in the audit trail; each customer call can buy each offer only once even if Mobius repeats a request; Test modes simulate everything and send nothing.
