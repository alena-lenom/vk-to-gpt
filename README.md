# VK to GPT

VK integration for ChatGPT through a secured remote Model Context Protocol (MCP) connector. The product goal is normal full social work with the owner's personal page and community, bounded by VK API permissions and an explicit method allowlist.

**Current status (20 November 2026):** `main` still contains the historical Node stdio read-only prototype; the active remote ChatGPT connector runs secured PHP MCP on REG.RU. The most recently confirmed **published** PHP revision is `66726f80564dafe24adab25dd01bd113477231b5` ([guarded release #44612969039](https://github.com/alena-lenom/vk-to-gpt/actions/runs/44612969039)), not the current head of draft PR #2. ChatGPT currently discovers 9 read tools and 3 write tools, and real read-only tool calls confirm community/personal wall access and paginated wall counters. The additional `post_metric_summary` analytics and follow-on release tooling are **source-only** until a separately verified deployment. `VK_USER_TOKEN` was missing in the last protected runtime audit; `wall.getById`/`wall.getComments` return VK API 1051 with the configured service key; native community statistics and all actual VK writes remain unverified. Listing write tools is not authorization to invoke them.

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

`public/mcp.php` uses the official experimental [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) (Apache-2.0) and `nyholm/psr7`. The secured remote MCP exposes **9 read** and **3 registered write** tools. The reads include `vk_wall_collect` (bounded multi-page `wall.get`), and an opt-in `vk_sapi_wall_replies` reader that currently returns `NOT_CONFIGURED` without separate approved access to SAPI's private event storage. The three write tools are `vk_wall_post`, `vk_wall_edit` and `vk_wall_create_comment`; these are allowlisted, target-bound and have **not** been live-executed for this ChatGPT connector. Reads are restricted to the configured personal/community identities, and write operations require an explicit authorized request.

The production credential routing released at `66726f8` maps each VK method to schema-compatible token classes before making a request, chooses one configured actor per method/target and does not change actors after a VK denial. A service credential can read both public walls, but the current VK provider returns 1051 for `wall.getById` and `wall.getComments`. The community token does not replace the missing eligible user token required for native `stats.get` and `stats.getPostReach`. The separately observed SAPI community posting result is donor evidence only, not permission or proof that this PHP connector can post.

Requirements: PHP 8.4, Composer 2, cURL/JSON/OpenSSL and PDO SQLite support, and private directories outside the web document root (mode 0700). OAuth uses League OAuth2 Server, SQLite, authorization code + PKCE (S256), and dynamic registration restricted to ChatGPT callback URLs. The authorization page checks the existing owner bearer from private hosting configuration; it does not retain or return that value. The original static bearer remains accepted for compatibility. Provision settings server-side, never in query parameters or public files:

- `MCP_BEARER_TOKEN`: a strong random bearer secret using RFC 6750 characters. Absent, empty, malformed or nonmatching tokens fail closed with HTTP 401, before SDK loading. Send it only in `Authorization: Bearer …`.
- `MCP_ALLOWED_HOST`: exact hostname without scheme or port; defaults to `localhost`. No wildcard hosts, forwarded-header trust or browser Origin requests are accepted.
- `MCP_SESSION_DIR`: existing private session directory, dedicated to this endpoint. Keep it outside the document root and inaccessible to other hosting users.
- `MCP_OAUTH_DB`, `MCP_OAUTH_PRIVATE_KEY`, `MCP_OAUTH_PUBLIC_KEY`, `MCP_OAUTH_ENCRYPTION_KEY`, `MCP_OAUTH_SESSION_DIR`: generated by `deploy/provision-oauth.php`; keep them in private storage with owner-only permissions.
- `VK_SERVICE_KEY`: credential for allowed public VK reads. `VK_USER_TOKEN`: eligible user credential for statistics and supported personal/community actions. `VK_COMMUNITY_TOKEN`: community credential for specifically supported group-context methods (including the bounded community post/comment route), never a substitute for a user-only stats method. Actual VK permissions/eligibility are checked per operation; none of these credential values belong in Git.
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

**Source CI (19 November 2026):** the PHP remote MCP and read-only suites passed on the current post-summary branch state, for example [#44616807377](https://github.com/alena-lenom/vk-to-gpt/actions/runs/44616807377) and [#44616807370](https://github.com/alena-lenom/vk-to-gpt/actions/runs/44616807370). These tests validate mock VK responses, schema/contract, OAuth and HTTP smoke, deployment guard/rollback and reporting logic. CI success does not establish that source-only changes are deployed.

**Production verified:** [#44612969039](https://github.com/alena-lenom/vk-to-gpt/actions/runs/44612969039) reports a successful guarded routing release with HTTPS bearer protection, OAuth discovery, MCP initialize/tools/list and protected-path verification. Real direct ChatGPT calls subsequently confirmed `vk_wall_get`, `vk_wall_collect`, `vk_users_get` and `vk_groups_get_by_id` for the configured owners. One completed community collection returned 94 wall entries; a fully paginated personal-wall extraction returned 2,144 entries. These are `wall.get` records and post counters, **not** native audience/reach or historical comment data. On read-only live tests, by-ID and comments returned VK error 1051 using the configured service credential, and `stats.get` reported missing `VK_USER_TOKEN`.

**Remaining gaps:** acquire/validate a provider-eligible user credential through an authorized supported path (do not attempt third-party/grayscale token sources); independently confirm restricted details/comments/statistics per credential; deliver comparable analytics reports and any owner-authorized write scenarios with real read-back; align the PHP production source with `main` after a guarded release review. The existing OAuth discovery and MCP authorization guards are verified; this evidence does not itself certify every possible OAuth token exchange. No live VK write was performed in these checks.

## Bounded wall collection (deployed) and extended analytics (source-only)

`vk_wall_collect` collects up to 300 post-counter records per call using `wall.get` with sequential `offset` pages, without `wall.getById` or `wall.getComments`. Follow `next_offset` to cover larger walls. Each result includes `reported_total`, `coverage` (COMPLETE/PARTIAL), duplicate counts, and actual `owner_id` for every returned post. A personal wall can include posts with community ownership; never silently attribute those posts to the personal owner. The response is ephemeral, not a durable SAPI cycle snapshot, and does not claim audience, native reach, historic comments, or period-complete analytics. No new VK credentials are needed.

For each retrieved page batch, `post_metric_summary` reuses SAPI's known-versus-missing counter rule for items with the requested actual `owner_id`: every counter reports `sum_known`, `available_posts`, `missing_posts`, and `status`. `0` is an available measurement, whereas a missing `views` field is **not** treated as zero. Posts belonging to another owner are excluded from the owned-post totals. Each batch summary has its own `coverage`; combine multiple offsets only after reconciling total counts, unique post keys and coverage. Counters are cumulative as of collection, not engagement generated during a publication period. This is a **source-only** extension until separately released to production.

**Release boundary:** the complete bounded read-adapter set was published by [#44601223355](https://github.com/alena-lenom/vk-to-gpt/actions/runs/44601223355); its production VK method/token routing was subsequently updated by [#44612969039](https://github.com/alena-lenom/vk-to-gpt/actions/runs/44612969039). The new `post_metric_summary` field remains **source-only** at the time of this documentation revision. Future releases must check the actual installed `REVISION` against an exact baseline, run the relevant tests, publish only the approved file set and verify/rollback on failure; do not reuse an obsolete baseline or treat the PR HEAD as automatically deployed.

SAPI's existing community `wall_reply_new` Callback collector is the authoritative source of observed inbound events. No second VK Callback endpoint is registered. An opt-in `vk_sapi_wall_replies` read-only adapter can consume **normalized** SAPI events when an operator separately configures `VK_SAPI_EVENTS_DIR` in the protected server configuration. It is disabled by default (`NOT_CONFIGURED`) and validates the group ID against stored provenance; it never returns raw event records or secret fields. These are only observed, deduplicated inbound comments, not historical VK comment coverage. Enabling access to SAPI's private storage requires separate security review and permission; the adapter code is deployed, but its private source remains disabled.

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

The repository runs independently of SAPI Control Center and Automation Platform. SAPI is an approved code donor for tested VK patterns and optional normalized Callback events, not a mandatory runtime dependency.

## Project documents

The authoritative project and Core instructions, roadmap, research evidence and current decisions are in **Google Drive / VK to GPT**. After each substantive research cycle, verified findings are saved there and read back. This README is an entry point, not a second decision log.
