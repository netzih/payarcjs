<?php
/**
 * Check PayArc credentials without charging anything.
 *
 * Standalone, from a file with PAYARC_API_KEY, PAYARC_API_PIN and
 * optionally PAYARC_PAYJS_PUBLIC_KEY and PAYARC_API_URL (defaults to live):
 *
 *   php bin/check-credentials.php ~/.config/payarcjs/live.env
 *
 * Or against a processor record in an installed CiviCRM (live or test):
 *
 *   cv scr bin/check-credentials.php 1
 *
 * Reads the recent-transactions list and mints a throwaway Pay.js payment key;
 * neither moves money. Exit status 0 when everything passes.
 */

$argument = $argv[1] ?? $_SERVER['argv'][1] ?? '';
$inCivi = class_exists('Civi');

if (!$inCivi) {
  foreach (['GatewayException', 'AmbiguousGatewayException', 'GatewayClient'] as $class) {
    require_once __DIR__ . '/../CRM/Payarcjs/' . $class . '.php';
  }
}

if ($inCivi && ctype_digit($argument)) {
  $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('name', 'is_test', 'user_name', 'password', 'signature', 'url_site')
    ->addWhere('id', '=', (int) $argument)
    ->execute()->first();
  if (!$processor) {
    fwrite(STDERR, "No payment processor with id $argument.\n");
    exit(2);
  }
  $label = sprintf('processor %d "%s" (%s)', $argument, $processor['name'], $processor['is_test'] ? 'test' : 'live');
  $apiKey = (string) $processor['user_name'];
  $apiPin = (string) $processor['password'];
  $publicKey = (string) $processor['signature'];
  $baseUrl = (string) $processor['url_site'];
}
else {
  $file = $argument !== '' ? $argument : (getenv('HOME') . '/.config/payarcjs/sandbox.env');
  if (!is_readable($file)) {
    fwrite(STDERR, "Usage: php bin/check-credentials.php <env-file>   or   cv scr bin/check-credentials.php <processor-id>\nCannot read $file\n");
    exit(2);
  }
  $env = [];
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] !== '#' && str_contains($line, '=')) {
      [$k, $v] = explode('=', $line, 2);
      $env[trim($k)] = trim($v, " \t\"'");
    }
  }
  $label = $file;
  $apiKey = $env['PAYARC_API_KEY'] ?? '';
  $apiPin = $env['PAYARC_API_PIN'] ?? '';
  $publicKey = $env['PAYARC_PAYJS_PUBLIC_KEY'] ?? '';
  $baseUrl = $env['PAYARC_API_URL'] ?? 'https://secure.payarc.com/api/v2';
}

$isSandbox = str_contains(parse_url($baseUrl, PHP_URL_HOST) ?: '', 'sandbox');
echo "Checking $label\n";
echo 'API URL:  ', $baseUrl, $isSandbox ? '  (sandbox)' : '  (LIVE)', "\n";
echo 'API key:  ', mask($apiKey), "\n";
$failed = FALSE;

try {
  $client = new CRM_Payarcjs_GatewayClient($apiKey, $apiPin, $baseUrl);
  $list = $client->verifyCredentials();
  $latest = $list['data'][0] ?? NULL;
  echo "API key + PIN: OK";
  if ($latest) {
    echo sprintf(' (latest transaction %s, %s, %s)', $latest['created'] ?? '?', $latest['trantype'] ?? '?', $latest['result'] ?? '?');
  }
  else {
    echo ' (no transactions on this key yet)';
  }
  echo "\n";
}
catch (Throwable $e) {
  $failed = TRUE;
  echo 'API key + PIN: FAILED - ', $e->getMessage(), "\n";
}

if ($publicKey === '') {
  echo "Pay.js public key: not set\n";
  $failed = TRUE;
}
else {
  echo 'Pay.js key: ', mask($publicKey), "\n";
  try {
    (new CRM_Payarcjs_GatewayClient($apiKey !== '' ? $apiKey : 'x', $apiPin !== '' ? $apiPin : 'x', $baseUrl))->verifyPublicKey($publicKey);
    echo "Pay.js public key: OK (throwaway payment key minted, nothing charged)\n";
  }
  catch (Throwable $e) {
    $failed = TRUE;
    echo 'Pay.js public key: FAILED - ', $e->getMessage(), "\n";
  }
}

echo "\nNot checked here: which commands the key allows (Sale, Auth Only, Void, Credit). Those are only exercised by a real transaction.\n";
exit($failed ? 1 : 0);

function mask(string $value): string {
  $value = trim($value);
  return strlen($value) > 8 ? substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4) : ($value === '' ? '(empty)' : '****');
}
