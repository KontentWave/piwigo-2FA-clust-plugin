<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

/**
 * `Two Factor` : redirect to two factor authentication
 */
function tf_redirect()
{
  $redirect = get_root_url().'identification.php?tf';
  if ('identification.php' !== basename($_SERVER['SCRIPT_NAME']))
  {
    //redirect_html($redirect);
    redirect($redirect);
  }
}

/**
 * `Two Factor` : clean two factor session
 */
function tf_clean_login()
{
  unset(
    $_SESSION[TF_SESSION_VALIDATED],
    $_SESSION[TF_SESSION_TRIES_LEFT]
  );
  pwg_unset_session_var(TF_SESSION_MAIL_CODE);
  pwg_unset_session_var(TF_SESSION_MAIL_SENT_AT);
  pwg_unset_session_var(TF_SESSION_SMS_CODE);
  pwg_unset_session_var(TF_SESSION_SMS_SENT_AT);
  pwg_unset_session_var(TF_SESSION_SMS_PHONE_NUMBER);

  pwg_unset_session_var(TF_SESSION_TMP_RECOVERY_CODES);
  pwg_unset_session_var(TF_SESSION_MAIL_SETUP_RATE_LIMIT);
  pwg_unset_session_var(TF_SESSION_MAIL_VERIFY_RATE_LIMIT);
  pwg_unset_session_var(TF_SESSION_SMS_SETUP_RATE_LIMIT);
  pwg_unset_session_var(TF_SESSION_SMS_VERIFY_RATE_LIMIT);
}

/**
 * `Two Factor` : Force logout
 */
function tf_force_logout($lockout_duration = null) {
  tf_clean_login();
  logout_user();
  if (isset($lockout_duration['expires_in']))
  {
    $wait = '0s';
    if ($lockout_duration['expires_in']->i > 0)
    {
      $wait = $lockout_duration['expires_in']->i . '-m';
    }
    else
    {
      $wait = $lockout_duration['expires_in']->s . '-s';
    }
    redirect(get_root_url().'identification.php?tf_lockout='.$wait);
  }
  else
  {
    redirect(get_root_url().'identification.php?tf_login_error');
  }
  exit;
}

/**
 * `Two Factor` : Login and redirect to home
 */
function tf_login_and_redirect()
{
  global $user;
  if ($user['tf_lockout_duration'])
  {
    single_update(
      USER_INFOS_TABLE,
      array('tf_lockout_duration' => null),
      array('user_id' => $user['id'])
    );
  }
  tf_clean_login();
  redirect(get_gallery_home_url());
  exit;
}

/**
 * `Two Factor` : Mail rate limit per $_SESSION 
 */
function tf_mail_rate_limit($time, $session_key)
{
  return tf_rate_limit($time, $session_key, 60);
}

/**
 * `Two Factor` : generic rate limit per $_SESSION
 */
function tf_rate_limit($time, $session_key, $window)
{
  if (!isset($_SESSION[$session_key]))
  {
    $_SESSION[$session_key] = time();
  }
  else
  {
    $time_diff = $time - $_SESSION[$session_key];
    if ($time_diff <= $window)
    {
      return $window - $time_diff;
    }
    $_SESSION[$session_key] = time();
  }
  return true;
}

/**
 * `Two Factor` : normalize config with defaults
 */
function tf_normalize_conf($config)
{
  return array_replace_recursive(tf_get_default_conf(), is_array($config) ? $config : array());
}

/**
 * `Two Factor` : whether SMS setup should use CPT profile phone
 */
function tf_use_cpt_profile_phone()
{
  global $conf;

  $sms_config = $conf['two_factor']['sms'] ?? array();
  if (!array_key_exists('use_cpt_profile_phone', $sms_config))
  {
    return true;
  }

  return (bool) $sms_config['use_cpt_profile_phone'];
}

/**
 * `Two Factor` : whether manual SMS phone entry is allowed
 */
function tf_allow_manual_sms_phone()
{
  global $conf;

  return !empty($conf['two_factor']['sms']['allow_manual_sms_phone']);
}

/**
 * `Two Factor` : source field key for CPT contact phone
 */
function tf_get_cpt_profile_contact_field_key()
{
  global $conf;

  $field_key = trim((string) ($conf['two_factor']['sms']['profile_contact_field'] ?? 'contact_number'));
  return '' === $field_key ? 'contact_number' : $field_key;
}

/**
 * `Two Factor` : whether CPT contact SMS flag is required for setup
 */
function tf_require_cpt_contact_sms_enabled()
{
  global $conf;

  return !empty($conf['two_factor']['sms']['require_contact_sms_enabled']);
}

/**
 * `Two Factor` : whether CPT owner profile storage can be read
 */
function tf_cpt_profile_available()
{
  if (function_exists('cpt_owner_profile_table_exists'))
  {
    return cpt_owner_profile_table_exists();
  }

  if (!function_exists('pwg_query') || !function_exists('pwg_db_real_escape_string'))
  {
    return false;
  }

  global $prefixeTable;
  $table = defined('CPT_OWNER_PROFILE_TABLE') ? CPT_OWNER_PROFILE_TABLE : $prefixeTable . 'cpt_owner_profile';
  $result = pwg_query("SHOW TABLES LIKE '".pwg_db_real_escape_string($table)."'");

  return (bool) ($result && function_exists('pwg_db_fetch_row') && pwg_db_fetch_row($result));
}

/**
 * `Two Factor` : fetch CPT owner profile contact rows for a user
 */
function tf_get_cpt_owner_profile_contact_rows($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0 || !tf_cpt_profile_available())
  {
    return array();
  }

  $field_keys = array('contact_number', 'contact_phone', 'contact_sms', 'contact_whatsapp');

  if (
    function_exists('cpt_get_effective_owner_root_album_id_for_user')
    && function_exists('cpt_fetch_owner_profile_rows')
  ) {
    $root_album_id = cpt_get_effective_owner_root_album_id_for_user($user_id);
    if (null !== $root_album_id)
    {
      $rows = cpt_fetch_owner_profile_rows((int) $root_album_id, $user_id);
      return array_intersect_key($rows, array_flip($field_keys));
    }
  }

  if (!function_exists('pwg_query') || !function_exists('pwg_db_fetch_assoc'))
  {
    return array();
  }

  global $prefixeTable;
  $table = defined('CPT_OWNER_PROFILE_TABLE') ? CPT_OWNER_PROFILE_TABLE : $prefixeTable . 'cpt_owner_profile';
  $query = '
SELECT field_key, value_text, tag_id
  FROM '. $table .'
WHERE owner_user_id = '. $user_id .'
  AND field_key IN (\'contact_number\', \'contact_phone\', \'contact_sms\', \'contact_whatsapp\')
ORDER BY updated_at DESC
;';

  $result = pwg_query($query);
  if (!$result)
  {
    return array();
  }

  $rows = array();
  while ($row = pwg_db_fetch_assoc($result))
  {
    $field_key = (string) ($row['field_key'] ?? '');
    if ('' === $field_key || isset($rows[$field_key]))
    {
      continue;
    }

    $rows[$field_key] = array(
      'field_key' => $field_key,
      'value_text' => isset($row['value_text']) ? (string) $row['value_text'] : null,
      'tag_id' => isset($row['tag_id']) && '' !== (string) $row['tag_id'] ? (int) $row['tag_id'] : null,
    );
  }

  return $rows;
}

/**
 * `Two Factor` : read raw CPT contact phone
 */
function tf_get_cpt_profile_contact_number($user_id)
{
  $rows = tf_get_cpt_owner_profile_contact_rows($user_id);
  $field_key = tf_get_cpt_profile_contact_field_key();
  $value = trim((string) ($rows[$field_key]['value_text'] ?? ''));

  return '' === $value ? null : $value;
}

/**
 * `Two Factor` : convert controlled CPT contact flag to bool
 */
function tf_get_cpt_contact_flag_value($row)
{
  if (!is_array($row) || empty($row))
  {
    return null;
  }

  if (isset($row['tag_id']) && null !== $row['tag_id'])
  {
    if (1 === (int) $row['tag_id'])
    {
      return true;
    }

    if (2 === (int) $row['tag_id'])
    {
      return false;
    }
  }

  $value = strtolower(trim((string) ($row['value_text'] ?? '')));
  if (in_array($value, array('1', 'yes', 'true'), true))
  {
    return true;
  }

  if (in_array($value, array('0', 'no', 'false'), true))
  {
    return false;
  }

  return null;
}

/**
 * `Two Factor` : CPT contact channel flags
 */
function tf_get_cpt_profile_contact_flags($user_id)
{
  $rows = tf_get_cpt_owner_profile_contact_rows($user_id);

  return array(
    'contact_phone' => tf_get_cpt_contact_flag_value($rows['contact_phone'] ?? null),
    'contact_sms' => tf_get_cpt_contact_flag_value($rows['contact_sms'] ?? null),
    'contact_whatsapp' => tf_get_cpt_contact_flag_value($rows['contact_whatsapp'] ?? null),
  );
}

/**
 * `Two Factor` : derive the SMS setup phone candidate from CPT
 */
function tf_get_sms_setup_phone_candidate($user_id)
{
  $flags = tf_get_cpt_profile_contact_flags($user_id);
  $candidate = array(
    'available' => false,
    'raw_phone' => null,
    'normalized_phone' => null,
    'masked_phone' => null,
    'source' => null,
    'flags' => $flags,
    'error' => null,
  );

  if (!tf_use_cpt_profile_phone())
  {
    return $candidate;
  }

  $raw_phone = tf_get_cpt_profile_contact_number($user_id);
  if (empty($raw_phone))
  {
    $candidate['error'] = l10n('Please add a valid contact phone number in My Profile first.');
    return $candidate;
  }

  $normalized_phone = tf_normalize_phone_number($raw_phone);
  if (!$normalized_phone)
  {
    $candidate['error'] = l10n('Please add a valid contact phone number in My Profile first.');
    return $candidate;
  }

  if (tf_require_cpt_contact_sms_enabled() && false === ($flags['contact_sms'] ?? null))
  {
    $candidate['error'] = l10n('Please enable SMS contact in My Profile first.');
    return $candidate;
  }

  $candidate['available'] = true;
  $candidate['raw_phone'] = $raw_phone;
  $candidate['normalized_phone'] = $normalized_phone;
  $candidate['masked_phone'] = tf_mask_phone_number($normalized_phone);
  $candidate['source'] = 'cpt_owner_profile.' . tf_get_cpt_profile_contact_field_key();

  return $candidate;
}

/**
 * `Two Factor` : whether the verified SMS phone differs from the CPT phone
 */
function tf_sms_phone_needs_reverify($user_id, $verified_phone)
{
  $verified_phone = tf_normalize_phone_number($verified_phone);
  if (!$verified_phone)
  {
    return false;
  }

  $candidate = tf_get_sms_setup_phone_candidate($user_id);
  if (empty($candidate['available']) || empty($candidate['normalized_phone']))
  {
    return false;
  }

  return $candidate['normalized_phone'] !== $verified_phone;
}

/**
 * `Two Factor` : profile URL
 */
function tf_get_profile_url()
{
  return get_root_url() . 'profile.php';
}

/**
 * `Two Factor` : whether current user is exempt from album-owner 2FA policy
 */
function tf_is_two_factor_policy_exempt_user($user_id)
{
  global $user;

  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return true;
  }

  if (!empty($user['id']) && (int) $user['id'] === $user_id)
  {
    if (function_exists('is_webmaster') && is_webmaster())
    {
      return true;
    }

    if (function_exists('is_admin') && is_admin())
    {
      return true;
    }
  }

  return false;
}

/**
 * `Two Factor` : whether any owned album currently contains images
 */
function tf_user_owns_albums_with_images($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0 || !function_exists('cpt_fetch_albums_owned_by'))
  {
    return false;
  }

  $owned_albums = cpt_fetch_albums_owned_by($user_id);
  if (empty($owned_albums) || !function_exists('pwg_query') || !function_exists('pwg_db_fetch_row'))
  {
    return false;
  }

  $album_ids = array();
  foreach ($owned_albums as $album)
  {
    $album_id = (int) ($album['id'] ?? 0);
    if ($album_id > 0)
    {
      $album_ids[$album_id] = $album_id;
    }
  }

  if (empty($album_ids))
  {
    return false;
  }

  $query = '
SELECT COUNT(*)
  FROM '.IMAGE_CATEGORY_TABLE.'
WHERE category_id IN ('.implode(',', $album_ids).')
;';
  $result = pwg_query($query);
  if (!$result)
  {
    return false;
  }

  list($count) = pwg_db_fetch_row($result);
  return (int) $count > 0;
}

/**
 * `Two Factor` : whether PLG is already managing periodic verification for this user
 */
function tf_is_profile_liveness_guard_managing_user($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return false;
  }

  if (!function_exists('profile_liveness_guard_get_record') || !function_exists('profile_liveness_guard_is_eligible_user'))
  {
    $functions_file = PHPWG_ROOT_PATH . 'plugins/profile_liveness_guard/include/functions.inc.php';
    if (defined('PROFILE_LIVENESS_GUARD_PATH') && file_exists($functions_file))
    {
      include_once($functions_file);
    }
  }

  if (!function_exists('profile_liveness_guard_get_record') || !function_exists('profile_liveness_guard_is_eligible_user'))
  {
    return false;
  }

  if (!profile_liveness_guard_is_eligible_user($user_id))
  {
    return false;
  }

  $record = profile_liveness_guard_get_record($user_id);
  if (!is_array($record))
  {
    return false;
  }

  $status = (string) ($record['status'] ?? '');
  return in_array($status, array('verified', 'sms_sent', 'albums_privatized', 'awaiting_admin_restore'), true);
}

/**
 * `Two Factor` : whether album ownership makes 2FA mandatory for this user
 */
function tf_is_album_owner_two_factor_required($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0 || tf_is_two_factor_policy_exempt_user($user_id))
  {
    return false;
  }

  if (!function_exists('cpt_fetch_albums_owned_by'))
  {
    return false;
  }

  if (tf_is_profile_liveness_guard_managing_user($user_id))
  {
    return false;
  }

  return tf_user_owns_albums_with_images($user_id);
}

/**
 * `Two Factor` : whether user has any configured 2FA method
 */
function tf_user_has_enabled_two_factor($user_id)
{
  foreach (PwgTwoFactor::$allowed_methods as $method)
  {
    if (PwgTwoFactor::isEnabled($user_id, $method))
    {
      return true;
    }
  }

  return false;
}

/**
 * `Two Factor` : count configured 2FA methods for a user
 */
function tf_count_enabled_two_factor_methods($user_id)
{
  $count = 0;

  foreach (PwgTwoFactor::$allowed_methods as $method)
  {
    if (PwgTwoFactor::isEnabled($user_id, $method))
    {
      $count++;
    }
  }

  return $count;
}

/**
 * `Two Factor` : delete all configured 2FA methods for a user
 */
function tf_delete_all_user_two_factor_methods($user_id)
{
  foreach (PwgTwoFactor::$allowed_methods as $method)
  {
    if (PwgTwoFactor::isEnabled($user_id, $method))
    {
      (new PwgTwoFactor($method))->deleteSecret($user_id);
    }
  }
}

/**
 * `Two Factor` : synchronize album-owner policy with current user state
 */
function tf_sync_album_owner_two_factor_policy($user_id)
{
  $user_id = (int) $user_id;
  $managed_by_plg = tf_is_profile_liveness_guard_managing_user($user_id);
  $required = tf_is_album_owner_two_factor_required($user_id);
  $has_enabled = $user_id > 0 ? tf_user_has_enabled_two_factor($user_id) : false;

  if (!$required)
  {
    unset($_SESSION[TF_SESSION_SETUP_REQUIRED]);
    if (!$managed_by_plg && $has_enabled && !tf_is_two_factor_policy_exempt_user($user_id))
    {
      tf_delete_all_user_two_factor_methods($user_id);
      $has_enabled = false;
    }

    return array(
      'required' => false,
      'has_enabled' => $has_enabled,
      'requires_setup' => false,
    );
  }

  if (!$has_enabled)
  {
    $_SESSION[TF_SESSION_SETUP_REQUIRED] = $user_id;
  }
  else if (isset($_SESSION[TF_SESSION_SETUP_REQUIRED]) && (int) $_SESSION[TF_SESSION_SETUP_REQUIRED] === $user_id)
  {
    unset($_SESSION[TF_SESSION_SETUP_REQUIRED]);
  }

  return array(
    'required' => true,
    'has_enabled' => $has_enabled,
    'requires_setup' => !$has_enabled,
  );
}

/**
 * `Two Factor` : ensure SMS schema additions exist on upgraded installs
 */
function tf_ensure_sms_schema()
{
  static $checked = false;

  if ($checked)
  {
    return true;
  }

  $checked = true;

  $result = pwg_query('SHOW COLUMNS FROM `'.TF_TABLE.'` LIKE "phone_number";');
  if (!pwg_db_num_rows($result))
  {
    pwg_query('ALTER TABLE `'.TF_TABLE.'` ADD COLUMN `phone_number` VARCHAR(32) DEFAULT NULL AFTER `method`;');
  }

  return true;
}

/**
 * `Two Factor` : get template mail
 */
function tf_generate_mail_template($username, $code, $setup = false)
{
  $message = '<p style="margin: 20px 0">';
  $message .= l10n('Hello %s,', $username).'</p>';
  if ($setup)
  {
    $message .= '<p style="margin: 20px 0">'.l10n('You are setting up two-factor authentication for your account.').'</p>';
  }
  $message .= '<p style="margin: 20px 0">'.l10n('Your verification code is: %s', $code).'</p>';
  $message .= '<p style="margin: 20px 0">'.l10n('This code will expire in a few minutes for security reasons.').'</p>';
  if ($setup)
  {
    $message .= '<p style="margin: 20px 0;">'.l10n('If you did not request this setup, please contact your administrator immediately.').'</p>'; 
  }
  
  return $message;
}

/**
 * `Two Factor` : SMS code validity in seconds
 */
function tf_get_sms_code_ttl()
{
  global $conf;

  return max(1, intval($conf['two_factor']['sms']['code_ttl'] ?? 600));
}

/**
 * `Two Factor` : SMS resend delay in seconds
 */
function tf_get_sms_resend_delay()
{
  global $conf;

  return max(0, intval($conf['two_factor']['sms']['resend_delay'] ?? 60));
}

/**
 * `Two Factor` : normalize phone number for provider submission
 */
function tf_normalize_phone_number($phone_number)
{
  $phone_number = trim((string) $phone_number);
  $phone_number = preg_replace('/[\s\-().]/', '', $phone_number);

  if (preg_match('/^00\d+$/', $phone_number))
  {
    $phone_number = '+' . substr($phone_number, 2);
  }

  if (preg_match('/^\+\d{8,15}$/', $phone_number))
  {
    return $phone_number;
  }

  if (preg_match('/^\d{9,15}$/', $phone_number))
  {
    return $phone_number;
  }

  return false;
}

/**
 * `Two Factor` : get SMS phone owner, excluding an optional user
 */
function tf_get_sms_phone_owner($phone_number, $exclude_user_id = null)
{
  $phone_number = tf_normalize_phone_number($phone_number);
  if (!$phone_number)
  {
    return null;
  }

  tf_ensure_sms_schema();

  $query = '
SELECT user_id
  FROM '.TF_TABLE.'
WHERE method = \'sms\'
  AND phone_number = \''.pwg_db_real_escape_string($phone_number).'\'';

  if (null !== $exclude_user_id)
  {
    $query .= '
  AND user_id != '.pwg_db_real_escape_string($exclude_user_id);
  }

  $query .= '
LIMIT 1
;';

  $result = pwg_db_fetch_assoc(pwg_query($query));
  if ($result && isset($result['user_id']))
  {
    return (int) $result['user_id'];
  }

  return null;
}

/**
 * `Two Factor` : get the verified stored SMS phone number for a user
 */
function tf_get_verified_sms_phone($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return null;
  }

  if (function_exists('pwg_db_num_rows'))
  {
    tf_ensure_sms_schema();
  }

  $result = pwg_db_fetch_assoc(pwg_query('
SELECT phone_number
  FROM '.TF_TABLE.'
WHERE user_id = '.pwg_db_real_escape_string($user_id).'
  AND method = \'sms\'
LIMIT 1
;'));

  if (!$result || empty($result['phone_number']))
  {
    return null;
  }

  return (string) $result['phone_number'];
}

/**
 * `Two Factor` : whether SMS login enrollment is enabled for a user
 */
function tf_is_sms_login_enrollment_enabled($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return false;
  }

  if (function_exists('pwg_db_num_rows'))
  {
    tf_ensure_sms_schema();
  }

  list($count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM '.TF_TABLE.'
WHERE user_id = '.pwg_db_real_escape_string($user_id).'
  AND method = \'sms\'
  AND enabled_at IS NOT NULL
;'));

  return (int) $count > 0;
}

/**
 * `Two Factor` : whether login-time SMS challenge should be enforced for a user
 */
function tf_is_sms_login_challenge_required($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return false;
  }

  if (!tf_is_sms_login_enrollment_enabled($user_id))
  {
    return false;
  }

  return !tf_is_profile_liveness_guard_managing_user($user_id);
}

/**
 * `Two Factor` : disable SMS login enrollment but keep verified phone storage
 */
function tf_disable_sms_login_enrollment($user_id)
{
  $user_id = (int) $user_id;
  if ($user_id <= 0)
  {
    return false;
  }

  if (function_exists('pwg_db_num_rows'))
  {
    tf_ensure_sms_schema();
  }

  $result = pwg_query('
UPDATE '.TF_TABLE.'
  SET enabled_at = NULL
WHERE user_id = '.pwg_db_real_escape_string($user_id).'
  AND method = \'sms\'
  AND enabled_at IS NOT NULL
;');

  if (false === $result)
  {
    return false;
  }

  return !tf_is_sms_login_enrollment_enabled($user_id);
}

/**
 * `Two Factor` : mask phone number for logs
 */
function tf_mask_phone_number($phone_number)
{
  $length = strlen($phone_number);
  if ($length <= 4)
  {
    return str_repeat('*', $length);
  }

  return substr($phone_number, 0, 3) . str_repeat('*', max(0, $length - 6)) . substr($phone_number, -3);
}

/**
 * `Two Factor` : SMS message text
 */
function tf_generate_sms_message($code, $setup = false)
{
  global $conf;

  $gallery_title = $conf['gallery_title'] ?? 'Piwigo';
  $expires_in = max(1, (int) ceil(tf_get_sms_code_ttl() / 60));

  if ($setup)
  {
    return l10n('SMS setup code for %s: %s. It expires in %d minutes.', $gallery_title, $code, $expires_in);
  }

  return l10n('SMS login code for %s: %s. It expires in %d minutes.', $gallery_title, $code, $expires_in);
}

/**
 * `Two Factor` : send SMS OTP using SMSTOOLS
 */
function tf_send_sms_message($phone_number, $code, $setup = false, $user_id = null)
{
  global $conf, $logger;

  $sms_config = $conf['two_factor']['sms'] ?? array();
  if (empty($sms_config['base_url']) || empty($sms_config['api_key']) || empty($sms_config['sender_text']))
  {
    return array(
      'success' => false,
      'message' => l10n('SMS configuration is incomplete.'),
    );
  }

  $base_url = rtrim(trim($sms_config['base_url']), '/');
  $endpoint = $base_url . '/3/send_batch';

  $payload = array(
    'auth' => array(
      'apikey' => $sms_config['api_key'],
    ),
    'data' => array(
      'message' => tf_generate_sms_message($code, $setup),
      'sender' => array(
        'text' => $sms_config['sender_text'],
      ),
      'recipients' => array(
        array('phonenr' => $phone_number),
      ),
    ),
  );

  $content = json_encode($payload);
  if (false === $content)
  {
    return array(
      'success' => false,
      'message' => l10n('Unable to send SMS code right now.'),
    );
  }

  $headers = array(
    'Content-Type: application/json;charset=UTF-8',
    'Content-Length: ' . strlen($content),
  );
  $response_body = false;
  $http_code = 0;
  $transport_error = '';

  if (function_exists('curl_init'))
  {
    $curl = curl_init($endpoint);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
    curl_setopt($curl, CURLOPT_TIMEOUT, 15);

    $response_body = curl_exec($curl);
    $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if (false === $response_body)
    {
      $transport_error = curl_error($curl);
    }
    curl_close($curl);
  }
  else
  {
    $context = stream_context_create(array(
      'http' => array(
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'timeout' => 15,
        'ignore_errors' => true,
      ),
    ));

    $response_body = @file_get_contents($endpoint, false, $context);
    if (isset($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches))
    {
      $http_code = (int) $matches[1];
    }
  }

  $masked_phone = tf_mask_phone_number($phone_number);
  $log_context = '[two_factor][user_id=' . intval($user_id) . '][method=sms][phone=' . $masked_phone . '][purpose=' . ($setup ? 'setup' : 'login') . ']';

  if (false === $response_body || $http_code < 200 || $http_code >= 300)
  {
    $logger->error($log_context . '[action=send_failed][http_code=' . $http_code . '][transport_error=' . $transport_error . ']');
    return array(
      'success' => false,
      'message' => l10n('Unable to send SMS code right now.'),
    );
  }

  $response = json_decode($response_body, true);
  if (!is_array($response) || ('OK' !== ($response['id'] ?? null)))
  {
    $provider_id = $response['id'] ?? 'INVALID_RESPONSE';
    $provider_note = $response['note'] ?? '';
    $logger->error($log_context . '[action=provider_rejected][provider_id=' . $provider_id . '][provider_note=' . $provider_note . ']');
    if (!empty($sms_config['debug']))
    {
      $logger->warning($log_context . '[action=provider_debug][response=' . $response_body . ']');
    }
    return array(
      'success' => false,
      'message' => l10n('Unable to send SMS code right now.'),
    );
  }

  $batch_id = $response['data']['batch_id'] ?? null;
  $msg_id = $response['data']['recipients']['accepted'][0]['msg_id'] ?? null;
  $logger->info($log_context . '[action=sent][batch_id=' . $batch_id . '][msg_id=' . $msg_id . ']');
  if (!empty($sms_config['debug']))
  {
    $logger->info($log_context . '[action=provider_debug][response=' . json_encode($response) . ']');
  }

  return array(
    'success' => true,
    'response' => $response,
    'batch_id' => $batch_id,
    'msg_id' => $msg_id,
  );
}

/**
 * `Two Factor` : get default conf
 */
function tf_get_default_conf()
{
  return array(
    'external_app' => array(
      'enabled' => true,                      // Enable 2FA by external app
    ),
    'email' => array(
      'enabled' => false,                     // Enable 2FA by email
    ),
    'sms' => array(
      'enabled' => false,                     // Enable 2FA by SMS
      'base_url' => 'https://api.smstools.sk',
      'api_key' => '',
      'sender_text' => '',
      'code_ttl' => 600,
      'resend_delay' => 60,
      'debug' => false,
      'use_cpt_profile_phone' => true,
      'allow_manual_sms_phone' => false,
      'profile_contact_field' => 'contact_number',
      'require_contact_sms_enabled' => false,
    ),
    'general' => array(
      'max_attempts' => 3,                    // Maximum number of failed attempts before lockout
      'lockout_duration' => 300,              // Lockout duration in seconds after max attempts (300 = 5 minutes)
      // 'auto_enable_existing_users' => false,  // later
      // 'auto_enable_new_users' => false,       // later
    )
  );
}