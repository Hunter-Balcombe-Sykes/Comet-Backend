# Partna Backend API

Laravel API for Partna.

- Public mini-site API (domain-scoped, unauthenticated)
- Barber dashboard API (authenticated via Supabase JWT)
- Staff API (authenticated staff-only tooling)

## Docs

- API Reference: [docs/api.md](docs/api.md)

## Key concepts

### Two hosts matter
Public mini-site endpoints must be called on the **mini-site host**, not the API host.

- API host (Laravel): `APP_URL` (example: `https://api.sidest.co`)
- Mini-site host: `https://{subdomain}.{SIDEST_PUBLIC_DOMAIN}`
- Public API base: `https://{subdomain}.{SIDEST_PUBLIC_DOMAIN}/api`

### Authentication
Partna uses **Supabase Auth** access tokens (JWT).

Send on authenticated endpoints:
- `Authorization: Bearer <SUPABASE_ACCESS_TOKEN>`
- `Accept: application/json`

Partna does not provide a login endpoint. The frontend signs in via Supabase and forwards the token.

### Media uploads
Uploads are direct from the frontend to **Supabase Storage**.  
The backend provides validated upload paths via `POST /api/uploads/prepare`.

## Quickstart (local)

### 1) Install
```bash
composer install
```

### 2) Configure env
```bash
cp .env.example .env
php artisan key:generate
```

Set at minimum:
- `APP_URL`
- `SIDEST_PUBLIC_DOMAIN`
- Database connection variables
- Supabase JWT verification variables (issuer/audience/JWKS)

See the full env checklists in [docs/api.md](docs/api.md#13-frontend-env-var-checklist).

### 3) Run
```bash
php artisan serve
```

Tip: use a wildcard dev domain like `localtest.me` or `lvh.me` so subdomains resolve locally:
- `SIDEST_PUBLIC_DOMAIN=localtest.me`
- `APP_URL=http://api.localtest.me`

## Common workflows

### Schema migrations
Schema changes are managed in `supabase/migrations` only.

- Do not add Laravel migration files in `database/migrations`.
- Apply schema updates with your Supabase migration workflow (for example `supabase db push` in environments that use the Supabase CLI).

### Bootstrap a new user
After the frontend has a Supabase access token, call:
- `POST /api/bootstrap`

Without bootstrap, barber/professional endpoints will fail with a forbidden error.

## Contributing

Recommended branch names:
- `feat/<short-name>`
- `fix/<short-name>`

Open a PR for review (even if it’s just two of you — it’s like spellcheck, but for API changes).

## License
Private project (update if you plan to open source).
