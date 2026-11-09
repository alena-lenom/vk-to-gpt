# VK to GPT

Read-only integration of VK (VKontakte) with ChatGPT through a secured remote Model Context Protocol (MCP) server.

**Status:** Discovery / pre-development. There is no deployed MCP endpoint, confirmed VK API authorization, or ChatGPT end-to-end connection yet.

## Goal

Enable ChatGPT to read and analyze VK data that the current VK API, token type, permissions, and account rights actually allow:

- Community and personal-page walls and posts.
- Available comments and engagement counters (likes, reposts, views).
- Available community statistics, reach, and audience metrics.
- Reproducible comparisons of periods and evidence-based reports.

Engagement counters are not substitutes for reach, visits, or audience statistics. Missing or restricted data must be stated explicitly.

## Architecture

`VK API -> VK MCP Server (HTTPS, authenticated, read-only) -> ChatGPT -> Analytics and reports`

The implementation follows **REUSE -> ADAPT -> IMPLEMENT**: choose one proven upstream MCP server, adapt only what is required, and reuse other donors only for verified gaps.

**Candidates under review (not yet selected):**

- [bulatko/vk-mcp-server](https://github.com/bulatko/vk-mcp-server) — current leading candidate.
- [SCom-82/vk-community-mcp](https://github.com/SCom-82/vk-community-mcp) — potential stats method donor.
- [theYahia/vk-mcp](https://github.com/theYahia/vk-mcp) — transport alternative.
- [ilyautov/vk-mcp-ru](https://github.com/ilyautov/vk-mcp-ru) — VK API coverage reference.

Donor forks are held separately at [alena-Lenom-fork](https://github.com/alena-Lenom-fork). No third-party code has been imported into this repository yet. Review each donor's license and preserve required notices before reuse.

## Roadmap

1. Audit donors: source, license, maintenance, transport, security and actual VK API coverage.
2. Establish a strict read-only tool and VK method allowlist; test denial of write operations.
3. Verify lawful VK ID/OAuth or service-key capabilities with real, redacted API evidence.
4. Implement a secured remote MCP endpoint with HTTPS, access control, and server-side secret storage.
5. Verify actual tool discovery and data reads from ChatGPT.
6. Build analytics from confirmed metrics, with pagination, coverage and period-comparability checks.
7. Verify failures, limits, data integrity, secret protection and reproducible end-to-end results.

Each milestone is complete only with evidence (specific commit, test/CI run, VK API result or MCP/ChatGPT runtime result). A successful build alone does not prove integration.

## Security and boundaries

Read-only by default. No publishing, editing, deleting, messaging, scraping around VK restrictions, or unapproved production/DNS/credential changes. Do not place VK tokens, credentials, or personal/private datasets in Git, issue comments, logs, test fixtures or reports. Do not use third-party app identifiers or gray-area OAuth flows as a shortcut.

The repository is independent of SAPI Control Center and Automation Platform.

## Project documents

The authoritative project and Core instructions, roadmap, research evidence and current decisions are in **Google Drive / VK to GPT**. After each substantive research cycle, verified findings are saved there and read back. This README is an entry point, not a second decision log.
