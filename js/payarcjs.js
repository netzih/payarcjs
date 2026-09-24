/* global CRM, PayarcHostedFields */
/* jshint esversion: 8 */
(function($, ts) {
  'use strict';

  // CiviCRM inserts the billing block's scripts again whenever an AJAX form
  // reloads it; the handlers registered below must only exist once. load()
  // re-reads the form and CRM.vars on every call.
  if (window.payarcjsLoaded) {
    return;
  }
  window.payarcjsLoaded = true;

  const scriptName = 'payarcjs';
  const tokenPrefix = 'payarc:';
  const walletPrefix = 'wallet:';
  let form = null;
  let fields = null;
  let mounting = false;
  let tokenizing = false;
  let walletWrapper = null;


  function vars() {
    return CRM.vars[scriptName];
  }

  // payarc-hostedfields.js reads its wording from here.
  function shareStrings() {
    window.PayarcHostedFieldsConfig = window.PayarcHostedFieldsConfig || {};
    window.PayarcHostedFieldsConfig.i18n = vars().i18n || {};
  }

  function str(key, fallback) {
    return (vars() && vars().i18n && vars().i18n[key]) || fallback;
  }

  // mjwshared's CRM.payment.displayError() writes to #card-errors, so the
  // template uses that id.
  function errorElement() {
    return document.getElementById('card-errors');
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

  /**
   * Show a message that is already worded for the donor (the helper words
   * tokenization errors itself).
   */
  function displayError(text) {
    text = String(text || str('unableToValidate', 'Unable to validate the card.'));
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
    if (!vars()) {
      return false;
    }
    const configuredID = parseInt(vars().id, 10);
    const selectedID = CRM.payment.getPaymentProcessorSelectorValue();
    return selectedID === null || typeof selectedID === 'undefined' || parseInt(selectedID, 10) === configuredID;
  }

  function shouldHandlePayment() {
    if (!form || !selectedProcessorIsOurs()) {
      return false;
    }
    // Set once the token is in the form: the submit we then trigger must
    // pass through untouched so CiviCRM's own handlers (AJAX popups use
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

    // A wallet token already sits in the form (the donor paid in the wallet
    // window, then fixed a billing field); no card to tokenize. Card tokens
    // are never reused: every tokenization returns a fresh one.
    const existing = form.querySelector('input[name="payment_token"]');
    if (existing && existing.value.indexOf(tokenPrefix + walletPrefix) === 0) {
      submitForm(button);
      return;
    }

    tokenizing = true;
    clearError();
    setButtonsDisabled(true);

    try {
      if (!fields) {
        throw new Error(str('enterCard', 'Please enter your card details.'));
      }
      const token = await PayarcHostedFields.tokenize(fields);
      setPaymentToken(token);
      submitForm(button);
    }
    catch (error) {
      tokenizing = false;
      setButtonsDisabled(false);
      if (error && error.raw && window.console) {
        window.console.warn('payarcjs: tokenization refused: ' + error.raw);
      }
      displayError(PayarcHostedFields.errorText(error));
      if (CRM.payment.triggerEvent) {
        CRM.payment.triggerEvent('crmBillingFormNotValid');
      }
    }
  }

  function setPaymentToken(value) {
    let hiddenToken = form.querySelector('input[name="payment_token"]');
    if (!hiddenToken) {
      hiddenToken = document.createElement('input');
      hiddenToken.type = 'hidden';
      hiddenToken.name = 'payment_token';
      form.appendChild(hiddenToken);
    }
    hiddenToken.value = tokenPrefix + value;
  }

  /**
   * Submit the form with the token in place, through the button the donor
   * pressed (or the first payment button for a wallet).
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

  function mountCardEntry() {
    const container = document.getElementById('payarcjs-card-element');
    if (!container || !vars() || container.dataset.payarcjsMounted === 'true' || mounting) {
      return;
    }
    if (typeof PayarcHostedFields === 'undefined') {
      displayError(str('loadFailed', 'The secure PayArc card form could not be loaded.'));
      return;
    }
    shareStrings();
    mounting = true;
    fields = null;
    PayarcHostedFields.mount({
      clientId: vars().clientId,
      scriptUrl: vars().scriptUrl,
      container: container,
      // Field validation messages arrive as the donor types and leaves a
      // field. Show them inline only; the CRM.payment alert is reserved for
      // tokenization failures at submit time.
      onFieldError: function(text) {
        setErrorText(text || '');
      }
    }).then(function(handles) {
      mounting = false;
      // The billing block may have been replaced while the script loaded;
      // mount into the new one.
      if (!document.body.contains(container)) {
        load();
        return;
      }
      fields = handles;
      container.dataset.payarcjsMounted = 'true';
      attachSubmitHandlers();
      mountWallets();
      if (CRM.payment.triggerEvent) {
        CRM.payment.triggerEvent('crmBillingFormReloadComplete', scriptName);
      }
    }).catch(function(error) {
      mounting = false;
      // A block replaced while mounting (an AJAX form reloading its billing
      // section twice in a row) fails half-way; mount the current one.
      if (!document.body.contains(container)) {
        load();
        return;
      }
      displayError(PayarcHostedFields.errorText(error));
    });
  }

  /* ---------------------------------------------------------------------
   * Apple Pay / Google Pay, through PayArc's wallet window. One-time gifts
   * on public pages only: a wallet token cannot be saved, so recurring
   * gifts, card updates and back-office forms keep the card fields. Where
   * Apple Pay is available only Apple Pay is offered.
   * ------------------------------------------------------------------ */

  function walletsWanted() {
    const cfg = vars().wallets || {};
    if (cfg.applePay && window.ApplePaySession) {
      return ['apple-pay'];
    }
    return cfg.googlePay ? ['google-pay'] : [];
  }

  function walletsAllowedHere() {
    const formId = vars().formId || form.getAttribute('id') || '';
    if (['Main', 'Register'].indexOf(formId) === -1) {
      return false;
    }
    return formHasAmountFields() && walletsWanted().length > 0;
  }

  function walletAmount() {
    const total = Number(CRM.payment.getTotalAmount ? CRM.payment.getTotalAmount() : 0);
    return total > 0 ? total.toFixed(2) : '0.00';
  }

  function isRecurringSelected() {
    return !!(CRM.payment.getIsRecur && CRM.payment.getIsRecur());
  }

  function refreshWalletVisibility() {
    if (walletWrapper && walletWrapper.dataset.payarcjsAvailable === 'true') {
      walletWrapper.hidden = isRecurringSelected();
    }
  }

  function mountWallets() {
    const wrapper = document.getElementById('payarcjs-wallets');
    const target = document.getElementById('payarcjs-wallet-buttons');
    if (!wrapper || !target || wrapper.dataset.payarcjsAvailable === 'true' || !walletsAllowedHere()) {
      return;
    }
    walletWrapper = wrapper;
    PayarcHostedFields.wallets({
      clientId: vars().clientId,
      scriptUrl: vars().scriptUrl,
      targetDiv: target,
      wallets: walletsWanted(),
      getAmount: walletAmount,
      onOpen: clearError,
      onCancel: clearError,
      onError: displayError,
      onKey: function(token) {
        clearError();
        setPaymentToken(walletPrefix + token);
        if (CRM.payment.validateForm && !CRM.payment.validateForm()) {
          displayError(str('walletIncomplete', 'Your wallet approved the payment. Please complete the highlighted fields and press the button to finish.'));
          return;
        }
        submitForm(paymentButton());
      }
    }).then(function(row) {
      if (!row || !document.body.contains(wrapper)) {
        return;
      }
      // Runs before the helper opens the wallet window: refuse recurring
      // gifts, which need a card that can be saved.
      row.addEventListener('click', function(event) {
        if (isRecurringSelected()) {
          event.preventDefault();
          event.stopImmediatePropagation();
          displayError(str('walletRecurring', 'Apple Pay and Google Pay cannot be used for a recurring gift. Please enter your card details below.'));
        }
      }, true);
      wrapper.dataset.payarcjsAvailable = 'true';
      wrapper.hidden = isRecurringSelected();
      $(form).on('change', '#is_recur, #auto_renew, input[name="is_pledge"]', refreshWalletVisibility);
    });
  }

  function load() {
    if (!CRM.payment || !vars()) {
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
    if (walletWrapper && !document.body.contains(walletWrapper)) {
      walletWrapper = null;
    }
    mountCardEntry();
  }

  if (CRM.payment && !CRM.payment[scriptName]) {
    CRM.payment[scriptName] = {reload: load};
    CRM.payment.registerScript(scriptName);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  }
  else {
    window.setTimeout(load, 0);
  }
  $(document).ajaxComplete(function(event, xhr, settings) {
    if (CRM.payment && CRM.payment.isAJAXPaymentForm && CRM.payment.isAJAXPaymentForm(settings.url)) {
      window.setTimeout(load, 0);
    }
  });
  // Forms opened in CiviCRM popups (Change Billing Details, back-office
  // contribution) arrive as snippets; load() is idempotent, so run it on
  // every snippet load and mount the card fields if the form is present.
  $(document).on('crmLoad crmFormLoad', function() {
    window.setTimeout(load, 0);
  });

}(CRM.$, CRM.ts('org.chabadrichmond.payarcjs')));
