# PayArc Hosted Fields for CiviCRM

A card payment processor for CiviCRM 6.18 using PayArc Hosted Fields and the
PayArc API v1. Card number, expiry, CVV and ZIP are typed into PayArc's
iframes (one per field) and never reach this server. The browser gets a
single-use token, and the server charges it.

This is a fork of `usaepayjs`, the USAePay Pay.js extension, with every name,
setting, template, job and message template renamed, so both can be enabled
on one site. It is built on [`chabadrichmond/payarc-php`](lib/payarc-php), the
framework-free PayArc client that the PayArc WordPress plugin also uses. The
library is kept in `lib/payarc-php` as a git subtree. Update it with:

```
git subtree pull --prefix lib/payarc-php ~/dev/payarc-php main --squash
```

On a WordPress site that also runs the PayArc Payments plugin, both copies of
the library register a loader for `Payarc\`, and whichever loads first
serves both. Keep the two subtrees on the same library commit.

Status: alpha. Every flow below passes against the PayArc sandbox (see
[Tested](#tested)). Nothing has been charged in live mode yet.

## Card form

The four PayArc fields (card number, MM/YY, CVV, ZIP) are drawn as one box
with thin dividers, like a single card input, and stack into two rows on a
phone. The whole box gets a focus ring while any field has focus, and turns
red with a message under it when a field is invalid:

```text
Card details
┌───────────────────────────────────┬───────┬─────┬───────┐
│ Card number                       │ MM/YY │ CVV │ ZIP   │  one PayArc iframe per field
└───────────────────────────────────┴───────┴─────┴───────┘
Card details are entered securely into fields hosted by PayArc.
```

The box border lives in `css/payarcjs.css`; the borderless inputs inside
the iframes are styled by `DEFAULT_CSS` in `js/payarc-hostedfields.js`.
Browsers do not match `:focus-within` while focus is inside a cross-origin
iframe, so the helper sets `payarc-focused` on the box instead.

## Scope

- One-time card payments, on contribution pages, event registration and the
  back-office "Submit Credit Card Contribution" form.
- CiviCRM-managed recurring gifts, charged by cron against saved cards.
- Change Billing Details (card replacement).
- Full and partial refunds from CiviCRM, within PayArc's limits (see
  [Refunds](#refunds)).
- Apple Pay and Google Pay for one-time gifts.
- System Status checks, and the failed-payment email to donors.
- USD only: PayArc accepts nothing else.

Not included: the USAePay extension's transaction importer (deferred), ACH,
and disputes/chargebacks, which are visible only in the PayArc dashboard.

## Requirements

- CiviCRM 6.18 or later with CiviContribute.
- The [Payment Shared (mjwshared)](https://lab.civicrm.org/extensions/mjwshared)
  extension, which supplies the `CRM.payment` browser library and the refund
  form.
- PHP 8.1+ with cURL. No Composer at runtime: the library ships in
  `lib/payarc-php`.

## Setup

1. Put this directory in a CiviCRM extension directory (on WordPress,
   commonly `wp-content/uploads/civicrm/ext/payarcjs`) and enable
   **PayArc Hosted Fields** under Administer > System Settings > Extensions.
2. Add a processor of type **PayArc Hosted Fields** under Administer >
   CiviContribute > Payment Processors. From the PayArc dashboard (**API**,
   then the eye icon), enter for each mode:
   - **Client ID**: public, sent to the browser for the card fields.
   - **API bearer token**: secret, server side only.

   The bearer token is a JWT of about a thousand characters. CiviCRM's
   `user_name` column holds only 255, so the token goes in the processor's
   `signature` field (the larger box, labelled "API bearer token") and the
   Client ID in `user_name`.
3. Leave the default URLs: `https://api.payarc.net/v1` for live,
   `https://testapi.payarc.net/v1` for test. The card fields follow the
   host (`portal.payarc.net` or `testportal.payarc.net`).
4. Check the credentials without charging anything:

   ```sh
   cv scr bin/check-credentials.php <processor-id>
   # or from an env file with PAYARC_BEARER_TOKEN, PAYARC_CLIENT_ID, PAYARC_API_URL
   php bin/check-credentials.php ~/.config/payarc/sandbox.env
   ```

   It lists one charge with the bearer token and opens an unused card-field
   session with the Client ID. The portal answers an unknown Client ID with
   HTTP 403, which is reported as such.
5. Optionally tick **Apple Pay** / **Google Pay** under Administer > System
   Settings > **PayArc Hosted Fields Settings**.

Each field on the processor form has a "?" with the same guidance.

A sandbox account can be created at
`https://testportal.payarc.net/accounts/create/test`. Test cards (TSYS
certification): Visa 4012 0000 9876 5439 (CVV 999), Mastercard 5146 3150 0000
0055 (CVV 998), Discover 6011 0009 9302 6909 (CVV 996); expiry 12/29, ZIP
85284. The sandbox now and then declines at random with D2026 ("Do not
honor"), and refuses the same card and amount twice within minutes as a
duplicate; use a different amount or card.

## How PayArc differs from USAePay, and what the extension does about it

- **Saved cards.** PayArc keeps cards under customer records. A recurring
  signup **saves the card first** (one PayArc customer per card), then
  charges the saved card, including the first installment: a token can be
  used only once. The saved-card reference `customer_id:card_id` is stored in
  a CiviCRM PaymentToken, with brand, last four and expiry, and linked to the
  series. Saved cards charge without a CVV. Installments are sent as
  merchant-initiated recurring charges (`eci_indicator` 2). If the first
  charge is declined, the saved card is deleted again.
- **No PayArc receipts.** Every charge and refund asks PayArc not to email or
  text the payer (`do_not_send_email_to_customer`,
  `do_not_send_sms_to_customer`), and no top-level email is sent. CiviCRM
  sends the receipts. The donor's name and email go into the charge's
  metadata, with the contact ID, so staff can find them in the dashboard.
- **Declines** come either as an HTTP error or as a 2xx charge with
  `failure_code` set; both are read by `Charge::outcome()`. A partial
  approval is voided at once and treated as a decline. "Duplicate request
  (approved previously)" codes and unknown statuses are treated as
  ambiguous, never as declines.
- **A wrong CVV is refused when the card is tokenized**, before anything
  reaches CiviCRM. The donor sees "Please check the security code" under the
  card fields and can correct it and submit again.
- **Refunds of unsettled charges.** PayArc does not refuse them as its docs
  say: it voids the **whole** charge, whatever amount was asked. See
  [Refunds](#refunds).

## Charge at most once

The invoice ID is sent as PayArc's `Idempotency-Key` (and as metadata
`reference`). PayArc answers a repeated key with the original charge, even
when the body differs (verified in the sandbox).

- **One-off gifts.** CiviCRM makes a new random invoice ID for every
  submission of a contribution or event page, so a retry after a decline gets
  a new key (PayArc would otherwise replay the old decline), while a
  double-submitted confirmation page gets the original charge back. When
  PayArc's answer is lost, the identical request is sent once more at once.
  If that answer is lost too, the donor is told not to try again and staff
  see the details in the log.
- **Installments** get a deterministic invoice ID,
  `payarcjs-{site}-{recur id}-{scheduled date}-{attempt}`. `{site}` is a
  six-character tag derived from the site key, so two CiviCRM sites on one
  PayArc account never share a key. A pending contribution with that invoice
  ID is written before PayArc is contacted.

### Lost answers in the recurring job

If a run sent an installment and never recorded the answer, the pending
contribution is still there. The next run settles it:

- **Within an hour** of sending, the identical request is sent again. PayArc
  returns the original charge, or makes it now if the first request never
  arrived. A note on the contribution records this.
- **After an hour** (PayArc does not document how long it keeps keys), the
  charge is looked up by its reference in the charge list. The lookup is
  conclusive back to the send time, because the list's `created_at` is a
  Unix time. Found approved: recorded as the payment. Found declined, or
  provably absent: handled like a decline, so the normal retry applies with a
  new attempt number and key. Found voided or refunded at PayArc: stopped for
  staff.
- **PayArc unreachable**: checked again about every hour; after 7 days the
  series is stopped for staff.

### Recurring schedule

As in the USAePay extension:
- Only series whose first payment completed (**In Progress**) are charged.
- A conclusive decline is retried after 3 days, as its own contribution, and
  the series stops after 3 consecutive failures. The donor is emailed after
  each decline with the workflow message **PayArc - Recurring Payment
  Failed**, which links to the update-billing form.
- Installment dates are anchored to the scheduled date and cycle day, and
  missed installments are not back-charged.
- Staff can edit amount, installments, frequency and next date in CiviCRM;
  nothing is sent to PayArc.

The scheduled job **PayArc recurring card payments** is created active. The
payment cron runs the live processor by default; test-mode series need
`mode=test`:

```sh
cv api3 Job.run_payment_cron processor_name=PayArcHostedFields mode=test
```

### Change Billing Details

The new card is saved at PayArc, then verified with a $1.00 authorization
that is voided at once (the donor may briefly see a pending hold). A decline
deletes the saved card again and shows the donor wording. On success the card
is stored as a new PaymentToken, linked to the series, and a series stopped
after repeated declines is reactivated.

## Refunds

Staff refund from the contribution or payment record with the mjwshared
refund form. PayArc refunds have no id of their own, so the refund is
recorded with the sale's charge id.

- **Full refund of an unsettled charge** (the same day, normally): sent, and
  PayArc voids the charge. Staff see "PayArc voided charge ... in full".
- **Partial refund of an unsettled charge**: refused before anything is sent,
  because PayArc would void the whole charge: "This payment has not settled
  at PayArc yet. Refunding part of it now would cancel the whole payment, so
  nothing was sent. ..."
- **Settled charges** can be refunded in full or in part. **Caveat:** the
  status PayArc shows for a settled (batched) charge is not yet known, so the
  library treats every charge as unsettled, and partial refunds are refused
  for now. That is the safe side. See `lib/payarc-php/docs/PLAN.md`.
- A second refund of a voided charge is refused ("already voided or refunded
  in full").
- If PayArc's answer to a refund is lost, the sale is read again: less left
  to refund than before means the refund went through and is recorded.
  Otherwise staff are told to check the charge in the dashboard before
  trying again.

## Apple Pay and Google Pay

The wallet sheet opens in a small PayArc window
(`checkout-lite.payarc.net`), so this site needs no Apple merchant setup or
domain file. Details:
- The window shows a wallet button only if the HTML sent to it contains
  PayArc's placeholder element; the helper script sends it.
- Wallets are offered on one-time gifts on public contribution and event
  pages only. Their tokens cannot be saved, so the buttons hide when "every
  month" is ticked, and the server refuses a wallet token for a recurring
  gift.
- Where the browser can do Apple Pay (Safari), only Apple Pay is offered.
- PayArc's window returns no billing contact, so the donor fills CiviCRM's
  billing fields as usual.
- The Apple Pay sheet names the payee "Payarc"; this cannot be changed.

Apple Pay was confirmed working through the same window with the PayArc
WordPress plugin on jewish-richmond.com. In this extension, the Google Pay
button (Chrome) is tested locally; the wallet sheets themselves need a real
HTTPS site.

## Declines and errors

Donors see plain wording ("Your card was declined by your bank. Please
contact your bank or try a different card.") chosen from PayArc's response
code, with specific wording for insufficient funds, expired cards, CVV and
address mismatches, invalid numbers, a used or expired card token, outages
and misconfiguration. Logged-in staff also see "Gateway response: ..." with
PayArc's text and code, which is what the log and the notes on failed
installments carry.

## Card-testing protection

Bots test stolen cards by running many small gifts through a contribution
page, most of them declined. Administer > System Settings > PayArc sets
limits on this:

- **Declines per IP address** (default 5 in 60 minutes). After that, the
  address is refused until an hour has passed since its first decline.
- **Declines site-wide** (default 20 in 60 minutes). After that, online card
  payments pause (default 60 minutes) and the **alert email** (default: the
  organization's "From" address) gets one message. System Status shows the
  pause, and its **Resume card payments** action (API3 `Payarcjs.resume`,
  which needs administer CiviCRM) lifts it early.
- **Minimum card payment** (default off).

What counts and who is affected:
- Declines, and PayArc's refusals of a token or card, count, on
  contribution and event pages and on card updates. Unclear answers do not
  count.
- A malformed token is refused before anything is sent to PayArc and is not
  counted.
- Donors see only "try again later" wording.
- The recurring job, which charges saved cards, is never counted or
  blocked.
- Staff with "edit contributions" are never counted or blocked, so
  back-office payments work during a pause.
- The state lives in the `long` cache.

The IP address comes from `CRM_Utils_System::ipAddress()`. Behind a proxy
that does not restore the visitor's address, set the per-IP limit to 0 and
rely on the site-wide one. The limits complement reCAPTCHA on contribution
pages; they do not replace it.

## System Status

Administer > Administration Console > System Status reports, for each
active PayArc processor:
- a missing bearer token or Client ID;
- an API URL that does not match the processor's live/test mode
  (`testapi.` vs `api.`);
- a bearer token or Client ID that PayArc refuses (checked without charging,
  and cached for six hours; an unreachable PayArc reports nothing);
- In Progress series with no stored card, which the job can never charge;
- a disabled recurring job;
- card payments paused by the card-testing limits, with a resume action.

## Security and operations

- Only the Client ID reaches the browser. The bearer token can charge and
  refund; regenerate it in the dashboard if it is ever exposed.
- CiviCRM stores only PayArc references (`customer_id:card_id`) and masked
  card details, never a card number or CVV.
- If the site has a Content Security Policy, allow `https://portal.payarc.net`
  (live) or `https://testportal.payarc.net` (test) for scripts and frames,
  the API host for the card fields' requests, and
  `https://checkout-lite.payarc.net` for the wallet window.
- Only one PayArc card form works per page: PayArc's script keeps its state
  in globals.

## Tested

Sandbox, local CiviCRM Standalone, 2026-09-24.

`tests/sandbox/onetime.php`:
- one-time charge; the same invoice ID sent again returned the same charge;
- partial refund of an unsettled charge refused, nothing sent;
- full refund voided and recorded as Refunded; a second refund refused;
- wrong CVV refused at tokenization; a used token worded as "enter your card
  details again".

`tests/sandbox/recurring.php`:
- signup (card saved, saved card charged, token with brand, last four and
  expiry stored);
- cron installment; nothing due charges nothing;
- lost answer replayed within the hour: the original charge recorded, no
  second charge;
- lost answer after the hour: found by reference and recorded;
- lost answer after the hour, never sent: recorded as failed, retry
  scheduled;
- decline on a bad saved card: failure note, retry date, donor email;
- card replacement (saved, $1 verification voided), then a cron charge on the
  new card.

Browser (`~/.config/payarc/browser/civi-test.mjs`, Playwright):
- one-time approve;
- wrong CVV refused with the CVV wording, then corrected and paid in the same
  session;
- monthly signup, then a due installment by cron;
- Change Billing Details;
- back-office "Submit Credit Card Contribution" popup;
- mjwshared refund form: partial refused, full voided;
- Google Pay button shown for one-time gifts, hidden for monthly;
- page scrolling, and the card box's focus ring, checked separately.

Browser (`~/.config/payarc/browser/velocity-civi.mjs`, an anonymous donor on
the live page, with low limits):
- per-IP refusal after two refused tokens;
- site-wide pause, with the alert email and the System Status message;
- a gift refused during the pause;
- `Payarcjs.resume` (refused to anonymous callers), then a gift approved.

Not yet exercised:
- Apple Pay and Google Pay sheets in this extension (they need a real HTTPS
  site);
- partial refunds of a settled charge;
- event registration pages;
- a live-mode smoke test (a small charge, then a refund).

## Sandbox acceptance checklist

- Successful one-time card charge
- Declined card displays a useful message and creates no completed payment
- Double-clicking the submit button produces only one charge
- Monthly recurring signup saves a CiviCRM payment token
- Manually due recurring installment charges that token once and advances the
  next scheduled date
- Failed recurring installment is recorded as failed and scheduled for retry
- Full refund appears in both CiviCRM and PayArc (voided); a partial refund
  of an unsettled charge is refused
- Confirmation and receipt emails contain the expected amount and
  contribution, and PayArc sends the donor nothing
- Mobile checkout and AJAX-rendered forms load the hosted fields
- Live-mode smoke test with a small amount, followed by a refund

## Development

```sh
composer install
composer test            # Schedule: invoice IDs, site tag, replay window, dates
```

The library has its own tests (`cd ~/dev/payarc-php && vendor/bin/phpunit`);
change it there, then pull it into the subtree.

End-to-end checks against a CiviCRM site with a sandbox processor are in
`tests/sandbox/` and run with `cv scr`; see the README there.

`js/payarc-hostedfields.js` is a copy of the WordPress plugin's
`assets/js/payarc-hostedfields.js`, with one guard added so a reloaded AJAX
billing block does not run it again. Keep the two copies in step.

## License

AGPL-3.0-or-later.
