/* global CRM, payarc */
/* jshint esversion: 8 */
(function($, ts) {
  'use strict';

  const scriptName = 'payarcjs';
  const tokenPrefix = 'payjs:';
  // Pay.js resolves its Apple Pay compatibility check from a postMessage
  // handler and never times out on its own.
  const APPLE_PAY_TIMEOUT_MS = 8000;
  const APPLE_PAY_MAX_ATTEMPTS = 2;
  let client = null;
  let cardEntry = null;
  let applePay = null;
  let applePayAttempts = 0;
  let applePayWrapper = null;
  let form = null;
  let tokenizing = false;
  let payJsLoading = false;

  // mjwshared's CRM.payment.displayError() writes to #card-errors, so the
  // template uses that id.
  function errorElement() {
    return document.getElementById('card-errors');
  }

  /**
   * Pay.js reports validation problems in terse merchant language
   * ("Invalid card informtion", "card number is required"); reword them.
   */
  function donorWording(message) {
    const m = String(message || '').toLowerCase();
    if (!m) {
      return ts('Unable to validate the card.');
    }
    if (/expir/.test(m)) {
      return ts('Please check the expiration date (MM/YY).');
    }
    if (/cvv|cvc|security|card code/.test(m)) {
      return ts('Please check the security code (the 3 or 4 digit CVV).');
    }
    if (/required|length|invalid card|card number|luhn|informtion|information/.test(m)) {
      return ts('Please check the card number, expiration date and security code.');
    }
    if (/public key|authenticat|unauthori|not allowed|invalid key/.test(m)) {
      return ts('The payment form is not configured correctly, so no charge was made. Please contact us.');
    }
    if (/network|timeout|failed to fetch|unavailable/.test(m)) {
      return ts('The card processor did not respond. Please wait a moment and try again.');
    }
    return String(message);
  }

  /**
   * Pay.js forwards the card iframe's payload as a JSON string. A field that
   * turns valid arrives as an "error" with code "0" and an empty message
   * (reason "clear error"), so an empty message must stay empty: falling back
   * to the raw JSON let its type ("cvv") match the wording rules below and
   * showed a security-code warning once the CVV was correct.
   */
  function errorMessage(error) {
    if (!error) {
      return '';
    }
    if (typeof error === 'string') {
      try {
        const decoded = JSON.parse(error);
        if (decoded && typeof decoded === 'object') {
          return typeof decoded.message === 'string' ? decoded.message : '';
        }
      }
      catch (ignored) {
        // Pay.js v1 emits a plain string.
      }
      return error;
    }
    return typeof error.message === 'string' ? error.message : String(error);
  }

  // Riverlea's public-form CSS draws a red right-hand border on any
  // .crm-section that :has() a .crm-error inside it, and :has() only tests
  // that the element exists - so the error classes go on while a message is
  // showing and come off again with it.
  function setErrorText(text) {
    const el = errorElement();
    if (!el) {
      return;
    }
    el.textContent = text;
    ['crm-error', 'alert', 'alert-danger'].forEach(function(name) {
      el.classList.toggle(name, text !== '');
    });
  }

  function displayError(message) {
    const text = donorWording(message);
    setErrorText(text);
    if (CRM.payment && CRM.payment.displayError) {
      try {
        CRM.payment.displayError(text, true);
      }
      catch (ignored) {
        // mjwshared needs #card-errors and a form; fall back to the inline text.
      }
    }
  }

  function clearError() {
    setErrorText('');
  }

  function submitButtons() {
    return (CRM.payment && CRM.payment.submitButtons) ? CRM.payment.submitButtons : [];
  }

  function setButtonsDisabled(disabled) {
    const buttons = submitButtons();
    for (let i = 0; i < buttons.length; i++) {
      buttons[i].disabled = disabled;
    }
  }

  function selectedProcessorIsOurs() {
    if (!CRM.vars[scriptName]) {
      return false;
    }
    const configuredID = parseInt(CRM.vars[scriptName].id, 10);
    const selectedID = CRM.payment.getPaymentProcessorSelectorValue();
    return selectedID === null || typeof selectedID === 'undefined' || parseInt(selectedID, 10) === configuredID;
  }

  function shouldHandlePayment() {
    if (!form || !selectedProcessorIsOurs()) {
      return false;
    }
    // Set once the key is in the form: the submit we then trigger must pass
    // through untouched so CiviCRM's own handlers (AJAX popups use
    // jquery.form) can process it.
    if (form.dataset.payarcjsSubmitting === 'true') {
      return false;
    }
    if (form.dataset.submitdontprocess === 'true') {
      return false;
    }
    // A $0 total needs no card, but only forms that carry an amount can be
    // judged that way: Change Billing Details has no amount fields and
    // mjwshared reports 0 for it.
    if (formHasAmountFields() && CRM.payment.getTotalAmount && CRM.payment.getTotalAmount() === 0.0) {
      return false;
    }
    return true;
  }

  function formHasAmountFields() {
    return !!(form.querySelector('#priceset, #totalAmount, #totalTaxAmount, [name="total_amount"], [name="amount"], [price]') ||
      (typeof calculateTotalFee === 'function'));
  }

  function isNonPaymentButton(element) {
    return !!(element && element.matches && element.matches(
      '[type="submit"][formnovalidate], [type="submit"].cancel, [type="submit"].webform-previous'
    ));
  }

  async function tokenizeAndSubmit(event) {
    if (event.type === 'click') {
      // mjwshared's addHandlerNonPaymentSubmitButtons() sets this flag at
      // attach time whenever a Cancel button exists (it calls its handler
      // instead of binding it), so a click on a payment button clears it, as
      // the Stripe extension does.
      form.dataset.submitdontprocess = 'false';
    }
    else if (event.type === 'submit' && isNonPaymentButton(event.submitter)) {
      // Cancel / back buttons submit the form without a payment.
      return;
    }
    if (!shouldHandlePayment()) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    if (tokenizing) {
      return;
    }

    if (CRM.payment.validateForm && !CRM.payment.validateForm()) {
      return;
    }

    const button = event.target && event.target.closest ? event.target.closest('[type="submit"]') : null;

    // An Apple Pay key already sits in the form (the donor authorised in the
    // sheet, then fixed a billing field); no card to tokenize.
    const existing = form.querySelector('input[name="payment_token"]');
    if (existing && existing.value.indexOf(tokenPrefix) === 0) {
      submitForm(button);
      return;
    }

    tokenizing = true;
    clearError();
    setButtonsDisabled(true);

    try {
      const result = await client.getPaymentKey(cardEntry);
      if (result && result.error) {
        throw new Error(result.error.message || result.error);
      }
      const paymentKey = typeof result === 'string' ? result : (result && result.key);
      if (!paymentKey) {
        throw new Error(ts('PayArc did not return a payment key.'));
      }
      setPaymentToken(paymentKey);
      submitForm(button);
    }
    catch (error) {
      tokenizing = false;
      setButtonsDisabled(false);
      displayError(errorMessage(error));
      if (CRM.payment.triggerEvent) {
        CRM.payment.triggerEvent('crmBillingFormNotValid');
      }
    }
  }

  function setPaymentToken(paymentKey) {
    let hiddenToken = form.querySelector('input[name="payment_token"]');
    if (!hiddenToken) {
      hiddenToken = document.createElement('input');
      hiddenToken.type = 'hidden';
      hiddenToken.name = 'payment_token';
      form.appendChild(hiddenToken);
    }
    hiddenToken.value = tokenPrefix + paymentKey;
  }

  /**
   * Submit the form with the payment key in place, through the button the
   * donor pressed (or the first payment button for Apple Pay).
   */
  function submitForm(button) {
    if (CRM.payment.resetBillingFieldsRequiredForJQueryValidate) {
      CRM.payment.resetBillingFieldsRequiredForJQueryValidate();
    }
    // Preserve which button was pressed; a scripted submit does not send
    // the button's own name/value.
    if (button && button.name && !form.querySelector('input[type="hidden"][name="' + button.name + '"]')) {
      const clicked = document.createElement('input');
      clicked.type = 'hidden';
      clicked.name = button.name;
      clicked.value = button.value;
      form.appendChild(clicked);
    }
    setButtonsDisabled(true);
    form.dataset.payarcjsSubmitting = 'true';
    // jQuery's submit runs jquery.form's AJAX handler on popups and falls
    // back to a native submit on ordinary pages.
    $(form).trigger('submit');
  }

  function paymentButton() {
    const buttons = submitButtons();
    for (let i = 0; i < buttons.length; i++) {
      if (!isNonPaymentButton(buttons[i])) {
        return buttons[i];
      }
    }
    return buttons.length ? buttons[0] : null;
  }

  function attachSubmitHandlers() {
    if (form.dataset.payarcjsHandlers === 'true') {
      return;
    }
    form.dataset.payarcjsHandlers = 'true';
    form.dataset.submitdontprocess = 'false';
    form.dataset.payarcjsSubmitting = 'false';

    if (CRM.payment.setBillingFieldsRequiredForJQueryValidate) {
      CRM.payment.setBillingFieldsRequiredForJQueryValidate();
    }
    // Populates CRM.payment.submitButtons; it is null until this runs.
    const buttons = CRM.payment.getBillingSubmit ? CRM.payment.getBillingSubmit() : submitButtons();
    if (CRM.payment.addHandlerNonPaymentSubmitButtons) {
      CRM.payment.addHandlerNonPaymentSubmitButtons();
    }

    for (let i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', tokenizeAndSubmit, true);
      // CiviCRM's submitOnce() would disable the button before we tokenize.
      buttons[i].removeAttribute('onclick');
    }
    form.addEventListener('submit', tokenizeAndSubmit, true);
  }

  /**
   * Pay.js is an external script. In AJAX-loaded billing blocks (back-office
   * "Submit Credit Card Contribution", WordPress AJAX forms) the region
   * script tag can still be downloading when this runs, so load it here and
   * mount once it arrives.
   */
  function loadPayJs(onLoad) {
    if (typeof payarc !== 'undefined') {
      onLoad();
      return;
    }
    const url = CRM.vars[scriptName].payJsUrl;
    let script = document.querySelector('script[data-payarcjs="payjs"]');
    if (!script) {
      script = document.querySelector('script[src="' + url + '"]');
    }
    if (!script) {
      script = document.createElement('script');
      script.src = url;
      script.async = true;
      script.dataset.payarcjs = 'payjs';
      document.head.appendChild(script);
    }
    if (payJsLoading) {
      return;
    }
    payJsLoading = true;
    script.addEventListener('load', function() {
      payJsLoading = false;
      onLoad();
    });
    script.addEventListener('error', function() {
      payJsLoading = false;
      displayError(ts('The secure PayArc card form could not be loaded.'));
    });
    // The region tag may already have finished; poll briefly as a fallback.
    let attempts = 0;
    const poll = window.setInterval(function() {
      if (typeof payarc !== 'undefined') {
        window.clearInterval(poll);
        if (payJsLoading) {
          payJsLoading = false;
          onLoad();
        }
      }
      else if (++attempts > 100) {
        window.clearInterval(poll);
      }
    }, 100);
  }

  function mountCardEntry() {
    const container = document.getElementById('payarcjs-card-element');
    if (!container || container.children.length || !CRM.vars[scriptName]) {
      return;
    }
    if (typeof payarc === 'undefined') {
      loadPayJs(mountCardEntry);
      return;
    }

    try {
      client = new payarc.Client(CRM.vars[scriptName].publicKey);
      cardEntry = client.createPaymentCardEntry();
      // Styles are applied inside the iframe as .payjs-base / .payjs-valid /
      // .payjs-invalid; the row is 42px to fill the 44px container.
      // Pay.js v2 takes the class styles under 'styles'; errors are shown by
      // this script beneath the box rather than inside the iframe.
      cardEntry.generateHTML({
        styles: {
          base: {'font-size': '16px', 'height': '42px', 'line-height': '42px', 'color': '#2c3338', 'background': 'transparent'},
          valid: {'color': '#2c3338'},
          invalid: {'color': '#b32d2e'}
        },
        display_errors: false
      });
      cardEntry.addHTML('payarcjs-card-element');
      // Field validation messages ("card number is required") arrive as the
      // donor types and blurs. Show them inline only; the CRM.payment alert is
      // reserved for tokenization failures at submit time.
      cardEntry.addEventListener('error', function(error) {
        const message = errorMessage(error);
        setErrorText(message ? donorWording(message) : '');
      });
      attachSubmitHandlers();
      mountApplePay();
      if (CRM.payment.triggerEvent) {
        CRM.payment.triggerEvent('crmBillingFormReloadComplete', scriptName);
      }
    }
    catch (error) {
      displayError(errorMessage(error));
    }
  }

  /* ---------------------------------------------------------------------
   * Apple Pay (Pay.js v2). One-time gifts on public pages only: the
   * resulting key is single-use, so recurring gifts, card updates and
   * back-office forms keep the card box.
   * ------------------------------------------------------------------ */

  function applePayAllowedHere() {
    const cfg = CRM.vars[scriptName].applePay;
    if (!cfg || !cfg.enabled || !window.ApplePaySession) {
      return false;
    }
    const formId = CRM.vars[scriptName].formId || form.getAttribute('id') || '';
    if (['Main', 'Register'].indexOf(formId) === -1) {
      return false;
    }
    return formHasAmountFields();
  }

  function applePayAmount() {
    const total = Number(CRM.payment.getTotalAmount ? CRM.payment.getTotalAmount() : 0);
    return total > 0 ? total.toFixed(2) : '0.00';
  }

  function isRecurringSelected() {
    return !!(CRM.payment.getIsRecur && CRM.payment.getIsRecur());
  }

  function refreshApplePayVisibility() {
    const wrapper = document.getElementById('payarcjs-apple-pay');
    if (!wrapper || !applePay || wrapper.dataset.payarcjsAvailable !== 'true') {
      return;
    }
    wrapper.hidden = isRecurringSelected();
  }

  /**
   * Pay.js looks its relay iframe up by element id, so one left behind by an
   * earlier attempt swallows every reply meant for a new entry.
   */
  function discardApplePayRelay() {
    const relay = document.getElementById('payjs-applePayRelay');
    if (relay && relay.parentNode) {
      relay.parentNode.removeChild(relay);
    }
  }

  /**
   * Keep the card box and record why Apple Pay is missing. Pay.js gives up
   * silently in several places, so without this the only symptom is a card
   * box where the button should be.
   */
  function applePayUnavailable(wrapper, reason) {
    wrapper.hidden = true;
    wrapper.dataset.payarcjsError = reason;
    if (window.console && window.console.warn) {
      window.console.warn('payarcjs: Apple Pay not shown: ' + reason);
    }
  }

  function remountApplePay() {
    const target = document.getElementById('payarcjs-apple-pay-button');
    discardApplePayRelay();
    if (target) {
      target.innerHTML = '';
    }
    applePay = null;
    mountApplePay();
  }

  function mountApplePay() {
    const wrapper = document.getElementById('payarcjs-apple-pay');
    if (!wrapper || applePay || !applePayAllowedHere() || !client.createApplePayEntry) {
      return;
    }
    const cfg = CRM.vars[scriptName].applePay;
    discardApplePayRelay();
    applePayAttempts++;
    applePay = client.createApplePayEntry({
      targetDiv: 'payarcjs-apple-pay-button',
      displayName: cfg.displayName,
      paymentRequest: {
        total: {label: cfg.displayName, amount: applePayAmount(), type: 'final'},
        countryCode: cfg.countryCode,
        currencyCode: cfg.currencyCode,
        requiredBillingContactFields: ['postalAddress', 'name'],
        requiredShippingContactFields: ['email']
      },
      applePayBtn: {type: 'donate', color: 'black'}
    });
    // Held separately so a callback that fires after the form was rebuilt
    // cannot touch whatever entry has replaced this one.
    const entry = applePay;
    applePayWrapper = wrapper;

    let billingPrefill = Promise.resolve();
    entry.on('applePayPaymentAuthorized', function(event) {
      try {
        billingPrefill = fillBillingFromApplePay(event && event.payment ? event.payment : {});
      }
      catch (ignored) {
        // Billing prefill is a convenience; the donor can complete the fields.
        billingPrefill = Promise.resolve();
      }
    });
    entry.on('applePaySuccess', function() {
      // The state list reloads by AJAX after the country is set; wait for it
      // so the form is not submitted with an empty required state.
      Promise.all([client.getPaymentKey(entry), billingPrefill]).then(function(results) {
        const result = results[0];
        const paymentKey = typeof result === 'string' ? result : (result && result.key);
        if (!paymentKey) {
          throw new Error(ts('PayArc did not return a payment key.'));
        }
        clearError();
        setPaymentToken(paymentKey);
        if (CRM.payment.validateForm && !CRM.payment.validateForm()) {
          displayError(ts('Apple Pay approved the payment. Please complete the highlighted fields and press the button to finish.'));
          return;
        }
        submitForm(paymentButton());
      }).catch(function(error) {
        displayError(errorMessage(error));
      });
    });
    entry.on('applePayError', function() {
      displayError(ts('Apple Pay could not complete the payment. Please try again or enter your card details below.'));
    });
    entry.on('applePayCancelled', function() {
      clearError();
    });

    // checkCompatibility() settles from a postMessage handler and carries no
    // timeout of its own, so a reply that never arrives leaves the promise
    // pending for ever and the button simply never appears. Build the entry
    // again once, then give up in a way that says so.
    let settled = false;
    const timer = window.setTimeout(function() {
      if (settled || !document.body.contains(wrapper)) {
        return;
      }
      settled = true;
      if (applePayAttempts < APPLE_PAY_MAX_ATTEMPTS) {
        remountApplePay();
        return;
      }
      applePayUnavailable(wrapper, 'Pay.js did not answer the compatibility check');
    }, APPLE_PAY_TIMEOUT_MS);

    entry.checkCompatibility().then(function() {
      if (settled || !document.body.contains(wrapper)) {
        return;
      }
      settled = true;
      window.clearTimeout(timer);
      // load() may have cleared the module reference while this was pending.
      applePay = entry;
      entry.addButton();
      wrapper.dataset.payarcjsAvailable = 'true';
      delete wrapper.dataset.payarcjsError;
      wrapper.hidden = isRecurringSelected();
      const button = document.getElementById('payjs-applePayBtn');
      if (button) {
        // Runs before Pay.js opens the sheet: refresh the amount and refuse
        // recurring gifts, which need a reusable card.
        button.addEventListener('click', function(event) {
          if (isRecurringSelected()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            displayError(ts('Apple Pay cannot be used for a recurring gift. Please enter your card details below.'));
            return;
          }
          const amount = applePayAmount();
          if (amount === '0.00') {
            event.preventDefault();
            event.stopImmediatePropagation();
            displayError(ts('Please choose an amount before paying with Apple Pay.'));
            return;
          }
          clearError();
          entry.applePayPaymentRequest.total.amount = amount;
        }, true);
      }
      $(form).on('change', '#is_recur, #auto_renew, input[name="is_pledge"]', refreshApplePayVisibility);
    }).catch(function(error) {
      if (settled || !document.body.contains(wrapper)) {
        return;
      }
      settled = true;
      window.clearTimeout(timer);
      applePayUnavailable(wrapper, errorMessage(error) || String(error));
    });
  }

  /**
   * Copy the Apple billing contact into empty CiviCRM billing fields.
   * Resolves once the state has been selected (or it is clear it cannot be),
   * because changing the country reloads the state list asynchronously.
   */
  function fillBillingFromApplePay(payment) {
    const cfg = CRM.vars[scriptName].applePay;
    const locationId = CRM.vars[scriptName].billingAddressID;
    const billing = payment.billingContact || {};
    const shipping = payment.shippingContact || {};

    function fill(id, value) {
      const el = document.getElementById(id);
      if (el && value && !el.value) {
        el.value = value;
        $(el).trigger('change');
      }
    }
    fill('billing_first_name', billing.givenName);
    fill('billing_last_name', billing.familyName);
    fill('billing_street_address-' + locationId, (billing.addressLines || []).join(', '));
    fill('billing_city-' + locationId, billing.locality);
    fill('billing_postal_code-' + locationId, billing.postalCode);
    fill('email-' + locationId, shipping.emailAddress || billing.emailAddress);

    const countrySelect = document.getElementById('billing_country_id-' + locationId);
    const stateSelect = document.getElementById('billing_state_province_id-' + locationId);
    const iso = String(billing.countryCode || '').toUpperCase();
    const countryId = iso && cfg.countryIds ? cfg.countryIds[iso] : null;
    const area = String(billing.administrativeArea || '').toUpperCase();

    function selectState() {
      if (!stateSelect || !area) {
        return;
      }
      let stateId = null;
      if (cfg.stateIds && cfg.stateIds[iso] && cfg.stateIds[iso][area]) {
        stateId = cfg.stateIds[iso][area];
      }
      if (!stateId) {
        for (let i = 0; i < stateSelect.options.length; i++) {
          if (stateSelect.options[i].text.toUpperCase() === area) {
            stateId = stateSelect.options[i].value;
            break;
          }
        }
      }
      if (stateId && stateSelect.querySelector('option[value="' + stateId + '"]')) {
        $(stateSelect).val(String(stateId)).trigger('change');
      }
    }

    return new Promise(function(resolve) {
      if (countrySelect && countryId && String(countrySelect.value) !== String(countryId)) {
        // The state list reloads through CiviCRM's chain-select; give it a
        // few seconds, then carry on regardless.
        let done = false;
        const finish = function() {
          if (!done) {
            done = true;
            selectState();
            resolve();
          }
        };
        $(stateSelect).one('crmOptionsUpdated', finish);
        window.setTimeout(finish, 4000);
        $(countrySelect).val(String(countryId)).trigger('change');
      }
      else {
        selectState();
        resolve();
      }
    });
  }

  function load() {
    if (!CRM.payment || !CRM.vars[scriptName]) {
      return;
    }
    form = CRM.payment.getBillingForm();
    if (!form || form.length === 0) {
      return;
    }
    CRM.payment.form = form;
    // A freshly (re)loaded form, e.g. after a server-side validation error in
    // an AJAX popup, starts with no tokenization in progress.
    tokenizing = false;
    // Discard the Apple Pay entry only once the billing block it mounted into
    // has gone. A mount still waiting on Pay.js has no button yet, and
    // clearing it here left the pending callback with nothing to add to.
    if (applePayWrapper && !document.body.contains(applePayWrapper)) {
      applePay = null;
      applePayWrapper = null;
      applePayAttempts = 0;
    }
    mountCardEntry();
  }

  if (CRM.payment && !CRM.payment[scriptName]) {
    CRM.payment[scriptName] = {reload: load};
    CRM.payment.registerScript(scriptName);
  }

  document.addEventListener('DOMContentLoaded', load);
  $(document).ajaxComplete(function(event, xhr, settings) {
    if (CRM.payment && CRM.payment.isAJAXPaymentForm && CRM.payment.isAJAXPaymentForm(settings.url)) {
      window.setTimeout(load, 0);
    }
  });
  // Forms opened in CiviCRM popups (Change Billing Details, back-office
  // contribution) arrive as snippets; load() is idempotent, so run it on
  // every snippet load and mount the card box if the form is present.
  $(document).on('crmLoad crmFormLoad', function() {
    window.setTimeout(load, 0);
  });

}(CRM.$, CRM.ts('org.chabadrichmond.payarcjs')));
