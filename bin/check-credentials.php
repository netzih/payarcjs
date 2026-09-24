<?php
/**
 * Check PayArc credentials without charging anything.
 *
 * Against a processor record in an installed CiviCRM (live or test):
 *
 *   cv scr bin/check-credentials.php 1
 *
 * Or standalone, from a file with PAYARC_BEARER_TOKEN, PAYARC_CLIENT_ID and
 * optionally PAYARC_API_URL (defaults to live):
 *
 *   php bin/check-credentials.php ~/.config/payarc/sandbox.env
 *
 * Lists one charge with the bearer token and opens an unused card-field
 * session with the Client ID; neither moves money. The portal answers an
 * unknown Client ID with HTTP 403. Exit status 0 when both pass.
 */

require_once dirname(__DIR__) . '/autoload.php';

use Payarc\GatewayClient;

$argument = $argv[1] ?? $_SERVER['argv'][1] ?? '';
$inCivi = class_exists('Civi');

if ($inCivi && ctype_digit($argument)) {
  $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('name', 'is_test', 'user_name', 'signature', 'url_site')
    ->addWhere('id', '=', (int) $argument)
    ->addWhere('is_test', 'IN', [TRUE, FALSE])
    ->execute()->first();
  if (!$processor) {
    fwrite(STDERR, "No payment processor with id $argument.\n");
    exit(2);
  }
  $label = sprintf('processor %d "%s" (%s)', $argument, $processor['name'], $processor['is_test'] ? 'test' : 'live');
  $bearerToken = (string) $processor['signature'];
  $clientId = (string) $processor['user_name'];
  $baseUrl = trim((string) $processor['url_site']) ?: ($processor['is_test'] ? GatewayClient::SANDBOX_URL : GatewayClient::LIVE_URL);
}
else {
  $file = $argument !== '' ? $argument : (getenv('HOME') . '/.config/payarc/sandbox.env');
  if (!is_readable($file)) {
    fwrite(STDERR, "Usage: cv scr bin/check-credentials.php <processor-id>   or   php bin/check-credentials.php <env-file>\nCannot read $file\n");
    exit(2);
  }
  $env = [];
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
      $env[$m[1]] = trim($m[2], "\"'");
    }
  }
  $label = $file;
  $bearerToken = $env['PAYARC_BEARER_TOKEN'] ?? '';
  $clientId = $env['PAYARC_CLIENT_ID'] ?? '';
  $baseUrl = $env['PAYARC_API_URL'] ?? GatewayClient::LIVE_URL;
}

$isSandbox = str_starts_with((string) parse_url($baseUrl, PHP_URL_HOST), 'test');
echo "Checking $label\n";
echo 'API URL:      ', $baseUrl, $isSandbox ? '  (sandbox)' : '  (LIVE)', "\n";
echo 'Bearer token: ', payarcjs_mask($bearerToken), "\n";
$failed = FALSE;
$client = NULL;

try {
  $client = new GatewayClient($bearerToken, $baseUrl, NULL, 'civicrm-payarcjs-check/0.1');
  $list = $client->verifyCredentials();
  printf("Bearer token: OK (%s charge pages on the account)\n", $list['total_pages'] ?? '?');
}
catch (Throwable $e) {
  $failed = TRUE;
  echo 'Bearer token: FAILED - ', $e->getMessage(), "\n";
}

if (trim($clientId) === '') {
  echo "Client ID:    not set\n";
  $failed = TRUE;
}
else {
  echo 'Client ID:    ', payarcjs_mask($clientId), "\n";
  try {
    ($client ?? new GatewayClient('unused', $baseUrl))->verifyClientId($clientId, $isSandbox ? GatewayClient::SANDBOX_PORTAL : GatewayClient::LIVE_PORTAL);
    echo "Client ID:    OK (card-field session opened, nothing charged)\n";
  }
  catch (Throwable $e) {
    $failed = TRUE;
    echo 'Client ID:    FAILED - ', $e->getMessage(), "\n";
  }
}

exit($failed ? 1 : 0);

function payarcjs_mask(string $value): string {
  $value = trim($value);
  return strlen($value) > 8 ? substr($value, 0, 4) . str_repeat('*', min(20, strlen($value) - 8)) . substr($value, -4) : ($value === '' ? '(empty)' : '****');
}
