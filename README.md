# VK to GPT

VK integration for ChatGPT through a secured remote Model Context Protocol (MCP) connector. The product goal is normal full social work with the owner's personal page and community, bounded by VK API permissions and an explicit method allowlist.

**Status:** `main` still contains the read-only Node stdio prototype. Production runs PHP HTTP release SHA `563cb4737fc42c9786d8266888fd6a7a6ed7c3a5`, verified with seven read and two write tools. Current source branch `feat/php-mcp-http` is at `93c851f` and adds the bounded wall comment/reply tool (seven reads, three writes). This isolated branch adds OAuth discovery and authorization on top of that base. It is not deployed. No live VK write or ChatGPT OAuth end-to-end connection is claimed.

## Goal

Enable ChatGPT to work with VK like a social-network connector: read and analyze content, publish and edit, work with comments and community inbox, use supported reactions/reposts, and manage supported community objects. Each operation must be available in the official VK API and authorized for the configured personal/community context:

- Personal and community profiles, walls, posts and attachments.
- Create/edit posts and, when supported, delete own content; manage comments/replies and community conversations.
- Use supported reactions, reposts and community-management methods.
- Read available counters, community statistics and reach; compare periods and produce evidence-based reports.

Engagement counters are not substitutes for reach, visits, or audience statistics. Missing or restricted data must be stated explicitly.

## Architecture

`ChatGPT -> secured VK MCP connector -> VK API -> content actions and analytics`

The implementation follows **REUSE -> ADAPT -> IMPLEMENT**: choose one proven upstream MCP server, adapt only what is required, and reuse other donors only for verified gaps.

**Selected base:** production uses a PHP HTTP adapter with the official MCP PHP SDK. VK method/token-handling patterns were adapted from the MIT donor [vk-mcp-server](https://github.com/alena-lenom-fork/vk-mcp-server); attribution is preserved in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md). The donor itself is stdio-only and is not directly deployed as the remote endpoint. Other forks are references only when a concrete coverage gap requires them.


## Current local prototype

The Node stdio prototype uses the upstream MCP server library and provides **seven read-only tools**: `vk_wall_get`, `vk_wall_get_by_id`, `vk_wall_get_comments`, `vk_users_get`, `vk_groups_get_by_id`, `vk_stats_get`, and `vk_post_reach`. The Node implementation remains unchanged and does not expose writes.

Requirements: Node.js 20+.

```sh
npm install
npm test
npm start
```

Use `VK_SERVICE_KEY` for permitted public VK reads and optionally `VK_USER_TOKEN` for statistics if it is lawfully issued with sufficient permissions. Store tokens in a private environment/secret store, not in tracked files or command history. `.env.example` documents variable names only. Statistics are **not confirmed available** with newly issued VK ID credentials.

This prototype is **stdio-only** and is not a ChatGPT remote connector. No public HTTPS listener or OAuth callback is configured. Do not expose stdio through an unauthenticated HTTP bridge.

Local validation on macOS: `npm test` passed all 6 Node tests. The Node stdio implementation is unchanged.

## PHP HTTP endpoint (REG.RU Host 0; production release and current branch)

`public/mcp.php` uses the official experimental [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) (Apache-2.0), with `nyholm/psr7` as its PSR-7/17 implementation. There is no application framework or custom MCP protocol implementation. `php/tools.json` mirrors the seven Node prototype reads. Production release SHA `563cb47` exposes `vk_wall_post` and `vk_wall_edit`; current branch `feat/php-mcp-http` additionally exposes `vk_wall_create_comment`. Reads are restricted to configured personal/community IDs, and write targets are fixed in server configuration. This is the current base, not yet full social coverage. PHP routes each method through an explicit VK API 5.199 `access_token_type` map before making a request. `users.get` and `groups.getById` may use a configured service, user or community credential; public wall reads permit service/user only, preferring USER for a personal wall and SERVICE for a community wall; statistics require USER. No token is retried as another actor after a VK denial. `wall.edit` requires USER; `wall.createComment` uses USER for personal and COMMUNITY for community targets. Community `wall.post` prefers COMMUNITY, then USER: the official schema lists USER, while one separately authorized SAPI test showed a single text-only COMMUNITY-key success for one group; the PHP MCP route has not been live-tested. Read errors identify only the credential class (service/user/group), never its secret. Read requests may retry rate limits; writes never retry after an uncertain response. Comment creation now supports comments and replies on the configured wall; delete, comment editing, messaging, reactions and attachment uploads are not yet exposed. Tool arguments never accept credentials or IDs outside configured targets.

Requirements: PHP 8.4, Composer 2, cURL/JSON/OpenSSL and PDO SQLite support, and private directories outside the web document root (mode 0700). OAuth uses League OAuth2 Server, SQLite, authorization code + PKCE (S256), and dynamic registration restricted to ChatGPT callback URLs. The authorization page checks the existing owner bearer from private hosting configuration; it does not retain or return that value. The original static bearer remains accepted for compatibility. Provision settings server-side, never in query parameters or public files:

- `MCP_BEARER_TOKEN`: a strong random bearer secret using RFC 6750 characters. Absent, empty, malformed or nonmatching tokens fail closed with HTTP 401, before SDK loading. Send it only in `Authorization: Bearer …`.
- `MCP_ALLOWED_HOST`: exact hostname without scheme or port; defaults to `localhost`. No wildcard hosts, forwarded-header trust or browser Origin requests are accepted.
- `MCP_SESSION_DIR`: existing private session directory, dedicated to this endpoint. Keep it outside the document root and inaccessible to other hosting users.
- `MCP_OAUTH_DB`, `MCP_OAUTH_PRIVATE_KEY`, `MCP_OAUTH_PUBLIC_KEY`, `MCP_OAUTH_ENCRYPTION_KEY`, `MCP_OAUTH_SESSION_DIR`: generated by `deploy/provision-oauth.php`; keep them in private storage with owner-only permissions.
- `VK_SERVICE_KEY`: server-side credential for permitted public reads. `VK_USER_TOKEN`: user credential used for available statistics, personal-page post/edit, and editing community posts. `VK_COMMUNITY_TOKEN`: community credential used only for community publishing.
- `VK_PERSONAL_OWNER_ID` and `VK_COMMUNITY_ID`: positive numeric IDs binding writes to the intended personal page and community. These are fixed server settings, never caller-controlled.

Install and validate locally:

```sh
composer install --no-interaction --prefer-dist
composer test
node test/php/contract.mjs
npm test
python3 -B test/php/smoke_http.py
```

The smoke test starts and stops temporary PHP HTTP listeners with isolated environments, fake MCP secrets and no VK credentials. It checks absent/malformed server configuration, missing/wrong request tokens, the MCP handshake and tool discovery. PHP tests mock VK HTTP responses and verify token routing plus no write retries; no real VK request is needed. For a manual listener, configure the variables above and run `php -S localhost:8080 -t public`; use `/mcp.php`. The built-in server is for local testing only.

**Confirmed locally (Linux sandbox, PHP 8.4.27):** PHP lint passed; `php test/php/run.php` passed 66 checks (validation, allowlists, credential/version override protection, token selection, retries and redaction); the Node/PHP schema parity check passed; all 7 existing Node tests passed. `composer validate --no-check-publish` passed with a missing project-license metadata warning. Dependencies are installed and `composer.lock` is committed: `composer check-platform-reqs` passed (php 8.4.27, ext-curl, ext-json and transitive requirements) and `composer audit` found no security vulnerability advisories. The HTTP smoke test passed on PHP 8.4.27 with real SDK execution: absent/malformed server configuration is rejected, missing/wrong bearer returns HTTP 401, and `initialize` plus `tools/list` returned the seven read tools and three bounded write tools.

**Production runtime verified (16.11.2026):** deploy run [43854665926](https://github.com/alena-lenom/vk-to-gpt/actions/runs/43854665926) succeeded from release SHA `563cb4737fc42c9786d8266888fd6a7a6ed7c3a5`. It checked PHP 8.4 prerequisites, deployed the tested artifact, verified HTTPS authentication, Origin rejection, MCP initialize/tools/list and protected paths, and preserved the homepage; it made no VK API calls. PHP CI [43854665508](https://github.com/alena-lenom/vk-to-gpt/actions/runs/43854665508) passed Composer install/platform/audit, PHP tests, schema parity, Node tests, and HTTP smoke. A separate real `groups.getById` community read is recorded in Drive / VK to GPT, section 55. Wall/statistics, live post/edit, and a ChatGPT-to-endpoint invocation remain unverified.

**Remaining GAP:** deployed MCP transport is verified, but ChatGPT cannot yet be counted as connected end to end. A real community-info VK read is recorded in Drive / VK to GPT, section 55. The private project record tracks which VK credentials are currently configured. Other wall/statistics methods and community posting permissions still need method-specific live verification. The owner has confirmed she has the needed token types; do not request or expose token values. No live VK write has been performed.

**OAuth branch verification:** OAuth code is not deployed and has not completed a real client authorization/token exchange. Local macOS PHP 8.4.27 lacks PDO SQLite; the OAuth integration test is intended for CI, where this branch must enable SQLite before it can pass. Existing base verification above does not certify this OAuth branch.

## Bounded wall collection (PHP branch only)

`vk_wall_collect` collects up to 300 post-counter records per call using `wall.get` with sequential `offset` pages, without `wall.getById` or `wall.getComments`. Follow `next_offset` to cover larger walls. Each result includes `reported_total`, `coverage` (COMPLETE/PARTIAL), duplicate counts, and actual `owner_id` for every returned post. A personal wall can include posts with community ownership; never silently attribute those posts to the personal owner. The response is ephemeral, not a durable SAPI cycle snapshot, and does not claim audience, native reach, historic comments, or period-complete analytics. No new VK credentials are needed.

For each retrieved page batch, `post_metric_summary` reuses SAPI's known-versus-missing counter rule for items with the requested actual `owner_id`: every counter reports `sum_known`, `available_posts`, `missing_posts`, and `status`. `0` is an available measurement, whereas a missing `views` field is **not** treated as zero. Posts belonging to another owner are excluded from the owned-post totals. Each batch summary has its own `coverage`; combine multiple offsets only after reconciling total counts, unique post keys and coverage. Counters are cumulative as of collection, not engagement generated during a publication period. This is a **source-only** extension until separately released to production.

Before release, deploy tooling must ship `ReadOnly.php`, `tools.json`, `WallCollector.php`, and `SapiEventReader.php` together with `server.php`; otherwise startup/tool discovery fails. The guarded updater stages these files, checks the last observed release `8bcff6a73ffe2b9375d75d8f197b3eb4c5c51a6e`, preserves existing modes and OAuth/configuration files, and supports rollback. This source-only candidate is NOT approved for production: confirm actual installed `REVISION` before any future dispatch.

SAPI's existing community `wall_reply_new` Callback collector is the authoritative source of observed inbound events. No second VK Callback endpoint is registered. An opt-in `vk_sapi_wall_replies` read-only adapter can consume **normalized** SAPI events when an operator separately configures `VK_SAPI_EVENTS_DIR` in the protected server configuration. It is disabled by default (`NOT_CONFIGURED`) and validates the group ID against stored provenance; it never returns raw event records or secret fields. These are only observed, deduplicated inbound comments, not historical VK comment coverage. Enabling access to SAPI's private storage requires separate security review and permission; this branch is not deployed.

## Roadmap

1. Bind every read to the configured personal page or community; select one configured credential from the explicit API-method/token-type/target map before the call, and never switch credential actors after a VK refusal.
2. Use the owner's existing credentials from private server configuration and verify wall/profile/statistics capabilities with redacted live evidence.
3. Map common social tasks to official VK API methods and the selected donor: posts/media, comments/replies, community conversations, reactions/reposts, and supported community actions.
4. Adapt the donor's working tools into the secured PHP HTTP MCP while keeping an explicit allowlist and target ownership checks. Never expose arbitrary VK methods.
5. Connect through the user's existing ChatGPT MCP path and verify tools/list plus real reads and explicitly requested writes.
6. Build analytics with full pagination, coverage tracking, period comparisons, and clear separation between VK values and derived metrics.
7. Verify errors, limits, idempotency, destructive-action handling, secret protection, and reproducible end-to-end behavior.

Each milestone is complete only with evidence (specific commit, test/CI run, VK API result or MCP/ChatGPT runtime result). A successful build alone does not prove integration.

## Security and boundaries

Read/write tools must stay limited to the owner's configured personal page and community. Publishing/editing and other requested actions use the correct target-bound token; destructive actions require explicit owner instruction. Do not scrape around VK restrictions or make unapproved production/DNS/credential changes. Do not place VK tokens, credentials, or personal/private datasets in Git, issue comments, logs, test fixtures or reports. Do not use third-party app identifiers or gray-area OAuth flows as a shortcut.

The repository is independent of SAPI Control Center and Automation Platform.

## Project documents

The authoritative project and Core instructions, roadmap, research evidence and current decisions are in **Google Drive / VK to GPT**. After each substantive research cycle, verified findings are saved there and read back. This README is an entry point, not a second decision log.
