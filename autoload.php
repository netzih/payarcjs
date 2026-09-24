<?php

/**
 * Autoloader for the bundled PayArc client (lib/payarc-php, a git subtree of
 * chabadrichmond/payarc-php). Loaded by payarcjs.php, the unit tests and
 * bin/check-credentials.php.
 *
 * On a WordPress site that also runs the PayArc Payments plugin, both copies
 * register a loader for the Payarc\ namespace and the first one asked wins.
 * Keep the two subtrees on the same library commit.
 */
spl_autoload_register(static function (string $class): void {
  if (!str_starts_with($class, 'Payarc\\')) {
    return;
  }
  $file = __DIR__ . '/lib/payarc-php/src/' . str_replace('\\', '/', substr($class, strlen('Payarc\\'))) . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});
