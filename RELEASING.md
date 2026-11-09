# Releasing the PHP SDK

Notes for the maintainer. Nothing here has been done yet: the package is not on Packagist and has
no repository of its own.

## One-time setup

1. **A repository of its own.** Packagist reads `composer.json` from the root of a public git
   repository, so this folder becomes the root of
   `github.com/pdfconversionapi/pdfconversionapi-php`. `sdks/scripts/export-sdk.sh php <folder>`
   copies the files git tracks in `sdks/php/` (including `.github/`, `.gitattributes` and
   `.gitignore`) and the shared conformance suite as `conformance/` at the repository root. CI
   and the release workflow run that copy and fail without it, and `.gitattributes` keeps it out
   of the archives Composer downloads. The script never copies `conformance/.local-api.env`.
2. **Packagist.** Sign in at https://packagist.org with the GitHub account that owns the
   repository, choose *Submit*, and enter the repository URL. The package name comes from
   `composer.json`: `pdfconversionapi/pdfconversionapi`. The first submission reserves the
   `pdfconversionapi` vendor name for that account.
3. **The webhook, so new tags reach Packagist on their own.** When you signed in with GitHub and
   gave Packagist access to the organization, Packagist adds the hook itself; the package page
   then has no "not auto-updated" warning. Otherwise add it by hand in the GitHub repository:
   *Settings → Webhooks → Add webhook*
   - Payload URL: `https://packagist.org/api/github?username=<your Packagist username>`
   - Content type: `application/json`
   - Secret: your Packagist API token (packagist.org → your profile → *Show API Token*)
   - Events: *Just the push event*

   GitHub's *Recent Deliveries* tab for the hook should show a 2xx answer after the next push.

No registry secret is needed: the release workflow only uses the repository's own
`GITHUB_TOKEN`.

## Each release

1. Set the new version in `src/Client.php` (`Client::VERSION`) and add its section to
   `CHANGELOG.md` (`## 0.2.0 - 2026-12-01`). Composer reads the version from the tag, so
   `composer.json` has no `version` field.
2. Run the checks locally: `composer validate --strict`, `composer test`, `composer analyse`,
   `composer cs`, and `composer test:live` against a local API. Packagist publishes the version as
   soon as the tag reaches GitHub, so these checks are what stands between a broken commit and a
   release.
3. Commit, then tag and push the tag:

   ```bash
   git tag v0.2.0
   git push origin v0.2.0
   ```

4. The *Release* workflow checks that the tag matches `Client::VERSION`, runs the unit and
   conformance tests, then the live tests against the production API when the
   `PDFCONVERSIONAPI_KEY` secret is set, and creates the GitHub release with the changelog section
   as its notes. The webhook tells
   Packagist about the tag; the version appears there within a minute or two.
5. Check it installs: `composer require pdfconversionapi/pdfconversionapi:^0.2` in an empty
   folder.
