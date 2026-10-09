# Capacity (PHP)

Intranet capacity-planning app for Autoneum. This folder is the development and deploy project.

- **API:** PHP 8 under `api/` (SQLite in `data/capacity.db`)
- **UI:** React + Vite under `client/`
- **Reference:** the Node app in `../capacity-app` stays as the original implementation

## Local development

1. Install UI dependencies once:

```bash
cd client
npm install
cd ..
```

2. Start the PHP API from this folder:

```bash
npm run php:api
```

That runs `tools/serve.ps1`, which starts PHP at `http://127.0.0.1:8765` using the full path to `tools/dev_router.php`. A relative `tools/dev_router.php` fails when the command is started from another directory.

3. Start the React app (proxies `/capacity/api` to the PHP server):

```bash
npm run dev
```

Open the Vite URL (port 5173). Login: `admin` / `Admin12345` unless you changed it.

## Production build

```bash
npm run build
```

Builds into `client/dist`, then publishes `index.html`, `assets/`, and public images into this folder (next to `api/` and `web.config`). Copy those published files plus `api/` to the IIS site `public\capacity`.

Do not copy `data\capacity.db` over a live database unless you intend to replace it.

## Plan status

Points 1–12 and 14 are in this project. Point 13 (block `*.db` download, remove public `phpinfo`, PHP limits) waits for IT confirmation of IIS / `php.ini` changes.
