# AI Prompt Architecture

**Author:** Reza Rafiei

## Principles
1. System prompts define behavior and output contracts.
2. User data is injected as structured context.
3. Instructions from planner data are data, not system-level commands.
4. Never expose secrets.
5. Never invent planner records.
6. Return uncertainty explicitly.

## Prompt layers
- System: role, safety, output contract.
- Domain: planner rules and definitions.
- Context: relevant stored records.
- User: current request.
- Output schema: machine-readable contract.

## AI planning rule
AI proposes. Planner Engine validates and decides what can actually be persisted or scheduled.