# Changelog

## 3.0.0 (2026-09-03)

First release from [StarCitizenTools/mediawiki-extensions-Apiunto](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto), now the canonical repository. The original [StarCitizenWiki/Apiunto](https://github.com/StarCitizenWiki/Apiunto) is archived and 2.0.0 is its last release.

Apiunto is no longer Star Citizen specific. Where 2.x was built around a single hard-coded API, 3.0.0 talks to any number of REST APIs declared in `$wgApiuntoSources`.

### ⚠ BREAKING CHANGES

* **config:** the single-API settings `$wgApiuntoUrl`, `$wgApiuntoKey`, `$wgApiuntoTimeout`, `$wgApiuntoApiVersion`, `$wgApiuntoDefaultLocale` and `$wgApiuntoCacheTimes` are all removed, replaced by one `$wgApiuntoSources` map of named sources. `$wgApiuntoEnableCache` is unchanged. ([92a1183](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/92a118387ef9b7c0f8a9da38f78a950b1ff20960))
* **config:** a source's `baseUrl` must now include the API's full path. 2.x appended `api/` on its own, so `https://api.star-citizen.wiki` becomes `https://api.star-citizen.wiki/api/`. ([92a1183](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/92a118387ef9b7c0f8a9da38f78a950b1ff20960))
* **lua:** `mw.ext.Apiunto.get_raw( uri, args )` is renamed to `mw.ext.Apiunto.fetch( source, uri, args )` and takes the source name as its new first argument. ([5c4bd6c](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/5c4bd6cfbd6294e6c8f808b379772070c21a5dbf))
* **lua:** the thirteen Star Citizen endpoint helpers are removed: `get_ship`, `get_ground_vehicle`, `get_manufacturer`, `get_comm_link_metadata`, `get_starsystem`, `get_celestial_object`, `get_galactapedia`, `get_weapon_personal`, `get_char_armor`, `get_cooler`, `get_power_plant`, `get_quantum_drive` and `get_shield`. Call `fetch()` with the endpoint path instead. ([80745cb](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/80745cba7987b54b319686eff7a011981612c1cf))
* **lua:** `args` is no longer filtered down to `limit`, `page`, `locale` and `include`; every key is now forwarded as a query parameter. A module that relied on unknown keys being silently dropped will start sending them. ([92a1183](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/92a118387ef9b7c0f8a9da38f78a950b1ff20960))
* **lua:** no `locale` is appended automatically now that `$wgApiuntoDefaultLocale` is gone. Modules that depended on the wiki-wide default must pass `locale` themselves. ([92a1183](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/92a118387ef9b7c0f8a9da38f78a950b1ff20960))
* **cache:** cache keys are now derived from the full request URL and hashed, and the `apiuntocache` page property holds a JSON manifest rather than a single key. Entries written by 2.x are unreachable and will expire on their own; run `maintenance/purgeCache.php` after upgrading to clear them. ([1fa0abd](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/1fa0abd8b75c4c34f604776fdd9f6617a3437760))
* requires MediaWiki 1.43 or later and PHP 8.1 or later, up from 1.39 and 7.3. ([9897c1a](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/9897c1afa5f9170a8cc1ea4a247b40661f9332d9))
* the Composer package is renamed from `starcitizenwiki/apiunto` to `starcitizentools/apiunto`. ([cd77e1e](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/cd77e1e3441695b271d8b94ba5c159d9c7726361))

### Features

* support any number of named REST API sources via `$wgApiuntoSources`, each with its own `baseUrl`, `token`, `timeout` and `cacheDuration` ([92a1183](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/92a118387ef9b7c0f8a9da38f78a950b1ff20960))
* rename `get_raw` to `fetch` ([5c4bd6c](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/5c4bd6cfbd6294e6c8f808b379772070c21a5dbf))
* add a per-source `followRedirects` option for APIs that expose a resolver endpoint, one answering `/search/{id}` with a `302` to the canonical record. Each hop is issued as its own request, capped at three, and a source's `token` is dropped on a redirect that leaves the host in its `baseUrl` ([c9b0112](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/c9b01125ec96ca1362648fe42102831c3cbe7403), [601b36c](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/601b36cdc072ee99313888073ba83011f24926ba))
* show live cache status, request URL and remaining duration on `action=info` ([b356db5](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/b356db589d2c8d3236364970bdd1a2b1bafe6b65), [e5cffce](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/e5cffce2c5e8fda68a7a58dff30c4fee997df333), [63642e3](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/63642e3492377752e0816a903ce4f9a0abb41573))
* deduplicate identical requests within a single page render ([9177d41](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/9177d41947db33afc3db9035e99d2ab3fdaf2555))
* key the cache on the full request URL so differing query arguments no longer collide ([1eed660](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/1eed660cef955c8b2f09bc59744c54be5a2d0add))
* hash the cache key and record the readable request URL alongside it ([1fa0abd](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/1fa0abd8b75c4c34f604776fdd9f6617a3437760))

### Bug Fixes

* purge every cache key a page populates. 2.x stored only one key in the `apiuntocache` property, overwriting it on each call, so a page fetching several endpoints purged just the last one and left the others cached until they expired ([a354c00](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/a354c00d3019c67c2220e591c369bf1864598ff8))
* correct the inverted `--dry-run` check in `purgeCache.php`, which in 2.x deleted only when `--dry-run` was passed and did nothing otherwise ([9591eec](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/9591eecfaf5565d09e10756fae980cc58231b218))
* stop writing stale content back under a fresh TTL. 2.x re-cached the stale response it served, so a brief upstream outage extended itself by another full cache duration; the entry is now left untouched and the next request retries upstream ([e3b6c8d](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/e3b6c8d0d299ab9f2ef8f94d61b0f803e3b80d74))
* describe the extension generically, as a Lua bridge to REST APIs rather than to the Star Citizen Wiki API ([4a95738](https://github.com/StarCitizenTools/mediawiki-extensions-Apiunto/commit/4a95738e72e1a8a9662d7ac3a9666cb289139970))

## 2.0.0 and earlier

Released from the archived [StarCitizenWiki/Apiunto](https://github.com/StarCitizenWiki/Apiunto) repository, which has no changelog. See its [commit history](https://github.com/StarCitizenWiki/Apiunto/commits/master) for the record.
