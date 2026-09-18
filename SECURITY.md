# Security Policy

**Author:** Reza Rafiei

## Reporting
Do not publish credentials, tokens, private user data, or exploitable security details in public issues. Report security concerns privately to the repository owner.

## Rules
- Never commit API keys, bot tokens, passwords, private keys or production environment files.
- Use environment variables or a secret manager.
- Rotate any credential that may have been exposed.
- Validate all external input.
- Treat AI output as untrusted input.
- Apply least privilege to integrations.
- Keep audit logs for security-sensitive mutations.

## Data protection
Planner data may contain sensitive personal and business information. Production deployments must use access controls, encryption where appropriate, backups, retention policies and secure transport.