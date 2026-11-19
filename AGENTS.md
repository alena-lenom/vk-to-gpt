# Repository agent instructions

## Scope and authority
These rules apply to this repository unless superseded by a more specific approved project instruction. User requests and current approved Core decisions define the task and acceptance. Do not infer new product capabilities, release authorization or requirements from this file.

## Duo OS development
Before acting on OS-related tasks, read [Duo OS development contract](docs/duo-os-workflow.md). One project and one Git history are used from Windows or macOS; native OS checks remain independently verified. Do not block portable implementation because the other OS or its runner is offline. Never discard uncommitted changes, conflate runner smoke with E2E, run untrusted PR code on persistent self-hosted machines, or mutate production as a side effect of cross-OS cleanup.

## Repository boundary
VK to GPT is a full social connector for the owner's personal VK page and community. Implement useful read and write actions for posts/media, comments/replies, community conversations, reactions/reposts, and supported community operations only where the official VK API and credentials permit them. Keep a server-side method allowlist and bind every operation to configured owner/community IDs; do not expose arbitrary-method execution or mass messaging. Support delete and other irreversible actions only when the API permits them, and require an explicit owner request for the concrete target/action. Keep VK tokens, the MCP bearer secret, session data and `shared/config.php` outside Git, the public webroot, logs, fixtures and reports. Serve only `public/` as the document root; never expose the stdio prototype through an unauthenticated HTTP bridge. Read-only verification, CI success and mocked tests do not authorize deployment, DNS/hosting changes, credential access or live VK writes. Do not use third-party app identifiers or gray-area OAuth flows, and do not bypass VK API restrictions.
