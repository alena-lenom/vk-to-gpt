# Third-party notices

The initial read-only VK API adapter and MCP server setup are adapted from:
- bulatko, [vk-mcp-server](https://github.com/bulatko/vk-mcp-server), fork reviewed at [alena-lenom-fork/vk-mcp-server](https://github.com/alena-lenom-fork/vk-mcp-server), version 0.9.0 (2026).
- Source patterns: `src/index.js` (VK request transport, retry/backoff, MCP server setup). Reimplemented as `src/vk-client.js` and `src/index.js` with explicit fail-closed read-only allowlists.
- Upstream uses the MIT License. The original license and copyright notice is reproduced in [third_party/bulatko-LICENSE](third_party/bulatko-LICENSE).

No implementation from SCom-82/vk-community-mcp is copied yet. Its statistics methods were used only as a reference for VK API method names. Licenses for other donors must be reviewed before importing code.

This repository does **not** claim any authorization to use private VK data or restricted API methods.
