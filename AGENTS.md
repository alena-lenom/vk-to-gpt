# Repository agent instructions

## Scope and authority
These rules apply to this repository unless superseded by a more specific approved project instruction. User requests and current approved Core decisions define the task and acceptance. Do not infer new product capabilities, release authorization or requirements from this file.

## Duo OS development
Before acting on OS-related tasks, read [Duo OS development contract](docs/duo-os-workflow.md). One project and one Git history are used from Windows or macOS; native OS checks remain independently verified. Do not block portable implementation because the other OS or its runner is offline. Never discard uncommitted changes, conflate runner smoke with E2E, run untrusted PR code on persistent self-hosted machines, or mutate production as a side effect of cross-OS cleanup.

## Repository boundary
VK to GPT. Preserve the named VK method allowlists: reads are limited to the declared read-only set, and wall post/edit tools may run only against the server-configured personal page and community, and only on an explicit owner instruction for each concrete write action. Never add delete, messaging, mass-send or arbitrary-method executor capabilities. Keep VK tokens, the MCP bearer secret, session data and `shared/config.php` outside Git, the public webroot, logs, fixtures and reports. Serve only `public/` as the document root; never expose the stdio prototype through an unauthenticated HTTP bridge. Read-only verification, CI success and mocked tests do not authorize deployment, DNS/hosting changes, credential access or live VK writes. Do not use third-party app identifiers or gray-area OAuth flows, and do not bypass VK API restrictions.
