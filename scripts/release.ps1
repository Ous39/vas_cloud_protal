<#
  Release VAS Cloud in one command:   .\scripts\release.ps1
  1. runs the tests (against the local Docker app + MySQL from docker-compose; start them first: docker compose up -d)
  2. refuses to go on if the tests fail or there are uncommitted changes
  3. builds the image from this exact commit, tags it :latest and :<commit>, pushes both
  4. prints the one command that puts it live, and how to check it
  Use -SkipTests only in an emergency.
#>
param([switch]$SkipTests)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$image = 'ghcr.io/ous39/vas-cloud-app'

if (-not $SkipTests) {
    Write-Host "== Tests ==" -ForegroundColor Cyan
    docker exec vas-enterprise-app sh -c "rm -rf /tmp/vt && mkdir -p /tmp/vt" | Out-Null
    docker cp app/lib/. vas-enterprise-app:/var/www/lib/ | Out-Null
    docker cp app/public/. vas-enterprise-app:/var/www/html/ | Out-Null
    docker cp tests vas-enterprise-app:/tmp/vt/tests | Out-Null
    docker exec vas-enterprise-app php /tmp/vt/tests/run.php
    if ($LASTEXITCODE -ne 0) { Write-Host "Tests failed - not releasing." -ForegroundColor Red; exit 1 }
}

$dirty = git status --porcelain
if ($dirty) { Write-Host "There are uncommitted changes - commit them first (the image is built from the commit):`n$dirty" -ForegroundColor Red; exit 1 }
git push
$sha = (git rev-parse --short HEAD).Trim()

Write-Host "== Build $sha ==" -ForegroundColor Cyan
docker build -q -t "${image}:latest" -t "${image}:$sha" --build-arg GIT_SHA=$sha .
if ($LASTEXITCODE -ne 0) { exit 1 }
Write-Host "== Push ==" -ForegroundColor Cyan
docker push "${image}:latest" | Select-Object -Last 1
docker push "${image}:$sha" | Select-Object -Last 1

Write-Host "`nReleased $sha. To put it live (Rancher shell):" -ForegroundColor Green
Write-Host "  kubectl -n vas-cloud rollout restart deployment/vas-cloud-app"
Write-Host "  kubectl -n vas-cloud rollout status deployment/vas-cloud-app"
Write-Host "Then open System Status in the portal (or /portal/health.php) - the version there should be $sha."
Write-Host "To go back to the previous release:  kubectl -n vas-cloud rollout undo deployment/vas-cloud-app"
