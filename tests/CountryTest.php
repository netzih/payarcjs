<?php

use PHPUnit\Framework\TestCase;

final class CountryTest extends TestCase {

  public function testAlpha2ToAlpha3(): void {
    self::assertSame('USA', CRM_Payarcjs_Country::alpha3('US'));
    self::assertSame('CAN', CRM_Payarcjs_Country::alpha3('ca'));
    self::assertSame('ISR', CRM_Payarcjs_Country::alpha3(' IL '));
    self::assertSame('GBR', CRM_Payarcjs_Country::alpha3('GB'));
    self::assertSame('CHE', CRM_Payarcjs_Country::alpha3('CH'));
    self::assertSame('ZAF', CRM_Payarcjs_Country::alpha3('ZA'));
  }

  public function testAlpha3PassesThroughOnlyKnownCodes(): void {
    self::assertSame('USA', CRM_Payarcjs_Country::alpha3('usa'));
    self::assertSame('', CRM_Payarcjs_Country::alpha3('XXX'));
  }

  public function testAlpha2FromAlpha3(): void {
    self::assertSame('US', CRM_Payarcjs_Country::alpha2('USA'));
    self::assertSame('IL', CRM_Payarcjs_Country::alpha2('isr'));
    self::assertSame('CA', CRM_Payarcjs_Country::alpha2('CA'));
    self::assertSame('', CRM_Payarcjs_Country::alpha2('XXX'));
  }

  public function testUnknownInputIsOmitted(): void {
    self::assertSame('', CRM_Payarcjs_Country::alpha3(''));
    self::assertSame('', CRM_Payarcjs_Country::alpha3('ZZ'));
    self::assertSame('', CRM_Payarcjs_Country::alpha3('United States'));
  }

}
