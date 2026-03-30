# TODO

## Immediate: Raw Sentinel CLI Polish

Priority work before returning to the TUI. The raw `sentinel` command is the primary interface.

### Singleflight Primitive
- `$scope->singleflight($key, $task)` — deduplicates concurrent tool calls across agents
- When 4 agents all request `ReadFile("src/AgentLoop.php")`, only one execution happens
- Vault docs: `20-knowledge/patterns/singleflight-multi-agent-tool-dedup.md`
- Implementation in `phalanx-core` on `ExecutionScope`

### Pre-fetch Changed File Contents
- Include file contents directly in the review prompt instead of agents needing to call ReadFile
- Reduces API round-trips and enables singleflight to work even better
- Truncate large files, skip binaries

### Non-blocking DaemonAI SDK
- Current `observe()` uses blocking `curl`/`file_get_contents`
- Bridge already bypasses SDK for reads using ReactPHP `Browser` directly
- Full async SDK: `Daemon::observeAsync(): PromiseInterface`
- See "Async DaemonAI SDK" section below for details

---

## TUI Integration (Paused)

**Status**: `phalanx/terminal` library complete (62 source files, 105 tests). TUI command scaffolded and partially working. Paused to focus on raw sentinel CLI correctness first.

### What Works
- Alternate screen mode with bordered agent panels in dynamic grid layout
- Status bar, input line with Box border, space key, all navigation keys
- Agent registration renders in panels
- File change notifications display in panels
- Keyboard input accepted and submitted on Enter
- Clean terminal restoration on exit (Ctrl+C, `quit`, crash)

### What Doesn't Work Yet

**Agent responses don't come back from TUI input** (Critical)
- Root cause: `\React\Async\async()` creates a standalone fiber detached from Phalanx's `ExecutionScope` fiber tree
- When `coordinator->humanMessage()` calls `$scope->concurrent($tasks)` inside that detached fiber, the scope may not support it
- The raw sentinel works because StdinReader runs inside a Task that's part of `$scope->concurrent()`, so the fiber is in the scope tree
- Fix options: (a) `$scope->execute(Task::of(...))` for properly-tracked child fiber, (b) investigate `ExecutionLifecycleScope` re-entrant concurrent support
- Detailed analysis in `phalanx/packages/phalanx-terminal/TODO.md`

**Real-time token streaming not implemented**
- Coordinator collects all tokens then renders complete text
- For TUI, tokens should stream character-by-character into ScrollableText panels
- Need token callback in `executeAndCollect()` that dispatches to Surface
- ConsoleRenderer already has `agentStreamStart/agentToken/agentStreamEnd` — just not wired

### Files
- `src/Sentinel/SentinelTuiCommand.php` — TUI command (Executable)
- `src/Sentinel/Render/TuiRenderer.php` — Surface-backed ReviewRenderer
- `src/Sentinel/Render/ReviewRenderer.php` — interface (both renderers implement)
- `bin/commands/sentinel-tui.php` — command definition
- `phalanx/packages/phalanx-terminal/` — the library (62 files)
- `phalanx/packages/phalanx-terminal/TODO.md` — library-specific TODO

### Architecture Context
- Continuation doc: `.aimind/context/session-continuation-2026-03-29-0230.md`
- Full render pipeline, input flow, anti-deadlock patterns documented there
- STDIN ownership constraint: raw sentinel (cooked mode) and TUI (raw mode) CANNOT share STDIN — separate commands, not a flag

---

## Agent Parallelism (Future)

Currently agents run concurrently via fibers in a single process. For CPU-heavy or latency-sensitive workloads, spawn each agent in its own child process.

### Approach
- Use `react/child-process` + `clue/ndjson-react` for IPC (same pattern as `phalanx-parallel`)
- Each agent process runs `AgentLoop::run()` independently
- Parent process collects results via NDJSON stream
- `wyrihaximus/react-child-process-pool` for crash recovery and round-robin dispatch

### What Needs to Happen
1. Make `ReviewAgent` serializable (it already uses a `Dossier` with string fields)
2. Create a worker script that receives agent config + prompt via stdin, runs the loop, writes result to stdout
3. Coordinator dispatches to worker pool instead of `scope->concurrent()`
4. DaemonAI bridge messages flow through parent process (workers don't need direct daemon access)

### Benefits
- True parallelism (multiple CPU cores for multiple LLM API calls)
- Process isolation (one agent crash doesn't kill the session)
- Memory isolation (each agent's conversation history in its own process)

### ReactPHP Packages Needed
- `clue/ndjson-react` — NDJSON framing for IPC
- `wyrihaximus/react-child-process-pool` — production worker pool
- `react/child-process` — already a dependency

---

## Async DaemonAI SDK (Future)

The DaemonAI PHP SDK is entirely blocking — all HTTP methods use `curl`/`file_get_contents` with 2-5s timeouts. The sentinel bridge bypasses the SDK for reads (uses ReactPHP `Browser` directly).

### Current State

| SDK Method | Transport | Blocking? |
|-----------|-----------|-----------|
| `sendUdp()` | UDP socket | No (fire-and-forget) |
| `observe()` | HTTP GET (curl) | Yes, 5s timeout |
| `send/log/warn/error` | HTTP POST (curl) | Yes, 2s timeout |

### Decision
Deferred. Bridge workaround (bypassing SDK for reads) works today. Full async SDK makes sense when other async consumers emerge or SSE/WebSocket observation is needed.

---

## Phalanx CLI Scaffolder (Future)

A `phalanx new` command that scaffolds new projects with:
- `symfony/runtime` + `autoload_runtime.php` entry point
- `.env` template with common config keys
- `ServiceBundle` skeleton
- Choice of project type: HTTP server, CLI tool, WebSocket server, worker
- PHPStan config with `wyrihaximus/phpstan-react`
- Rector config for PHP 8.4+
