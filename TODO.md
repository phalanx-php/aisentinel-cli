# TODO

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

- `clue/ndjson-react` -- NDJSON framing for IPC
- `wyrihaximus/react-child-process-pool` -- production worker pool
- `react/child-process` -- already a dependency

## Phalanx CLI Scaffolder (Future)

A `phalanx new` command that scaffolds new projects with:
- `symfony/runtime` + `autoload_runtime.php` entry point
- `.env` template with common config keys
- `ServiceBundle` skeleton
- Choice of project type: HTTP server, CLI tool, WebSocket server, worker
- PHPStan config with `wyrihaximus/phpstan-react`
- Rector config for PHP 8.4+

## TUI (Future)

Replace raw ANSI output with `php-tui/term` for:
- Alternate screen with agent focus views
- Raw mode keyboard input (1-4 to focus agent, ESC to go back)
- Status bar with token counts and daemon connection
- Requires reworking StdinReader to use polling instead of ReactPHP stream events
