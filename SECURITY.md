# Security

uzpay handles payment callbacks and keys, so security reports get priority.

## Reporting a vulnerability

Please do not open a public issue. Report it privately through GitHub: the repository's **Security** tab → **Report a vulnerability**. Describe what is affected, how to reproduce it and what an attacker could do; a proof of concept helps.

You will get an answer within a few days. Once a fix is released, the report is credited in the CHANGELOG unless you ask otherwise.

## Supported versions

Fixes go to the latest release of the current minor version (0.1.x now).

## Using the package safely

See the "Security" section of the [README](README.md#english): card numbers stay in the browser, keys stay out of logs, a separate Payme `test_key`, callback URLs open only to the providers.
