# n8n ChatAgent for Unions

**Contributors:** Jason Cox  
**Tags:** chat, ai, live-chat, customer-support, webhook  
**Requires at least:** WP 5.0  
**Tested up to:** WP 6.9  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later — [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 🔎 Overview  

`n8n ChatAgent for Unions` provides a lightweight, self-hosted chat widget tailored for labor organizations. The plugin injects a floating chat window into any WordPress site and forwards all messages to an `n8n` webhook — no bundled AI service, no external SaaS, and no hidden dependencies. The responses rendered in the chat come solely from your configured `n8n` flows, giving you full control over storage, routing, compliance, and data handling.

---

## 📄 Table of Contents  
- [Features](#features)  
- [Security & Privacy](#security--privacy)  
- [Installation](#installation)  
- [Frequently Asked Questions](#frequently-asked-questions)  
- [Changelog](#changelog)  

---

## ✨ Features  

- **Webhook-first chat**: Every user message is POSTed to your chosen HTTPS webhook; the JSON response is rendered — no external AI service or SaaS required.  
- **Union-aware metadata**: Logged-in union members send enriched metadata (ID, username, email, roles, local identifier, HMAC-signed session token). Guests send anonymized session data + device/locale info.  
- **Optional attachment uploads**: Support for PDFs, JPGs, PNGs (base64-encoded), subject to admin-defined limits. Toggle off if you prefer no file transfers.  
- **Customizable greetings & disclaimers**: Control welcome messages, header copy, disclaimers, and embedded shortcodes/links (e.g. office hours, policy).  
- **Brandable launcher & bot identity**: Upload your own avatars/icons, adjust widget dimensions, and style using union-specific color palettes.  
- **Quick action chips & human handoff**: Pre-defined buttons for canned questions, plus a “request human” CTA for live agents when available.  
- **Persistent transcripts (client-side)**: Chats are stored in browser localStorage so users don’t lose context when navigating pages. Clearing on logout or “New Chat” ensures privacy.  
- **Full-screen mobile interface**: On phones the widget expands into a safe-area overlay with scroll-locking for smooth usability; on desktop it's a floating launcher with themed header.  
- **Security-hardened**: AJAX endpoints validate nonces/capabilities, webhook URLs must be HTTPS, attachments are sanitized server-side, and session tokens expire per admin TTL. Zero tracking, zero “pro/enterprise” gating.

---

## 🔐 Security & Privacy  

- Visitor messages are forwarded to WordPress AJAX endpoint, then proxied to your configured `n8n` webhook. Make sure your webhook validates origin/authenticity and sanitizes or escapes any HTML before sending back responses for display.  
- The only external call is an optional geolocation check (via `ip-api.com`). If IP-based lookup violates your policy, you may disable it.  
- The plugin never stores user credentials or chat transcripts on the WordPress server — all data persistence (audit logs, storage, transcripts) is handled fully by your `n8n` workflows or external storage.  
- REST and AJAX endpoints enforce WordPress nonce and capability checks so anonymous visitors can only access allowed routes.  
- The front-end JS runs in a sandboxed closure; optional DOM nodes are guarded to avoid runtime errors or injection.  

---

## 🛠 Installation  

1. Upload the folder `n8n-chatagent-for-unions` to `/wp-content/plugins/` (if you’re using a zip archive, compress the plugin folder itself — avoid double-wrapped archives).  
2. Activate the plugin under **Plugins → Installed Plugins**.  
3. Go to admin panel **n8n ChatAgent for Unions**, enter your `n8n` webhook URL, and configure optional copy, branding, and settings.  
4. Load the frontend — the widget auto-attaches to `<body>` and becomes available site-wide.  

---

## ❓ Frequently Asked Questions  

**Does it work without n8n?**  
No — the plugin has only been tested with `n8n` webhooks. Other automation platforms may work, but you’re responsible for verifying payload/response formats before using them in production.

**Where are conversations stored?**  
No data is stored on WordPress. Every message is forwarded to your webhook. If you want transcripts, logging, or CRM storage — build that logic inside your `n8n` workflows.  

**Can I customize the look-and-feel?**  
Yes — you can modify colors, icons, greetings, disclaimers, widget size, and more via the admin settings. For deeper layout changes, edit `assets/chat-widget.css`.  

**Can visitors upload attachments?**  
Yes — if **Allow Attachments** is enabled. The widget supports PDFs and common image file types; data is base64-encoded and forwarded. If you prefer no file transfers, simply disable the option.  

**Does it support user authentication context?**  
Yes — when a union member is logged in, the plugin includes user metadata in the payload (roles, union local ID, HMAC session token, etc.). You determine how that data is handled in your `n8n` workflows.  

**Does chat data persist on shared computers?**  
By default, transcripts are stored in the visitor’s browser localStorage until logout or “Start New Chat.” If your site is used on shared devices, you may want to clear storage at logout, and disclose this behavior in your privacy policy.  

---

## 📝 Changelog  

### 1.0.0

- Added optional attachment uploads (with admin-configurable limits) and visual chips in the chat transcript to indicate file sends.  
- Enabled shortcode/HTML support in header subtitle; surfaced clickable links styled for union (IAM) usage.  
- Forward richer metadata to `n8n` (union tokens, device info, optional geolocation, session timing); exposed token TTL controls.  
- Enforced true full-screen mobile layout with scroll locking so chats fill the viewport on phones.  
- Removed deprecated “disclaimer secondary text” option; now only a streamlined single disclaimer is shown in footer.  
- Hardened JS bootstrap — optional DOM nodes are guarded to prevent runtime errors when optional features are disabled.  
- Added WP-side webhook proxy — avoids CORS issues and keeps webhook URL out of the browser.  
- Implemented client-side transcript caching (localStorage) so conversations survive page navigation; automatically cleared on logout or manual reset.  
- Updated readme and security documentation to explicitly state GitHub distribution, n8n-only support, and packaging/install instructions.  

---

## 📄 License  

This project is licensed under **GPLv2 or later**. See [LICENSE](LICENSE) for full text.  
