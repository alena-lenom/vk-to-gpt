# Duo OS development contract
Status: project-level execution rules for a dual-boot Windows/macOS development machine. Effective for new work; do not rewrite past evidence.

## Authority and ownership
- Git commits, refs and remote history define committed code identity. GitHub issues, PRs and approved Core decisions govern work and acceptance. The GitHub remote is the shared publication/coordination point; it does not contain uncommitted local edits, secrets, databases or runtime state.
- Work packages belong to **projects, components and Git revisions**, never to a host OS. The currently booted OS is an execution detail, not a separate development authority or an automatic task blocker.
- Preserve each project's approved security boundaries, active package, release gates and scoped agent rules. This document cannot authorize extra capabilities, deployments, credential access, provider writes or destructive cleanup.

## Classify the verification, not the task
- **Portable:** source edits, architecture, documentation, review, most unit tests, lint/typecheck/build and CI diagnosis. Execute on either OS with the required dependencies. Do not require an inactive OS to begin or continue this work.
- **Native Windows / native macOS:** only API, service, GUI, privilege, filesystem, packaging or runner behavior that actually depends on the specified OS. Execute those checks on that OS and report their exact results. Native acceptance on one OS never proves the other.
- **Operational:** production, deployment, authentication and database actions follow their separate access/approval gates regardless of the current OS.
- Describe an outstanding native check as `verification_pending:<os>`, with exact revision and missing evidence. Do **not** relabel the whole portable work package `BLOCKED` unless that check is a required immediate acceptance/release gate.

## Checkout and Git handoff
- Discover the repository with `git rev-parse --show-toplevel`; check `git status --short --branch`, `git rev-parse HEAD`, branch/upstream, and `git worktree list --porcelain` before edits and again at handoff. Do not assume `D:\GitWorkspace` or `/Volumes/Lenom Disk/GitWorkspace` is present.
- A verified, healthy shared NTFS checkout is an **option**, not a mandatory source of truth. The same Git remote can be used with OS-local clones/worktrees when a shared checkout is inaccessible or unreliable. Inventory the existing workspace, dirty changes, branches and remote before creating any replacement; avoid duplicate active ownership of the same task.
- Do not share a live `.git` administration directory between independent OS-local clones. Linked worktrees share Git metadata and need correct path references; never move/copy their metadata blindly across OSes. Prefer independent OS-local clones when filesystem mounts/path semantics are unstable.
- Do not run `git reset --hard`, `git clean`, unsafe `stash pop`, forced branch movement, index deletion/rebuild or unknown worktree pruning to fix cross-OS problems. Back up and isolate failed Git metadata before any approved repair. A Git crash is a local checkout incident, not proof that the whole project requires the other OS.
- Preserve local changes and their provenance. Before moving an unfinished task, use an intentional commit/push or a reviewed patch/handoff with exact HEAD, dirty-file inventory and verification evidence. Do not pretend that an uncommitted change is present in the remote.
- Probe filesystem-specific `core.ignoreCase`, `core.fileMode`, `core.symlinks` and line endings; never apply one global Git setting blindly. Prefer tracked `.gitattributes` for stable text normalization. Keep virtual environments, executables, caches, native dependencies, runner state and credentials OS-local, not in a shared source checkout.

## CI and runners
- Route portable checks to any appropriately configured **trusted** eligible runner or isolated hosted backend. Pin the required toolchain versions and capture exact commit SHA, runner OS, run/job ID, status and relevant test output.
- Route native checks to separately labeled OS-specific jobs. An offline on-demand runner means that particular check is pending/queued, not that unrelated development must stop. Do not count queued, skipped, timed-out or pre-checkout failures as passing tests.
- Never execute untrusted PR head code on a persistent, privileged self-hosted runner; especially review `pull_request_target`, tokens, checkout trust and shared runner workspace isolation. Use isolated hosted/ephemeral execution or trusted-source validation. No new runner registration, autostart or persistent access is implied.
- Keep OS-dependent setup/cleanup under explicit `runner.os` conditions or separate jobs. Do not run Windows paths or PowerShell-only commands unconditionally on macOS; likewise do not treat Darwin-specific commands as Windows-portable.
- For a cross-OS acceptance claim, test the **same exact source revision** independently on each required OS; label each evidence source. A portable suite passing on one OS is portable evidence, not native parity.

## Completion and incident handling
- Work continuously through available portable tasks. At each gate record DONE / VERIFIED, native verification PENDING, or a concrete protected blocker with affected scope. Never inflate capability or release readiness based solely on documentation, mocks, a runner smoke test or an HTTP status.
- Keep issue/PR history and previous acceptance intact; perform the smallest change and affected tests first. A code/CI change requires separate source validation and its normal acceptance gate. Do not merge or deploy solely because this document exists.

## Project-specific boundary
VK to GPT: keep VK tokens, the MCP bearer secret, session data, `shared/config.php`, hosting state and provider permissions outside source and CI logs. The VK method allowlists are named and fixed: read-only tools only for reads; wall post/edit only on an explicit owner instruction per action and only against the configured personal/community targets. No delete, messaging, mass-send or generic VK method executor. Read-only verification does not authorize live VK writes, deployment, DNS/hosting changes or credential access. Platform-native checks only where behavior actually depends on the host OS.

## References
- Git worktrees and per-worktree configuration: https://git-scm.com/docs/git-worktree
- Git configuration and filesystem differences: https://git-scm.com/docs/git-config
- GitHub Actions runner labels and routing: https://docs.github.com/en/actions/how-tos/manage-runners/self-hosted-runners/use-in-a-workflow
- GitHub Actions workflow shell/platform rules: https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax
- GitHub Actions self-hosted security: https://docs.github.com/en/actions/reference/security/secure-use
