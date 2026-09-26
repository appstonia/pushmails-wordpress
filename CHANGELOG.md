# Changelog

Versions follow [Semantic Versioning](https://semver.org). Each release is a git
tag (`v1.0.0`) with the installable zip attached on GitHub Releases.

## [1.0.0] — 2026-09-26

- Subscribe form with double opt-in: the "PushMails subscribe form" block and the
  `[pushmails_form]` shortcode. Visitors join after confirming their address;
  honeypot, timing check and rate limits against abuse.
- Consent box on the registration form and on the user's own profile.
- Three modes, chosen by the site owner: all users + site emails (default),
  only users who consent, all users. Authors, editors and administrators are
  never sent.
- Existing users are sent automatically on connect and when the mode starts
  including more users; a button re-runs it.
- Site emails through PushMails, by kind (emails to users, emails to
  administrators — off by default); anything PushMails cannot take is sent by
  WordPress as before.
- The saved token is never shown again, only its last four characters; a
  Disconnect button forgets it.
- Does not activate on a WooCommerce site; PushMails for WooCommerce takes over
  its connection, settings and forms when it is activated.
