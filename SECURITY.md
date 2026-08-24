# Security Policy

## Supported version

Security fixes are applied to the current `main` branch. Older commits and local forks are not supported release lines.

## Reporting a vulnerability

Do not disclose vulnerabilities, credentials, personal information, screenshots, database exports, or private evidence in a public GitHub Issue.

Use GitHub's private vulnerability reporting option on the repository Security page when it is available. If private reporting is unavailable, contact the repository owner through a private channel listed on their GitHub profile and provide only the minimum information needed to establish a secure reporting channel.

Include:

- the affected page or component;
- reproduction steps using fictional test data;
- the expected impact;
- suggested remediation, if known.

Allow maintainers reasonable time to investigate and deploy a fix before public disclosure. Never access, download, alter, or retain real user records while testing a report.

## Secrets and privacy

Assume any credential committed to Git is compromised. Revoke or rotate it, remove it from the current tree and history, and review associated access logs. Operational databases, uploaded IDs, incident evidence, return evidence, mail previews, logs, and `.env` files must never be attached to Issues or committed to the repository.
