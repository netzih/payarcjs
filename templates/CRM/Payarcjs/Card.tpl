{crmScope extensionKey='org.chabadrichmond.payarcjs'}
  <div id="payarcjs-payment-container" class="crm-section payarcjs-payment-container">
    <div class="label">{ts}Card details{/ts}</div>
    <div class="content">
      <div id="payarcjs-wallets" class="payarc-wallets-wrapper" hidden>
        <div id="payarcjs-wallet-buttons"></div>
        <div class="payarc-wallet-divider"><span>{ts}or enter card details{/ts}</span></div>
      </div>
      <div id="payarcjs-card-element" class="payarc-card-element" aria-label="{ts escape='htmlattribute'}Secure card details{/ts}"></div>
      <div id="card-errors" class="payarc-card-errors" role="alert" aria-live="polite"></div>
      <p class="description payarc-card-note">{ts}Card details are entered securely into fields hosted by PayArc.{/ts}</p>
    </div>
    <div class="clear"></div>
  </div>
{/crmScope}
