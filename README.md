# PushMails for WordPress

Grow your [PushMails](https://pushmails.net) audience with a double opt-in
subscribe form and opted-in site users, and deliver your site emails (password
resets, account notices) through PushMails. Any email PushMails cannot take is
sent by WordPress as before.

On a WooCommerce site use
[PushMails for WooCommerce](https://github.com/appstonia/pushmails-woocommerce)
instead: it includes everything here and takes over this plugin's connection,
settings and forms when activated. This plugin refuses to activate next to
WooCommerce.

## Install

1. Download the latest zip from [Releases](https://github.com/appstonia/pushmails-wordpress/releases/latest)
   and upload it in WordPress (**Plugins → Add New → Upload Plugin**).
2. In PushMails: **Integrations → WordPress → Connect**, copy the token.
3. **Settings → PushMails**: paste the token, choose who to send, save.
4. Add the **PushMails subscribe form** block, or `[pushmails_form]`
   (`name="yes"` asks for a first name, `button="…"` sets the button text).

Requires WordPress 6.2+, PHP 7.4+.

## What is sent

- Form subscribers, after they click the confirmation link: email, first name,
  consent `granted` with its time, tags `wordpress` and `form`.
- Site users (never authors, editors or administrators): email, name, consent
  (`granted` / `withdrawn` / `unknown` with its time), tags `wordpress`,
  `registered`, field `registered_at`.

Filters: `pushmails_wp_contact` (a user), `pushmails_form_contact` (a form
subscriber), `pushmails_confirmation_email` (the confirmation email),
`pushmails_store_email` (a site email; return null to leave it with WordPress).

## Development

```bash
make check                 # versions agree, PHP syntax
make zip                   # dist/pushmails-for-wordpress-<version>.zip
make bump V=1.0.1          # new version everywhere
scripts/sync-shared.sh     # copy includes/shared into ../WooCommerce
```

`includes/shared/` is shared with PushMails for WooCommerce and edited here
only. Point a test site at another API with
`define( 'PUSHMAILS_API_URL', '…' );` in `wp-config.php`. Releases are built by
`.github/workflows/release.yml` from a `vX.Y.Z` tag.

## License

GPL-2.0-or-later
