# BookNest

Appointment booking software by **Softnic Solutions** —
[softnicsolutions.com](https://softnicsolutions.com).

**Repository:** [hamzaarshad-exe/booknest](https://github.com/hamzaarshad-exe/booknest)  
**Expected container image:** `ghcr.io/hamzaarshad-exe/booknest:latest`

The image is published only after the GitHub build and container checks pass.

PHP + MariaDB, packaged for Docker Compose. One business per installation.
MIT licensed. No Node.js server is required.

Customers choose a service, team member and available time, then receive a private
booking link. Owners manage services, team availability, time off, appointments,
rescheduling, cancellations, manual payments/refunds, and CSV exports. Calendar
downloads and owner password recovery are included.

## Start through your browser

1. Create a **public** GitHub repository and upload this package's extracted
   contents, including `.github`. Keep `Dockerfile` and `docker-compose.yml` at
   the repository root, rather than putting an extra folder above them.
2. Open **Actions**. The included workflow builds a Docker image, tests a fresh
   workspace, checks owner login and restart persistence, then publishes the
   passing image to GitHub Container Registry. No personal access token or SSH
   command is required by this workflow.
3. Open the successful job's summary and copy its `ghcr.io/...:latest` image
   address. Check that the package has **Public** visibility so Hostinger can
   download it without registry credentials.
4. The production Compose file already points to `ghcr.io/hamzaarshad-exe/booknest:latest`.
   Confirm that the successful workflow published this image before submitting
   the repository to Hostinger. No image-address editing is needed for this repository.
5. In Hostinger's **Open source** section, choose **List your app** and supply
   the public repository. Hostinger checks deployment readiness. Use the actual
   deploy button/partner code returned by that flow in this README.

Hostinger approval and earnings are not established by uploading these files.
Publishing a repository and preparing the image do not run the app on your
ordinary PHP hosting plan. A Docker VPS runs the Docker edition.

The browser-only walkthrough is in [GITHUB_HOSTINGER_STEPS.txt](docs/GITHUB_HOSTINGER_STEPS.txt).

## Deployment configuration

`docker-compose.yml` runs PHP/Apache, MariaDB and a Caddy HTTPS proxy using
published images. It works with a remote Compose URL; it does not require a
Dockerfile to be present beside a downloaded Compose file on the VPS.

Provide these values privately in the Docker deployment environment:

| Variable | Purpose |
|---|---|
| `BOOKNEST_DOMAIN` | Booking hostname, without a scheme or path |
| `BOOKNEST_BUSINESS_NAME` | Customer-facing business name |
| `BOOKNEST_OWNER_NAME` | Initial owner name |
| `BOOKNEST_OWNER_EMAIL` | Initial owner login email |
| `BOOKNEST_OWNER_PASSWORD` | Initial owner password, 12–72 characters |
| `BOOKNEST_DB_PASSWORD` | Unique application database password |
| `BOOKNEST_DB_ROOT_PASSWORD` | Separate database root password |
| `BOOKNEST_TIMEZONE` | Defaults to `Asia/Karachi` |
| `BOOKNEST_CURRENCY` | Defaults to `PKR` |
| `BOOKNEST_RECOVERY_KEY` | Normally empty; one-use recovery when deliberately set |

The booking hostname must point to the VPS. Ports 80 and 443 must be available
for the included proxy. If the VPS already uses Hostinger Traefik or another
proxy on those ports, adapt the routing before deploying; do not launch a second
proxy on the same ports. Domain and live TLS issuance depend on the actual VPS
and DNS setup. There is no automatic change to an existing hosting account.

`database`, `state`, `certificates` and `proxy_config` are persistent volumes.
Back up all of them before upgrades. Deleting volumes deletes data. Owner setup
values are only used for a fresh database. Changing them later does not silently
replace accounts or business settings. The initial service is hidden until you
edit and enable it in the dashboard.

After successful initialization, the production deployment's initial owner
password can be removed from its environment; the stored password hash remains.
Use the account page to change the password. Database passwords must continue to
match the database's stored credentials; changing an environment value alone
does not rotate an already initialized MariaDB account.

To recover an owner account without SSH, set a **new** `BOOKNEST_RECOVERY_KEY`
of at least 32 characters in Docker Manager, recreate the app container, then
open `/recover.php`. Enter the key, existing owner email and new password. Clear
the environment key afterward. A used key is not enabled again merely by
restarting the same configuration.

## Files

- `src/`: application source. Runtime configuration is stored outside the web root.
- `Dockerfile`: PHP 8.3 Apache image with PDO MySQL.
- `docker-compose.yml`: published-image deployment with HTTPS proxy.
- `docker-compose.local.yml`: isolated localhost development and CI setup.
- `.github/workflows/publish.yml`: build, container checks and image publication.
- `.env.example`: public example with blank secret fields; never publish a filled `.env`.
- `tests/`: domain, bootstrap and container checks.

## Validation status

See [VALIDATION.md](docs/VALIDATION.md). PHP source and database startup behavior
can be checked before publication. Full Docker build and container tests run in
GitHub Actions after upload; a green run is required before submitting the app.
No Hostinger VPS deployment or domain/TLS issuance has been verified here.

## Hostinger referral button

Copy the final snippet from your own Hostinger partner dashboard. The actual
repository and partner code must replace placeholders before publication. The
button attributes referred hosting purchases to your account; software downloads
alone are not a paid hosting referral.

Official documentation:

- [Hostinger Open Source Partners](https://www.hostinger.com/open-source-partners)
- [Hostinger deploy button](https://www.hostinger.com/support/deploy-on-hostinger-button/)
- [GitHub browser uploads](https://docs.github.com/en/repositories/working-with-files/managing-files/adding-a-file-to-a-repository)
- [GitHub Container Registry](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)

## License

MIT. Copyright © 2026 Softnic Solutions. Commercial use, modification and
distribution are permitted under the included [LICENSE](LICENSE).
