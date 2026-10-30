# Security Policy

## Supported versions

Security fixes are released for the latest major version only.

| Version | Supported |
|---|---|
| latest `1.x` | Yes |
| older releases | No. Please upgrade to the latest release |

## Reporting a vulnerability

**Do not open public GitHub issues, discussions or pull requests for security vulnerabilities.**

Report vulnerabilities privately via **GitHub Security Advisories**:

1. Go to <https://github.com/lexo-ch/laravel-health-endpoints/security/advisories/new> (repository → **Security** tab → **Report a vulnerability**).
2. Describe the issue, affected versions, steps to reproduce or a proof of concept, and the impact you expect.

We will acknowledge your report, keep you informed while we investigate, and coordinate a fix and disclosure date with you. Please give us reasonable time to release a fix before any public disclosure. We are happy to credit you in the advisory unless you prefer to stay anonymous.

## Scope

In scope: this package's code, its default configuration and its documented behaviour. Examples: token or IP allowlist bypass, information disclosure in health responses or logs, injection through check names, targets or markers, denial of service through the health endpoints.

Out of scope: vulnerabilities in Laravel, PHP or third-party packages (report those upstream), and issues that require an already compromised server or a deliberately insecure configuration (for example `LHE_DETAILS=always` on public endpoints).

## Hardening

See the "Security" section of the [README](README.md#10-security) for the recommended production configuration and the hardening checklist.
