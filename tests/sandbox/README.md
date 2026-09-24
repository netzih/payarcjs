# Sandbox scripts

Manual end-to-end checks against a CiviCRM site that has this extension
installed with a PayArc **sandbox** processor. They create real test records
and real sandbox charges. Run them from the CiviCRM root with `cv`:

```sh
cv scr /path/to/payarcjs/tests/sandbox/onetime.php
cv scr /path/to/payarcjs/tests/sandbox/recurring.php
```

Both use the active test-mode processor of type **PayArc Hosted Fields** and
refuse to run unless its API URL is the sandbox (`testapi.payarc.net`). They
mint single-use tokens server-side with that processor's bearer token, for
the TSYS test cards, as the Hosted Fields do in the browser.

`recurring.php` expects outbound mail to be routed to the database
(Administer > System Settings > Outbound Email > Redirect to Database) so the
failed-payment notice can be shown. It simulates crashed runs by leaving
pending attempts behind, to exercise the replay and lookup paths.

The sandbox declines some charges at random (D2026 "Do not honor") and
refuses the same card and amount twice within minutes as a duplicate; the
scripts vary the amounts, and a rerun usually passes.
