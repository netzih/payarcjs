# Sandbox scripts

Manual end-to-end checks against a CiviCRM site that has this extension
installed with a PayArc **sandbox** processor. They create real test records
and real sandbox transactions. Run them from the CiviCRM root with `cv`:

```sh
cv scr /path/to/payarcjs/tests/sandbox/onetime.php
cv scr /path/to/payarcjs/tests/sandbox/recurring.php
```

Both read the Pay.js public key from `~/.config/payarcjs/sandbox.env`
(`PAYARC_PAYJS_PUBLIC_KEY=...`) to mint single-use payment keys the way the
Pay.js iframe does, and use the test-mode processor named **PayArc**.
`recurring.php` expects outbound mail to be routed to the database
(Administer > System Settings > Outbound Email > Redirect to Database) so the
failed-payment notice can be shown.
