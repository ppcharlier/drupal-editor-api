# Editor API (Drupal)

Exposes the **Editor API** contract — `/api/editor/v1` — on a Drupal 11 site, so the
**Editor for Drupal** iOS app can browse, write, publish and restore content without
knowing anything about Drupal. The contract is the one of the Statamic addon
[`ppcharlier/statamic-editor-api`](https://github.com/ppcharlier/statamic-editor-api):
same envelopes, same capabilities, same error codes.

Version 0.1 — pre-release. The contract path stays `/api/editor/v1` whatever the module version.

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

## What maps to what

| Editor API | Drupal |
| --- | --- |
| collection / blueprint | content type (one blueprint per type, handle = machine name) |
| entry | node; `slug` = last segment of the URL alias (`/{type}/{slug}`), `date` = created |
| field handles | field machine names, verbatim (`body`, `field_hero`) |
| `text_long` / `text_with_summary` fields | type `html`, value served and stored **verbatim**, text format kept; `config.allowed_html` lists what the format allows |
| drafts & revisions | Content Moderation when the type uses a workflow (`revisions_enabled: true`); otherwise writes are direct and `/revisions` answers `422 revisions_disabled` |
| unpublish under a workflow | the first state that is unpublished *and* a default revision (`archived` in the standard editorial workflow) |
| taxonomy / term, assets / media | plan 2 of the roadmap |
| globals, navigations, forms, templates, multi-site | not in 0.1 — empty in `/config` |

## Tests

Kernel tests drive the real HTTP kernel (routing, authentication, access, error envelopes):

```bash
cd web && ../vendor/bin/phpunit -c ../phpunit.xml modules/custom/editor_api/tests/src/Kernel
```

See `docs/superpowers/specs/2026-09-04-editor-api-drupal-design.md` for the design.

## License

GPL-2.0-or-later.
