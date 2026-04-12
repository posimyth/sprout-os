# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 1.1.x   | Yes                |
| < 1.1   | No                 |

## Reporting a Vulnerability

The POSIMYTH team takes security seriously. If you discover a security vulnerability in SproutOS, **please do not open a public GitHub issue.**

Instead, report it privately via our helpdesk:

**[store.posimyth.com/helpdesk/](https://store.posimyth.com/helpdesk/)**

Please include:

- A description of the vulnerability
- Steps to reproduce the issue
- The potential impact
- Your SproutOS version, WordPress version, and PHP version
- Any suggested fix (optional but appreciated)

## Response Timeline

- **Acknowledgement**: We aim to acknowledge your report within **48 hours**
- **Assessment**: We will assess the severity and impact within **5 business days**
- **Fix**: Critical vulnerabilities will be patched as quickly as possible, typically within **7-14 days** depending on complexity
- **Disclosure**: We will coordinate with you on public disclosure timing after a fix is released

## Security Design

SproutOS is built with multiple layers of protection:

- **Administrator-only access**: All MCP operations require `manage_options` capability
- **Sensitive file guards**: Critical files (`.env`, `wp-config.php`, `.htaccess`, VCS metadata, SQL dumps) are blocked from AI access regardless of permissions
- **Path boundary enforcement**: All file operations are confined to the WordPress installation root
- **Sandbox isolation**: PHP file execution is restricted to the sandbox directory with syntax validation, conflict detection, and crash recovery
- **Input sanitization**: All user and AI-supplied input is sanitized using WordPress sanitization functions
- **Prepared statements**: All database queries use `$wpdb->prepare()`
- **Nonce verification**: All AJAX handlers verify WordPress nonces
- **Safe Mode**: One-click read-only mode that disables all write and destructive operations

## Scope

The following are in scope for security reports:

- Authentication or authorization bypass
- Path traversal or directory escape
- Code injection or execution outside the sandbox
- SQL injection
- Cross-site scripting (XSS) in the admin UI
- Sensitive data exposure
- Denial of service via MCP tools
- Sandbox escape

The following are out of scope:

- Vulnerabilities that require Administrator-level access (SproutOS is designed for administrators)
- Issues in third-party dependencies (report these to the respective maintainers)
- Social engineering attacks
- Attacks requiring physical access to the server

Thank you for helping keep SproutOS and the WordPress ecosystem secure.
