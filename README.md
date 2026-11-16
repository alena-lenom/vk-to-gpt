# VK to GPT

VK integration for ChatGPT through a secured remote Model Context Protocol (MCP) connector. The product goal is normal full social work with the owner's personal page and community, bounded by VK API permissions and an explicit method allowlist.

**Status:** The Node stdio prototype on `main` remains read-only. The PHP 8.4 branch `feat/php-mcp-http` is deployed on REG.RU at release SHA `563cb4737fc42c9786d8266888fd6a7a6ed7c3a5`. GitHub Actions verified HTTPS authentication, MCP initialize, and discovery of seven read and three bounded write tools. A community-info VK read succeeded; wall/statistics access, live post/edit, and ChatGPT-to-endpoint invocation remain unverified.

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

## PHP HTTP endpoint (REG.RU Host 0; deployed release)

`public/mcp.php` uses the official experimental [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) (Apache-2.0), with `nyholm/psr7` as its PSR-7/17 implementation. There is no application framework or custom MCP protocol implementation. `php/tools.json` mirrors the seven Node prototype reads. The deployed PHP endpoint adds `vk_wall_post`, `vk_wall_edit`, and `vk_wall_comment_create`; reads are restricted to configured personal/community IDs, and write targets are fixed in server configuration. This is the current base, not yet full social coverage. Community publishing uses `VK_COMMUNITY_TOKEN`; personal publishing and both edit targets use `VK_USER_TOKEN`. Personal-wall reads prefer `VK_USER_TOKEN`, public community reads prefer `VK_SERVICE_KEY`, and read-only fallback occurs only on VK wrong-token-kind errors 27/1051. Statistics require `VK_USER_TOKEN`. Read requests may retry rate limits; writes never retry after an uncertain response. Comment creation now supports comments and replies on the configured wall; delete, comment editing, messaging, reactions and attachment uploads are not yet exposed. Tool arguments never accept credentials or IDs outside configured targets.

Requirements: PHP 8.4, Composer 2, cURL/JSON/OpenSSL support, and a private writable session directory outside the web document root (mode 0700). File sessions survive separate PHP requests and expire after ten minutes. Provision these server-side environment variables using private hosting configuration, never query parameters or public files:

- `MCP_BEARER_TOKEN`: a strong random bearer secret using RFC 6750 characters. Absent, empty, malformed or nonmatching tokens fail closed with HTTP 401, before SDK loading. Send it only in `Authorization: Bearer …`.
- `MCP_ALLOWED_HOST`: exact hostname without scheme or port; defaults to `localhost`. No wildcard hosts, forwarded-header trust or browser Origin requests are accepted.
- `MCP_SESSION_DIR`: existing private session directory, dedicated to this endpoint. Keep it outside the document root and inaccessible to other hosting users.
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

**Remaining GAP:** deployed MCP transport is verified, but ChatGPT cannot yet be counted as connected end to end; the current plugin authentication setup is not compatible with the static bearer flow. A real community-info VK read is recorded in Drive / VK to GPT, section 55. The private project record tracks which VK credentials are currently configured. Other wall/statistics methods and community posting permissions still need method-specific live verification. The owner has confirmed she has the needed token types; do not request or expose token values. No live VK write has been performed.

## Roadmap

1. Bind every read to the configured personal page or community; route tokens by API method and account context, with fallback only for documented token-type errors.
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
