## Problem

<!-- What is wrong or missing, and what behavior results from this change? -->

## Checks

<!-- List the checks you ran and their results. See CONTRIBUTING.md. -->

- [ ] `XDEBUG_MODE=off composer ai:validate`
- [ ] Regression tests cover the changed behavior
- [ ] Documentation updated; MCP changes are also in `AGENTS.md`, `LLMS.txt` and `McpServerCommand::agentInstructions()`
- [ ] For UI, authentication or MoonShine changes: `npm run test:e2e` and `docker/smoke.sh`
- [ ] No `.env`, tokens, local databases or generated services are committed
