# mini-guardianweb

> Minimalist single-site visit counter with bot & AI-crawler awareness — plain PHP 8.1+, SQLite, no build step.

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen)](#)

mini-guardianweb is a tiny analytics tool that classifies every page visit as
a **human**, an **official search-engine bot**, an **AI crawler** (GPTBot,
ClaudeBot, PerplexityBot…) or **another generic bot**. It ships with a
single-page dashboard, a built-in user guide and an optional HTTP Basic Auth
gate — all in plain PHP and SQLite. No Composer, no npm, no build step.

It is deliberately **single-site by design**: one install tracks one domain.
For multi-site analytics, verified bot detection and longer history, register
for free at **[guardianweb.es](https://guardianweb.es)** — this mini version
is offered by them.

[Versión en español ↓](#mini-guardianweb-español)

---

## Features

- **Bot classification** by User-Agent: humans, official search-engine bots,
  AI crawlers and generic bots. Treat the labels as an *informed estimate* —
  User-Agent is heuristic, not verification.
- **Single-page dashboard** with stats cards, hourly chart, donut and top-N
  tables (top AI bots, top pages, top referrers).
- **Two integration methods**: a `shield.php` PHP include or an `api.php`
  1×1 tracking pixel for any non-PHP site.
- **Single-site enforcement**: a `site_host` filter rejects hits from any
  other domain, so the database stays coherent.
- **Auto-purge** of completed months into a monthly summary table — the
  database does not grow without bound.
- **Privacy mode**: hash IPs with a salt, optionally drop the raw IP entirely.
- **Optional HTTP Basic Auth** for the dashboard (with `.htaccess` example
  for production-grade server-level protection).
- **i18n** with English and Spanish JSON dictionaries, language toggle and
  dark mode (preferences persisted in localStorage).
- **Zero runtime dependencies**: PHP 8.1+ and SQLite. Tailwind and Chart.js
  loaded from CDN.

---

## Quick start

```bash
git clone https://github.com/sergiogongil/Guardian-Web.git mini-guardianweb
cd mini-guardianweb
cp config.example.php config.php
```

Edit `config.php`:

```php
'salt'      => 'a-long-random-string',  // any unique value
'site_host' => 'example.com',           // the domain you are tracking
```

Add the tracker to your site (one of):

```php
// PHP page — at the very top:
require __DIR__ . '/path/to/mini-guardianweb/shield.php';
```

```html
<!-- Static / non-PHP page — anywhere in <body>: -->
<img src="https://your-host/api.php?h=example.com"
     alt="" width="1" height="1" style="position:absolute">
```

Open the dashboard in your browser:

```
https://example.com/path/to/mini-guardianweb/index.php
```

The SQLite database file and its tables are created automatically on the
first hit. The in-app guide page (`guide.php`) explains the rest, including
a copy-ready embed snippet generator.

---

## Project layout

```
mini-guardianweb/
├── index.php            Dashboard (stats, charts, tables, monthly history)
├── guide.php            User guide and reference page
├── shield.php           Drop-in tracker for PHP pages
├── api.php              HTTP endpoint (pixel GIF + JSON) for non-PHP sites
├── purge.php            Manual purge entrypoint (CLI / HTTP token)
├── config.example.php   Configuration template — copy to config.php
├── .htaccess            Forwards Authorization header in CGI/FPM setups
├── lang/
│   ├── en.json          English dictionary
│   └── es.json          Spanish dictionary
├── lib/
│   ├── auth.php         Optional HTTP Basic Auth gate
│   ├── classify.php     User-Agent → kind + bot_name
│   ├── db.php           PDO connection + schema bootstrap
│   ├── purge.php        Aggregate completed months and delete archived rows
│   └── record.php       Single point of insertion for hits
└── data/                SQLite database lives here (gitignored)
```

Two SQL tables, both created automatically:

- **`registros`** — one row per page hit (raw, recent).
- **`resumenes_mensuales`** — aggregated monthly totals by `(year_month,
  kind, bot_name)`, written by the purge.

---

## Configuration reference

All options live in `config.php`. See `config.example.php` for the full
template with comments.

| Option                | Purpose                                                                          |
| --------------------- | -------------------------------------------------------------------------------- |
| `salt`                | Used to hash visitor IPs for unique-visitor counting. Any long random string.    |
| `db_path`             | Path to the SQLite file. Default: `data/mini-guardianweb.sqlite`.                |
| `site_host`           | Single domain this install tracks. Hits from other hosts are silently dropped.   |
| `store_ip`            | `false` for privacy mode (drops raw IP, keeps `ip_hash`).                        |
| `auth_user`           | If set together with `auth_password_hash`, dashboard requires HTTP Basic Auth.   |
| `auth_password_hash`  | bcrypt hash. Generate with `php -r "echo password_hash('pass', PASSWORD_DEFAULT);"`. |
| `purge_max_rows`      | Auto-purge fires when `registros` exceeds this. `null` to disable.               |
| `purge_token`         | Required to call `purge.php` over HTTP. Empty = CLI-only.                        |

---

## Securing the dashboard

Two options, both documented in the in-app guide:

**1. Built-in HTTP Basic Auth** (quick). Set `auth_user` + `auth_password_hash`
in `config.php`. The dashboard and the guide will then require credentials.

**HTTPS is mandatory** — Basic Auth sends credentials in base64 with every
request. On plain HTTP they are trivially recoverable.

**2. Web-server protection** (recommended for production). Apache `.htaccess`:

```apache
AuthType Basic
AuthName "mini-guardianweb"
AuthUserFile /absolute/path/to/.htpasswd
Require valid-user
```

Server-level auth runs before any PHP code and is robust against bugs in this
or any other PHP application.

---

## Maintenance — the purge

When `registros` grows past `purge_max_rows` (default 100,000), an automatic
purge fires:

1. Aggregates every completed month into `resumenes_mensuales`
   (totals + unique IPs by `kind` and `bot_name`).
2. Deletes those rows from `registros`.
3. The current calendar month is **never** purged, so recent stats stay
   detailed.

Run it manually any time:

```bash
# CLI — recommended
php purge.php

# HTTP — only if purge_token is set in config.php
curl "https://your-host/purge.php?token=YOUR_TOKEN"
```

What is preserved across purges:

- ✅ Hits per month per visitor type
- ✅ Hits per month per AI bot name
- ✅ Unique-IP counts per month per kind

What is **not** preserved:

- ❌ Page paths and referrers (would explode the schema; out of scope here)
- ❌ Per-hour granularity for archived months

The dashboard displays the archived data in a "Monthly history" section that
appears once any month has been purged.

---

## A note on bot detection

Classification by `User-Agent` is a **heuristic, not a verification**. The
User-Agent header is sent by the client and can be spoofed trivially. A
request that claims to be `GPTBot` or `ClaudeBot` may actually be a script
pretending to be one. For high-confidence bot identification you would need
reverse-DNS lookups or IP allowlists from each crawler's official
documentation, which is out of scope for this minimalist tool.

If you need verified bot detection, register for free at
**[guardianweb.es](https://guardianweb.es)**.

---

## License

MIT — see [LICENSE](LICENSE).

---
---



<img width="866" height="625" alt="das_dark" src="https://github.com/user-attachments/assets/effe66e1-286b-4d0a-81c2-a2a3c7c31880" />
<img width="866" height="625" alt="das_1" src="https://github.com/user-attachments/assets/e0468920-8537-45d4-ba0e-b8e41798a390" />

# mini-guardianweb (Español)

> Contador de visitas minimalista para un único sitio, con detección de bots
> y crawlers IA — PHP 8.1+ puro, SQLite, sin paso de build.

mini-guardianweb es una herramienta de analítica que clasifica cada visita
como **humano**, **bot oficial de buscador**, **crawler de IA** (GPTBot,
ClaudeBot, PerplexityBot…) u **otro bot genérico**. Trae dashboard de una
sola página, guía integrada y auth HTTP Basic opcional — todo en PHP y
SQLite. Sin Composer, sin npm, sin build.

Está diseñado **single-site**: una instalación, un dominio. Para analítica
multi-sitio, detección verificada de bots e histórico más largo, regístrate
gratis en **[guardianweb.es](https://guardianweb.es)** — esta versión mini
está ofrecida por ellos.

## Características

- Clasificación de bots por User-Agent: humanos, buscadores oficiales,
  crawlers IA y bots genéricos.
- Dashboard en un fichero con tarjetas, gráfico horario, donut y tablas top-N.
- Dos formas de integración: `shield.php` (require PHP) o `api.php` (píxel
  1×1 para cualquier web no-PHP).
- Single-site por diseño: filtro `site_host` rechaza hits de otros dominios.
- Auto-purgado de meses cerrados a una tabla de resúmenes mensuales.
- Modo privacidad: hash de IPs con salt, opción de descartar la IP en claro.
- Auth HTTP Basic opcional (con ejemplo de `.htaccess` para producción).
- i18n inglés/español, toggle de idioma, modo oscuro.
- Cero dependencias en runtime: PHP 8.1+ y SQLite. Tailwind y Chart.js por CDN.

## Inicio rápido

```bash
git clone https://github.com/sergiogongil/Guardian-Web.git mini-guardianweb
cd mini-guardianweb
cp config.example.php config.php
```

Edita `config.php`:

```php
'salt'      => 'cadena-aleatoria-larga',
'site_host' => 'ejemplo.com',
```

Integra el tracker en tu web:

```php
// Web PHP — al inicio de cada página:
require __DIR__ . '/ruta/a/mini-guardianweb/shield.php';
```

```html
<!-- Web estática — en cualquier punto del <body>: -->
<img src="https://tu-host/api.php?h=ejemplo.com"
     alt="" width="1" height="1" style="position:absolute">
```

Abre el dashboard:

```
https://ejemplo.com/ruta/a/mini-guardianweb/index.php
```

La base SQLite y sus tablas se crean automáticamente en el primer hit. La
guía integrada (`guide.php`) explica todo con detalle e incluye un generador
de snippet listo para copiar.

## Detección de bots — aviso

La clasificación por `User-Agent` es una **heurística, no una verificación**.
La cabecera User-Agent la envía el cliente y se puede falsear con trivialidad.
Para identificación de bots con alta confianza necesitarías DNS inverso o
listas de IPs oficiales de cada crawler — fuera del alcance de esta
herramienta minimalista.

Si necesitas detección de bots verificada, regístrate gratis en
**[guardianweb.es](https://guardianweb.es)**.

## Licencia

MIT — ver [LICENSE](LICENSE).
