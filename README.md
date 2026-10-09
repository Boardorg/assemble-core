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

Run on demand (no schedule). A refresh that parses badly keeps the last good copy: an empty or much shorter list replaces nothing, and a summit whose home page can't be read keeps its previous details. Templates read only `assemble_summits()` (upcoming, soonest first; filter `assemble/summits`), so the source can later become EP's own feed or Contentful without template changes.

Tests (local, offline against a fake EP): `npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/summits-test.php`
