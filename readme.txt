=== PushMails for WordPress ===
Contributors: appstonia
Tags: newsletter, subscribe form, double opt-in, email, pushmails
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Grow your PushMails audience with a double opt-in subscribe form and opted-in site users, and deliver your site emails through PushMails.

== Description ==

* **Subscribe form with double opt-in.** Add the "PushMails subscribe form" block or the `[pushmails_form]` shortcode. Visitors get a confirmation email and join your PushMails audience only after confirming on the page it links to.
* **Site users.** A consent box on the registration form and on the user profile. By default every user is sent so site emails reach them, but only users who consent receive campaigns. Authors, editors and administrators are never sent.
* **Site emails through PushMails.** Password resets, account emails and notifications from other plugins can be delivered by PushMails. You choose which kinds; emails to administrators are off by default. Anything PushMails cannot take is sent by WordPress as before — no email is lost.
* **Ready for WooCommerce.** If your site starts selling with WooCommerce, install PushMails for WooCommerce: it switches this plugin off and takes over its connection, settings and subscribe forms.

= External service =

This plugin sends contact data (email address, name, consent) to PushMails at `https://send.pushmails.net` when you connect it with a token. See the [terms](https://pushmails.net/terms) and [privacy policy](https://pushmails.net/privacy).

== Installation ==

1. In WordPress, upload and activate the plugin. The plugins list shows the connection steps under it until the site is connected.
2. In PushMails, go to Integrations → WordPress → Connect, pick the list new subscribers should join and copy the connection token. It is shown only once.
3. Go to Settings → PushMails, paste the token, choose who to send and which site emails go through PushMails, and save. Existing users are sent right away.
4. Add the subscribe form to a page with the block or `[pushmails_form]`.

On a site that runs WooCommerce, use PushMails for WooCommerce instead; this plugin refuses to activate there.

== Frequently Asked Questions ==

= Why double opt-in? =

Anyone can type any address into a public form. Without a confirmation step, strangers end up on your list, and their complaints hurt your domain's reputation.

= Which site emails go through PushMails? =

You choose: emails to users and subscribers (password resets, email changes, subscription confirmations, other plugins' notifications) and emails to administrators. The second is off by default because it includes password reset links for accounts that can manage your site.

= What if PushMails cannot send an email? =

WordPress sends it as before — for example when PushMails is unreachable, the plan limit is reached, the sender address is not registered, or the email has attachments.

= Where do I find the token again? =

It is shown only once. Create a new connection in PushMails and paste the new token; the settings page only shows the last four characters of the saved one.

== Changelog ==

= 1.0.0 =
* First release: double opt-in subscribe form (block and shortcode), consent on registration and profile, sending of existing users, site emails through PushMails with automatic fallback, hand-over to PushMails for WooCommerce.
