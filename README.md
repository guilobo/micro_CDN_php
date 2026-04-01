# CDN Manager

A lightweight CDN manager with a React frontend, a PHP backend, and a very simple file API.

This project is built for straightforward hosting environments where PHP is available and the public web root can point to `public/`. It gives you a small dashboard for browsing and editing files inside the CDN directory, plus a simple API for creating, replacing, renaming, and deleting files or folders.

## Highlights

- Simple React dashboard for browsing CDN files
- PHP API with session-based dashboard authentication
- API key authentication for external integrations
- File upload, create, edit, rename, and delete support
- Folder creation and recursive folder deletion
- Designed for classic shared hosting layouts

## Project Structure

```text
project/
  .env
  .env.example
  app/
    bootstrap.php
  public/
    .htaccess
    index.php
    router.php
    api/
      index.php
    build/
    cdn/
  src/
  package.json
  vite.config.ts
```

## How It Works

- `public/` is the public web root
- `.env` stays outside `public/`, so it is not directly exposed on the web
- `app/bootstrap.php` loads the environment and shared backend helpers
- `public/api/index.php` exposes the dashboard and API routes
- `public/cdn/` is the storage area managed by the application

## Quick Start

1. Copy `.env.example` to `.env`
2. Set your dashboard credentials, API key, and session secret
3. Install dependencies with `npm install`
4. Build the frontend with `npm run build`
5. Point your web server document root to `public/`

## Environment Variables

Example `.env`:

```env
# Dashboard credentials
ADMIN_USERNAME="admin"
ADMIN_PASSWORD="password123"

# Simple API key used by external file operations
api_key="replace-with-a-secure-api-key"

# Session security
SESSION_SECRET="replace-with-a-secure-session-secret"

# Optional debug flag
DEBUG="false"
```

## Dashboard API

These routes are used by the web interface and rely on the login session:

- `POST /api/login`
- `POST /api/logout`
- `GET /api/me`
- `GET /api/files`
- `POST /api/mkdir`
- `GET /api/read`
- `POST /api/save`
- `POST /api/rename`
- `DELETE /api/delete`
- `POST /api/upload`

## Simple External API

The external API uses `api_key` authentication. You can send the key in:

- `X-API-Key`
- `Authorization: Bearer ...`
- the JSON body as `api_key`

### Create or overwrite a file

`POST /api/key/upsert`

```json
{
  "api_key": "your-api-key",
  "path": "clients/demo/example.txt",
  "type": "file",
  "content": "Hello from the simple CDN API",
  "overwrite": true
}
```

### Create a folder

`POST /api/key/upsert`

```json
{
  "api_key": "your-api-key",
  "path": "clients/demo/uploads",
  "type": "directory",
  "overwrite": false
}
```

### Rename a file or folder

`POST /api/key/rename`

```json
{
  "api_key": "your-api-key",
  "path": "clients/demo/example.txt",
  "newName": "example-renamed.txt"
}
```

You can also send `newPath` if you want to move and rename in a single request.

### Delete a file or folder

`POST /api/key/delete` or `DELETE /api/key/delete`

```json
{
  "api_key": "your-api-key",
  "path": "clients/demo/example-renamed.txt"
}
```

## Local Development

Install dependencies:

```bash
npm install
```

Run type checking:

```bash
npm run lint
```

Build the frontend:

```bash
npm run build
```

Run the PHP app locally:

```bash
php -S localhost:8000 -t public public/router.php
```

Then open `http://localhost:8000`.

## Production Deployment

Upload these items outside the public web root:

```text
app/
.env
```

Upload these items inside the public web root (`public/` or `public_html/`):

```text
public/.htaccess
public/index.php
public/router.php
public/api/
public/build/
public/cdn/
```

Notes:

- Keep `public/cdn/` in place even if it starts empty
- If your host uses `public_html`, move the contents of `public/` into `public_html`
- Keep `app/` and `.env` one level above the public web root

## Not Required In Production

These files are development-only and usually do not need to be deployed:

```text
src/
node_modules/
.vscode/
dist/

index.html
package.json
package-lock.json
tsconfig.json
vite.config.ts
.env.example
README.md
metadata.json
```
