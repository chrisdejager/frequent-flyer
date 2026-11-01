# Status: debuggability

Not started. Agreed with the owner on 1 Nov 2026 as a work item; nothing is
designed or built yet.

- Gate 1 — Product: pending (light: there is no UI; the "user" is whoever
  diagnoses a production problem)
- Gate 2 — Architecture: pending
- Gate 3 — Program Design: pending
- Gate 4 — Slice plan: pending

## Scope (from the owner's default stack)

- A request id per request: in a response header, in every error body, and in
  every log line.
- Structured logs to stderr. Today: Symfony's plain `Logger`, warning and up
  (`api/config/services.yaml`, pinned by `LoggerConfigurationTest`).
- Xdebug in the dev image in trigger mode, switchable off with
  `XDEBUG_MODE=off make up`. Never in the `prod` target.
- `docs/debugging.md`: debugging backend and frontend locally, and reproducing
  a production problem locally.

## Why now

The app runs in production. An open point is that booking links with a bad
key give a generic 502; a request id in the response and in the log line is
what makes such a report traceable.

## Notes for a fresh session

- Monolog would be a new dependency: justify it at Gate 2 (guided-build), or
  keep the plain `Logger` with a JSON formatter.
- No command bus exists, so no command-logging middleware.
