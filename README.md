# VK to GPT

Personal VK integration for ChatGPT through a secured remote Model Context Protocol (MCP) server.

**Status:** Main contains a local read-only Node prototype. A PHP Streamable HTTP MCP adapter is on branch `feat/php-mcp-http`. No deployed endpoint, verified VK credentials, or ChatGPT end-to-end connection is confirmed.

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

The `feat/php-mcp-http` branch contains a PHP Streamable HTTP endpoint and seven read-only tools. Its successful CI run does not prove installed production dependencies, live MCP handshake, VK authorization, or deployment.

The open draft PR #1 proposes a Node/systemd production deployment and does not match the chosen PHP/Host-0 route. Do not use it for deployment.

## Actions and safeguards

Creation and editing of the owner's publications are in scope. Implement only named actions mapped to officially supported VK methods and the owner's own token permissions. Require a clear owner instruction for each concrete write action; validate target and content, use method-level allowlists, return the VK result, and read back the affected object when supported.

Deletion, mass messaging, arbitrary API calls, and unrelated community/account administration are not authorized by this scope. Never bypass VK restrictions or use third-party/gray-area OAuth flows.

Store VK tokens and the MCP bearer secret outside the public webroot and Git. Do not put secrets in GitHub files, logs, fixtures, or reports. Keep minimal permissions and authenticate/authorize every remote MCP request.

## Runtime and verification

Target host: REG.RU virtual hosting, Host-0; owner-reported PHP 8.4.27/Python 3.14 are not yet independently verified for the deployed runtime. The CLI versions observed earlier differed. Compatibility with the PHP HTTP endpoint, private secret storage and the current `/mcp` route remains unverified.

Before deployment, verify the PHP branch and dependencies, endpoint handshake and authentication, one real authorized read, then a safe create/edit flow only after capability and target are confirmed. Confirm actual ChatGPT connection to this endpoint. CI alone is not end-to-end evidence.

## Project documents

The current scope and roadmap are maintained in Google Drive / VK to GPT. This README is an entry point; after substantive research, save and verify findings in the existing project document.
