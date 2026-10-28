# TanimTrack local development

This project runs locally with Docker Compose: Vite frontend, PHP API, MySQL, and an experimental image-classification service. The database and uploaded files persist in Docker volumes. Do not use this setup as an unattended production deployment.

> **Publication status:** The Docker setup and application changes in this working copy are not committed or published yet. A fresh clone from GitHub will not contain these instructions or files until the changes are published. The current checkout is `janril15/Tanod-bukid-capstone` on `main`; the `maand3silva/TanodBukid` repository and `infra/local-development` branch mentioned in a separate handover guide are not this project source.

## Requirements

- Windows 10/11 with Docker Desktop using the WSL 2 engine and Ubuntu integration
- Ubuntu under WSL 2, Git, and at least 25 GB of free disk space
- Docker Desktop running before `docker compose` commands

For smoother file watching and fewer Windows file-permission issues, clone and run the project from the Ubuntu filesystem (for example `~/projects/Tanod-bukid-capstone`), not from `/mnt/c`.

## Windows and WSL checks

If WSL is not installed, open an administrator PowerShell window and run `wsl --install -d Ubuntu`, then restart Windows if prompted. Check that Ubuntu uses WSL 2 from PowerShell:

```powershell
wsl --list --verbose
```

In Docker Desktop, enable **Settings > General > Use the WSL 2 based engine** and **Settings > Resources > WSL Integration > Ubuntu**. Start Docker Desktop, then verify the tools from the Ubuntu terminal:

```sh
docker --version
docker compose version
docker ps
```

If `docker ps` reports a socket permission error, first confirm Docker Desktop is running and Ubuntu integration is enabled, then close and reopen the Ubuntu terminal. Do not change socket permissions blindly. On a Linux Docker Engine installation where `/var/run/docker.sock` is owned by the `docker` group, adding a user to that group grants root-equivalent privileges; only do so on a trusted development machine.

## First-time setup

In Ubuntu WSL:

```sh
mkdir -p ~/projects
cd ~/projects
git clone https://github.com/janril15/Tanod-bukid-capstone.git
cd Tanod-bukid-capstone
git status --short --branch
umask 077
python3 - <<'PY'
from pathlib import Path
import secrets

path = Path(".env")
if path.exists():
    raise SystemExit(".env already exists; inspect it instead of overwriting it")
path.write_text(
    f"DB_PASSWORD={secrets.token_hex(24)}\n"
    f"DB_ROOT_PASSWORD={secrets.token_hex(24)}\n"
    "TANODBUKID_DEV_OTP=0\n"
)
path.chmod(0o600)
PY
git check-ignore .env
docker compose config --quiet
docker compose up -d --build
docker compose ps
```

The `.env` file contains unique local database passwords. It is ignored by Git; never copy another person's credentials, share terminal output containing secrets, or force-add `.env`.

Open <http://localhost:5173>. PHP endpoints are proxied through Vite under `/api`; the PHP service is also bound to localhost port 8001 for local development. MySQL and the AI service are internal to the Compose network.

Do not open `index.html` with VS Code Live Server for sign-in or registration: Live Server is static and does not provide the Vite `/api` proxy. Start the Docker Compose stack and use <http://localhost:5173> instead. The Compose project name and MySQL/upload volume names are fixed so commands from this checkout reuse the same containers and persistent data instead of creating a second stack or selecting a new volume.

The PHP image is built from `Dockerfile.backend` (not `docker/php/Dockerfile`); the Vite proxy reaches PHP at `http://backend:8000` over the Compose network. The backend Dockerfile installs `libonig-dev` before compiling `mbstring`.

On a fresh database volume, MySQL runs every SQL file in `database/migrations` in filename order during its first initialization. The initial `users` table and password-reset table are included, along with the climate, farm activity, and report tables. Existing volumes are not migrated again automatically. For an existing database, inspect its schema and migration state, back it up, and apply only migrations that have not already run; do not delete the volume to force initialization.

After startup, confirm all four services show healthy in `docker compose ps`, then use a disposable test account to check registration, login, the dashboard, and one image upload. Do not use real account information for development testing.

## Smoke test and useful pages

1. Open <http://localhost:5173> and confirm the landing page loads.
2. Register a disposable local test account and sign in.
3. Use the app's own navigation for the dashboard and profile setup. Do not use `/dashboard/index.html`; that path is not present in this repository.
4. The standalone, authenticated crop scanner is <http://localhost:5173/dashboard.html>. Upload a sample image; the first prediction can take longer while the model downloads.
5. Check the session endpoint and service logs:

   ```sh
   curl -i http://localhost:5173/api/session.php
   docker compose logs --tail=40 frontend
   docker compose logs --tail=40 backend
   docker compose logs --tail=40 ai
   ```

   Before login, a session response with `authenticated: false` is expected. To validate a frontend production build from the container, run `docker compose exec frontend npm run build`.

## Daily commands

```sh
docker compose up -d
docker compose ps
docker compose logs --tail=40 backend ai
docker compose down
```

`docker compose down` keeps database, upload, and model-cache data. **Do not add `-v` unless you intentionally want to permanently delete those volumes.**

The frontend and backend Dockerfiles copy the source into their images; Compose does not bind-mount the project source. After editing frontend or PHP source, rebuild and recreate the affected service to see the change:

```sh
docker compose up -d --build frontend backend
```

After changing Dockerfiles, Compose settings, or dependencies, rebuild the full stack with `docker compose up -d --build`. This setup does not automatically hot-reload local source edits from the host.

For this Windows workspace, the local database passwords are kept outside the OneDrive-synced project in `%LOCALAPPDATA%\TanodBukid\.env`. In PowerShell, use:

```powershell
$envFile = Join-Path $env:LOCALAPPDATA 'TanodBukid\.env'
docker compose --env-file $envFile up -d
```

Use the same `--env-file $envFile` option for `ps`, `logs`, `exec`, `down`, and rebuild commands from this checkout. A fresh WSL clone can instead use its own Git-ignored `./.env` as shown above.

The first image prediction downloads the configured model weights from Hugging Face and can take several minutes. Check AI readiness with:

```sh
docker compose exec ai python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8080/health').read().decode())"
```

An `ok` health response only proves that FastAPI is running; the model loads when the first image is submitted. The current experimental model is a PlantVillage classifier for Apple, Blueberry, Cherry, Corn (maize), Grape, Orange, Peach, Bell pepper, Potato, Raspberry, Soybean, Squash, Strawberry, and Tomato classes. It has no rice or livestock classes. Scores below the configured 0.55 model-confidence floor are marked uncertain; higher scores are still not calibrated diagnoses. Field photos may not match training images. Do not claim support for other crops or treat predictions as treatment advice.

## Password reset demo

SMS delivery is not configured. The backend defaults `TANODBUKID_DEV_OTP` to `0`, which keeps simulated codes disabled. To test the simulated reset-code flow locally only, change `TANODBUKID_DEV_OTP=1` in your private `.env`, then recreate the backend:

```sh
docker compose up -d --no-deps --force-recreate backend
docker compose exec backend printenv TANODBUKID_DEV_OTP
```

The local app displays a development code only in this mode. Set it back to `0` and recreate the backend before opening any internet-accessible tunnel. Never run the development OTP flow on a public URL.

## Temporary phone demo

Use test accounts and data only. A Cloudflare Quick Tunnel is publicly reachable by anyone with its URL; it is not a production deployment.

1. Confirm `docker compose exec backend printenv TANODBUKID_DEV_OTP` prints `0`.
2. In a new PowerShell or Ubuntu terminal, start the temporary tunnel using Docker Desktop:

   ```powershell
   docker run --rm cloudflare/cloudflared:latest tunnel --no-autoupdate --url http://host.docker.internal:5173 --protocol http2
   ```

   Keep this terminal open. Cloudflare prints a temporary HTTPS `trycloudflare.com` URL; open that exact URL on the phone. If you already have `cloudflared` installed in WSL, you can instead run:

   ```sh
   cloudflared tunnel --url http://localhost:5173 --protocol http2
   ```

3. Open the generated HTTPS `trycloudflare.com` URL on the phone. HTTPS is needed for browser camera access.
4. Stop the tunnel with Ctrl+C as soon as the supervised demo ends. The laptop, Docker Desktop, and tunnel must remain running during the demo.

Do not expose the app if the backend reports development OTP as enabled. Quick Tunnel URLs change on restart and do not provide access control.

## Common problems

- **The page does not open:** make sure Docker Desktop is running, then check `docker compose ps` and `docker compose logs --tail=50 frontend`.
- **MySQL does not become healthy:** inspect `docker compose logs --tail=80 database`. Initialization SQL runs only the first time a fresh named volume is created; existing volumes are not reset by changing migration files.
- **Login or session errors:** check `docker compose logs --tail=50 backend` and inspect `/api/session.php` in the browser network panel. Requests should use root-relative `/api/...` URLs, not a path nested under `/dashboard/`.
- **The AI scanner is slow or unavailable:** inspect `docker compose logs --tail=50 ai`. The first model download and CPU inference can take longer than usual.
