# Editor API (Drupal)

Exposes the **Editor API** contract — `/api/editor/v1` — on a Drupal 11 site, so the
**Editor for Drupal** iOS app can browse, write, publish and restore content without
knowing anything about Drupal. The contract is the one of the Statamic addon
[`ppcharlier/statamic-editor-api`](https://github.com/ppcharlier/statamic-editor-api):
same envelopes, same capabilities, same error codes.

Pre-release. The package version is not written in `composer.json`: drupal.org derives it
from the release tag. The contract path stays `/api/editor/v1` whatever the module version.

## Install

```bash
composer require drupal/editor_api
drush en editor_api
```

Grant **Access the Editor API** (`access editor api`) to the roles that may sign in from
the app. Everything else is decided by Drupal's own access control: node create/edit/delete
permissions, workflow transitions (Content Moderation) or `administer nodes` for publishing.

Tokens live in the `editor_api_token` table; `token_ttl_days` (default 90, 0 = never) in
`editor_api.settings`.

## Try it

The sibling repository `editor-demo` (not published) is a throwaway Drupal 11 in Docker that
carries « Carnet d'Ailleurs », the same demo content as the Statamic addon: exported config,
seeded content, an `editor` account. One `docker compose up` gives you a site the iOS app can
sign in to.

## What maps to what

| Editor API | Drupal |
| --- | --- |
| collection / blueprint | content type (one blueprint per type, handle = machine name) |
| entry | node; `slug` = last segment of the URL alias (`/{type}/{slug}`), `date` = created — written as `Y-m-d` read in the site's timezone: sending the current day leaves `created` untouched, another day keeps the time of day |
| field handles | field machine names, verbatim (`body`, `field_hero`) |
| `datetime` fields | type `date`; served as an ISO 8601 instant in UTC (`2026-06-15T17:30:00+00:00`); on write, a string with an offset or `Z` is that instant, a string without one (`2026-06-15 19:30`) is wall-clock time in the site's timezone; date-only fields keep the calendar day as sent |
| `text_long` / `text_with_summary` fields | type `html`, value served and stored **verbatim**, text format kept; `config.allowed_html` lists what the format allows |
| drafts & revisions | Content Moderation when the type uses a workflow (`revisions_enabled: true`); otherwise writes are direct and `/revisions` answers `422 revisions_disabled` |
| unpublish under a workflow | the first state that is unpublished *and* a default revision (`archived` in the standard editorial workflow) |
| taxonomy / term | vocabulary / term; `id` = `{vocab}::{tid}`, `slug` = last segment of the term's URL alias (`/{vocab}/{slug}`), else the tid; unpublished terms need `administer taxonomy` |
| asset container / asset | media type with an image or file source / media item; `path` = `{mid}/{basename}`, no folders (`folders: []`, `can.move: false`); upload validated by the source field (extensions, size) and, for images, decodability (`FileIsImage`); `data` = `{ alt, title }` for images, `{ description }` for files; `embed: { entity_type: "file", uuid }` from the source file, omitted when the media item has none — copied by the app onto inserted `<img data-entity-*>` |
| relationship values | a `terms` value is **read** as the bare slug (`bretagne`; the tid as a string when the term has no alias) and **written** as a bare slug, `{vocab}::{slug}` or a tid — resolved by the alias `/{vocab}/{slug}`, then by any alias ending in `/{slug}`, so whatever a read returns is accepted back. A referenced term or media item must be **viewable** by the account, else `422` |
| `timezone` in `/config` | the site's default timezone (`system.date`), `UTC` when unset — the zone in which `date` is read and written |
| globals, navigations, forms, templates, multi-site | not in 0.1 — empty in `/config` |

## Tests

Kernel tests drive the real HTTP kernel (routing, authentication, access, error envelopes):

```bash
cd web && ../vendor/bin/phpunit -c ../phpunit.xml modules/custom/editor_api/tests/src/Kernel
```

See `docs/superpowers/specs/2026-09-04-editor-api-drupal-design.md` for the design.

## License

GPL-2.0-or-later.
