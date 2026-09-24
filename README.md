# PayArc Pay.js for CiviCRM

A modern, card-only PayArc payment processor for CiviCRM 6.18 on WordPress.

The extension embeds PayArc's hosted Pay.js card entry inside the normal
CiviCRM contribution form. Card number, expiration, and CVV are entered in the
PayArc iframe and never pass through WordPress or CiviCRM. The browser receives
a single-use `payment_key`; the server then submits the charge through PayArc's
REST API v2.

## Current scope

- One-time credit and debit card payments
- CiviCRM-managed recurring card payments
- PayArc card-reference vaulting for recurring gifts
- Charges against stored CiviCRM payment tokens
- Full and partial refunds from CiviCRM
- Live and PayArc sandbox modes
- WordPress/CiviCRM AJAX contribution forms

Not included in this first release: ACH, Google Pay, or the redirect flow
needed for a PayArc `V` (3-D Secure/cardholder verification) response.
This release uses Pay.js v2, which adds Apple Pay on public contribution
pages; see [Apple Pay](#apple-pay) below.
Treat this as alpha software until the sandbox acceptance checklist has passed
on the actual site.

## Card form

Visitors remain on the CiviCRM contribution page. Billing name and address use
CiviCRM's normal fields. Beneath them, the extension inserts PayArc's compact
hosted card box:

```text
Card details
┌──────────────────────────────────────────────────────┐
│ Card number              MM / YY          CVV        │  PayArc iframe
└──────────────────────────────────────────────────────┘
Card details are entered securely into a form hosted by PayArc.

                                      [ Contribute Now ]
```

The exact controls inside the box are rendered by Pay.js. Site CSS styles the
outer border and error message; sensitive fields remain isolated in the iframe.

## Requirements

- CiviCRM 6.18 or later with CiviContribute.
- The [Payment Shared (mjwshared)](https://lab.civicrm.org/extensions/mjwshared)
  extension, which supplies the shared `CRM.payment` browser library and the
  refund user interface.
- PHP cURL.

## Install on WordPress

1. Put this directory in a CiviCRM extension directory, commonly:
   `wp-content/uploads/civicrm/ext/payarcjs`.
2. In CiviCRM, open **Administer > System Settings > Extensions** and enable
   **PayArc Pay.js**.
3. Open **Administer > CiviContribute > Payment Processors**, add
   **PayArc Pay.js**, and first configure the **Test account** section.
4. Enter the three credentials from the same PayArc sandbox API key:
   - **API key**: private REST application/source key
   - **API PIN**: PIN belonging to that API key
   - **Pay.js public key**: public key generated beneath that API key
5. Leave the supplied sandbox and production REST URLs unchanged.
6. Add the processor to a test contribution page and make a sandbox gift.

### PayArc API key settings

Check these on the API key (source key) in the PayArc merchant console. They
apply to both the sandbox and the live key.

- **Allowed commands**: **Sale** is needed for donations and recurring
  signups. **Auth Only** and **Void** are needed for the card replacement
  action. Enable **Credit** (PayArc's name for refunds) if staff will issue
  refunds through CiviCRM; a new key rejects refunds with "Transaction type
  not allowed from this source" until this is turned on.
- **Customer receipts**: PayArc emails its "Transaction API and Payment Form
  (Customer)" receipt whenever a charge carries a customer email address, and
  no request flag turns that off. To avoid a second receipt on every gift, the
  extension does not send the donor's email address to PayArc as the
  transaction email; it appears only in the billing address on the gateway
  record. CiviCRM sends the receipts.
- **Pay.js public key**: generate it beneath the same API key and copy it into
  the processor settings. The public key alone cannot charge cards. The
  extension loads Pay.js **v2** (`/js/v2/pay.js`), which provides both the
  card box and Apple Pay.
- **Finding a donor's transactions in the console**: every charge and card
  verification carries the CiviCRM contact ID in PayArc's **Customer ID**
  (`custid`) field and the contribution ID in **Invoice**, so the console's
  transaction search can be filtered by donor. The extension does not create
  PayArc customer records; CiviCRM is the record of donors, saved cards and
  recurring schedules. The saved card tokens themselves live in PayArc's
  vault and are listed with each transaction.

### Field help on the processor form

Each field on the payment processor settings form has a "?" icon with the
same guidance as this section: where to find the key, PIN and Pay.js public
key in the PayArc console, which commands to allow, and which URL belongs to
which mode.

### Checking credentials without charging

`bin/check-credentials.php` proves that an API key, PIN and Pay.js public key
work before any money moves. It lists the most recent transaction on the key
and mints a throwaway Pay.js payment key; neither creates a charge.

```sh
# From a processor record in an installed site (id 1 here):
cv scr bin/check-credentials.php 1

# Or from a file holding PAYARC_API_KEY, PAYARC_API_PIN,
# PAYARC_PAYJS_PUBLIC_KEY and optionally PAYARC_API_URL (defaults to live):
php bin/check-credentials.php ~/.config/payarcjs/live.env
```

A wrong key or PIN reports "Specified source key not found." or "API
authentication failed". The check cannot tell which commands the key allows;
the first real Sale, Void or Credit does that.

## Apple Pay

Contribution pages can show an Apple Pay button above the card box for
one-time gifts. The button appears only in Safari on a device with a card in
Apple Wallet, on a site served over HTTPS whose domain is registered for
Apple Pay in PayArc; everywhere else donors just see the card box.

Setup:

1. In the PayArc console, complete **Settings > Apple Pay** (Apple developer
   account, merchant ID, the two certificates, and register the CiviCRM
   site's domain). Host the domain verification file Apple gives you at
   `https://your-site/.well-known/apple-developer-merchantid-domain-association`
   (a plain file, no extension, served as-is). Apple checks it during setup
   and again periodically.
2. In CiviCRM, tick **Offer Apple Pay on contribution pages** under
   Administer > System Settings > **PayArc Pay.js Settings**, and optionally
   set the merchant name shown on the Apple Pay sheet.

How it works: Pay.js (v2, which also renders the card box) validates the
merchant, shows the Apple Pay sheet for the form's current total, and returns
a single-use payment key that is charged exactly like a card key. The
donor's billing name, address and email from the sheet are copied into
empty CiviCRM billing fields, so a donor can finish a gift without typing
anything; if a required field is still missing the form points it out and
the donor presses the normal button to finish. The Apple Pay key cannot be
vaulted, so the button is refused for recurring gifts (with a message) and
is not offered on back-office forms or Change Billing Details.

Apple Pay cannot be exercised on a development machine: the sheet requires
Safari, HTTPS and a registered domain. The extension's browser harness
simulates the Pay.js Apple Pay entry to test everything around it.

## Declines and errors

Donors see plain-language wording rather than PayArc's merchant-facing text:
"Your card was declined. Please check the card details, try a different
card, or contact your bank." instead of "Card Declined (00)", with specific
wording for expired cards, security-code and address mismatches, invalid card
numbers, processor outages and misconfigured credentials. Logged-in staff
using back-office forms see the same wording followed by "Gateway response:
..." with PayArc's text and error code, which is also what is written to
the CiviCRM log and to the notes on failed recurring contributions.

CiviCRM itself prefixes the message with "Payment Processor Error message:"
on contribution pages. When **Administer > System Settings > Debugging** has
debugging enabled, CiviCRM also displays its internal log line
("...Confirm::PostProcess is_payment_failure...") to administrators; that
line never appears with debugging off.

## Refunds

Staff issue refunds from the contribution or payment record using the
mjwshared refund form. What happens at PayArc depends on whether the charge
has settled:

- **Settled charge** (normally the day after it was made): a refund for the
  full or a partial amount is submitted and appears in PayArc as a separate
  credit transaction.
- **Unsettled charge** (same day, still in the open batch): a full-amount
  refund voids the original charge instead, so it never posts to the donor's
  card. A partial refund of an unsettled charge is refused with an
  explanation, because PayArc would void the whole charge; wait until the
  next day and refund the partial amount then.

The **Credit** command must be enabled on the API key; **Void** must be
enabled for same-day reversals.

## Recurring payments

For an initial recurring gift, the REST sale includes `save_card: true`.
PayArc returns `savedcard.key`; the extension stores that card reference in a
CiviCRM `PaymentToken` and links it to the recurring contribution. Later charges
send the reference in the REST credit-card number field with expiration `0000`,
the REST equivalent of the documented `UMCardRef` reuse pattern.

Enabling the extension creates an active scheduled job named **PayArc recurring
card payments**. The normal CiviCRM cron must run for future installments to be
charged. The worker retries a conclusive decline after three days, recording
each attempt as its own failed contribution, and stops the series after three
consecutive failures.

Only series whose first payment completed (status **In Progress**) are charged.

### Invoice reference and duplicate protection

Each installment gets a deterministic invoice reference before PayArc is
contacted: `payarcjs-{recurring id}-{scheduled date}-{attempt}`, for example
`payarcjs-6-2026-09-10-0`. The attempt number is 0 for the first try and
counts up with each retry after a decline. The job looks the reference up
before charging, so a repeated cron run for the same due date finds the
existing record instead of charging the donor twice. Donors never see it;
PayArc receives it as the transaction's order ID and the contribution ID as
its invoice number.

### Lost responses are reconciled, not retried

If PayArc does not answer (timeout, dropped connection), the job does not
know whether the card was charged. It leaves the pending contribution in
place, keeps the series In Progress, and asks PayArc for a transaction with
that order ID, immediately and then about once an hour:

- Found and approved: the payment is recorded against the pending
  contribution and the schedule advances. A note on the contribution says it
  was reconciled.
- Found and declined: handled like any other decline (retry in three days,
  donor emailed).
- Not found after 24 hours: the card was not charged; the attempt is treated
  as a decline so the normal retry applies.
- PayArc unreachable for 7 days: the series is stopped with status Failed
  and a note for staff.

The order-ID lookup pages through the newest transactions on the key (up to
300), which covers an hour or two of activity on all but the busiest accounts.

### Design notes

- **One installment per scheduled date.** The invoice reference is built from
  the scheduled date, so setting a series' next scheduled date back to a day
  that already has a completed installment does not charge again; the job
  treats it as done and moves on. Record an extra gift as a separate
  contribution instead.
- **Retries really happen on the retry date.** When an installment fails,
  CiviCRM core moves the next scheduled date a whole period ahead; the job
  puts the installment's own date back so the three-day retry is due when
  the retry date passes rather than next month.

- **A failed daily or weekly gift skips the missed dates.** After a decline
  the retry happens three days later; when it succeeds, the schedule moves to
  the next future date and the days in between are not charged.
- **Pay.js keys are single-use and short-lived.** A donor who idles on a
  confirmation page for a long time is asked to re-enter the card.
- **Disputes and chargebacks are not imported.** Staff see them only in the
  PayArc console; record any adjustment in CiviCRM by hand.
- **The System Status page** warns about In Progress series with no stored
  card (which the job can never charge), credential or URL problems, and a
  disabled recurring job.

After each conclusive decline the donor is emailed using the workflow message
**PayArc Pay.js - Recurring Payment Failed** (Administer > Communications >
Message Templates > System Workflow Messages). The message names the reason
PayArc gave, says when the next attempt will be made or that the series has
been paused, and links to CiviCRM's update-billing form so the donor can enter
a new card. Edit the template there to change the wording; the email is not
sent when the contact has no usable email address, and inconclusive failures
that need staff review do not email the donor.

### Managing a recurring gift

- **Replace the card.** Staff (and donors, through the self-service link in
  receipts) can use CiviCRM's *Change Billing Details* action. The new card is
  entered in the hosted Pay.js box and vaulted through a $1.00 authorization
  that is voided immediately (PayArc's `cc:save` command does not accept
  Pay.js keys). The donor may briefly see a $1.00 pending hold that drops off
  without settling. The card reference is stored as a new CiviCRM payment
  token and linked to the series. A series that was stopped after repeated declines is reactivated at
  the same time.
- **Edit the schedule.** Staff can change the amount, number of installments,
  frequency and next scheduled date from CiviCRM's *Edit Recurring
  Contribution* form. CiviCRM owns the schedule, so nothing is sent to PayArc.
- **Missed installments are not back-charged.** When a paused or reactivated
  series resumes, the next charge is the next future scheduled date. Charge any
  missed gift manually if the donor wants to make it up.
- **Card brand and last four digits** are recorded on each payment so staff
  see, for example, "Visa: 2224" on the contribution.

To run only this processor manually from a CiviCRM-capable command line:

```sh
cv api3 Job.run_payment_cron processor_name=PayArcHostedFields
```

CiviCRM's payment cron runs the **live** processor by default. Recurring
contributions created against the test processor are only charged when the job
is run with `mode=test`:

```sh
cv api3 Job.run_payment_cron processor_name=PayArcHostedFields mode=test
```

## Importing transactions made outside CiviCRM

A store or form on the same PayArc merchant account (WooCommerce, Gravity
Forms, a virtual terminal) can have its sales recorded in CiviCRM without any
plugin on the other side. The extension reads PayArc's transaction list and
creates a contribution for each approved sale it does not already have.

**Setup** (Administer > System Settings > **PayArc Pay.js Settings**):

- **Source keys to import**: the names of the PayArc API keys whose sales
  should be imported, exactly as shown in the "Source" column of PayArc's
  transaction reports, comma-separated. `*` imports every sale on the account
  that CiviCRM does not already have. Nothing is imported until this is set.
- **Financial type**, **currency** and the **import window** (how many days
  back each scheduled run looks; already-imported sales are skipped, so a
  generous window is safe).
- **Account time zone**: PayArc reports times in the merchant account's zone
  without saying which. The import normally works the zone out by comparing a
  transaction CiviCRM made itself with PayArc's timestamp for it; the setting
  is the fallback when there is nothing to compare.

**What is imported.** Approved card sales from the named keys become
Completed contributions with the sale amount, the card brand and last four
digits, the PayArc transaction key as the transaction ID, the PayArc invoice
number as the CiviCRM invoice number, source "PayArc import: {key name}", and
a note carrying the order ID, customer ID and description. Declines, errors
and authorizations are ignored.

**Contacts.** PayArc does not return the payer's email address on a
transaction, even when the store sent one, so matching normally works from
the billing name: an exact first and last name match is used when it is
unique, namesakes are told apart by postal code and then by having been
created by an earlier import, and anything still ambiguous gets a new
contact rather than a guess. An email is used when it is available, through
CiviCRM's Individual Unsupervised dedupe rule and then an exact email match:
when the store created a PayArc customer record for the payer, or when the
store put the email in the transaction's **Customer ID** (`custid`) field,
which is the convention this project's WooCommerce and Gravity Forms plugins
follow. New contacts get the billing address and phone and the source
"PayArc import". A sale with no name at all is recorded against a contact
named "PayArc Customer".

**Refunds and voids.** A sale voided at PayArc is recorded as a full refund
on its contribution, whichever system created it. A refund issued at PayArc
is matched to an imported sale by invoice number (narrowed by card and
remaining amount) and recorded as a refund; a refund that matches nothing or
more than one sale is listed under `unmatched_refunds` in the job result for
staff to record by hand. Refunds issued from CiviCRM are already known and
skipped. Imported contributions carry this processor on their payment, so
staff can also refund them from CiviCRM.

**Running it.** The scheduled job **PayArc import transactions** runs hourly
over the import window and does nothing until source keys are configured.
For a first backfill, a specific range, or a preview, call the API:

```sh
# Preview a month without writing anything
cv api3 Payarcjs.importtransactions dry_run=1 from=2026-08-01 to=2026-08-31

# Import it
cv api3 Payarcjs.importtransactions from=2026-08-01 to=2026-08-31

# Use a different processor record's credentials (see below)
cv api3 Payarcjs.importtransactions processor_id=3 source_names='WooCommerce'
```

The result lists imported contributions, refunds and voids applied, contacts
created, unmatched refunds and errors. Job runs record the same summary in
the job log.

**Which transactions the key can see.** PayArc's transaction list is
account-wide: reading with CiviCRM's key returns sales made by other keys on
the same merchant account (verified on a live account with a virtual
terminal sale). Should a key ever be limited to its own history, add a
second PayArc Pay.js payment processor record holding the store's API key
and PIN (it can be left disabled) and select it under **Read transactions
with this processor's API key**; the API call also accepts `processor_id`.

**Source key names, not keys.** The setting takes the *name* of each key as
shown in PayArc's "Source" column and under Settings > API Keys, not the key
string itself. When nothing matches, the job result says which source names
PayArc reported in the window, so the right name can be copied from there.

**Limits.** PayArc's list endpoint has no date or source filter, so the
import pages through the newest transactions (100 per page, up to 50 pages a
run) until it passes the start of the window. Refund matching needs the
store to send an invoice number with each sale, which WooCommerce and
Gravity Forms do by default.

## Sandbox test cards

Any future expiration date and any CVV work unless noted. From PayArc's
[test card list](https://help.payarc.info/developer/reference/testcards/).

| Card | Result |
| --- | --- |
| 4000100011112224 | Visa, approved |
| 4000100111112223 | Visa, approved (different last four) |
| 5555444433332226 | MasterCard, approved |
| 371122223332225 | American Express, approved |
| 6011222233332224 | Discover, approved |
| 4000300011112220 | Declined |
| 4000300611112224 | Declined: insufficient funds (51) |
| 4000300211112228 | Declined: do not honor (05) |
| 4000300111112229 | Declined: pick up card (04) |
| 4000301311112225 | Declined for CVV failure (97) |

## Sandbox acceptance checklist

- Successful one-time card charge
- Declined card displays a useful message and creates no completed payment
- Double-clicking the submit button produces only one charge
- Monthly recurring signup saves a CiviCRM payment token
- Manually due recurring installment charges that token once and advances the
  next scheduled date
- Failed recurring installment is recorded as failed and scheduled for retry
- Full and partial refund appear in both CiviCRM and PayArc
- Confirmation and receipt emails contain the expected amount and contribution
- Mobile checkout and WordPress AJAX-rendered forms load the hosted fields
- Live-mode smoke test with a small amount, followed by a refund

## Security and operations

- Never put the API key or PIN in JavaScript. Only the Pay.js public key is sent
  to the browser.
- Serve the contribution page over HTTPS.
- If the site uses a Content Security Policy, allow the PayArc Pay.js script
  and iframe origins: `https://www.payarc.com` for live processors and
  `https://sandbox.payarc.com` for test processors.
- Ensure PHP cURL is installed and outbound HTTPS to PayArc is allowed.
- Review CiviCRM logs and failed recurring contributions after each cron run
  during the pilot.
- **Administer > Administration Console > System Status** reports PayArc
  processors with missing credentials, an API URL that does not match the
  processor's live/test mode, and a disabled recurring-payment job.
- Keep CiviCRM's database encrypted/backed up appropriately: it stores only
  PayArc references and masked card details, never PAN or CVV.

## Development

The runtime has no Composer dependency. Development checks use PHPUnit:

```sh
composer install
composer test
```

End-to-end checks against a CiviCRM site with a sandbox processor live in
`tests/sandbox/` and run with `cv scr`; see the README there.

Primary references:

- [PayArc Pay.js v1](https://help.payarc.com/developer/payjs-v1/)
- [PayArc tokenization](https://help.payarc.com/developer/reference/tokenization/)
- [PayArc sandbox](https://help.payarc.com/developer/reference/sandbox/)
- [CiviCRM payment processors](https://docs.civicrm.org/dev/en/latest/extensions/payment-processors/)

## License

AGPL-3.0-or-later.
