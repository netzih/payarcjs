<?php

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use CRM_Payarcjs_ExtensionUtil as E;
use Payarc\AmbiguousGatewayException;
use Payarc\Amount;
use Payarc\CardDetails;
use Payarc\Charge;
use Payarc\DonorMessage;
use Payarc\GatewayClient;
use Payarc\GatewayException;
use Payarc\UnsettledPartialRefundException;

/**
 * CiviCRM payment processor for PayArc Hosted Fields.
 *
 * Processor record fields: signature holds the API bearer token (secret,
 * server side only; it is a JWT of about a thousand characters, and
 * signature is the only credential column longer than 255), user_name the
 * Client ID (public, sent to the browser).
 */
class CRM_Core_Payment_Payarcjs extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * Marks the hidden payment_token as a PayArc token from this page (card
   * fields), as opposed to a stored PaymentToken reference.
   */
  private const TOKEN_PREFIX = 'payarc:';

  /**
   * Follows TOKEN_PREFIX for a token from the Apple Pay / Google Pay window.
   * Wallet tokens cannot be saved, so they are refused for recurring gifts.
   */
  private const WALLET_PREFIX = 'wallet:';

  /**
   * Sent as the User-Agent and metadata 'software', so the PayArc dashboard
   * shows which integration made each charge.
   */
  public const SOFTWARE = 'civicrm-payarcjs/0.1';

  public function __construct($mode, $paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  public function checkConfig() {
    $errors = [];
    if (trim((string) ($this->_paymentProcessor['signature'] ?? '')) === '') {
      $errors[] = E::ts('The PayArc API bearer token is required.');
    }
    if (trim((string) ($this->_paymentProcessor['user_name'] ?? '')) === '') {
      $errors[] = E::ts('The PayArc Client ID is required.');
    }
    return $errors ? implode('<br>', $errors) : NULL;
  }

  public function getPaymentTypeName() {
    return 'credit_card';
  }

  public function getPaymentTypeLabel() {
    return E::ts('Credit card');
  }

  /**
   * No QuickForm payment fields: the card is entered in PayArc's Hosted
   * Fields and the browser script posts the resulting token as a plain
   * 'payment_token' request parameter. Declaring it as a hidden QuickForm
   * field makes core's BillingBlock.tpl emit notices, because hidden elements
   * are not exposed to the template as $form.payment_token.
   */
  public function getPaymentFormFields(): array {
    return [];
  }

  public function getPaymentFormFieldsMetadata(): array {
    return [];
  }

  /**
   * With a confirmation page the token is posted on the main page and the
   * charge happens one request later, so core's pre-approval mechanism
   * carries it across: doPreApproval() stores it, getPreApprovalDetails()
   * merges it back into the payment params.
   */
  protected function supportsPreApproval() {
    return TRUE;
  }

  public function doPreApproval(&$params) {
    $token = $this->paymentTokenFromRequest($params);
    if ($token === '') {
      throw new PaymentProcessorException(E::ts('No PayArc payment token was supplied. Please re-enter the card.'));
    }
    return ['pre_approval_parameters' => ['payment_token' => $token]];
  }

  public function getPreApprovalDetails($storedDetails) {
    return is_array($storedDetails) ? $storedDetails : [];
  }

  /**
   * The PayArc token from the submitted params, the pre-approval parameters,
   * or the raw request (back-office and no-confirmation-page forms submit it
   * in the same request as the charge).
   */
  private function paymentTokenFromRequest(array $params): string {
    $token = trim((string) ($params['payment_token'] ?? $params['pre_approval_parameters']['payment_token'] ?? ''));
    if ($token === '') {
      $token = trim((string) CRM_Utils_Request::retrieve('payment_token', 'String', NULL, FALSE, '', 'POST'));
    }
    return $token;
  }

  public function buildForm(&$form) {
    $vars = [
      'id' => (int) $this->_paymentProcessor['id'],
      'clientId' => trim((string) $this->_paymentProcessor['user_name']),
      'scriptUrl' => $this->getHostedFieldsUrl(),
      'formId' => (string) $form->getAttribute('id'),
      'wallets' => [
        'applePay' => (bool) Civi::settings()->get('payarcjs_apple_pay_enabled'),
        'googlePay' => (bool) Civi::settings()->get('payarcjs_google_pay_enabled'),
      ],
      'i18n' => self::browserStrings(),
    ];

    Civi::resources()->addVars(E::SHORT_NAME, $vars);
    $form->assign('payarcjsVars', $vars);

    // PayArc's own script (iframeprocess.js) is not added here: it declares
    // top-level constants and must run once per page, but a region script
    // tag is inserted again each time an AJAX form reloads its billing
    // block. payarc-hostedfields.js loads it on demand, once.
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => E::url('js/payarc-hostedfields.js'),
      'weight' => 90,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'styleUrl' => E::url('css/payarcjs.css'),
      'weight' => 95,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'template' => E::path('templates/CRM/Payarcjs/Card.tpl'),
      'weight' => 96,
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => E::url('js/payarcjs.js'),
      'weight' => 100,
    ]);

    $form->assign('isJsValidate', TRUE);
    return FALSE;
  }

  /**
   * Wording used by the browser scripts (payarc-hostedfields.js reads these
   * keys from window.PayarcHostedFieldsConfig.i18n).
   */
  private static function browserStrings(): array {
    return [
      'cardNumber' => E::ts('Card number'),
      'expiry' => E::ts('MM/YY'),
      'cvv' => E::ts('CVV'),
      'zip' => E::ts('ZIP'),
      'unableToValidate' => E::ts('Unable to validate the card.'),
      'checkExpiry' => E::ts('Please check the expiration date (MM/YY).'),
      'checkCvv' => E::ts('Please check the security code (the 3 or 4 digit CVV).'),
      'checkZip' => E::ts('Please check the billing ZIP code.'),
      'checkCard' => E::ts('Please check the card number, expiration date and security code.'),
      'misconfigured' => E::ts('The payment form is not configured correctly, so no charge was made. Please contact us.'),
      'sessionExpired' => E::ts('The card form timed out. Please enter your card details again.'),
      'noResponse' => E::ts('The card processor did not respond. Please wait a moment and try again.'),
      'loadFailed' => E::ts('The secure PayArc card form could not be loaded.'),
      'noKey' => E::ts('PayArc did not return a card token.'),
      'reload' => E::ts('The card form was reset. Please enter your card details again.'),
      'enterCard' => E::ts('Please enter your card details.'),
      'busy' => E::ts('Please wait, your card is being checked.'),
      'walletTotal' => E::ts('Total'),
      'applePay' => E::ts('Pay with Apple Pay'),
      'googlePay' => E::ts('Pay with Google Pay'),
      'applePayHint' => E::ts('Tap the Apple Pay button to pay.'),
      'googlePayHint' => E::ts('Tap the Google Pay button to pay.'),
      'chooseAmount' => E::ts('Please choose an amount before paying with a wallet.'),
      'walletRecurring' => E::ts('Apple Pay and Google Pay cannot be used for a recurring gift. Please enter your card details below.'),
      'walletIncomplete' => E::ts('Your wallet approved the payment. Please complete the highlighted fields and press the button to finish.'),
      'walletFailed' => E::ts('The wallet payment could not be completed. Please try again or enter your card details below.'),
    ];
  }

  public function doPayment(&$params, $component = 'contribute') {
    $propertyBag = $this->beginDoPayment($params);
    if ((float) $propertyBag->getAmount() === 0.0) {
      return $this->setStatusPaymentCompleted([]);
    }

    $payment = $this->resolvePaymentToken($propertyBag, (array) $params);
    if ($payment === NULL) {
      throw new PaymentProcessorException(E::ts('No PayArc payment token was supplied. Please re-enter the card.'));
    }

    $isRecurring = $propertyBag->getIsRecur();
    $amount = number_format((float) $propertyBag->getAmount(), 2, '.', '');
    $this->assertCurrency($propertyBag);
    // Payer-initiated only: the recurring job charges saved cards.
    $velocity = $payment['type'] === 'saved' ? NULL : CRM_Payarcjs_Velocity::singleton();
    $refusal = $velocity ? $velocity->refusal($amount) : NULL;
    if ($refusal !== NULL) {
      throw new PaymentProcessorException($refusal, 'PAYARCJS_REFUSED');
    }
    $options = $this->chargeOptions($propertyBag);
    $client = $this->getGatewayClient();
    $saved = NULL;

    try {
      if ($payment['type'] === 'saved') {
        // A stored card, charged by the recurring job: a merchant-initiated
        // installment.
        $response = $this->send(fn() => $client->chargeCard($payment['value'], $amount, $options + ['recurring' => TRUE]), $options['reference']);
      }
      elseif ($isRecurring) {
        if ($payment['type'] === 'wallet') {
          throw new PaymentProcessorException(E::ts('Apple Pay and Google Pay cannot be used for a recurring gift. Please enter your card details instead.'), 'PAYMENT_DECLINED');
        }
        // A token is single-use: save the card first, then charge the saved
        // card, which also proves later installments can be charged.
        try {
          $saved = $client->saveCard($payment['value'], $this->customerFields($propertyBag));
        }
        catch (AmbiguousGatewayException $e) {
          // Saving a card charges nothing, so an unclear answer here is a
          // plain failure: the donor may safely try again.
          throw new GatewayException('The card could not be saved at PayArc: ' . $e->getMessage(), 0, ['code' => 'E0200'], $e);
        }
        try {
          $response = $this->send(fn() => $client->chargeCard($saved['reference'], $amount, $options), $options['reference']);
        }
        catch (GatewayException | InvalidArgumentException $e) {
          if (!$e instanceof AmbiguousGatewayException) {
            $this->deleteCardQuietly($client, $saved['reference']);
          }
          throw $e;
        }
      }
      else {
        $response = $this->send(fn() => $client->chargeToken($payment['value'], $amount, $options), $options['reference']);
      }
    }
    catch (AmbiguousGatewayException $e) {
      Civi::log(E::SHORT_NAME)->critical($e->getMessage(), [
        'contribution_id' => $propertyBag->getter('contributionID', TRUE),
        'invoice_id' => $propertyBag->getter('invoiceID', TRUE),
        'response' => $e->getResponseData(),
      ]);
      throw $this->ambiguousException($e->getMessage(), $e->getResponseData(), $e);
    }
    catch (GatewayException | InvalidArgumentException $e) {
      $velocity?->failed();
      throw $this->donorException($e);
    }

    try {
      $this->assertApproved($response, $client);
    }
    catch (PaymentProcessorException $e) {
      if ($e->getErrorCode() !== 'PAYMENT_AMBIGUOUS') {
        $velocity?->failed();
      }
      if ($e->getErrorCode() === 'PAYMENT_AMBIGUOUS') {
        Civi::log(E::SHORT_NAME)->critical($e->getErrorData()['payarcjs_gateway_message'] ?? $e->getMessage(), [
          'contribution_id' => $propertyBag->getter('contributionID', TRUE),
          'invoice_id' => $propertyBag->getter('invoiceID', TRUE),
          'response' => $response,
        ]);
      }
      elseif ($saved) {
        $this->deleteCardQuietly($client, $saved['reference']);
      }
      throw $e;
    }

    $chargeID = Charge::id($response);
    if ($chargeID === '') {
      $detail = 'PayArc approved the payment but returned no charge id.';
      Civi::log(E::SHORT_NAME)->critical($detail, [
        'contribution_id' => $propertyBag->getter('contributionID', TRUE),
        'invoice_id' => $propertyBag->getter('invoiceID', TRUE),
        'response' => $response,
      ]);
      throw $this->ambiguousException($detail, $response);
    }

    $result = [
      'trxn_id' => $chargeID,
      'processor_id' => $chargeID,
    ];

    // Core's checkout reads card_type_id / pan_truncation back out of the
    // params it passed in, so set them there as well as on the result.
    $cardDetails = $this->cardDetailsForCivi($response);
    if (!$cardDetails && $saved) {
      $cardDetails = $this->cardDetailsForCivi(['card' => ['data' => $saved['card']]]);
    }
    $result += $cardDetails;
    if (is_array($params)) {
      $params += $cardDetails;
    }

    if ($saved) {
      try {
        $paymentTokenID = $this->storeReusableToken($propertyBag, $saved['reference'], (array) $saved['card']);
        if ($paymentTokenID) {
          $result['payment_token_id'] = $paymentTokenID;
          $result['processor_id'] = $saved['reference'];
        }
        else {
          $this->markRecurringUnusable($propertyBag, 'The approved payment could not be linked to a CiviCRM contact token.');
        }
      }
      catch (Throwable $e) {
        $this->markRecurringUnusable($propertyBag, $e->getMessage(), $e);
      }
    }

    return $this->setStatusPaymentCompleted($result);
  }

  /**
   * Send a charge. When the answer is lost, send the identical request once
   * more at once: it carries the same Idempotency-Key, so PayArc answers with
   * the original charge (or makes it now if the first request never
   * arrived). A request without a key is never resent.
   */
  private function send(callable $call, ?string $reference): array {
    try {
      return $call();
    }
    catch (AmbiguousGatewayException $e) {
      if ($reference === NULL || $reference === '') {
        throw $e;
      }
      Civi::log(E::SHORT_NAME)->warning('No conclusive answer from PayArc for {reference}; sending the same request again.', [
        'reference' => $reference,
        'error' => $e->getMessage(),
      ]);
      return $call();
    }
  }

  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Refund or void a payment.
   *
   * PayArc does not refuse a refund of an unsettled charge as documented: it
   * voids the WHOLE charge, whatever amount was asked for. The library's
   * reverse() therefore reads the charge first, sends a partial amount only
   * for a charge known to have settled, and refuses otherwise
   * (UnsettledPartialRefundException) without sending anything. A full
   * refund of an unsettled charge comes back as a void.
   *
   * PayArc refunds have no id of their own, so the refund is recorded with
   * the sale's charge id.
   */
  public function doRefund(&$params) {
    $chargeID = trim((string) ($params['trxn_id'] ?? $params['transaction_id'] ?? ''));
    if ($chargeID === '') {
      throw new PaymentProcessorException(E::ts('The original PayArc charge ID is missing.'));
    }
    $amount = isset($params['amount']) && $params['amount'] !== '' ? number_format(abs((float) $params['amount']), 2, '.', '') : NULL;
    $options = [
      'reference' => $this->siteReference('refund-' . $chargeID . '-' . substr(bin2hex(random_bytes(4)), 0, 6)),
      'description' => E::ts('Refund from CiviCRM'),
    ];

    try {
      $client = $this->getGatewayClient();
      // A snapshot of the sale, so a refund whose answer is lost can be
      // settled by reading the sale again (a refund has no row of its own).
      $remainingBefore = Charge::remainingCents($client->getCharge($chargeID));
      try {
        $result = $client->reverse($chargeID, $amount, $options);
      }
      catch (AmbiguousGatewayException $e) {
        $result = $this->settleLostRefund($client, $chargeID, $remainingBefore, $e);
      }
      $response = $result['response'];
      if (!in_array(Charge::outcome($response), [Charge::REVERSED, Charge::APPROVED], TRUE)) {
        throw new PaymentProcessorException(
          E::ts('PayArc answered the refund with status "%1". Check charge %2 in the PayArc dashboard before trying again.', [1 => (string) ($response['status'] ?? ''), 2 => $chargeID]),
          'PAYMENT_AMBIGUOUS',
          $response
        );
      }
    }
    catch (UnsettledPartialRefundException $e) {
      throw new PaymentProcessorException(
        E::ts('This payment has not settled at PayArc yet. Refunding part of it now would cancel the whole payment, so nothing was sent. Refund the full amount, or refund part of it after the payment settles (normally the next business day).'),
        'EXTERNAL_FAILURE',
        $e->getResponseData(),
        $e
      );
    }
    catch (AmbiguousGatewayException $e) {
      throw new PaymentProcessorException(
        E::ts('PayArc did not answer, so the refund may or may not have gone through. Check charge %1 in the PayArc dashboard before trying again. (%2)', [1 => $chargeID, 2 => $e->getMessage()]),
        'PAYMENT_AMBIGUOUS',
        $e->getResponseData(),
        $e
      );
    }
    catch (GatewayException | InvalidArgumentException $e) {
      throw new PaymentProcessorException($e->getMessage(), 'EXTERNAL_FAILURE', $e instanceof GatewayException ? $e->getResponseData() : [], $e);
    }

    if ($result['action'] === 'void') {
      Civi::log(E::SHORT_NAME)->info('PayArc charge {charge} voided in full (not yet settled) for a refund from CiviCRM.', ['charge' => $chargeID]);
      CRM_Core_Session::setStatus(E::ts('PayArc voided charge %1 in full because it had not settled yet. The donor will not see it posted to their card.', [1 => $chargeID]), E::ts('Payment voided'), 'info');
    }

    // Shape expected by core Payment.create and the mjwshared refund UI.
    return [
      'refund_trxn_id' => $chargeID,
      'refund_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
      'refund_status' => 'Completed',
      'fee_amount' => 0,
    ];
  }

  /**
   * The refund request went out and no answer came back. The sale shows
   * whether it went through: less left to refund than before (or voided).
   *
   * @return array{action: 'refund'|'void', response: array}
   *
   * @throws AmbiguousGatewayException
   *   When the sale looks unchanged or cannot be read: the refund may still
   *   be in flight, so staff must check before trying again.
   */
  private function settleLostRefund(GatewayClient $client, string $chargeID, ?int $remainingBefore, AmbiguousGatewayException $lost): array {
    try {
      $sale = $client->getCharge($chargeID);
    }
    catch (Throwable $e) {
      throw $lost;
    }
    $remainingNow = Charge::remainingCents($sale);
    if ($remainingBefore === NULL || $remainingNow === NULL || $remainingNow >= $remainingBefore) {
      throw $lost;
    }
    $voided = in_array(strtolower((string) ($sale['status'] ?? '')), ['void', 'voided'], TRUE);
    return ['action' => $voided ? 'void' : 'refund', 'response' => $sale];
  }

  protected function supportsBackOffice() {
    return TRUE;
  }

  protected function supportsMultipleConcurrentPayments() {
    return FALSE;
  }

  protected function supportsFutureRecurStartDate() {
    return FALSE;
  }

  protected function supportsCancelRecurring() {
    return TRUE;
  }

  protected function supportsCancelRecurringNotifyOptional() {
    return FALSE;
  }

  public function doCancelRecurring(PropertyBag $propertyBag) {
    $propertyBag->setIsNotifyProcessorOnCancelRecur(FALSE);
    return ['message' => E::ts('Recurring contribution cancelled in CiviCRM.')];
  }

  /**
   * Process due CiviCRM-managed recurring contributions for this processor.
   *
   * Called by CiviCRM's Job.run_payment_cron API.
   */
  public function handlePaymentCron(): array {
    $processor = new CRM_Payarcjs_RecurringProcessor($this);
    return $processor->run();
  }

  /**
   * Staff may edit these schedule fields. CiviCRM owns the schedule, so no
   * call to PayArc is needed when they change.
   */
  public function getEditableRecurringScheduleFields() {
    return ['amount', 'installments', 'frequency_interval', 'frequency_unit', 'next_sched_contribution_date'];
  }

  public function getRecurringScheduleUpdateHelpText() {
    return E::ts('CiviCRM manages this recurring schedule. Changes apply from the next scheduled charge and nothing is sent to PayArc.');
  }

  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * Called by CRM_Contribute_Form_UpdateSubscription. Returning TRUE lets
   * core save the new schedule values itself.
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    $message = E::ts('Recurring schedule updated in CiviCRM.');
    return TRUE;
  }

  /**
   * Replace the stored card on a recurring contribution.
   *
   * Called by CRM_Contribute_Form_UpdateBilling with the submitted billing
   * fields plus payment_token from the Hosted Fields. The new card is saved
   * at PayArc, proved chargeable with a $1.00 authorization that is voided at
   * once, stored as a PaymentToken and linked to the series. A series
   * stopped by repeated declines is reactivated.
   */
  public function updateSubscriptionBillingInfo(&$message = '', $params = []) {
    try {
      $recurID = (int) ($params['contributionRecurID'] ?? 0);
      if (!$recurID) {
        throw new PaymentProcessorException(E::ts('The recurring contribution could not be identified.'));
      }
      $payment = $this->parseBrowserToken($this->paymentTokenFromRequest($params));
      if ($payment === NULL || $payment['type'] !== 'token') {
        throw new PaymentProcessorException(E::ts('No new card was supplied. Please re-enter the card details.'));
      }

      $recur = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recurID]);
      $contactID = (int) $recur['contact_id'];
      $email = trim((string) ($params['email'] ?? ''));

      $bag = new PropertyBag();
      $bag->setContactID($contactID);
      $bag->setContributionRecurID($recurID);
      if ($email !== '') {
        $bag->setEmail($email);
      }
      if (!empty($params['first_name'])) {
        $bag->setFirstName((string) $params['first_name']);
      }
      if (!empty($params['last_name'])) {
        $bag->setLastName((string) $params['last_name']);
      }

      $velocity = CRM_Payarcjs_Velocity::singleton();
      $refusal = $velocity->refusal(NULL);
      if ($refusal !== NULL) {
        throw new PaymentProcessorException($refusal, 'PAYARCJS_REFUSED');
      }
      $client = $this->getGatewayClient();
      try {
        $saved = $client->saveCard($payment['value'], $this->customerFields($bag, [
          'address_1' => (string) ($params['street_address'] ?? ''),
          'city' => (string) ($params['city'] ?? ''),
          'state' => $this->stateAbbreviation((string) ($params['state_province'] ?? '')),
          'zip' => (string) ($params['postal_code'] ?? ''),
          'country' => (string) ($params['country'] ?? ''),
        ]));
      }
      catch (GatewayException | InvalidArgumentException $e) {
        $velocity->failed();
        throw $this->donorException($e);
      }

      try {
        $verification = $client->verifyCard($saved['reference'], [
          'reference' => $this->siteReference('verify-' . $recurID . '-' . substr(bin2hex(random_bytes(4)), 0, 6)),
          'description' => E::ts('Card verification for recurring contribution %1', [1 => $recurID]),
          'metadata' => ['contact_id' => $contactID, 'contribution_recur' => $recurID],
        ]);
        if (!Charge::approved($verification)) {
          $texts = $this->donorTexts($verification);
          throw new PaymentProcessorException($this->withStaffDetail($texts['donor'], $texts['gateway']), 'PAYMENT_DECLINED', $verification + ['payarcjs_gateway_message' => $texts['gateway']]);
        }
      }
      catch (GatewayException | InvalidArgumentException | PaymentProcessorException $e) {
        $this->deleteCardQuietly($client, $saved['reference']);
        if (!$e instanceof AmbiguousGatewayException) {
          $velocity->failed();
        }
        if ($e instanceof AmbiguousGatewayException) {
          throw $this->ambiguousException($e->getMessage(), $e->getResponseData(), $e);
        }
        throw $e instanceof PaymentProcessorException ? $e : $this->donorException($e);
      }

      $voidWarning = '';
      if (!empty($verification['void_error'])) {
        Civi::log(E::SHORT_NAME)->warning('Card verification authorization {charge} on recurring contribution {recur} was not voided: {error}', [
          'charge' => Charge::id($verification),
          'recur' => $recurID,
          'error' => $verification['void_error'],
        ]);
        $voidWarning = E::ts('Note for staff: the %1 verification authorization (PayArc charge %2) could not be voided (%3). It expires on its own after seven days, or void it in the PayArc dashboard.', [
          1 => CRM_Utils_Money::format(GatewayClient::CARD_VERIFICATION_AMOUNT),
          2 => Charge::id($verification),
          3 => $verification['void_error'],
        ]);
      }

      $paymentTokenID = $this->storeReusableToken($bag, $saved['reference'], (array) $saved['card']);
      if (!$paymentTokenID) {
        throw new PaymentProcessorException(E::ts('The new card could not be stored in CiviCRM.'));
      }

      $update = [
        'id' => $recurID,
        'failure_count' => 0,
        'failure_retry_date' => '',
      ];
      $statusName = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur['contribution_status_id']);
      if ($statusName === 'Failed') {
        $update['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'In Progress');
        $nextDate = (string) ($recur['next_sched_contribution_date'] ?? '');
        if ($nextDate === '' || strtotime($nextDate) < time()) {
          // Resume from now; missed installments are not charged retroactively.
          $update['next_sched_contribution_date'] = date('Y-m-d H:i:s');
        }
      }
      civicrm_api3('ContributionRecur', 'create', $update);

      $last4 = $saved['card']['last4'] ?? NULL;
      $message = $last4
        ? E::ts('Card ending in %1 will be used for future charges.', [1 => $last4])
        : E::ts('The new card will be used for future charges.');
      if ($statusName === 'Failed') {
        $message .= ' ' . E::ts('The recurring contribution has been reactivated.');
      }
      if ($voidWarning !== '' && $this->isStaffSession()) {
        $message .= ' ' . $voidWarning;
      }
      return TRUE;
    }
    catch (PaymentProcessorException $e) {
      Civi::log(E::SHORT_NAME)->error('Updating the card on a recurring contribution failed.', [
        'contribution_recur_id' => $params['contributionRecurID'] ?? NULL,
        'exception' => $e,
      ]);
      // CRM_Contribute_Form_UpdateBilling displays a CRM_Core_Error to the user.
      return CRM_Core_Error::createError($e->getMessage());
    }
  }

  /**
   * Map the card in a PayArc response to CiviCRM's card_type_id and
   * pan_truncation fields. Returns an empty array if nothing usable came back.
   */
  public function cardDetailsForCivi(array $response): array {
    $details = CardDetails::fromResponse($response);
    $out = [];
    if ($details['last4']) {
      $out['pan_truncation'] = $details['last4'];
    }
    if ($details['brand']) {
      $cardTypeID = CRM_Core_PseudoConstant::getKey('CRM_Financial_DAO_FinancialTrxn', 'card_type_id', $details['brand']);
      if ($cardTypeID) {
        $out['card_type_id'] = (int) $cardTypeID;
      }
    }
    return $out;
  }

  /**
   * Whether this processor talks to the PayArc sandbox. Follows the API
   * URL's host (testapi.payarc.net), so a "live" record pointed at the
   * sandbox (a development site) loads the sandbox card fields too. Falls
   * back to the test flag when no URL is set.
   */
  public function isSandbox(): bool {
    $host = strtolower((string) parse_url((string) ($this->_paymentProcessor['url_site'] ?? ''), PHP_URL_HOST));
    return $host !== '' ? str_starts_with($host, 'test') : !empty($this->_paymentProcessor['is_test']);
  }

  /**
   * PayArc Hosted Fields script, from the portal matching the API URL.
   */
  private function getHostedFieldsUrl(): string {
    return ($this->isSandbox() ? GatewayClient::SANDBOX_PORTAL : GatewayClient::LIVE_PORTAL) . '/js/iframeprocess.js';
  }

  /**
   * Look a charge up by the reference (invoice ID) it was sent with. Used by
   * the recurring job to settle an attempt whose answer was lost, once the
   * idempotency key may have expired.
   *
   * @return array|null
   *   NULL when PayArc provably has no such charge since $sentAt.
   *
   * @throws \Payarc\ReconciliationInconclusiveException
   */
  public function findChargeByReference(string $reference, int $sentAt, ?string $amount): ?array {
    return $this->getGatewayClient()->findChargeByReference($reference, $sentAt, 5, $amount);
  }

  /**
   * The site tag used in references this extension makes up itself, so two
   * CiviCRM sites on one PayArc account never share an idempotency key.
   */
  public static function siteTag(): string {
    $seed = defined('CIVICRM_SITE_KEY') ? (string) CIVICRM_SITE_KEY : '';
    if ($seed === '') {
      $seed = (string) CRM_Core_Config::singleton()->userFrameworkBaseURL;
    }
    return CRM_Payarcjs_Schedule::siteTag($seed);
  }

  private function siteReference(string $suffix): string {
    $tag = self::siteTag();
    return 'payarcjs-' . ($tag !== '' ? $tag . '-' : '') . $suffix;
  }

  private function getGatewayClient(): GatewayClient {
    $url = trim((string) ($this->_paymentProcessor['url_site'] ?? ''));
    if ($url === '') {
      $url = !empty($this->_paymentProcessor['is_test']) ? GatewayClient::SANDBOX_URL : GatewayClient::LIVE_URL;
    }
    try {
      return new GatewayClient((string) $this->_paymentProcessor['signature'], $url, NULL, self::SOFTWARE);
    }
    catch (InvalidArgumentException $e) {
      throw new PaymentProcessorException(E::ts('The PayArc payment processor is not configured correctly: %1', [1 => $e->getMessage()]), 'EXTERNAL_FAILURE', [], $e);
    }
  }

  /**
   * What to charge: a browser token (card fields or wallet) or a stored
   * card reference.
   *
   * @return array{type: 'token'|'wallet'|'saved', value: string}|null
   */
  private function resolvePaymentToken(PropertyBag $propertyBag, array $params): ?array {
    $token = $propertyBag->has('paymentToken') ? trim((string) $propertyBag->getPaymentToken()) : '';
    if ($token === '' && empty($params['payment_token_id'])) {
      $token = $this->paymentTokenFromRequest($params);
    }
    if ($token !== '') {
      $payment = $this->parseBrowserToken($token);
      if ($payment === NULL) {
        throw new PaymentProcessorException(E::ts('The submitted PayArc payment token is not valid for browser checkout.'));
      }
      return $payment;
    }

    $paymentTokenID = (int) ($params['payment_token_id'] ?? 0);
    if ($paymentTokenID) {
      try {
        $query = [
          'id' => $paymentTokenID,
          'payment_processor_id' => $this->getID(),
          'return' => 'token',
        ];
        $contactID = (int) ($this->getContactId($propertyBag) ?? 0);
        if ($contactID) {
          $query['contact_id'] = $contactID;
        }
        $reference = trim((string) civicrm_api3('PaymentToken', 'getvalue', $query));
      }
      catch (CRM_Core_Exception $e) {
        throw new PaymentProcessorException(E::ts('The selected saved payment method is unavailable.'));
      }
      if ($reference === '') {
        throw new PaymentProcessorException(E::ts('The selected saved payment method is unavailable.'));
      }
      return ['type' => 'saved', 'value' => $reference];
    }

    return NULL;
  }

  /**
   * @return array{type: 'token'|'wallet', value: string}|null
   */
  private function parseBrowserToken(string $token): ?array {
    if (!str_starts_with($token, self::TOKEN_PREFIX)) {
      return NULL;
    }
    $value = substr($token, strlen(self::TOKEN_PREFIX));
    $type = 'token';
    if (str_starts_with($value, self::WALLET_PREFIX)) {
      $value = substr($value, strlen(self::WALLET_PREFIX));
      $type = 'wallet';
    }
    return $value === '' ? NULL : ['type' => $type, 'value' => $value];
  }

  /**
   * PayArc takes USD only.
   */
  private function assertCurrency(PropertyBag $propertyBag): void {
    $currency = strtoupper(trim((string) $propertyBag->getter('currency', TRUE, '')));
    if ($currency !== '' && $currency !== 'USD') {
      throw new PaymentProcessorException(E::ts('Currency %1 is not supported by PayArc, which accepts US dollars only.', [1 => $currency]));
    }
  }

  /**
   * Charge options. The invoice ID is the Idempotency-Key and metadata
   * 'reference': core makes a new one for every submission of a
   * contribution or event page (so a retry after a decline is not answered
   * with the old decline), and the recurring job makes a deterministic one
   * per installment and attempt. No top-level email or phone: CiviCRM sends
   * the receipts, and the library always asks PayArc not to.
   */
  private function chargeOptions(PropertyBag $propertyBag): array {
    $contributionID = (string) $propertyBag->getter('contributionID', TRUE, '');
    $invoiceID = trim((string) $propertyBag->getter('invoiceID', TRUE, ''));
    if ($invoiceID === '' && $contributionID !== '') {
      $invoiceID = $this->siteReference('contribution-' . $contributionID);
    }
    $contactID = (int) ($this->getContactId($propertyBag) ?? 0);
    $name = trim((string) $propertyBag->getter('firstName', TRUE, '') . ' ' . (string) $propertyBag->getter('lastName', TRUE, ''));

    return [
      'reference' => $invoiceID !== '' ? $invoiceID : NULL,
      'invoice' => $contributionID,
      'description' => (string) $propertyBag->getter('description', TRUE, ''),
      'metadata' => [
        'contact_id' => $contactID > 0 ? (string) $contactID : '',
        'payer_name' => $name,
        'payer_email' => (string) $propertyBag->getter('email', TRUE, ''),
        'contribution_recur' => (string) $propertyBag->getter('contributionRecurID', TRUE, ''),
      ],
    ];
  }

  /**
   * The PayArc customer record created for a saved card (one per card).
   * PayArc requires an email; a contact without one gets a placeholder at
   * this site's domain, which never receives mail.
   */
  private function customerFields(PropertyBag $propertyBag, array $address = []): array {
    $contactID = (int) ($this->getContactId($propertyBag) ?? 0);
    $email = $contactID ? (string) $this->getBillingEmail($propertyBag, $contactID) : (string) $propertyBag->getter('email', TRUE, '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $host = (string) parse_url((string) CRM_Core_Config::singleton()->userFrameworkBaseURL, PHP_URL_HOST);
      $email = 'no-email@' . ($host !== '' && str_contains($host, '.') ? $host : 'example.invalid');
    }
    $address += [
      'address_1' => (string) $propertyBag->getter('billingStreetAddress', TRUE, ''),
      'city' => (string) $propertyBag->getter('billingCity', TRUE, ''),
      'state' => (string) $propertyBag->getter('billingStateProvince', TRUE, ''),
      'zip' => (string) $propertyBag->getter('billingPostalCode', TRUE, ''),
      'country' => (string) $propertyBag->getter('billingCountry', TRUE, ''),
    ];
    return [
      'email' => $email,
      'name' => trim((string) $propertyBag->getter('firstName', TRUE, '') . ' ' . (string) $propertyBag->getter('lastName', TRUE, '')),
      'description' => $contactID ? E::ts('CiviCRM contact %1', [1 => $contactID]) : E::ts('CiviCRM donor'),
      'phone' => (string) $propertyBag->getter('phone', TRUE, ''),
    ] + array_filter($address, static fn($value) => trim((string) $value) !== '');
  }

  private function deleteCardQuietly(GatewayClient $client, string $reference): void {
    try {
      $client->deleteCard($reference);
    }
    catch (Throwable $e) {
      Civi::log(E::SHORT_NAME)->warning('The unused PayArc saved card {reference} could not be deleted: {error}', [
        'reference' => $reference,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * CRM_Contribute_Form_UpdateBilling passes the state name; send the
   * abbreviation. Anything already short is passed through.
   */
  private function stateAbbreviation(string $state): string {
    $state = trim($state);
    if (strlen($state) <= 3) {
      return $state;
    }
    try {
      $abbreviation = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_StateProvince', $state, 'abbreviation', 'name');
      return $abbreviation ? (string) $abbreviation : $state;
    }
    catch (Throwable $e) {
      return $state;
    }
  }

  /**
   * Throw for anything but an approval (Charge::outcome()). Donors get
   * wording they can act on; the gateway's own text travels in the error
   * data (and is appended for logged-in staff) so back-office users and
   * logs keep the real reason.
   *
   * - A decline can arrive as a 2xx charge with failure_code set.
   * - A partial approval (money moved, but not all of it) is voided and
   *   treated as a decline; if the void fails it is ambiguous.
   * - Anything unknown, including D0001/D0008 "duplicate (approved
   *   previously)", is ambiguous: never tell a donor who may have been
   *   charged that they were declined.
   */
  private function assertApproved(array $response, GatewayClient $client): void {
    $outcome = Charge::outcome($response);
    if ($outcome === Charge::APPROVED) {
      return;
    }

    if ($outcome === Charge::DECLINED) {
      $texts = $this->donorTexts($response);
      $errorCode = Charge::failureCode($response) !== '' ? Charge::failureCode($response) : 'PAYMENT_DECLINED';
      throw new PaymentProcessorException(
        $this->withStaffDetail($texts['donor'], $texts['gateway']),
        $errorCode,
        $response + ['payarcjs_gateway_message' => $texts['gateway']]
      );
    }

    if ($outcome === Charge::PARTIAL) {
      try {
        $client->void(Charge::id($response), 'other', 'Partially approved');
      }
      catch (Throwable $e) {
        throw $this->ambiguousException('PayArc approved only part of the amount and the charge could not be voided: ' . $e->getMessage(), $response, $e);
      }
      $gateway = 'Partially approved; the charge was voided.';
      throw new PaymentProcessorException(
        $this->withStaffDetail(E::ts('Your card was approved for only part of the amount, so the payment was cancelled and nothing was charged. Please try a different card.'), $gateway),
        'PAYMENT_DECLINED',
        $response + ['payarcjs_gateway_message' => $gateway]
      );
    }

    throw $this->ambiguousException(sprintf('PayArc answered with status "%s" (%s), which does not say whether the card was charged.', (string) ($response['status'] ?? ''), Charge::failureCode($response) ?: 'no code'), $response);
  }

  /**
   * @return array{donor: string, gateway: string}
   */
  private function donorTexts(array $response): array {
    DonorMessage::setTranslator(static fn(string $text): string => E::ts($text));
    return DonorMessage::fromResponse($response);
  }

  /**
   * A definitive failure before or during the gateway call (an HTTP error
   * answer such as a decline or a used token, or a request refused before
   * it was sent): donor wording plus the real reason for staff and logs.
   */
  private function donorException(Throwable $e): PaymentProcessorException {
    $data = $e instanceof GatewayException ? $e->getResponseData() : [];
    $texts = $this->donorTexts($data ?: ['error' => $e->getMessage()]);
    $gateway = $e->getMessage() !== '' ? $e->getMessage() : $texts['gateway'];
    $code = $e instanceof GatewayException ? DonorMessage::code($data) : '';
    return new PaymentProcessorException(
      $this->withStaffDetail($texts['donor'], $gateway),
      $code !== '' ? $code : 'EXTERNAL_FAILURE',
      $data + ['payarcjs_gateway_message' => $gateway],
      $e
    );
  }

  /**
   * The charge may or may not have happened. The donor must not retry; the
   * detail goes to staff and the logs.
   */
  private function ambiguousException(string $detail, array $response = [], ?Throwable $previous = NULL): PaymentProcessorException {
    $donor = E::ts('We could not confirm whether your payment went through. Please do not submit it again; contact us and we will check and get back to you.');
    return new PaymentProcessorException(
      $this->withStaffDetail($donor, $detail),
      'PAYMENT_AMBIGUOUS',
      $response + ['payarcjs_gateway_message' => $detail],
      $previous
    );
  }

  /**
   * Append the gateway's reason for logged-in staff (back-office forms).
   */
  private function withStaffDetail(string $donorMessage, string $gatewayMessage): string {
    if ($this->isStaffSession()) {
      return $donorMessage . ' ' . E::ts('Gateway response: %1', [1 => $gatewayMessage]);
    }
    return $donorMessage;
  }

  private function isStaffSession(): bool {
    try {
      return (bool) CRM_Core_Session::getLoggedInContactID() && CRM_Core_Permission::check('access CiviContribute');
    }
    catch (Throwable $e) {
      // No session (cron).
      return FALSE;
    }
  }

  /**
   * Store a saved card ({customer_id}:{card_id}) as a CiviCRM PaymentToken
   * and link it to the recurring contribution.
   *
   * @param array $card
   *   CardDetails::fromResponse() of the saved card.
   */
  private function storeReusableToken(PropertyBag $propertyBag, string $cardReference, array $card): ?int {
    $contactID = (int) ($this->getContactId($propertyBag) ?? 0);
    if (!$contactID) {
      Civi::log(E::SHORT_NAME)->error('Cannot store PayArc card reference without a contact ID.');
      return NULL;
    }

    // CiviCRM's expiry_date lets staff find cards that are about to expire.
    $expiry = CRM_Payarcjs_Schedule::expiryDate($card['exp_month'] ?? NULL, $card['exp_year'] ?? NULL);
    $masked = !empty($card['last4']) ? trim(($card['brand'] ?? '') . ' ' . str_repeat('X', 12) . $card['last4']) : '';
    $existing = civicrm_api3('PaymentToken', 'get', [
      'sequential' => 1,
      'contact_id' => $contactID,
      'payment_processor_id' => $this->getID(),
      'token' => $cardReference,
      'options' => ['limit' => 1],
    ]);
    if (!empty($existing['values'][0]['id'])) {
      $paymentTokenID = (int) $existing['values'][0]['id'];
    }
    else {
      $created = civicrm_api3('PaymentToken', 'create', [
        'contact_id' => $contactID,
        'payment_processor_id' => $this->getID(),
        'token' => $cardReference,
        'email' => $this->getBillingEmail($propertyBag, $contactID),
        'billing_first_name' => (string) $propertyBag->getter('firstName', TRUE, ''),
        'billing_last_name' => (string) $propertyBag->getter('lastName', TRUE, ''),
        'masked_account_number' => $masked,
        'ip_address' => CRM_Utils_System::ipAddress(),
      ] + ($expiry ? ['expiry_date' => $expiry] : []));
      $paymentTokenID = (int) $created['id'];
    }

    $recurID = (int) $propertyBag->getter('contributionRecurID', TRUE, 0);
    if ($recurID) {
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $recurID,
        'payment_token_id' => $paymentTokenID,
        'processor_id' => $cardReference,
      ]);
    }

    return $paymentTokenID;
  }

  /**
   * Stop future installments without turning an already-approved sale into a
   * browser error which could encourage the donor to submit it again.
   */
  private function markRecurringUnusable(PropertyBag $propertyBag, string $message, ?Throwable $exception = NULL): void {
    $recurID = (int) $propertyBag->getter('contributionRecurID', TRUE, 0);
    Civi::log(E::SHORT_NAME)->critical($message, [
      'contribution_recur_id' => $recurID ?: NULL,
      'exception' => $exception,
    ]);

    if ($recurID) {
      try {
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $recurID,
          'contribution_status_id' => CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_ContributionRecur',
            'contribution_status_id',
            'Failed'
          ),
        ]);
      }
      catch (Throwable $statusException) {
        Civi::log(E::SHORT_NAME)->critical('Unable to stop the unusable recurring contribution.', [
          'contribution_recur_id' => $recurID,
          'exception' => $statusException,
        ]);
      }
    }
  }

}
