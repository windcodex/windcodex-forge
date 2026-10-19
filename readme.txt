=== WindCodex Forge – Read-Only AI Diagnostics ===
Contributors: windcodex
Tags: claude, chatgpt, mcp server, ai agent, debugging
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give Claude, ChatGPT, and MCP AI platforms safe, read-only access to inspect WordPress files, database, and hooks. Safe to run live.

== Description ==

**WindCodex Forge** gives Claude, ChatGPT, and other MCP-compatible AI assistants a small number of general-purpose, read-only tools for understanding a WordPress site: installed plugins, active theme, registered hooks, files within wp-content, database structure and data (read-only), Gutenberg content blocks, and cross-conversation memory.

This plugin is entirely free and read-only – it inspects a site's plugins, theme, hooks, files, and database, but cannot change any of them. The only data it ever writes is its own: memory notes and an activity log.

= Why Developers Choose WindCodex Forge =

* **Safe to run on a live site** – no tool can change your content, settings, users, plugins, theme, files, or database tables.
* **No code-execution capability at all** – that's not a setting to be careful with; it simply isn't present in the codebase.
* **Administrators stay in control** – only Administrators can connect an AI app by default, every tool call is logged, and login credentials are never returned.
* **Real diagnostics, not guesswork** – let an AI read hook registrations, database schema, and file contents directly instead of asking you to paste them in.
* **Cross-conversation memory** – save a fact or preference once so a future, separate conversation can pick it back up.
* **No license key, no paywall** – every tool in this plugin is free.

= Key Features =

18 tools across 4 groups, entirely free, no license key required:

* **Site introspection** – installed plugins, active theme, registered hooks, screenshots, static page inspection, recent connector activity, and design-authority detection (works out whether an active page builder or the theme's own design tokens should govern new visual work).
* **File read** – browse and read file contents within wp-content (themes, plugins, uploads).
* **Database read** – read rows from any table using structured filters (columns, conditions, sorting, paging) that always run as prepared statements – no raw SQL is accepted – plus table listing and schema introspection. Password hashes, reset keys, session tokens, application passwords, and secret-looking option/meta values are never returned.
* **Content blocks (read-only)** – read a Gutenberg block's full structure (attributes, inner HTML) by position, and list registered reusable blocks.
* **Memory** – `wp_remember`/`wp_list_memories`/`wp_forget` let a conversation save a fact or preference site-wide so a *future*, separate conversation can pick it back up. Plaintext, not a secrets vault; capped at 200 entries per site.

Plus, in the plugin's settings:

* **Access control** – choose who may approve an AI connection (Administrators only by default, or Editors and above). Each connection is limited to that person's own permissions and stops working as soon as they lose the role.
* **Activity log** – every tool call, successful or not, is recorded with its arguments, the user whose connection made it, and when.
* **Per-tool switches** – turn any individual tool off.
* **Activity log preference** – keep the log on (default) or turn recording off.

A flat 120 requests/minute rate limit applies.

= Use Cases =

* Ask an AI to explain why a page looks the way it does by reading the active theme, registered hooks, and page builder in use.
* Debug a data issue by reading rows from your live database without ever risking a write.
* Have an AI review a plugin's or theme's source files directly from wp-content before suggesting a fix.
* Take a screenshot of a page and inspect its structure as part of a support or QA workflow.
* Let a future conversation pick up context – naming conventions, prior decisions, site quirks – saved by an earlier one via memory.
* Audit which connectors and AI platforms have accessed the site recently.

= For Developers =

Every tool here is read-only, so this plugin is built for understanding a client site or codebase quickly, not editing it live:

* Onboard onto an unfamiliar client site fast – ask an AI to list active plugins and theme, then read the hooks a specific feature actually depends on, instead of grepping through an unfamiliar codebase by hand.
* Track down a plugin conflict by reading exactly what's hooked into a given action or filter, across every active plugin, before touching any code.
* Review a plugin's or theme's source file straight from wp-content as part of a code review or before writing a patch, without needing FTP/SSH access to the server.
* Read rows from the live database with simple filters to confirm a bug's root cause (bad meta value, orphaned row, wrong option) before writing the fix.
* Take a screenshot of a broken page and get a structural read on it – markup, blocks, active builder – to speed up a bug report or a QA handoff.
* Save project-specific conventions to memory once (naming patterns, past architectural decisions, known quirks of the client's stack) so the next debugging session with the same site doesn't start from zero.

= Why This Is Safe on a Live Site =

This plugin has no code-execution capability and no tool that can change your site – that's not a setting to be careful with, it simply isn't present in the codebase. The memory tools write only to the plugin's own notes table.

On top of that:

* **Only approved people can connect.** Administrators only by default. Each connection is limited to the permissions of the person who approved it – there is no shared or fallback account – and stops working immediately if they lose that role. The connection never logs in as that person; it can only read.
* **No access to your internal network.** The page-inspection tool only fetches public web addresses and the site's own pages, never private or local network addresses.
* **Sensitive tools need an Administrator.** File and database tools always require an Administrator account, whatever the connection setting.
* **Credentials stay private.** The database tool refuses requests that select, filter, or sort on password hashes, reset keys, session tokens, or application passwords, and redacts secret-looking values.
* **Everything is logged and revocable.** Every call appears on the Activity tab, and any connected app can be revoked from the Connection tab.

= How It Works =

1. Install and activate the plugin.
2. Go to **Settings > WindCodex Forge** and copy the Connector URL from the Connection tab.
3. Add it to Claude, ChatGPT, or any other MCP-compatible AI platform as a custom connector.
4. Authenticate once via OAuth while logged in as an Administrator – most platforms register themselves automatically from the discovery URLs on the Connection tab.
5. The AI can now read plugins, files, the database, and content blocks – but never change them.

= Requirements =

* WordPress 6.2 or higher
* PHP 7.4 or higher
* An MCP-compatible AI platform (Claude, ChatGPT, or similar) to connect to
* No account or API key required. The optional screenshot tool asks a public WordPress.com screenshot service to render a page, so that page's URL is sent to it (you can switch that tool off in Settings > WindCodex Forge > Tools)

= Privacy =

This plugin has no code-execution capability and no tool that can change your site. Every tool call is recorded in an activity log stored in your own database. Memory storage is plaintext, not encrypted – not meant for secrets or credentials. All tool calls run locally against your own site through WordPress's normal APIs, and the plugin talks only to the AI platform you connect over the OAuth connector you set up yourself. The one exception is the optional screenshot tool, which sends the requested page's URL to a public WordPress.com screenshot service; switch it off on the Tools tab if that isn't acceptable. By default, deleting the plugin leaves all data in place; opt into full cleanup via **Settings > WindCodex Forge > General > Data & Privacy**.

== External services ==

This plugin connects to the following external services:

**The AI platform you connect (e.g. Claude, ChatGPT, or any other MCP-compatible client)**

The entire purpose of this plugin is to let an AI platform you choose to connect – over the OAuth connector you set up yourself in **Settings > WindCodex Forge > Connection** – read a fixed list of diagnostic information from your site. When that platform calls an enabled tool, this plugin sends back only what that specific tool returns (for example: plugin/theme/hook listings, file contents from wp-content, database query results, or Gutenberg block structure), and only in direct response to that tool call. Nothing is sent proactively, on a schedule, or to any platform you haven't explicitly connected and authorized via OAuth. Because you choose which platform to connect, its terms and privacy policy are the platform's own – for example, Anthropic's (https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy) or OpenAI's (https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy).

**WordPress.com mshots screenshot service (s.wordpress.com)**

The "Take Screenshot" tool (`wp_take_screenshot`), when called by your connected AI, asks WordPress.com's free, public mshots service to render a screenshot of a URL – the URL is sent to `https://s.wordpress.com/mshots/v1/` as part of the request, and the target page must be publicly reachable for a screenshot to render. This only happens when that specific tool is invoked, and it can be turned off entirely in **Settings > WindCodex Forge > Tools**. This service is operated by Automattic; see its terms (https://wordpress.com/tos/) and privacy policy (https://automattic.com/privacy/).

== Installation ==

= From your WordPress dashboard =

1. Go to **Plugins > Add New**.
2. Search for **WindCodex Forge**.
3. Click **Install Now**, then **Activate**.

= Manual installation =

1. Download the plugin ZIP file.
2. Upload the `windcodex-forge` folder to `/wp-content/plugins/`.
3. Activate through the **Plugins** screen in WordPress.

= After activation =

1. Go to **Settings > WindCodex Forge**.
2. Copy the Connector URL from the Connection tab and add it to your AI platform as a custom connector.

== Frequently Asked Questions ==

= Is it safe to run this on my live, production site? =

Yes. This plugin has no code-execution capability and no tool that can change your site's content, settings, users, plugins, theme, files, or database tables.

= Can the AI modify my database or files with this plugin? =

No. This plugin only exposes read-only tools – database reads, file reads, and read-only content-block inspection. There is no write or code-execution capability anywhere in the codebase. The one exception is the memory tools, which save and delete notes in the plugin's own table.

= How does authentication work? =

OAuth only. Most platforms register themselves automatically via the discovery URLs shown on the Connection tab. If auto-registration fails, paste the Client ID/Secret from that same tab into the platform's Advanced/OAuth settings instead. The Connection tab lists every app that's ever connected, who it's currently acting as, and lets you revoke any one of them individually.

= Who can connect an AI app? =

Only Administrators, by default. Anyone else who reaches the consent screen is told their account isn't allowed to connect. To let Editors connect too, change **Settings > WindCodex Forge > General > Who can connect**. Even then, the file and database tools still require an Administrator.

= Does this plugin phone home or require an account? =

No. There's no license key and no account, and nothing is sent to WindCodex. It talks to the AI platform you connect, over the OAuth connector you set up yourself. The one exception is the screenshot tool (`wp_take_screenshot`), which asks a public WordPress.com screenshot service to render a page, so that page's URL is sent to that service and the page must be publicly reachable. You can switch that tool off on the Tools tab.

= What can the database read tool actually see? =

Rows from any table in your WordPress database, plus schema introspection (table listing and column structure). The AI never sends SQL: it names a table, the columns it wants, and simple conditions (e.g. `option_name LIKE 'woocommerce_%'`), and the plugin builds the query itself – table and column names are checked against your actual schema, and every value is passed through `$wpdb->prepare()`. It can only ever produce a single `SELECT` on one table, so it cannot change data, read files, or write to disk.

Login credentials are never returned: requests that name password hashes, password-reset keys, session tokens, or application passwords are refused, and any value that looks like a password hash – or an option/meta value whose name looks like a password, secret, API key, or token – is replaced with `[redacted]`.

= Whose permissions does the AI use? =

Those of the WordPress user who logged in and approved the connection on the consent screen – there is no shared or fallback account. If that user later loses the role allowed to connect, or is deleted, the connection stops working immediately. Every tool call is recorded on the Activity tab with its arguments and whether it succeeded, and you can revoke any connected app from the Connection tab at any time.

= What is the memory feature for? =

`wp_remember`, `wp_list_memories`, and `wp_forget` let one AI conversation save a fact or preference about the site so a completely separate, future conversation can pick it up automatically – useful for naming conventions, past decisions, or site-specific quirks. It's plaintext and capped at 200 entries per site, not intended for secrets.

= Which AI platforms can connect to WindCodex Forge? =

Any platform that supports the Model Context Protocol (MCP) as a client, including Claude and ChatGPT. Connection uses standard OAuth, so most platforms auto-register themselves from the discovery URLs on the Connection tab.

= Can I see which files the AI has access to? =

The File read tool is scoped to wp-content (themes, plugins, uploads) only – it cannot read files outside of that directory, such as wp-config.php or core WordPress files.

== Screenshots ==

1. Settings > WindCodex Forge – Connection tab, with the Connector URL and list of connected AI platforms.
2. Settings > WindCodex Forge – Tools tab, showing every tool group with its risk badge and on/off toggle.
3. Settings > WindCodex Forge – General tab, including "Who can connect" and the Data & Privacy uninstall cleanup option.
4. Settings > WindCodex Forge – Activity tab, showing the log of AI actions with what ran, when, and whether it succeeded.

== Changelog ==

= 1.0.1 =
* Added: "Keep activity log" preference (Settings > WindCodex Forge > General) to stop recording new tool calls.
* Improved: Every tool call from an authorized connection is now recorded in the activity log, including calls the plugin rejects (unknown or disabled tool, account not allowed to connect).
* Fixed: Admin notices are now translatable.
* Fixed: Readme requirement now matches the plugin (WordPress 6.2 or higher).

= 1.0.0 =
Initial release of WindCodex Forge as its own standalone, entirely free, read-only plugin.

* Site introspection, file read, database read, read-only content-block access, and memory.
* "Who can connect" setting – Administrators only by default; connections stop working if their user loses the role.
* Every tool call recorded in the activity log.
* Login credentials never returned by the database tool.

== Upgrade Notice ==

= 1.0.1 =
Adds a "Keep activity log" preference and logs rejected tool calls too. No upgrade steps required.

= 1.0.0 =
Initial release – no upgrade steps required.
