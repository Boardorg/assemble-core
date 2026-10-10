# Assemble Core

Public-site plugin for theassemble.com. It holds content **authored in WordPress**: ACF field groups (as ACF JSON in `acf-json/`), the Site Settings page, the host-based noindex guard, redirects, roles, and the idempotent `wp assemble setup` command. It also ships the mu-plugin that forces `afr_bypass_mode` to `off` on production.

Content authored in Contentful belongs to [assemble-content](https://github.com/Boardorg/assemble-content) instead. Nothing here is shared with the Member Center.

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`). Production deploys are manual and need a reviewer's approval. No secrets in this repo, ever: it is public.

## Summits (interim: read from executiveplatforms.com)

The Executive Platforms sites stay the source of truth for summit facts for now. `inc/summits-ep.php` copies the public summit list into the `assemble_ep_summits` option and never writes to EP:

- `executiveplatforms.com/summits/`: code, title, start date, city, wordmark logo, register link;
- each summit's home page hero: the full date range, the venue, the stats counters.

```
wp assemble summits refresh [--dry-run]
wp assemble summits list [--all]
```

It runs **daily** (WP-Cron, `assemble_summits_daily_refresh`, from 0.4.0) and on demand. A refresh that parses badly keeps the last good copy: an empty or much shorter list replaces nothing, and a summit whose home page can't be read keeps its previous details. Each run's outcome is stored in `assemble_ep_summits_status`; when the last run failed, or nothing has refreshed for three days, administrators see a notice in wp-admin and `wp assemble summits list` prints a warning. Templates read only `assemble_summits()` (upcoming, soonest first; filter `assemble/summits`), so the source can later become EP's own feed or Contentful without template changes.

**Assemble-only extras** (`inc/summits-extras.php`, agreed with Cale 2026-10-09): what EP doesn't keep, per summit code. `area` (the `data-area` colour; '' renders neutral, as for Life Sciences), `area_label` (the eyebrow, in EP's practice areas), `communities` (Community slugs it's featured under; `['*']` for every Community in its area), `blurb` (the one-line card description) and `logo` (a colour logo in the shared image library, relative to `wp-content/assets/`). `assemble_summits_with_extras()` merges them in; an extra never overrides an EP fact, and an unmapped summit renders neutral with no blurb or logo. Change one without a commit through the `assemble/summit_extras` filter.

Tests (local, offline against a fake EP): `npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/summits-test.php`
