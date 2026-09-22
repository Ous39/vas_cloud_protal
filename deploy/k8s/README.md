# Deploying to Rancher

These are plain Kubernetes manifests — no Helm required. Rancher can apply them two ways:

- **Rancher UI**: open your cluster → the `≡` menu (top left) → **kubectl Shell**, or use
  **Cluster → Import YAML** (paste or upload the files in this folder) — the simplest path if you
  don't have `kubectl` set up locally.
- **kubectl**: download the kubeconfig from Rancher (cluster page → top-right ⬇ **Download KubeConfig**),
  then run the commands below from this folder against that cluster.

Do these in order.

## 1. Build and push the image

Rancher pulls from a container registry — it can't build from source. Pick one your cluster can
reach (Docker Hub, GHCR, an internal Harbor/registry your team already runs), then:

```bash
docker build -t <your-registry>/vas-cloud-app:latest ..
docker push <your-registry>/vas-cloud-app:latest
```

Edit `03-app-deployment.yaml` and replace `<your-registry>/vas-cloud-app:latest` with the real
path. Repeat build+push (with a new tag, e.g. a version or git SHA rather than reusing `:latest`)
every time you ship a change, and update the Deployment's image field to match — that's what
actually rolls out the new version.

## 2. Create the namespace and secrets

```bash
kubectl apply -f 00-namespace.yaml

# vas_portal's local MySQL credentials — pick real passwords, not these examples:
kubectl create secret generic vas-cloud-db-secret -n vas-cloud \
  --from-literal=MYSQL_ROOT_PASSWORD='change-me' \
  --from-literal=MYSQL_USER=vas_user \
  --from-literal=MYSQL_PASSWORD='change-me-too'

# The app's own secrets. DB_PASSWORD must match MYSQL_PASSWORD above. HERA_DB_USER/PASSWORD are
# your real HeraProduction/HeraTesting credentials (leave blank/omit to keep Hera schemas on the
# in-cluster MySQL, same inert-by-default behavior as an unconfigured .env — see
# docs/ARCHITECTURE.md). Generate APP_ENCRYPTION_KEY with: php -r "echo bin2hex(random_bytes(32));"
kubectl create secret generic vas-cloud-app-secret -n vas-cloud \
  --from-literal=DB_PASSWORD='change-me-too' \
  --from-literal=HERA_DB_USER='' \
  --from-literal=HERA_DB_PASSWORD='' \
  --from-literal=APP_ENCRYPTION_KEY='<generate one>'
```

**Never commit a YAML file with these values filled in.** `kubectl create secret` (or Rancher's UI
under Secrets) keeps them out of git entirely, which is the whole point.

## 3. Seed the vas_portal schema

The MySQL StatefulSet mounts its init scripts from a ConfigMap built from the file already in this
repo, so it stays in sync automatically instead of being copy-pasted into a manifest:

```bash
kubectl create configmap vas-cloud-db-init -n vas-cloud \
  --from-file=../../database/init/00_platform_schema.sql
```

(Only `00_platform_schema.sql` — the other two files in `database/init/` seed demo
HeraProduction/HeraTesting data for local dev, which is irrelevant once you're pointing at real
infrastructure via `HERA_DB_*`.)

## 4. Apply everything else

```bash
kubectl apply -f 01-mysql-statefulset.yaml
kubectl apply -f 02-app-configmap.yaml
kubectl apply -f 03-app-deployment.yaml
kubectl apply -f 04-ingress.yaml   # edit the host first, and the ingressClassName if needed
```

Watch it come up (Rancher's UI shows this too, under Workloads):

```bash
kubectl get pods -n vas-cloud -w
```

## 5. First login

Once `vas-cloud-app` pods are Ready, open the ingress host you set. Log in with `admin` /
`admin123` and **change that password immediately** (Admin → Users → Edit) — same default the
local Docker setup ships with, and just as much a real risk once this is reachable by your whole
team.

## Known limitation: sessions aren't shared across pods

The Deployment runs 2 replicas by default. PHP's default session store is a file on that pod's own
disk, so a user can occasionally get bounced back to the login page if their next request lands on
a different pod than their last one. Harmless (they just log back in) but worth knowing before your
team asks why. Fixing it properly means moving sessions into MySQL or Redis — worth doing if this
becomes annoying at your team's actual usage pattern, not before.

## Updating a deployed version

```bash
docker build -t <your-registry>/vas-cloud-app:<new-tag> ..
docker push <your-registry>/vas-cloud-app:<new-tag>
kubectl set image deployment/vas-cloud-app app=<your-registry>/vas-cloud-app:<new-tag> -n vas-cloud
```

Kubernetes rolls pods over one at a time by default — no downtime for the whole team, just a brief
moment where a request could land on an old or new pod mid-rollout.
