<?php

use PHPUnit\Framework\TestCase;

class SmsHelpersTest extends TestCase
{
  protected function setUp(): void
  {
    $_SESSION = array();

    global $conf;
    $conf = array(
      'gallery_title' => 'Test Gallery',
      'two_factor' => tf_get_default_conf(),
    );
  }

  public function testNormalizePhoneNumberAcceptsE164AndFormattedInput(): void
  {
    $this->assertSame('+421905000000', tf_normalize_phone_number('+421 905 000 000'));
    $this->assertSame('+421905000000', tf_normalize_phone_number('00421905000000'));
    $this->assertSame('421905000000', tf_normalize_phone_number('421-905-000-000'));
  }

  public function testNormalizePhoneNumberRejectsMalformedValues(): void
  {
    $this->assertFalse(tf_normalize_phone_number('not-a-phone'));
    $this->assertFalse(tf_normalize_phone_number('+42190abc'));
    $this->assertFalse(tf_normalize_phone_number('1234'));
  }

  public function testMaskPhoneNumberPreservesEndsOnly(): void
  {
    $this->assertSame('+42*******000', tf_mask_phone_number('+421905000000'));
    $this->assertSame('****', tf_mask_phone_number('1234'));
  }

  public function testSmsConfigAccessorsHonorConfiguredBounds(): void
  {
    global $conf;

    $conf['two_factor']['sms']['code_ttl'] = 180;
    $conf['two_factor']['sms']['resend_delay'] = 25;
    $this->assertSame(180, tf_get_sms_code_ttl());
    $this->assertSame(25, tf_get_sms_resend_delay());

    $conf['two_factor']['sms']['code_ttl'] = 0;
    $conf['two_factor']['sms']['resend_delay'] = -10;
    $this->assertSame(1, tf_get_sms_code_ttl());
    $this->assertSame(0, tf_get_sms_resend_delay());
  }

  public function testGenerateSmsMessageIncludesPurposeCodeAndGalleryTitle(): void
  {
    global $conf;
    $conf['two_factor']['sms']['code_ttl'] = 121;

    $setupMessage = tf_generate_sms_message('123456', true);
    $loginMessage = tf_generate_sms_message('654321', false);

    $this->assertSame('SMS setup code for Test Gallery: 123456. It expires in 3 minutes.', $setupMessage);
    $this->assertSame('SMS login code for Test Gallery: 654321. It expires in 3 minutes.', $loginMessage);
  }

  public function testRateLimitReturnsRemainingTimeInsideWindow(): void
  {
    $_SESSION['tf_test_rate_limit'] = 100;

    $this->assertSame(40, tf_rate_limit(120, 'tf_test_rate_limit', 60));
    $this->assertTrue(tf_rate_limit(170, 'tf_test_rate_limit', 60));
    $this->assertIsInt($_SESSION['tf_test_rate_limit']);
    $this->assertGreaterThanOrEqual(time() - 1, $_SESSION['tf_test_rate_limit']);
    $this->assertLessThanOrEqual(time(), $_SESSION['tf_test_rate_limit']);
  }

  public function testSendSmsFailsClosedWhenConfigurationIsIncomplete(): void
  {
    global $conf;
    $conf['two_factor']['sms'] = array(
      'enabled' => true,
      'base_url' => 'https://api.smstools.sk',
      'api_key' => '',
      'sender_text' => '',
      'code_ttl' => 600,
      'resend_delay' => 60,
      'debug' => false,
    );

    $result = tf_send_sms_message('+421905000000', '123456', true, 7);

    $this->assertFalse($result['success']);
    $this->assertSame('SMS configuration is incomplete.', $result['message']);
  }

  public function testNormalizeConfAddsSmsDefaults(): void
  {
    $normalized = tf_normalize_conf(array(
      'sms' => array(
        'enabled' => true,
        'sender_text' => 'PIWIGO',
      ),
    ));

    $this->assertTrue($normalized['sms']['enabled']);
    $this->assertSame('PIWIGO', $normalized['sms']['sender_text']);
    $this->assertSame('https://api.smstools.sk', $normalized['sms']['base_url']);
    $this->assertSame(600, $normalized['sms']['code_ttl']);
    $this->assertSame(60, $normalized['sms']['resend_delay']);
    $this->assertFalse($normalized['sms']['debug']);
  }
}