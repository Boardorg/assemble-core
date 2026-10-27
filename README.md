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

## Community pages (0.9.0)

Sales pages under `/communities/` are ordinary pages on the theme's **Community page** template, with their copy in Secure Custom Fields (`inc/communities.php`, field groups in `acf-json/`):

- **Community page** (on pages using the template): the page type (Board, Council, Practice area, Communities index), the Community it sells (a dropdown of the Assemble Content feed's Community directory, which supplies the name, logo and practice colour), and the Board's own copy: hero, member count and companies, problem, challenges, its own pillars, upcoming Working Sessions, closing.
- **Community defaults** (Site Settings → Community defaults): the copy every Board page shares (impact cards, What's included pillars, requirements, headings), with `{board}`, `{field}` and `{a_field}` filled in per page. Code holds only structural defaults (section labels, the Apply button); the shared copy is content and lives in the options page.

`assemble_community_page_view( $post )` builds the view model and answers the theme's `assemble/community_page` filter. Tests: `wp eval-file wp-content/plugins/assemble-core/tests/communities-test.php` (local only).

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

## Summits (from the shared engine in Assemble Content)

Since 0.8.0 the summits reader lives in **Assemble Content** (`AC_Summits`, `AC_Summit_Extras`, `AC_Summit_Editions`, named `AFR_*` before 2.0.0), so the Member Center can read the same data. See that plugin's README, "Summits". This plugin keeps only thin wrappers with the names the theme reads, in `inc/summits.php`:

- `assemble_summits( $upcoming_only = true )`: upcoming summits, soonest first;
- `assemble_summits_with_extras( $upcoming_only = true )`: the same, with Assemble's extras merged in;
- `assemble_summit_extras()` and `assemble_summits_refreshed_at()`.

Without Assemble Content 2.0.0 they return nothing, and the theme shows its empty states. On upgrade from 0.7.0, the stored copy in `assemble_ep_summits` seeds the engine's `ac_summits` option once; then the old option, its status option and the `assemble_summits_refresh` schedule are removed. `wp assemble summits refresh|list` still works, as an alias of `wp assemble-content summits`.

Filters moved with the code: `assemble/summits` is now `ac_summits`, and `assemble/summit_extras` is now `ac_summit_extras`.
