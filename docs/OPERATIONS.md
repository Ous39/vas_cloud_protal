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

## Refunds

**Operations → Refunds** (admins with the settings permission). Two steps on one page:

1. **Investigate** — type the customer's number (7 digits, or with 220) and the dates to look between (set *From* to the day it was bought; subscriptions go back up to 400 days, the transaction log shows its newest 31 days of the range). Hera's tables hold the number as 6704843, the USSD ledger as 2206704843: the page tries both. The page shows the purchases made through the USSD menu (as buyer or as the other number, with "not charged" flagged where Hera said nothing was taken), the number's subscriptions, and its transactions with the offer and vendor each carried. **Use** on any row fills the form below.
2. **Refund** — five fields, as in Hera's example: *offer code*, *vendor*, *number* (who got the bundle — the other number for a buy-for-other), *channel* (REF) and *date it was bought* (`2025-08-08 15:30:18`). **Preview** shows exactly what will be sent (`POST …/hera/prepaid/BundleSubscription` with the purchase headers); **Send** confirms and sends.

Hera is asked once per number + offer + time: a refund that went through is never sent again (typing the number with or without 220 makes no difference); a failed or test one can be tried again. Every request, who sent it, and Hera's reply are in the history at the bottom and in the audit trail; a refund started from a purchase also shows on that purchase in USSD Proxy → Buying offers.

**Headers for refunds:** the refund has its own headers box (same place; encrypted, never shown again). Left empty, the purchase headers are used — but those belong to the *test* Hera, so against the production Hera the refund is answered `success: false, message: Api user does not exist`. Save the production API user's headers there (`X-API-KEY`, `X-USERNAME`, `X-HASHED-PASSWORD`). Hera's answer is read as `success` true/false or by its result code; an answer that is neither is recorded as **sent** and is not sent again until someone has looked at the raw reply in the history.

Mode (off / test / live) and the address are set under USSD Proxy → Buying offers → Refunds. Start in **Test** (recorded, nothing sent). The refund address is the production Hera (`https://vas-prod.comium.gm/hera/prepaid/BundleSubscription`). The pods must be able to resolve the refund host (`kubectl -n vas-cloud exec deploy/vas-cloud-app -- getent hosts vas-prod.comium.gm`; if nothing prints, add it under `hostAliases` as for `vas-testing`). The page reads the database currently selected at the top (use HeraProduction for real customers).

## Secrets

Never paste keys, passwords or logs containing them into chat or tickets. System Status lists the four secrets and flags any not changed in 90 days. To rotate:

| Secret | How |
|---|---|
| USSD endpoint token | USSD Proxy → New token, then put the new URL in the Mobius PROXY menu(s) |
| Mobius API password | Create/choose a dedicated API user in Mobius; enter it on USSD Proxy → Mobius connection |
| Hera headers (API key) | Get a new key from the Hera owner; USSD Proxy → Buying from the menu → Headers to send |
| Alert cron token | Alert Settings → rotate, then update the CronJob with the command it shows |

The portal will not start in production without `DB_PASSWORD` in the environment (the `vas-cloud-app-secret` Secret) — a missing Secret shows as pods "not ready" and `/health.php` 503, not as a silent fall-back to a built-in password.

Also: change the `admin` password after setting up (signing in with the install default `admin123` forces a change), give each person their own login, and keep **manage_ussd_menus** (building) and **manage_api_keys** (settings, secrets, status) for people who need them.

## When something is wrong

**Customers get no menu / "connection problem"** — in this order:
1. System Status: is the USSD endpoint "on, Live, answering through Mobius"? Is Mobius "Logged in"?
2. USSD Proxy → Captured requests: do dials arrive? If not, the problem is in Mobius (the PROXY menu's address, or the short code). If they arrive but the last column says `PUSH FAILED`, use **Run diagnostics** on the Mobius connection card.
3. Menu Builder → Menu check for that short code.

**Purchases fail** — USSD Proxy → the purchase table: hover the customer text for Hera's raw reply. `Low balance`/`insufficient` is the customer's balance; HTTP 500/400 is the request shape; "Could not resolve host" is DNS (see the host alias in the deployment).

**A menu change went wrong** — Menu Builder → History → Restore.

**The portal itself is down** — `kubectl -n vas-cloud get pods`, `kubectl -n vas-cloud logs deployment/vas-cloud-app`. Pods "not ready" with a healthy cluster usually means the portal database is unreachable (`/health.php` returns 503).

## The database disk is full (happened 2026-10-08)

**Symptoms:** nobody can sign in, `/health.php` says `"down"`, the app log says `Too many connections`, the alert cron jobs fail, and `kubectl logs mysql-0` repeats `Disk is full writing './binlog.…' (errno 28)`.

**Cause:** MySQL's binary logs (a change journal, nothing in this setup reads it) grew by about 1 GB a day and filled the 10 GB volume. The portal's real data is only about 60 MB.

**Fix (nothing is lost):**
1. Protect the data first, in case the volume claim is ever removed: `kubectl patch pv <the pv name> -p '{"spec":{"persistentVolumeReclaimPolicy":"Retain"}}'`.
2. If MySQL refuses even root (`Too many connections`), delete the OLDEST `binlog.0000NN` file with `kubectl -n vas-cloud exec mysql-0 -- rm /var/lib/mysql/binlog.0000NN` to make room. Never touch other files in that folder.
3. `PURGE BINARY LOGS TO '<the newest binlog file>'` (run through `kubectl exec mysql-0 -- sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e …"` so the password is not typed).
4. Keep only 3 days from now on: `SET PERSIST binlog_expire_logs_seconds=259200` (done 2026-10-08).
5. Check `df -h /var/lib/mysql` and `/health.php`; take a dump (`mysqldump --single-transaction --all-databases`) and keep a copy off the cluster.

**Still to do:** the volume claim `mysql-data-mysql-0` is `Terminating` (someone asked for it to be deleted). It stays bound while `mysql-0` runs, but a restart of `mysql-0` or a reboot of the `master` node would finish the deletion and leave MySQL `Pending`. Repair in a short window, with a fresh dump taken first — the data is kept and re-attached, not restored:
1. `kubectl -n vas-cloud scale deployment vas-cloud-app --replicas=0`, then `kubectl -n vas-cloud scale statefulset mysql --replicas=0`. The stuck claim now finishes deleting and the volume shows `Released` (it is kept because of `Retain`).
2. Free the volume for a new claim: `kubectl patch pv <pv name> --type=json -p='[{"op":"remove","path":"/spec/claimRef"}]'`.
3. Create the claim again, pointing at that same volume (do this BEFORE step 4, or the StatefulSet makes its own empty one): name `mysql-data-mysql-0`, namespace `vas-cloud`, `storageClassName: longhorn`, `volumeName: <pv name>`, `accessModes: [ReadWriteOnce]`, `resources.requests.storage: 10Gi`.
4. `kubectl -n vas-cloud scale statefulset mysql --replicas=1`, wait for `1/1`, then scale the app back to 2.
5. To make it bigger, repeat with MySQL stopped: raise the claim's `storage` (e.g. 30Gi) and let Longhorn expand it, or use Longhorn's Volume → Expand.

**Early warning:** the portal cannot see that disk. Set a Longhorn / Rancher alert for the volume at 80% used.

## Safety rules that are built in

Production Hera databases are read-only from the portal; nothing is ever deleted (menus, versions and old items are archived, purchases are a permanent ledger); every change is in the audit trail; each customer call can buy each offer only once even if Mobius repeats a request; Test modes simulate everything and send nothing.
