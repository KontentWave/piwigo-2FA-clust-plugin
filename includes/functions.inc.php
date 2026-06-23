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
    ),
    'general' => array(
      'max_attempts' => 3,                    // Maximum number of failed attempts before lockout
      'lockout_duration' => 300,              // Lockout duration in seconds after max attempts (300 = 5 minutes)
      // 'auto_enable_existing_users' => false,  // later
      // 'auto_enable_new_users' => false,       // later
    )
  );
}