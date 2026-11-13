# VK to GPT

VK integration for ChatGPT through a secured remote Model Context Protocol (MCP) server, with bounded read, post, and edit operations.

**Status:** Node stdio prototype remains read-only. The PHP 8.4 Streamable HTTP branch adds bounded wall post/edit tools; it is not deployed. Tests use mocked VK responses. No live VK write, real credential check, or ChatGPT end-to-end connection is claimed.

## Goal

Enable ChatGPT to read and analyze VK data, and publish or edit wall posts only when explicitly requested and allowed by the VK API, token type, permissions, and account rights:

- Community and personal-page walls and posts, including explicit text post creation and text editing on configured targets.
- Available comments and engagement counters (likes, reposts, views).
- Available community statistics, reach, and audience metrics.
- Reproducible comparisons of periods and evidence-based reports.

Engagement counters are not substitutes for reach, visits, or audience statistics. Missing or restricted data must be stated explicitly.

## Architecture

`VK API -> VK MCP Server (HTTPS, authenticated, restricted reads and writes) -> ChatGPT -> Analytics and reports`

The implementation follows **REUSE -> ADAPT -> IMPLEMENT**: choose one proven upstream MCP server, adapt only what is required, and reuse other donors only for verified gaps.

**Candidates under review (not yet selected):**

- [bulatko/vk-mcp-server](https://github.com/bulatko/vk-mcp-server) — current leading candidate.
- [SCom-82/vk-community-mcp](https://github.com/SCom-82/vk-community-mcp) — potential stats method donor.
- [theYahia/vk-mcp](https://github.com/theYahia/vk-mcp) — transport alternative.
- [ilyautov/vk-mcp-ru](https://github.com/ilyautov/vk-mcp-ru) — VK API coverage reference.

Donor forks are held separately at [alena-Lenom-fork](https://github.com/alena-Lenom-fork). The first adapter is adapted from bulatko/vk-mcp-server patterns. Its MIT notice is preserved in [third_party/bulatko-LICENSE](third_party/bulatko-LICENSE); see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md). Do not interpret this as evidence that the upstream server is already deployed.


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

## PHP HTTP endpoint (REG.RU Host 0 target; not deployed)

`public/mcp.php` uses the official experimental [mcp/sdk](https://github.com/modelcontextprotocol/php-sdk) (Apache-2.0), with `nyholm/psr7` as its PSR-7/17 implementation. There is no application framework or custom MCP protocol implementation. `php/tools.json` mirrors the seven read-only Node tools and the parity test detects drift. PHP adds `vk_wall_post` and `vk_wall_edit` through a separate strict allowlist, with target IDs fixed in server configuration. Community publishing uses `VK_COMMUNITY_TOKEN`; personal publishing and both edit targets use `VK_USER_TOKEN`. Read requests may retry rate limits; writes never retry after an uncertain response. There is no delete tool. Tool arguments never accept credentials or arbitrary owner IDs.

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

**Confirmed locally:** PHP 8.4.27 lint passed; `php test/php/run.php` passed 47 checks (validation, allowlists, credential/version override protection, token selection, retries and redaction); the Node/PHP schema parity check passed; all 6 existing Node tests passed. `composer validate --no-check-publish` passed with a missing project-license metadata warning. These checks do not exercise the SDK.

**Still unverified locally:** Composer cannot reach Packagist (configured proxy connection fails; without proxy DNS resolution fails), so dependencies are not installed and no `composer.lock` could be generated. The HTTP smoke test cannot bind loopback sockets in this sandbox (`Operation not permitted`). Neither HTTP 401 nor SDK `tools/list` is claimed as observed. Complete installation, generate and review/commit the lockfile, run `composer check-platform-reqs`, `composer audit`, and rerun the smoke test before deployment. The experimental SDK integration must pass that gate.

**Production requirements remaining:** verify REG.RU Host 0 actually supports PHP 8.4 and the resolved dependencies, outbound verified HTTPS to VK, private environment configuration and session storage, and PHP execution limits sufficient for VK timeouts/retries. Serve only `public/` as the document root over HTTPS with a valid certificate; keep `vendor/`, source, Git, environment and session files outside it. Confirm Apache/FastCGI passes Authorization and MCP headers, request-size/time limits, session continuity across workers, and SDK responses through hosting buffering. Add hosting-level request/rate limits and disable PHP error display. Validate HTTPS unauthorized rejection, Host/Origin rejection, initialize, discovery and mocked read failures on the intended runtime. Static bearer authentication is not an OAuth implementation; confirm the intended MCP client's authentication support separately. Actual lawful VK access and ChatGPT end-to-end use remain separate acceptance checks. No REG.RU connection or deployment has been performed.

## Roadmap

1. Audit donors: source, license, maintenance, transport, security and actual VK API coverage.
2. Keep a strict method allowlist; restrict post/edit to configured walls, route tokens by target, and verify writes with mock responses.
3. Verify lawful VK ID/OAuth or service-key capabilities with real, redacted API evidence.
4. Implement a secured remote MCP endpoint with HTTPS, access control, and server-side secret storage.
5. Verify actual tool discovery and data reads from ChatGPT.
6. Build analytics from confirmed metrics, with pagination, coverage and period-comparability checks.
7. Verify failures, limits, data integrity, secret protection and reproducible end-to-end results.

Each milestone is complete only with evidence (specific commit, test/CI run, VK API result or MCP/ChatGPT runtime result). A successful build alone does not prove integration.

## Security and boundaries

Reads remain available; publishing and editing require an explicit tool call and are limited to the configured personal/community wall. Deleting and messaging are not exposed. Do not scrape around VK restrictions or make unapproved production/DNS/credential changes. Do not place VK tokens, credentials, or personal/private datasets in Git, issue comments, logs, test fixtures or reports. Do not use third-party app identifiers or gray-area OAuth flows as a shortcut.

The repository is independent of SAPI Control Center and Automation Platform.

## Project documents

The authoritative project and Core instructions, roadmap, research evidence and current decisions are in **Google Drive / VK to GPT**. After each substantive research cycle, verified findings are saved there and read back. This README is an entry point, not a second decision log.
