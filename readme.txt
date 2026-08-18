=== HubSpot Fallback Forms ===
Contributors: ferociousmedia
Tags: hubspot, forms, fallback, mailgun
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later

A safety net that replaces embedded HubSpot forms site-wide with self-hosted HTML forms emailed through the Mailgun API when HubSpot embeds are unavailable.

== Description ==

This plugin detects the standard HubSpot v2 embed (`hbspt.forms.create({...})`) anywhere in your rendered pages and, when you flip the master toggle on, swaps each embed for a self-hosted HTML form that mirrors the same fields, consent checkboxes, and disclaimers. Submissions are emailed to your chosen recipients through the Mailgun API.

The fallback is built from HubSpot's own **public form endpoint** — the same one the official embed script reads — so **no API token is required**. You sync each form once (while HubSpot is up) and the fields, options, consent boxes, and disclaimers are cached in your database. If HubSpot later goes down, the cached fallback keeps working.

= How it works =

1. **Settings → HubSpot Fallback**.
2. Enter your **Portal ID** (the `portalId` from your embed code) and **Region** (e.g. `na1`), then **Save**. No token needed.
3. Under **Cached forms**, add each HubSpot form ID used across the site and click **Sync form**. The fields, consent boxes, and disclaimers are fetched from HubSpot's public endpoint and cached.
4. Enter your **Mailgun API key**, **sending domain**, and **API region**, plus **recipient emails** (comma-separated).
5. When HubSpot is down, tick **Fallback mode** on. Every embed is now replaced with the cached HTML form. Turn it off to restore the real embeds.

= Email =

Each submission is emailed with the subject:
`Hubspot Fallback Submission | <Site Title>`
and a table of every submitted field plus consent answers. The submitter's email (if present) is set as Reply-To.

= Notes =

* **Styling is synced too.** With "Match the HubSpot form styling" enabled (default), the fallback renders with HubSpot's own CSS class names (`hs-form`, `hs-input`, `hs-button`, …) so your theme's existing form CSS applies automatically, and it also emits a scoped style block built from each form's synced theme tokens (button color, font, label/consent colors and sizes, border radius, alignment). Turn it off to use only the plugin's plain default styling.
* Each cached form has a **Preview** button that renders the fallback form right on the settings page (with its synced styling) so you can eyeball it before enabling fallback mode. Submissions are disabled in the preview.
* Each cached form also has a **Send test** button that emails your recipients a sample submission with every field pre-filled with dummy answers — use it to confirm Mailgun delivery before you rely on the fallback. Test emails are prefixed with `[TEST]` in the subject.
* Replacement is done via output buffering, so no page edits are required — the same embed code across all your sites is handled automatically.
* If a form ID has not been synced/cached, its original HubSpot embed is left untouched (fail-safe).
* A hidden honeypot field provides basic spam protection.

== Changelog ==

= 1.0.0 =
* Initial release.
