<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('PwgTwoFactor')) {
  class PwgTwoFactor
  {
    public static $allowed_methods = array('email', 'sms', 'external_app');
    public static $enabled_methods = array();
    public static $deleted_methods = array();

    private $method;

    public function __construct($method)
    {
      $this->method = $method;
    }

    public static function isEnabled($user_id, $method = null)
    {
      $user_id = (int) $user_id;
      $methods = self::$enabled_methods[$user_id] ?? array();
      if (null === $method) {
        return !empty($methods);
      }

      return in_array($method, $methods, true);
    }

    public function deleteSecret($user_id = null)
    {
      $user_id = (int) $user_id;
      self::$deleted_methods[] = array($user_id, $this->method);
      if (!isset(self::$enabled_methods[$user_id])) {
        return true;
      }

      self::$enabled_methods[$user_id] = array_values(array_filter(
        self::$enabled_methods[$user_id],
        function ($method) {
          return $method !== $this->method;
        }
      ));

      return true;
    }
  }
}

if (!function_exists('cpt_count_albums_owned_by')) {
  function cpt_count_albums_owned_by($user_id)
  {
    return $GLOBALS['tf_test_owned_album_count'][$user_id] ?? 0;
  }
}

if (!function_exists('cpt_fetch_albums_owned_by')) {
  function cpt_fetch_albums_owned_by($user_id)
  {
    return $GLOBALS['tf_test_owned_albums'][$user_id] ?? array();
  }
}

if (!function_exists('pwg_query')) {
  function pwg_query($query)
  {
    $GLOBALS['tf_test_last_query'] = $query;
    $GLOBALS['tf_test_query_history'][] = $query;
    return $query;
  }
}

if (!function_exists('pwg_db_fetch_row')) {
  function pwg_db_fetch_row($result)
  {
    if (array_key_exists('tf_test_fetch_row_result', $GLOBALS) && null !== $GLOBALS['tf_test_fetch_row_result']) {
      return $GLOBALS['tf_test_fetch_row_result'];
    }

    return array($GLOBALS['tf_test_image_membership_count'] ?? 0);
  }
}

if (!function_exists('pwg_db_fetch_assoc')) {
  function pwg_db_fetch_assoc($result)
  {
    return $GLOBALS['tf_test_fetch_assoc_result'] ?? null;
  }
}

if (!function_exists('pwg_db_real_escape_string')) {
  function pwg_db_real_escape_string($value)
  {
    return (string) $value;
  }
}

if (!function_exists('is_webmaster')) {
  function is_webmaster()
  {
    return !empty($GLOBALS['tf_test_is_webmaster']);
  }
}

if (!function_exists('is_admin')) {
  function is_admin()
  {
    return !empty($GLOBALS['tf_test_is_admin']);
  }
}

if (!function_exists('profile_liveness_guard_is_eligible_user')) {
  function profile_liveness_guard_is_eligible_user($user_id)
  {
    return !empty($GLOBALS['tf_test_plg_is_eligible'][$user_id]);
  }
}

if (!function_exists('profile_liveness_guard_get_record')) {
  function profile_liveness_guard_get_record($user_id, $root_category_id = null)
  {
    return $GLOBALS['tf_test_plg_records'][$user_id] ?? null;
  }
}

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

    global $user;
    $user = array('id' => 7);

    PwgTwoFactor::$enabled_methods = array();
    PwgTwoFactor::$deleted_methods = array();
    $GLOBALS['tf_test_owned_album_count'] = array();
    $GLOBALS['tf_test_owned_albums'] = array();
    $GLOBALS['tf_test_image_membership_count'] = 0;
    $GLOBALS['tf_test_last_query'] = null;
    $GLOBALS['tf_test_query_history'] = array();
    $GLOBALS['tf_test_fetch_row_result'] = null;
    $GLOBALS['tf_test_fetch_assoc_result'] = null;
    $GLOBALS['tf_test_is_webmaster'] = false;
    $GLOBALS['tf_test_is_admin'] = false;
    $GLOBALS['tf_test_plg_is_eligible'] = array();
    $GLOBALS['tf_test_plg_records'] = array();
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
    $this->assertTrue($normalized['sms']['use_cpt_profile_phone']);
    $this->assertFalse($normalized['sms']['allow_manual_sms_phone']);
    $this->assertSame('contact_number', $normalized['sms']['profile_contact_field']);
    $this->assertFalse($normalized['sms']['require_contact_sms_enabled']);
  }

  public function testSmsSetupPhoneCandidateFailsClosedWithoutCptProfilePhone(): void
  {
    $candidate = tf_get_sms_setup_phone_candidate(7);

    $this->assertFalse($candidate['available']);
    $this->assertNull($candidate['normalized_phone']);
    $this->assertSame('Please add a valid contact phone number in My Profile first.', $candidate['error']);
  }

  public function testSmsSetupPhoneCandidateReturnsNoSourceWhenCptModeDisabled(): void
  {
    global $conf;
    $conf['two_factor']['sms']['use_cpt_profile_phone'] = false;

    $candidate = tf_get_sms_setup_phone_candidate(7);

    $this->assertFalse($candidate['available']);
    $this->assertNull($candidate['error']);
  }

  public function testSmsPhoneNeedsReverifyReturnsFalseForInvalidVerifiedPhone(): void
  {
    $this->assertFalse(tf_sms_phone_needs_reverify(7, 'bad-phone'));
  }

  public function testSmsPhoneNeedsReverifyReturnsFalseWhenCptCandidateUnavailable(): void
  {
    $this->assertFalse(tf_sms_phone_needs_reverify(7, '+421905000000'));
  }

  public function testSmsPhoneNeedsReverifyReturnsTrueWhenCptPhoneDiffers(): void
  {
    global $conf;
    $conf['two_factor']['sms']['use_cpt_profile_phone'] = true;

    if (!function_exists('cpt_owner_profile_table_exists')) {
      function cpt_owner_profile_table_exists() {
        return true;
      }
    }

    if (!function_exists('cpt_get_effective_owner_root_album_id_for_user')) {
      function cpt_get_effective_owner_root_album_id_for_user($user_id) {
        return 10;
      }
    }

    if (!function_exists('cpt_fetch_owner_profile_rows')) {
      function cpt_fetch_owner_profile_rows($root_album_id, $owner_user_id) {
        return array(
          'contact_number' => array('value_text' => '+421905111111', 'tag_id' => null),
          'contact_sms' => array('value_text' => null, 'tag_id' => 1),
        );
      }
    }

    $this->assertTrue(tf_sms_phone_needs_reverify(7, '+421905000000'));
    $this->assertFalse(tf_sms_phone_needs_reverify(7, '+421905111111'));
  }

  public function testCountEnabledTwoFactorMethodsCountsConfiguredMethods(): void
  {
    PwgTwoFactor::$enabled_methods[7] = array('email', 'sms');

    $this->assertSame(2, tf_count_enabled_two_factor_methods(7));
  }

  public function testGetVerifiedSmsPhoneReturnsStoredPhoneNumber(): void
  {
    $GLOBALS['tf_test_fetch_assoc_result'] = array(
      'phone_number' => '+421905000000',
    );

    $this->assertSame('+421905000000', tf_get_verified_sms_phone(7));
    $this->assertStringContainsString("SELECT phone_number", (string) $GLOBALS['tf_test_last_query']);
    $this->assertStringContainsString("user_id = 7", (string) $GLOBALS['tf_test_last_query']);
  }

  public function testGetVerifiedSmsPhoneReturnsNullWhenMissing(): void
  {
    $GLOBALS['tf_test_fetch_assoc_result'] = null;

    $this->assertNull(tf_get_verified_sms_phone(7));
    $this->assertNull(tf_get_verified_sms_phone(0));
  }

  public function testSmsLoginEnrollmentHelperChecksEnabledAt(): void
  {
    $GLOBALS['tf_test_fetch_row_result'] = array(1);

    $this->assertTrue(tf_is_sms_login_enrollment_enabled(7));
    $this->assertStringContainsString('enabled_at IS NOT NULL', (string) $GLOBALS['tf_test_last_query']);
  }

  public function testDisableSmsLoginEnrollmentKeepsVerifiedPhoneFoundation(): void
  {
    $GLOBALS['tf_test_fetch_row_result'] = array(0);

    $this->assertTrue(tf_disable_sms_login_enrollment(7));
    $this->assertCount(2, $GLOBALS['tf_test_query_history']);
    $this->assertStringContainsString('SET enabled_at = NULL', (string) $GLOBALS['tf_test_query_history'][0]);
    $this->assertStringContainsString('enabled_at IS NOT NULL', (string) $GLOBALS['tf_test_query_history'][1]);
  }

  public function testSmsLoginChallengeIsRequiredWithoutPlgManagement(): void
  {
    $GLOBALS['tf_test_fetch_row_result'] = array(1);

    $this->assertTrue(tf_is_sms_login_challenge_required(7));
  }

  public function testSmsLoginChallengeIsSuppressedWhenPlgManagesUser(): void
  {
    $GLOBALS['tf_test_fetch_row_result'] = array(1);
    $GLOBALS['tf_test_plg_is_eligible'][7] = true;
    $GLOBALS['tf_test_plg_records'][7] = array(
      'status' => 'verified',
    );

    $this->assertFalse(tf_is_sms_login_challenge_required(7));
  }

  public function testAlbumOwnerRequirementIgnoresOwnedAlbumsWithoutImages(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 0;

    $this->assertFalse(tf_is_album_owner_two_factor_required(7));
  }

  public function testAlbumOwnerRequirementAppliesWhenOwnedAlbumsContainImages(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
      array('id' => 1023),
    );
    $GLOBALS['tf_test_image_membership_count'] = 3;

    $this->assertTrue(tf_is_album_owner_two_factor_required(7));
    $this->assertStringContainsString('category_id IN (1022,1023)', (string) $GLOBALS['tf_test_last_query']);
  }

  public function testAlbumOwnerRequirementIsSuppressedWhenPlgManagesUser(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 1;
    $GLOBALS['tf_test_plg_is_eligible'][7] = true;
    $GLOBALS['tf_test_plg_records'][7] = array(
      'status' => 'verified',
    );

    $this->assertFalse(tf_is_album_owner_two_factor_required(7));
  }

  public function testAlbumOwnerRequirementStillAppliesBeforePlgVerificationCompletes(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 1;
    $GLOBALS['tf_test_plg_is_eligible'][7] = true;
    $GLOBALS['tf_test_plg_records'][7] = array(
      'status' => 'not_started',
    );

    $this->assertTrue(tf_is_album_owner_two_factor_required(7));
  }

  public function testAlbumOwnerPolicyRequiresSetupWithoutEnabledMethod(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 1;

    $policy = tf_sync_album_owner_two_factor_policy(7);

    $this->assertTrue($policy['required']);
    $this->assertTrue($policy['requires_setup']);
    $this->assertSame(7, $_SESSION[TF_SESSION_SETUP_REQUIRED]);
  }

  public function testAlbumOwnerPolicyClearsSetupFlagWhenMethodEnabled(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 1;
    PwgTwoFactor::$enabled_methods[7] = array('sms');
    $_SESSION[TF_SESSION_SETUP_REQUIRED] = 7;

    $policy = tf_sync_album_owner_two_factor_policy(7);

    $this->assertTrue($policy['required']);
    $this->assertFalse($policy['requires_setup']);
    $this->assertArrayNotHasKey(TF_SESSION_SETUP_REQUIRED, $_SESSION);
  }

  public function testAlbumOwnerPolicyDeletesMethodsAfterAlbumOwnershipEnds(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array();
    $GLOBALS['tf_test_image_membership_count'] = 0;
    PwgTwoFactor::$enabled_methods[7] = array('email', 'sms');
    $_SESSION[TF_SESSION_SETUP_REQUIRED] = 7;

    $policy = tf_sync_album_owner_two_factor_policy(7);

    $this->assertFalse($policy['required']);
    $this->assertFalse($policy['has_enabled']);
    $this->assertSame(array(array(7, 'email'), array(7, 'sms')), PwgTwoFactor::$deleted_methods);
    $this->assertArrayNotHasKey(TF_SESSION_SETUP_REQUIRED, $_SESSION);
  }

  public function testAlbumOwnerPolicyDoesNotDeleteMethodsWhenPlgManagesUser(): void
  {
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 1;
    $GLOBALS['tf_test_plg_is_eligible'][7] = true;
    $GLOBALS['tf_test_plg_records'][7] = array(
      'status' => 'verified',
    );
    PwgTwoFactor::$enabled_methods[7] = array('email');
    $_SESSION[TF_SESSION_SETUP_REQUIRED] = 7;

    $policy = tf_sync_album_owner_two_factor_policy(7);

    $this->assertFalse($policy['required']);
    $this->assertTrue($policy['has_enabled']);
    $this->assertFalse($policy['requires_setup']);
    $this->assertSame(array(), PwgTwoFactor::$deleted_methods);
    $this->assertArrayNotHasKey(TF_SESSION_SETUP_REQUIRED, $_SESSION);
  }

  public function testAlbumOwnerPolicyExemptsAdmins(): void
  {
    global $user;

    $user['id'] = 7;
    $GLOBALS['tf_test_owned_albums'][7] = array(
      array('id' => 1022),
    );
    $GLOBALS['tf_test_image_membership_count'] = 1;
    $GLOBALS['tf_test_is_admin'] = true;
    PwgTwoFactor::$enabled_methods[7] = array('email');

    $policy = tf_sync_album_owner_two_factor_policy(7);

    $this->assertFalse($policy['required']);
    $this->assertTrue($policy['has_enabled']);
    $this->assertSame(array(), PwgTwoFactor::$deleted_methods);
  }
}