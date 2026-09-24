<?php

/**
 * Small, dependency-free extension utility.
 */
class CRM_Payarcjs_ExtensionUtil {

  public const SHORT_NAME = 'payarcjs';

  public const LONG_NAME = 'org.chabadrichmond.payarcjs';

  public static function ts(string $text, array $params = []): string {
    $params['domain'] = self::LONG_NAME;
    return ts($text, $params);
  }

  public static function path(string $path = ''): string {
    return rtrim(dirname(__DIR__, 2), '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
  }

  /**
   * Both callers are the billing block's script and stylesheet, so ask for the
   * cache code: without ?r=... a browser keeps serving the copy it already has
   * and an upgrade's JS fix never reaches returning donors. The code changes
   * on a flush.
   */
  public static function url(string $path = ''): string {
    return Civi::resources()->getUrl(self::LONG_NAME, ltrim($path, '/'), TRUE);
  }

}
