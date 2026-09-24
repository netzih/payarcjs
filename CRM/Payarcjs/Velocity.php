<?php

use CRM_Payarcjs_ExtensionUtil as E;
use Payarc\VelocityGuard;
use Payarc\VelocityStore;

/**
 * Card-testing protection (see Payarc\VelocityGuard).
 *
 * Contribution pages and card updates ask refusal() before sending a token
 * to PayArc and report each failure with failed(). The recurring job charges
 * saved cards and skips both. Staff with "edit contributions" are never
 * refused or counted, so they can take payments during a pause.
 *
 * When failures across the site reach the limit, card payments pause, the
 * alert address is emailed once, and System Status shows the pause with a
 * "Resume card payments" action (API3 Payarcjs.resume).
 */
class CRM_Payarcjs_Velocity implements VelocityStore {

  private const PREFIX = 'payarcjs_vg_';

  private ?VelocityGuard $guard = NULL;

  private static ?self $instance = NULL;

  public static function singleton(): self {
    return self::$instance ??= new self();
  }

  public function guard(): VelocityGuard {
    return $this->guard ??= new VelocityGuard($this, [
      'ip_limit' => (int) Civi::settings()->get('payarcjs_velocity_ip_limit'),
      'site_limit' => (int) Civi::settings()->get('payarcjs_velocity_site_limit'),
      'pause_minutes' => (int) Civi::settings()->get('payarcjs_velocity_pause_minutes'),
      'min_amount' => (string) Civi::settings()->get('payarcjs_velocity_min_amount'),
    ]);
  }

  /**
   * Donor wording when this payment must not be sent, or NULL to go ahead.
   *
   * @param string|null $amount
   *   Decimal amount of a charge; NULL for a card save or verification.
   */
  public function refusal(?string $amount): ?string {
    if ($this->exempt()) {
      return NULL;
    }
    $reason = $this->guard()->check($this->ip(), $amount);
    if ($reason === NULL) {
      return NULL;
    }
    Civi::log(E::SHORT_NAME)->info('Velocity guard refused a payment ({reason})', ['reason' => $reason]);
    \Payarc\DonorMessage::setTranslator(static fn(string $text): string => E::ts($text));
    return VelocityGuard::donorText($reason);
  }

  /**
   * Count a declined or refused charge or card save.
   */
  public function failed(): void {
    if ($this->exempt()) {
      return;
    }
    if ($this->guard()->recordFailure($this->ip()) === VelocityGuard::TRIPPED) {
      $this->alert();
    }
  }

  private function exempt(): bool {
    try {
      return (bool) CRM_Core_Session::getLoggedInContactID() && CRM_Core_Permission::check('edit contributions');
    }
    catch (Throwable $e) {
      return FALSE;
    }
  }

  private function ip(): string {
    $ip = (string) CRM_Utils_System::ipAddress();
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  private function alert(): void {
    $config = $this->guard()->config();
    $when = CRM_Utils_Date::customFormat(date('Y-m-d H:i:s', (int) $this->guard()->pausedUntil()));
    Civi::log(E::SHORT_NAME)->critical('Velocity guard paused card payments until {until}', ['until' => $when, 'site_limit' => $config['site_limit']]);

    [$domainName, $domainEmail] = CRM_Core_BAO_Domain::getNameAndEmail(TRUE);
    $to = trim((string) Civi::settings()->get('payarcjs_velocity_alert_email')) ?: $domainEmail;
    if (!$to || !$domainEmail) {
      return;
    }
    $statusUrl = CRM_Utils_System::url('civicrm/a/#/status', NULL, TRUE, NULL, FALSE);
    $text = implode("\n\n", [
      E::ts('%1 card payments were declined or refused within %2 minutes on %3. This is what card testing looks like: a bot trying stolen cards.', [1 => $config['site_limit'], 2 => $config['window_minutes'], 3 => CRM_Utils_System::baseURL()]),
      E::ts('Online card payments through PayArc are paused until %1. Donors see a message asking them to try again later; staff can still take payments on back-office forms.', [1 => $when]),
      E::ts('To resume sooner, use "Resume card payments" on the System Status page: %1', [1 => $statusUrl]),
      E::ts('To change the limits: Administer > System Settings > PayArc. If the declines are real donors (for example a busy event), raise the site-wide limit there.'),
    ]);
    $mail = [
      'from' => "\"{$domainName}\" <{$domainEmail}>",
      'toEmail' => $to,
      'subject' => E::ts('[%1] Card payments paused after repeated declines', [1 => $domainName]),
      'text' => $text,
    ];
    try {
      CRM_Utils_Mail::send($mail);
    }
    catch (Throwable $e) {
      Civi::log(E::SHORT_NAME)->error('Velocity alert email not sent: {error}', ['error' => $e->getMessage()]);
    }
  }

  // VelocityStore, on the persistent "long" cache. PSR-16 keys may not
  // contain ':'.

  public function get(string $key): ?array {
    $value = Civi::cache('long')->get($this->key($key));
    return is_array($value) ? $value : NULL;
  }

  public function set(string $key, array $value, int $ttl): void {
    Civi::cache('long')->set($this->key($key), $value, $ttl);
  }

  public function delete(string $key): void {
    Civi::cache('long')->delete($this->key($key));
  }

  private function key(string $key): string {
    return self::PREFIX . preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
  }

}
