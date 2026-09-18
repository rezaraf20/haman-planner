# Architecture

**Author:** Reza Rafiei

## Target architecture

Telegram / Web UI
→ Laravel API
→ Planner Application Service
→ Repository Layer
→ PostgreSQL (target) / Notion adapter (MVP)
→ Analytics & Review Engine
→ AI Provider Layer

Voice:
Telegram Voice → Speech-to-Text → Intent Parser → Entity Resolver → Validation → Confirmation → Planner Engine → Repository

## Architectural rules
- Domain logic must not depend directly on Telegram or a specific AI vendor.
- External providers are accessed through interfaces/adapters.
- Planner mutations are validated before persistence.
- AI output is treated as untrusted input until validated.
- Secrets never enter source control.
- Every automated mutation should be traceable through an activity log.

## Interfaces
PlannerRepository:
- GoalRepository
- ProjectRepository
- MilestoneRepository
- TaskRepository
- ScheduleRepository
- ExecutionRepository
- ReviewRepository
- ReminderRepository

AIProviderInterface:
- Gemini
- Groq
- OpenRouter
- OpenAI
- future providers

SpeechProviderInterface:
- local Whisper
- cloud speech provider

## Deployment strategy
Phase 1: existing Laravel infrastructure + Telegram + low-cost/free model APIs.
Phase 2: PostgreSQL + queue workers + caching.
Phase 3: dedicated planner application and richer web UI.

## Security
Authentication, authorization, input validation, rate limiting, secret management, audit logging, provider isolation and backup/restore are mandatory production concerns.