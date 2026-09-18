# Planner Domain

**Author:** Reza Rafiei

This directory contains framework-independent planning rules and contracts.

The first implementation keeps the domain small:
- weighted progress
- task status
- priority signals
- planning capacity
- dependency validation

Application services orchestrate these rules; controllers and Telegram adapters must not contain domain logic.