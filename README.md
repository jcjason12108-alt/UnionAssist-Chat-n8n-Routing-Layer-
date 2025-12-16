# n8n ChatAgent for Unions

**AI-powered chat widget for labor organizations running n8n**  
*A webhook-first WordPress chat interface that keeps data, logic, and compliance in your own automation stack.*

---

## 📦 Plugin Overview

| Field | Value |
|-----|------|
| **Contributors** | Jason Cox |
| **Tags** | chat, ai, live-chat, customer-support, webhook |
| **Requires WordPress** | 5.0+ |
| **Tested up to** | 6.9 |
| **Requires PHP** | 7.4 |
| **Stable Tag** | 1.0.2 |
| **License** | GPLv2 or later |
| **License URI** | https://www.gnu.org/licenses/gpl-2.0.html |

---

## 🚦 What This Plugin Is (and Is Not)

**n8n ChatAgent for Unions** is a **UI-only chat widget** for WordPress.

- ✅ No bundled AI model  
- ✅ No SaaS dependency  
- ✅ No hidden workflows  
- ✅ No third-party tracking  

Every message is proxied **directly to the n8n webhook you configure**, and **whatever JSON your workflow returns is exactly what the visitor sees**.

You own:
- Storage
- Routing
- Moderation
- Compliance
- Safety enforcement

All of it lives in **your n8n flows**, not inside WordPress.

---

## 🧩 Description

This plugin drops a single floating chat window onto any WordPress site and immediately hands conversations off to an **n8n webhook**.

Because it is strictly a frontend shell:
- There is **no embedded AI brain**
- There is **no external service**
- There is **no opinionated workflow**

Defaults (copy, gradients, disclaimers, icons) are tuned for **labor organizations**, but **everything is customizable** from wp-admin.

> **Compatibility note**  
> This plugin is **only tested with n8n webhooks**. Other automation platforms *may* work, but are unverified. Test thoroughly before production use.

---

## ✨ Features

### 🔗 Webhook-First Architecture
- Every message is POSTed to your webhook (HTTPS required)
- Rendered output is exactly what your workflow returns
- No bundled AI or external processing

### 🪪 Union-Aware Metadata
- Logged-in users automatically send:
  - Name
  - Email
  - Username
  - Roles
  - IAM local identifier
  - HMAC-signed session token
- Guests send anonymous session + device/locale metadata

### 📎 Attachment Uploads (Mode-Controlled)
- Supports **PDF / JPG / PNG**
- Button visibility modes:
  - `Never`
  - `Always`
  - `Agent-controlled`
- Trigger uploads dynamically with a workflow keyword  
  *(default: `[ATTACHMENT_READY]`)*

### 📝 Custom Greetings & Disclaimers
- Optional welcome webhook call
- Fully custom greeting text
- Multi-line disclaimers with HTML + shortcode support
- Header subtitle supports links and CTAs

### 🎨 Brandable UI
- Custom launcher & bot avatars
- IAM color palette control
- Adjustable widget size
- Built-in SVG icons or custom images

### 🧭 Quick Actions & Human Handoff
- Configurable quick-action chips
- “Request a human” CTA
- Clean session resets

### 💾 Persistent Transcripts
- Cached in browser `localStorage`
- Survives page navigation
- Clears automatically on logout or “Start New Chat”

### 📱 Full-Screen Mobile Mode
- Safe-area aware overlay
- Scroll locking
- Floating launcher retained on desktop

### 🔐 Security Hardened
- WordPress nonces + capability checks
- HTTPS-only webhooks
- Server-side attachment sanitization
- Expiring HMAC tokens
- No tracking, no upsells, no external telemetry

---

## ⚙️ Settings Reference

Access via **wp-admin → n8n ChatAgent for Unions**

### Core Settings
- **Webhook Endpoint / Timeout**  
  HTTPS n8n webhook URL + response timeout (default 120s)

- **AI Assistant Name / Default Agent Identifier**  
  Display name + `agentId` passed to n8n for routing

- **Header Title & Subtitle**  
  Subtitle supports HTML, shortcodes, and helper links

### Attachments
- **Attachment Button Mode**  
  `Never` / `Always` / `Agent-controlled`

- **Prompt Keyword**  
  Phrase your workflow emits to surface the uploader  
  *(default: `[ATTACHMENT_READY]`)*

### Security & Identity
- **Union Token TTL**  
  HMAC token lifetime in seconds (600 recommended)

### Messaging
- **Chat Disclaimer Text**  
  Multi-line footer disclaimer (HTML supported)

- **Greeting Message**  
  Launcher hover bubble (optional)

- **Custom Welcome Message**  
  Skip initial webhook call and show static text instead

### Visuals
- **Bot Icon / Launcher Icon**  
  Built-in SVGs or custom uploads (~50×50px recommended)

### Optional Metadata
- **Visitor IP Lookup**  
  Adds city/country via `ip-api.com` (disable if needed)

---

## 🐞 Bug Fix Highlights

- Fixed attachment visibility + sanitization
- Prevented optional UI elements from blocking chat open
- Added resilient transcript caching
- Resolved double-encoding in admin textareas

---

## 🔍 Security & Privacy Notes

- Messages relay through a WordPress AJAX proxy to your webhook
- Optional IP lookup uses `http://ip-api.com`
- No credentials stored in WordPress
- Transcript persistence is **browser-local only**
- All endpoints protected by WordPress nonces/cap checks
- JS guarded against missing DOM nodes

---

## 📥 Installation

1. Upload `n8n-chatagent-for-unions` to `/wp-content/plugins/`
   > ⚠️ Zip the folder itself — WordPress rejects double-wrapped archives
2. Activate via **Plugins → Installed Plugins**
3. Configure via **n8n ChatAgent for Unions** in wp-admin
4. Load any frontend page — the widget auto-attaches to `<body>`

---

## ❓ Frequently Asked Questions

### Does it work without n8n?
Only n8n webhooks are officially supported.

### Where are conversations stored?
Nowhere in WordPress. Store transcripts in your automation stack.

### Can I customize the UI?
Yes — colors, icons, greetings, and disclaimers are configurable.  
For deeper changes, edit `assets/chat-widget.css`.

### Are attachments supported?
Yes. Visibility is controlled by **Attachment Button Mode**. Files are base64-encoded and forwarded to your webhook.

### Does it support authentication?
Yes. Logged-in WordPress user context is passed to n8n.

### What about shared computers?
Transcripts persist in `localStorage` until logout or reset.  
Advise members accordingly and note this in your privacy policy.

---

## 📝 Changelog

### 1.0.2
- Version bump to flush caches
- Hardened attachment rendering
- Removed redundant “Allow Attachments” toggle

### 1.0.1
- Added attachment visibility modes
- Configurable attachment trigger keyword
- Added `agentId` routing support
- Fixed disclaimer encoding issues

### 1.0.0
- Initial release
- Attachment uploads
- Rich metadata forwarding
- Full-screen mobile layout
- Webhook proxy to avoid CORS
- Local transcript caching
- Hardened JS bootstrap

---

**Built for unions. Controlled by you. Powered by n8n.**
