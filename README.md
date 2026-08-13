# Apiunto

Apiunto lets MediaWiki templates pull live data from REST APIs. Configure an API source once in `LocalSettings.php`, then fetch from it in Lua with `mw.ext.Apiunto.fetch()`. Responses are cached automatically, so repeated lookups don't re-hit the upstream API.

[![CI](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/actions/workflows/ci.yml/badge.svg)](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/actions/workflows/ci.yml)

## Requirements

- MediaWiki 1.43 or later
- PHP 8.1 or later
- [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto) (hard dependency)

## Installation

Clone or download this repository into your `extensions/` directory, then load it in `LocalSettings.php`:

```php
wfLoadExtension( 'Apiunto' );

$wgApiuntoSources = [
    'StarCitizenWikiAPI' => [
        'baseUrl' => 'https://api.star-citizen.wiki/api/',
        'token' => '', // optional
        'timeout' => 5, // optional, default 5
        'cacheDuration' => 3600, // optional, default 86400
        'followRedirects' => false, // optional, default false
    ],
];
```

## Usage

### Lua

```lua
local api = mw.ext.Apiunto

-- Request data for the Greycat Industrial ROC
-- Docs: https://docs.star-citizen.wiki/v2
-- Output: https://api.star-citizen.wiki/api/v2/vehicles/ROC
local roc = api.fetch( 'StarCitizenWikiAPI', 'v2/vehicles/ROC' )
```

`fetch( source, uri, args )` takes:

- **`source`** — a source name from `$wgApiuntoSources`
- **`uri`** — the endpoint path to request
- **`args`** *(optional)* — a table of query parameters to append. Array values are joined with commas; empty values are dropped.

```lua
-- Resolves to v2/vehicles?manufacturer=AEGS&limit=10
local ships = api.fetch( 'StarCitizenWikiAPI', 'v2/vehicles', {
    manufacturer = 'AEGS',
    limit = 10,
} )
```

## Configurations

| Variable | Default | Description |
| --- | --- | --- |
| `$wgApiuntoSources` | `[]` | An array of API sources. Each source accepts `baseUrl`, and optional `token`, `timeout` (seconds, default `5`), `cacheDuration` (seconds, default `86400`), and `followRedirects` (default `false`). |
| `$wgApiuntoEnableCache` | `true` | Whether to cache API responses. |

### Following redirects

By default a redirect response is returned as-is, so a `3xx` reaches Lua as the
upstream's redirect body rather than the record it points at. Set
`followRedirects => true` on a source whose API exposes a resolver endpoint —
one that answers `/search/{id}` with a `302` to the canonical record URL — so
`fetch()` returns the resolved record in a single call.

MediaWiki caps the chain at 5 hops, and Guzzle strips the `Authorization` and
`Cookie` headers on a cross-origin hop, so an enabled source cannot leak its
token to another host. Responses are cached under the *requested* URL, not the
resolved one.

## Caching

Each response is cached for its source's `cacheDuration`. Purging a page (`action=purge`) clears the Apiunto responses cached for it, and a page's information page (`action=info`) shows the current cache status, URL, and time remaining.

To purge the entire cache from the command line:

```sh
php maintenance/run.php extensions/Apiunto/maintenance/purgeCache.php
```

Add `--dry-run` to preview what would be purged without deleting anything.
