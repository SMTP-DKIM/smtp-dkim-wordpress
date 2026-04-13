=== SMTP DKIM ===
Contributors: smtpdkim
Tags: smtp, email, dkim, mail, deliverability
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stop your WordPress emails from going to spam. Sign every outgoing email with DKIM cryptography so Gmail, Outlook and Yahoo trust your messages.

== Description ==

🚨 **Are your WordPress emails landing in your customers' spam folder?**

Gmail, Outlook and Yahoo silently reject unauthenticated emails. Result: order confirmations, passwords, notifications — all gone. **SMTP DKIM is the solution.**

Send your WordPress emails via **professional SMTP**, sign them with **DKIM**, and make sure they land **directly in the inbox** — not in spam. All your data stays encrypted on your own server.

---

**Why SMTP alone is not enough**

Without DKIM, even an email sent via professional SMTP can be marked as spam. DKIM is a cryptographic digital signature that proves *you* sent that email — and that nobody altered it in transit. **This is what Gmail, Yahoo and Outlook have required since 2024.**

---

= 📬 No more emails in spam =

SMTP + DKIM + SPF + DMARC: the winning combination for maximum deliverability. Gmail and Outlook will trust every email you send.

= ⚙️ Universal SMTP Configuration =

Compatible with all SMTP providers — Gmail, Outlook, Brevo, cPanel, OVH, Infomaniak, SiteGround, GoDaddy, Bluehost, WP Engine, Kinsta, Namecheap, Gandi, o2switch, LWS, PlanetHoster and more. SSL/TLS with AES-256 encrypted password.

= 🔏 Built-in DKIM Signing =

Every outgoing email is signed with your RSA private key. Your customers receive authenticated, trusted emails. Removes the "Unverified" label in Outlook and Gmail.

= 🔍 Real-time DNS Diagnostic =

Check SPF, DKIM and DMARC in one click via Cloudflare DoH. Guided fixes if a record is missing or incorrect.

= 🔒 Your data stays with you =

SMTP password and DKIM private key encrypted AES-256-CBC in your WordPress database. Nothing is sent to smtp-dkim.com. Unlike other SMTP plugins, **zero configuration data ever leaves your server.**

= 🌐 Fully bilingual FR / EN =

Complete French and English interface. Perfect for agencies managing clients in both languages. Language preference saved per user.

---

= SMTP Features (Free) =

* Complete SMTP configuration: server, port, SSL/TLS/STARTTLS/None
* AES-256-CBC encrypted password — never stored or transmitted in plain text
* Force sender email and name on all outgoing emails
* Auto TLS, debug levels 0–4, built-in test email
* External delivery test to any address — verify inbox vs spam in real conditions
* Built-in DNS diagnostic: SPF · DKIM · DMARC via Cloudflare DoH
* Intercepts all wp_mail() calls: WooCommerce, CF7, Gravity Forms, Elementor, BuddyPress, LearnDash, etc.
* Bilingual French/English interface with per-user language selector

= DKIM Signing (licence required) =

* RSA cryptographic signature on every outgoing email
* Removes "Unverified" label in Gmail and Outlook
* Automatic DKIM selector detection from live DNS on activation
* Domain locked after activation — no risk of accidental change
* Automatic licence revalidation every 2 hours via WordPress cron
* Licence report email with full DNS status (SPF · DKIM · DMARC)
* Deactivate on one domain → reactivate immediately on another

= Security & Privacy =

* SMTP password encrypted AES-256-CBC before storage
* DKIM private key encrypted and masked in the interface
* Encryption key unique to your installation (derived from SECURE_AUTH_KEY)
* Licence validation sends only: licence key + domain name — nothing else
* **Zero SMTP credentials, passwords or private keys ever reach smtp-dkim.com**

== Installation ==

1. Upload the `smtp-dkim` folder to `/wp-content/plugins/`
2. Activate from **Plugins > Installed Plugins**
3. Go to **SMTP DKIM** in the WordPress sidebar
4. Set your DKIM domain manually (e.g. `yourdomain.com`) — do this **before** activating your licence to avoid www. detection issues
5. Configure your SMTP settings and click Save
6. Activate your DKIM licence from smtp-dkim.com
7. Paste your DKIM private key from cPanel → Email Deliverability
8. Run the DNS diagnostic — verify SPF, DKIM and DMARC are green
9. Send a test email to confirm inbox delivery ✅

== Frequently Asked Questions ==

= Does the plugin work without a DKIM licence? =
Yes. All SMTP features are completely free. The licence unlocks DKIM signing only. Your emails are still sent via SMTP without DKIM signature.

= Is my SMTP data sent to smtp-dkim.com? =
No. Only the licence key validation (key + domain) reaches our servers. No SMTP credentials, no passwords, no private keys ever leave your server.

= My domain starts with www. — will DKIM work? =
Set your DKIM domain manually (e.g. `yourdomain.com`) in the plugin BEFORE activating your licence to avoid the www. detection issue.

= Where do I find my DKIM private key? =
cPanel → Email Deliverability → your domain → DKIM → View → copy the full Private Key block (from `-----BEGIN RSA PRIVATE KEY-----` to `-----END RSA PRIVATE KEY-----`).

= Why can't the private key be detected automatically? =
The DNS contains only the DKIM public key. The private key is generated on your cPanel server and never published in DNS — this is the RSA asymmetric cryptography principle that makes DKIM signatures secure.

= Can I move my licence to another domain? =
Yes. Deactivate your licence in the plugin — the domain is released on the server side. Then reactivate on the new domain immediately.

= What happens when my licence expires? =
Your DKIM private key remains safely encrypted in your database. DKIM signing is disabled until renewal. Your emails continue to be sent via SMTP without DKIM signature.

= Is the plugin compatible with WooCommerce? =
Yes. SMTP DKIM intercepts all wp_mail() calls: WooCommerce, CF7, Gravity Forms, BuddyPress, LearnDash, etc.

= Can I use one licence on multiple sites? =
Depends on your plan: 1 site, 3 sites, 5 sites, unlimited or lifetime plans are available at smtp-dkim.com.

== External Services ==

This plugin connects to the following external services:

**1. smtp-dkim.com — Licence validation API**
* URL: https://smtp-dkim.com/wp-json/sdlm/v1/validate
* Purpose: Validates your DKIM licence key. Sends only the licence key and your domain name. No SMTP credentials, no private keys, no personal data beyond the domain.
* When: Only when you activate, deactivate or verify a licence from the plugin admin page, and automatically every 2 hours via WordPress cron.

**2. Cloudflare DNS over HTTPS — DNS diagnostic tool**
* URL: https://cloudflare-dns.com/dns-query
* Purpose: Resolves SPF, DKIM and DMARC DNS records for your domain. Used only by the built-in DNS diagnostic tool.
* When: Only when you manually click "Scan DNS" in the plugin admin page.

**3. Your SMTP mail server**
* Purpose: Sends WordPress emails through your configured SMTP server.
* When: Every time WordPress sends an email (wp_mail()).

== Changelog ==

= 2.3.8 – April 6, 2026 =
* Improvement: licence report sent directly to the licence holder with full DNS status
* New: external delivery test to a custom address — verify inbox vs spam in real conditions
* Improvement: licence deactivation / reactivation process clarified and simplified
* Fix: DKIM selector label corrected in the Signature section
* Improvement: built-in step-by-step guide to retrieve your DKIM private key from cPanel
* Fully bilingual FR / EN interface — each user picks their preferred language

= 2.3.0 – April 2, 2026 =
* Security: RSA cryptographic link between the DKIM signature and the active licence
* New: security key management section added in plugin settings
* Improvement: signature validation faster, automatic synchronisation strengthened
* Improvement: deactivating a licence releases the domain server-side — immediate reactivation on any new domain

= 2.2.0 – March 28, 2026 =
* New: licence holder name, email and subscription type displayed in the plugin
* New: "Lifetime" badge for permanent licences — no expiry date ever shown
* Improvement: licence status synchronised every 2 hours via background cron
* Fix: expiry date display corrected, sensitive technical keys automatically hidden
* Compatibility verified with WordPress 6.9

= 2.1.0 – March 17, 2026 =
* New: automatic plugin updates directly from the WordPress dashboard
* Improvement: DKIM section automatically locked if licence is invalid or expired
* Improvement: licence status synchronisation faster and more reliable

= 2.0.0 – March 3, 2026 =
* Stability fixes, broader compatibility, internal improvements

= 1.0.0 – March 1, 2026 =
* Initial release: full SMTP configuration (SSL/TLS/STARTTLS), DKIM signing,
  built-in DNS diagnostic (SPF · DKIM · DMARC), email test tool, bilingual FR/EN interface
