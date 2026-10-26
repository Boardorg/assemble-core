# Assemble Core

Public-site plugin for theassemble.com. It holds content **authored in WordPress**: ACF field groups (as ACF JSON in `acf-json/`), the Site Settings page, free accounts, the host-based noindex guard, redirects, and the idempotent `wp assemble setup` command. It also ships the mu-plugin that forces `ac_bypass_mode` to `off` on production.

Content authored in Contentful belongs to [assemble-content](https://github.com/Boardorg/assemble-content) instead. Nothing here is shared with the Member Center.

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`). Production deploys are manual and need a reviewer's approval. No secrets in this repo, ever: it is public.

## Standard pages and the masthead menu: `wp assemble setup`

```
wp assemble setup [--dry-run] [--content-dir=<dir>] [--adopt-elementor] [--adopt-um]
```

Creates what's missing on any install (local, the beta, a clean copy at launch) and never overwrites what exists, so a second run changes nothing:

- the standard pages at today's theassemble.com URLs: `/about-us/`, `/our-team/`, `/careers/`, `/contact-us/`, `/contact-us/thank-you/`, `/policies/` (filter `assemble/setup_pages`);
- the account pages at the paths of the Site Settings account URLs (`/login/`, `/register/`, `/account/`; filter `assemble/setup_account_pages`), created empty because their templates render the forms. `--adopt-um` empties an account page that still holds an Ultimate Member shortcode and retitles it, once (the old content stays as a revision);
- the "Masthead" menu (Insights → the Field Reports archive, Communities → the `communities_url` Site Setting, Summits → the Summits page; filter `assemble/setup_primary_items`), assigned to the theme's `primary` location unless a menu already holds it. The footer stays on Site Settings → Footer columns.

Page copy is content, not code, so it isn't in this public repo. A new page gets a one-line placeholder, or `<dir>/<slug>.html` (block markup; a child page's file is `<parent>--<slug>.html`) with `--content-dir`. `--adopt-elementor` also replaces the content of a standard page that is still an Elementor page with its file, resets its template and sentence-cases its title; WordPress keeps the old content as a revision, and the page is marked so it's adopted once. Content files are trusted (they may embed a form script), so setup writes them without kses.

## Redirects from the old site

`inc/redirects.php` holds the 301s for old theassemble.com URLs that aren't rebuilt (plan: the project hub's BUILD-INSTRUCTIONS §9.5). Paths match without a trailing slash and case-insensitively, the query string is passed on, and they fire before WordPress picks a template, so an old page that still exists on the beta redirects too. Add or change one with the `assemble/redirects` filter or in the map. The old Ultimate Member URLs go to the new account flow (`/password-reset/` → Sign in's reset state; `/account-confirmation/`, `/user/`, `/members/`, `/networking-profile/` → Account) and `/my-report/` to the `benchmark_url` setting (Phase 7). `/login/`, `/register/` and `/account/` were rebuilt at the same URLs, and `/logout/` is a handler, so none of them is in the map; nor are the other benchmark pages (decision #10).

## Accounts (Phase 7)

Free accounts are ordinary WordPress users (role `subscriber`) until Mark's SSO plugin arrives in a later phase; newsletters are a later phase too. The theme renders the pages (`page-login.php`, `page-register.php`, `page-account.php`); everything that decides anything is here.

- **URLs:** `assemble_account_url( 'login'|'register'|'account'|'lostpassword'|'logout', $redirect )` from the Site Settings `sign_in_url`, `create_account_url` and `account_url` (filter `assemble/account_url`). WordPress's own login, register, lost-password and logout links point at them, except sign-in redirects into wp-admin, which keep wp-login.php (and its two-factor step).
- **Forms** (`inc/account-forms.php`): Create account, Sign in, lost and reset password, Save, Sign out, each posting back to its page; `assemble_account_state()` is what the theme reads. Sign-in goes through `wp_signon()`, so `authenticate` filters (SSO) keep working. New accounts are signed in at once and emailed a link to set their password. Every account page is uncacheable. Free accounts never see the admin bar and are sent from wp-admin to the Account page.
- **User meta:** `assemble_company`, `assemble_job_title`, `assemble_follow_areas` (practice-area keys), `assemble_follow_communities` (Community slugs, kept only inside a followed area). Editable on the user screens (`inc/account-admin.php`), with Company and Follows columns in the Users list.
- **Seams for SSO:** `assemble/account/create` (create the user elsewhere), `assemble/account/audiences` (membership), `assemble/account_url`.
- **The Field Report gate:** one `ac_current_audiences` callback adds `assemble_user_audiences()` for signed-in users; the feed's gate is not changed. Until SSO, admins can set **test audiences** on a user (`assemble_test_audiences`, `promote_users` only); they are ignored on theassemble.com, whatever the database says.
- **Free-read meter** (`inc/free-reads.php`): turns on the feed's `ac_public_full_text`, so reports ticked Public carry their full text, and the theme's meter swaps them for a create-account prompt after `free_reads` (Site Setting, default 2) reads per 8 hours for signed-out visitors. A soft meter, counted in the browser because of WP Engine's page cache; members-only reports are never affected.
- **Mail guard** (`inc/mail-guard.php`): on every host except theassemble.com, WordPress mail goes only to addresses in the `assemble_mail_allowlist` option (one per line; `name@x.com` also allows `name+tag@x.com`). Everything else is dropped and noted; `wp assemble mail held` lists it (with the body on a local site, so set-password links can be read there).

Tests (local, creates and deletes a test user): `npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/accounts-test.php`

## The team

One `team` post per person at `/our-team/<slug>/`, with `name`, `position` and `bio` fields and the headshot as the featured image. On the beta (and so at launch) the post type and its fields are defined in Secure Custom Fields' admin; `inc/team.php` registers the same post type only where nothing else has, and `assemble_team_members()` / `assemble_team_member()` read people for the theme with or without the fields plugin. People data stays in WordPress, never in this repo.

Tests (local): `npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/setup-test.php`

## Summits (read from Executive Platforms' summit API)

The Executive Platforms sites stay the source of truth for summit facts. `inc/summits-ep.php` reads EP's public summit API (`https://www.executiveplatforms.com/wp-json/ep/v1/summits?v=1`, live since 2026-10-15; reference: the hub's `EP-SUMMITS-API-SPEC.md`) and copies each summit's current edition into the `assemble_ep_summits` option. It never writes to EP. Until 0.7.0 it read EP's web pages instead (17 requests); that code is gone.

```
wp assemble summits refresh [--dry-run]
wp assemble summits list [--all]
```

Per summit: `code`, `short_name`, `title`, `season`, `url`, `register_url`, `agenda_url` (once EP publishes the agenda), `api_url` (the full edition, for later), `logo_url` (EP's white wordmark), `city`, `state`, `venue`, `start`, `end`, `timezone`, `status`, `year`, `modified`. `date_text` and `stats` stay in the shape but are empty: the API has no stats counters, and the theme formats dates from `start` and `end`. The title is whichever of EP's `title` and `site_name` names the edition ("… – Fall Edition"), else `title`.

It runs **hourly** (WP-Cron, `assemble_summits_refresh`; daily before 0.7.0, and the old daily hook is cleared on upgrade) and on demand: one request, sent with the stored `ETag`, so an unchanged list costs a 304 with no body. A refresh that looks wrong keeps the last good copy: an unreachable API, a body that isn't the expected JSON, or a list that's empty or under half as long as before replaces nothing; a summit with no current edition or start date is left out with a warning. Each run's outcome is stored in `assemble_ep_summits_status`; when the last run failed, or nothing has refreshed for three days, administrators see a notice in wp-admin and `wp assemble summits list` prints a warning. Templates read only `assemble_summits()` (upcoming, soonest first; filter `assemble/summits`).

**Assemble-only extras** (`inc/summits-extras.php`, agreed with Cale 2026-10-09): what EP doesn't keep, per summit code. `area` (the `data-area` colour; '' renders neutral, as for Life Sciences), `area_label` (the eyebrow, in EP's practice areas), `communities` (Community slugs it's featured under; `['*']` for every Community in its area), `blurb` (the one-line card description) and `logo` (a colour logo in the shared image library, relative to `wp-content/assets/`). `assemble_summits_with_extras()` merges them in; an extra never overrides an EP fact, and an unmapped summit renders neutral with no blurb or logo. Change one without a commit through the `assemble/summit_extras` filter.

Tests (local, offline against a fake EP API, 42 checks): `npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-core/tests/summits-test.php`
