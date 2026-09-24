{crmScope extensionKey='org.chabadrichmond.payarcjs'}
  <div id="payarcjs-payment-container" class="crm-section payarcjs-payment-container">
    <div class="label">{ts}Card details{/ts}</div>
    <div class="content">
      <div id="payarcjs-apple-pay" class="payarcjs-apple-pay" hidden>
        <div id="payarcjs-apple-pay-button" class="payarcjs-apple-pay-button"></div>
        <div class="payarcjs-apple-pay-divider"><span>{ts}or enter card details{/ts}</span></div>
      </div>
      <div id="payarcjs-card-element" aria-label="{ts escape='htmlattribute'}Secure card details{/ts}"></div>
      <div id="card-errors" class="payarcjs-card-errors" role="alert" aria-live="polite"></div>
      <p class="description">{ts}Card details are entered securely into a form hosted by PayArc.{/ts}</p>
    </div>
    <div class="clear"></div>
  </div>
{/crmScope}
