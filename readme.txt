=== n8n ChatAgent for Unions ===
Contributors: Jason Cox
Tags: chat, ai, live-chat, customer-support, webhook
Requires at least: 5.0
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 1.0.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered chat widget purpose-built for labor organizations that run n8n. Messages are relayed only to the webhook URL you configure, keeping control of data in your own automation stack.

== Description ==

n8n ChatAgent for Unions drops a single floating chat window onto any WordPress site and immediately hands conversations off to an n8n webhook. There is no bundled AI brain, no SaaS dependency, and no example workflow hiding in the plugin—every message is proxied to the endpoint you configure, and whatever JSON that endpoint returns is what the visitor sees.

The defaults (copy, gradients, disclaimers, icon handling) are tuned for labor and organizing teams but can be customized entirely from wp-admin. Because the plugin is just a UI shell, you keep control of storage, routing, compliance, and safety enforcement inside your n8n flows.

Important compatibility note: this plugin is **only tested with n8n webhooks**. Other automation services (Zapier, Make, Pipedream, custom APIs, etc.) might respond correctly, but they are unverified. Test thoroughly before exposing anything except n8n to production traffic.

== Features ==

* **Webhook-first chat:** Every message is proxied to the webhook you choose (HTTPS enforced). Whatever JSON your n8n workflow returns is rendered back in the widget—no bundled AI service or SaaS dependency.
* **Union-aware metadata:** Logged-in members automatically contribute ID, display name, email, role(s), username, IAM local identifier, and an HMAC-signed session token. Guest visitors still send anonymous session IDs plus device/locale info so you can branch flows.
* **Starter-question routing:** Add as many starter questions as you need (website help, contract questions, carrier policies, grievances, etc.). They appear as an assistant reply inside the chat, and clicks are forwarded to n8n as a `selectRoute` action with `route`, `routeLabel`, and `metadata.selectedRoute`.
* **Attachment uploads (mode-controlled):** PDF/JPG/PNG uploads are always available but you decide how the button behaves (Never / Always / Agent-controlled). Drop the phrase `[ATTACHMENT_READY]` (or any custom keyword you configure) into your workflow reply to surface the uploader exactly when the agent asks for files.
* **Custom greetings & disclaimers:** Turn the welcome webhook call off, inject your own greeting text, and define multi-line disclaimers. The header subtitle accepts links and shortcodes so you can surface policy, office hours, or live status indicators.
* **Page-level visibility controls:** Hide the agent on specific WordPress pages with checkboxes, or add custom URL/path/slug rules for non-page routes. The widget assets are skipped entirely on matching pages.
* **Brandable launcher & bot identity:** Upload launcher or bot avatars, pick one of the baked-in SVG icons, adjust widget width/height, and control the full IAM color palette (primary, gradient, bubble colors, etc.).
* **Quick actions & human handoff:** Configure quick-action chips that send canned questions, expose a “request a human” CTA when live agents are online, and reset chats cleanly between sessions.
* **Persistent transcripts:** Conversations are cached in the browser’s localStorage so a user can navigate between pages without losing context. Logging out or using “Start New Chat” wipes the cache instantly.
* **Full-screen mobile experience:** On phones the widget expands to a safe-area aware overlay with scroll locking so the input is always accessible. Desktop keeps the floating launcher with gradient header.
* **Security hardened:** AJAX endpoints use nonces/cap checks, visitor chat sessions are server-signed, webhook URLs must be HTTPS, attachments are sanitized server-side, and user tokens expire based on the TTL you set. There is zero “pro edition” or third-party tracking baked in.
* **Thumbs-up / Thumbs-down ratings:** Every bot reply can be marked helpful/unhelpful (or you can disable the prompt entirely). Customize the “Helpful?” label plus the thumb text/icons, and ratings are forwarded to your webhook with the `responseId` so n8n can track bad answers automatically.

== Settings Reference ==

Use **wp-admin → n8n ChatAgent for Unions** to configure everything. Each field maps directly to the frontend:

* **Webhook Endpoint / Timeout** – Paste your n8n webhook URL (must be HTTPS). The timeout controls how long WordPress waits before showing “service unavailable.” Keep it at 120s unless your workflow routinely takes longer.
* **AI Assistant Name / Default Agent Identifier** – The name shows in the transcript UI. The agent identifier is passed as `agentId` inside every payload so n8n can route requests even when no human agent is attached (slugged version of the assistant name works well).
* **Starter Questions** – Enable starter questions inside the chat, rename the prompt, and add one starter per line as `Question text|route_key`. Use the route key in n8n Switch/IF nodes to send website questions, contract questions, policy questions, or any other simple category to the right workflow path.
* **Visibility** – Pick “Show to everyone” (default) or “Only show when logged in.” When you restrict it, the widget assets aren’t enqueued for logged-out visitors at all, keeping the chat private to members.
* **Hide On Selected Pages** – Check any WordPress pages where the agent should not load. Use the optional “Additional URLs, Paths, Or Slugs” textarea for non-page routes; query strings and trailing slashes are ignored, so `/contact` also matches `/contact?source=email`.
* **Header Title & Subtitle** – Title sits at the top of the widget. Subtitle supports basic HTML or shortcodes. The optional “Link Text/URL” helper auto-appends a clickable CTA without you having to edit HTML manually.
* **Attachment Button Mode** – Choose `Never` to hide uploads entirely, `Always` to keep the paperclip visible, or `Agent-controlled` to reveal it when your workflow includes the keyword (default `[ATTACHMENT_READY]`). The “Prompt keyword” field lets you change that trigger to any other phrase; case-insensitive and stripped before visitors see the reply.
* **Union Token TTL** – Sets how long the HMAC-signed union token is valid (in seconds). Longer TTL reduces re-auth prompts but keeps tokens active longer; 600s is a good balance.
* **Chat Disclaimer Text** – Multi-line text shown above the input. Supports HTML links and optional helper fields so you can add “Read the policy” style links without editing markup. Check “Show disclaimer card” to display it, or uncheck to remove.
* **Greeting Message** – Controls the small speech bubble next to the launcher. Update the text and/or uncheck “Show the greeting bubble” if you don’t want the hover prompt.
* **Bot Icon / Launcher Icon** – Choose a built-in SVG or switch to “Custom Image.” Use the upload buttons to launch the WordPress media modal and the Remove buttons to revert to default artwork. Recommended size ~50x50px PNG.
* **Visitor IP Lookup** – When enabled, the widget sends visitor IPs to ip-api.com to add city/country into the metadata block. Leave unchecked if you can’t share IPs with third parties.
* **Custom Welcome Message** – Tick the checkbox to skip the initial webhook call and show your own text inside the chat window. The webhook will only run after the visitor sends their first real message, which saves tokens for simple greetings.
* **Response Ratings** – (Off by default) Turn the thumbs-up/thumbs-down UI on or off and change the prompt label plus each thumb’s text/icon. Leave the label blank if you only want the icons. When enabled, include a `responseId` in your workflow response to correlate feedback; otherwise the widget generates a `resp_` ID before posting a `rateMessage` payload to your webhook.

All other styling (colors, gradients, fonts) lives under the **Colors & Styling** tab; those controls directly mirror CSS variables used by the widget.

== Bug Fix Highlights ==

* Fixed attachment uploads so they only appear when enabled, sanitize before forwarding, and visually confirm when they’re sent.
* Hardened the launcher so optional UI (icons, quick actions, disclaimers) no longer block the chat from opening.
* Added transcript caching that survives page navigation yet clears automatically when members log out or click “Start New Chat”.
* Resolved double-encoding on admin textareas (subtitle/disclaimer) so HTML links stay intact no matter how many times you save.

== Security & Privacy Review ==

* Visitor text is POSTed to a WordPress AJAX endpoint which relays it to your webhook; authenticate/authorize that WordPress origin and continue sanitizing any HTML you echo back.
* A single optional request to `http://ip-api.com` is used for coarse IP geolocation; replace or disable that call if HTTP lookups violate your policies.
* The plugin never stores user credentials. Persisting transcripts, retention rules, and audit logging are entirely up to your n8n workflows; only a short-term transcript cache lives in the visitor’s browser to keep sessions consistent.
* REST/AJAX endpoints require WordPress nonces/capabilities, and visitor-owned chat endpoints require a server-signed visitor token so one visitor cannot read or write another visitor’s transcript by guessing an ID.
* The JavaScript bundle stays in a closure, guards optional DOM nodes, and escapes webhook/visitor text before markdown rendering to reduce XSS risk.

== Installation ==

1. Upload `n8n-chatagent-for-unions` to `/wp-content/plugins/` (if zipping manually, compress that folder itself—WordPress will reject double-wrapped archives like `New AI Live Chat Test.zip`).
2. Activate via **Plugins → Installed Plugins**.
3. Visit **n8n ChatAgent for Unions** under the admin menu and enter your n8n webhook URL plus any optional copy/branding tweaks.
4. Load the frontend— the widget attaches itself to `<body>` automatically.

== GitHub Updates ==

Automatic updates are powered by Plugin Update Checker and use the GitHub repository at https://github.com/jcjason12108-alt/UnionAssist-Chat-n8n-Routing-Layer-.

If the repository is private, add a GitHub token with read access to the repository in wp-config.php:

`define( 'N8N_UNION_CHAT_UPDATE_GITHUB_TOKEN', 'your-github-token' );`

The generic `PLUGIN_UPDATE_GITHUB_TOKEN` constant or environment variable is also supported.

== Frequently Asked Questions ==

= Does it work without n8n? =
Only n8n webhooks have been tested. Other REST endpoints may work, but you are responsible for validating payload formats and responses.

= Where are conversations stored? =
Nowhere inside WordPress. Every message is forwarded to your webhook; store transcripts inside your automation (e.g., n8n workflows writing to databases, CRMs, etc.).

= Can I change the look and feel? =
Yes. All primary colors, iconography, greeting copy, and disclaimers are editable from the settings page. For deeper layout changes, edit `assets/chat-widget.css`.

= Can visitors upload attachments? =
Yes. Attachments are always enabled behind the scenes. Use the **Attachment Button Mode** setting to hide the UI (Never), show it permanently (Always), or let your workflow control it (Agent-controlled). In agent mode include your configured keyword (defaults to `[ATTACHMENT_READY]`) somewhere in your workflow reply when you want the uploader button to appear; otherwise it stays hidden. Files are converted to base64 (PDF/JPG/PNG by default) and forwarded to your webhook alongside the message.

= Does it support user authentication? =
The plugin passes WordPress user context (role, name, email, token) to your webhook when someone is logged in. You decide how to use that metadata inside your automation.

= Does the chat leave data on a shared computer? =
For continuity the widget stores the transcript in the browser’s localStorage until the user logs out or clicks “Start New Chat”. Remind members not to leave sensitive chats open on shared devices, and note this behavior in your privacy policy if needed.

== Changelog ==

= 1.0.16 =
* Enabled branch-only GitHub update checks to avoid release endpoint errors when using direct main branch updates.
* Added generic `PLUGIN_UPDATE_GITHUB_TOKEN` constant/environment variable support alongside the plugin-specific token.
* Updated the plugin URI to the real GitHub repository URL.

= 1.0.15 =
* Added GitHub update support with Plugin Update Checker.
* Added optional private repository token support.
* Updated WordPress compatibility metadata.

= 1.0.14 =
* Added a desktop resize handle for the chat window.
* Saved visitor-selected chat size in browser localStorage while preserving mobile fullscreen behavior.

= 1.0.13 =
* Replaced the input-row route pill with a small inline “Switch topic” control under Bruno replies.
* Route choices now expand only when requested, keeping the message input full width.

= 1.0.12 =
* Moved route switching into a compact topic pill/dropdown inside the message input row.
* Removed the space-consuming topic bar above the transcript.

= 1.0.11 =
* Improved topic bar formatting so route buttons wrap cleanly instead of clipping or showing a horizontal scrollbar.

= 1.0.10 =
* Generalized the topic bar so visitors can switch back and forth between any configured route.
* Highlighted the active route and kept route switching tied to the same `selectRoute` n8n payload.

= 1.0.9 =
* Added a persistent topic bar with a one-click Contract Help switch.
* Contract route switching sends `selectRoute` to n8n so the workflow can reply with a follow-up question.

= 1.0.8 =
* Render starter questions as an assistant message inside the chat transcript.
* Send starter clicks to n8n as a `selectRoute` action so the workflow can reply with a follow-up question.

= 1.0.7 =
* Added configurable starter questions in the chat window.
* Added admin settings to rename the prompt and maintain any number of `Question text|route_key` choices.
* Forwarded the selected route to n8n as top-level payload fields and metadata for simple workflow routing.

= 1.0.6 =
* Added a “Hide On These Pages” setting so admins can suppress the chat agent on selected URLs, paths, or slugs.
* Added page checkboxes for hide rules, backed by WordPress page IDs so selections survive permalink changes.
* Added server-signed visitor session tokens to protect visitor-scoped AJAX endpoints from guessed `visitor_id` access.
* Hardened frontend rendering for quick actions, chat text, and live-agent messages so untrusted text is escaped before display.
* Enforced HTTPS when saving webhook URLs, matching the runtime webhook validation.

= 1.0.5 =
* Ratings are now opt-in by default; new installs start with the thumbs UI hidden until you enable it.
* Clearing the “Helpful?” label in settings now removes the label entirely instead of falling back to the default text.
* Fixed checkbox handling so disabling ratings really hides the widget controls for anonymous visitors.

= 1.0.4 =
* Added a visibility toggle plus text inputs for the thumbs-up/thumbs-down UI so you can disable ratings completely or rename the “Helpful?” label and thumb captions to match your brand.
* Frontend now honors the rating toggle and uses the new label/icon settings, displaying an acknowledgement message after someone clicks a thumb or hiding the entire block when disabled.
* Bumped assets/version constants so browsers pull the refreshed script/CSS bundle.

= 1.0.3 =
* Added per-reply thumbs-up/thumbs-down controls. Ratings are forwarded to the webhook as a `rateMessage` action with `responseId` and `rating` so n8n can log or retrain bad answers.
* Added a visibility toggle so the chat widget can be limited to logged-in visitors only.
* Documented all settings (and the new rating workflow) inside the readme.

= 1.0.2 =
* Bumped the plugin version to flush caches and ensure the new attachment-mode logic ships everywhere.
* Hardened attachment rendering so the paperclip is present whenever attachments are enabled, regardless of how WordPress serializes the setting.
* Removed the redundant “Allow Attachments” checkbox—use the Attachment Button Mode (Never/Always/Agent) instead, with `[ATTACHMENT_READY]` as the workflow trigger.

= 1.0.1 =
* Added attachment visibility modes (Never/Always/Agent) plus the `[ATTACHMENT_READY]` trigger phrase so workflows can request uploads dynamically.
* Made the attachment trigger keyword configurable from the settings page so you can match whatever phrase your workflow already emits.
* Added a “Default Agent Identifier” setting so every payload includes a consistent `agentId` for n8n routing (falls back to a slug of the assistant name if blank).
* Fixed disclaimer link helpers double-encoding/duplicating anchors when saving settings repeatedly.
* Ensured helper link fields and sanitizers work together so subtitles/disclaimers stay human-readable.

= 1.0.0 =
* Added attachment uploads (with admin-side toggles/limits) plus visual chips inside the transcript so visitors know files were sent.
* Enabled shortcode/HTML support inside the header subtitle and surfaced clickable IAM-styled links.
* Forwarded richer metadata to n8n (union tokens, visitor device info, optional geolocation, session timing) and exposed token TTL controls.
* Enforced a true full-screen mobile layout (with scroll locking) so chats fill the viewport on phones.
* Removed the deprecated "disclaimer secondary text" option so the streamlined disclaimer controls the entire footer.
* Hardened the JavaScript bootstrap, guarding optional buttons and keeping the first agent reply visible (fixes the "No agent has populated" symptom).
* Added a WordPress-side webhook proxy so CORS is no longer required and your webhook URL stays out of the browser.
* Cached transcripts in localStorage so conversations survive page changes, while automatically clearing them on logout or manual resets.
* Reworked the readme/security notes to make the GitHub distribution, n8n-only support, and packaging instructions explicit.
