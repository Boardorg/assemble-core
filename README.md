# Assemble Core

Public-site plugin for theassemble.com. It holds content **authored in WordPress**: the `summit` post type, ACF field groups (as ACF JSON in `acf-json/`), the Site Settings page, the host-based noindex guard, redirects, roles, and the idempotent `wp assemble setup` command. It also ships the mu-plugin that forces `afr_bypass_mode` to `off` on production.

Content authored in Contentful belongs to [assemble-content](https://github.com/Boardorg/assemble-content) instead. Nothing here is shared with the Member Center.

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`). Production deploys are manual and need a reviewer's approval. No secrets in this repo, ever: it is public.
