# Validation record

- PHP 8.3.6 syntax checks pass for all application and Docker PHP files.
- POSIX shell syntax check passes for the container entrypoint.
- Production Compose, local Compose, and GitHub Actions files parse as YAML.
- 25 Docker-bootstrap logic checks pass against MariaDB 10.11.14.
- 34 booking-domain checks pass against the same database server version.
- The race test creates exactly one appointment from six simultaneous requests.

The bootstrap checks cover initial owner creation, password hashing, private
configuration permissions, hidden initial service, restart persistence, retained
owner credentials, external configuration paths, and one-use recovery keys.

No Docker engine is available in the local environment. These checks do not
establish that a container image has been built or run. The included GitHub
Actions workflow must build the image and pass its Docker/Apache HTTP smoke tests
before publication and submission. It validates the production Compose model,
checks owner login and administration pages, tests container restart, and checks
Apache protection for the private application directory.

The Compose CLI validation and running-container checks are pending that workflow.
A Hostinger VPS deployment, actual DNS/TLS issuance, program acceptance, partner
code and referral attribution have not been verified. These require the actual
GitHub repository, public image, hosting environment and Hostinger account.

Full local check labels: [bootstrap](LOCAL_BOOTSTRAP_CHECKS.txt) and
[booking domain](LOCAL_DOMAIN_CHECKS.txt).

## Repository configuration

The supplied repository is `https://github.com/hamzaarshad-exe/booknest`. Production Compose
is configured for `ghcr.io/hamzaarshad-exe/booknest:latest`. That address is an expected
publication target, not confirmation that an image is currently available.
The first GitHub Actions build and Docker/Apache container checks remain pending.
