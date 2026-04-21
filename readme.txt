=== SignEmail SMTP & DNS Diagnostic ===
Contributors: smtpdkim
Tags: smtp, email, mail, deliverability, dkim
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.4.35
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stop WordPress emails from going to spam. Send emails via SMTP and check SPF, DKIM, DMARC records to improve email deliverability.

== Description ==

🚨 Are your WordPress emails going to spam?

Without proper email authentication, services like Gmail, Outlook, and Yahoo may block or mark your emails as spam. This results in lost notifications, password resets, and order emails.

SignEmail SMTP helps you send reliable WordPress emails using professional SMTP servers, with optional DKIM authentication support.

---

= Why email authentication matters =

Modern email providers require proper authentication (SPF, DKIM, DMARC) to trust your messages. Without it, even valid emails may be filtered as spam.

---

= 📬 SMTP Email Delivery =

Send all WordPress emails through reliable SMTP servers instead of PHP mail(), improving deliverability and consistency.

= ⚙️ Universal SMTP Compatibility =

Compatible with Gmail, Outlook, Brevo, cPanel, OVH, Infomaniak, SiteGround, GoDaddy, Bluehost, WP Engine, Kinsta, Namecheap, Gandi, o2switch, LWS, and more.

= 🔍 Email Testing Tool =

Send test emails to verify inbox delivery and diagnose spam placement in real conditions.

= 🔎 DNS Diagnostic Tool =

Check SPF, DKIM, and DMARC records using Cloudflare DNS-over-HTTPS.

= 🔒 Secure Storage =

SMTP credentials are encrypted using AES-256-CBC and stored securely in your WordPress database.

= 🌐 Multilingual Support =

Full French and English interface with per-user language selection.

---

= SMTP Features =

* Full SMTP configuration (SSL / TLS / STARTTLS / None)
* Encrypted password storage (AES-256-CBC)
* Force sender email and name
* Debug logging system
* Built-in email test tool
* DNS verification (SPF / DKIM / DMARC)
* Compatible with WooCommerce, CF7, Elementor, Gravity Forms, BuddyPress, LearnDash, etc.
* Fully intercepts wp_mail()

---

= Optional DKIM Support =

DKIM email signing is available in the **Premium version** of this plugin — smtp-dkim.com.

* Works alongside SMTP delivery
* Improves inbox trust and deliverability

= Security & Privacy =

* SMTP credentials encrypted before storage
* No credentials are sent to external servers
* License verification (if used) only transmits license key + domain name
* No email content is ever transmitted externally

---

== Installation ==

1. Upload plugin folder to /wp-content/plugins/
2. Activate plugin
3. Open SignEmail SMTP menu in WordPress admin
4. Configure SMTP settings
5. Run email test
6. (Optional) Configure DKIM if supported by your mail provider
7. Run DNS diagnostic tool

---

== Frequently Asked Questions ==

= Does the plugin work without DKIM? =
Yes. SMTP email delivery works fully without DKIM.

= Is any data sent to external servers? =
No SMTP credentials or email content are ever sent externally.

= Can I test email delivery? =
Yes, built-in test tool allows sending emails to verify inbox or spam placement.

= Is it compatible with WooCommerce? =
Yes, it integrates with all WordPress email systems using wp_mail().

= Can I move configuration between sites? =
Yes, settings can be exported and reused.

---

== External Services ==

This plugin may connect to external services:

1. DNS-over-HTTPS (Cloudflare)
   - Used for DNS validation (SPF/DKIM/DMARC)
   - Only triggered manually by admin action

2. SMTP Server (user configured)
   - Used to send WordPress emails
   - No external dependency required

3. License validation (optional)
   - Endpoint: https://smtp-dkim.com/wp-json/sdlm/v1/validate
   - Sends only license key + domain
   - No email or SMTP data is transmitted

---

== Changelog ==

= 2.4.35 – April 21, 2026 =
* WP.org compliance: updated language strings to neutral terminology
* Removed redundant UI notices from settings page
* Fixed SMTP debug log button flickering during live email test polling
* Updated plugin description for WP.org submission
* Moved all CSS and JavaScript to properly enqueued external files

= 2.4.30 – April 19, 2026 =
* Adjusted test email content when DKIM signing is active
* Excluded .htaccess and debug.log from plugin directory (WP.org requirement)
* Improved live SMTP debug log writing during email send
* DKIM private key field UI improvements

= 2.4.20 – April 13, 2026 =
* DNS diagnostic improvements and card layout fixes
* Configuration summary card moved to top of settings page
* DKIM label consistency fixes across UI

= 2.3.9 – April 6, 2026 =
* Added external email test tool
* Improved license report email
* UX improvements for DNS setup guidance

= 2.3.0 – April 2, 2026 =
* Security improvements for license validation
* WordPress 6.9 compatibility confirmed

= 2.2.0 – March 28, 2026 =
* Improved license status display
* Auto-update support added

= 2.0.0 – March 3, 2026 =
* Major refactor of internal architecture
* General stability improvements

= 1.0.0 – March 1, 2026 =
* Initial release

