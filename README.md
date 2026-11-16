# VK to GPT

Personal VK integration for ChatGPT through a secured remote Model Context Protocol (MCP) server.

**Status:** `main` contains the local read-only Node prototype. PHP Streamable HTTP MCP is deployed from `feat/php-mcp-http` at release SHA `563cb4737fc42c9786d8266888fd6a7a6ed7c3a5`. GitHub Actions verified HTTPS authentication, MCP initialization, and discovery of seven read and two bounded write tools. A community-info VK read is confirmed in project evidence; wall/statistics access, live post/edit, and ChatGPT-to-endpoint invocation remain unverified.

## Goal

Let ChatGPT read and analyze the VK data available to the owner's authorized account, and create or edit the owner's publications where the official VK API and token permissions allow it.

- Read community and personal-page walls, posts, comments and available engagement/statistics.
- Create and edit named publications through explicit, owner-requested actions.
- Produce evidence-based comparisons and reports.

Available methods and metrics depend on VK API rules, token type, permissions, ownership and community settings. Do not invent unavailable data or promise unsupported actions.

## Architecture

`VK API -> secured, authenticated PHP MCP endpoint -> ChatGPT -> reading, analytics, and named publication actions`

Reuse existing open-source code and adapt only the necessary parts. Keep named VK method allowlists; do not expose a generic arbitrary-method executor.

## Current implementations

The main branch is a local stdio-only Node prototype with seven read-only tools. It is not a remote ChatGPT connector.

The `feat/php-mcp-http` branch contains the deployed PHP Streamable HTTP endpoint, seven read tools, and named `vk_wall_post`/`vk_wall_edit` tools. Deploy run [43854665926](https://github.com/alena-lenom/vk-to-gpt/actions/runs/43854665926) verified production HTTPS auth, MCP initialize/tools/list, protected paths, and homepage preservation without making VK API calls. PHP CI run [43854665508](https://github.com/alena-lenom/vk-to-gpt/actions/runs/43854665508) passed. The current branch tip is ahead only by documentation commits; deployed PHP source still matches release SHA `563cb4737fc42c9786d8266888fd6a7a6ed7c3a5`.

PR #1, the old draft proposal for Node/systemd deployment, is closed and its separate branch remains. It is not the PHP/Host-0 production route.

## Actions and safeguards

Creation and editing of the owner's publications are in scope. The PHP endpoint exposes named text-only actions, fixed server-configured targets, method allowlists, and target-specific token routing; writes are not retried automatically. Require a clear owner instruction for each concrete action. Read-back and ownership checks are not yet implemented, and no live write has been tested.

Deletion, mass messaging, arbitrary API calls, and unrelated community/account administration are not authorized by this scope. Never bypass VK restrictions or use third-party/gray-area OAuth flows.

Store VK tokens and the MCP bearer secret outside the public webroot and Git. Do not put secrets in GitHub files, logs, fixtures, or reports. Keep minimal permissions and authenticate/authorize every remote MCP request.

## Runtime and verification

Target host is REG.RU Host-0. Workflow `43854665926` verified PHP 8.4 runtime prerequisites, HTTPS endpoint `/mcp`, bearer authentication, Origin rejection, MCP initialize/tools/list, protected private paths, and homepage preservation. It made no VK API calls. The project's OAuth-compatible ChatGPT connection is not established; current static bearer authentication does not complete that connection.

Before deployment, verify the PHP branch and dependencies, endpoint handshake and authentication, one real authorized read, then a safe create/edit flow only after capability and target are confirmed. Confirm actual ChatGPT connection to this endpoint. CI alone is not end-to-end evidence.

## Project documents

The current scope and roadmap are maintained in Google Drive / VK to GPT. This README is an entry point; after substantive research, save and verify findings in the existing project document.
