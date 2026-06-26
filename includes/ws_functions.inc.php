<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

/**
 * `Two Factor` : add new pwg method
 */
function tf_add_methods($arr)
{
  $service = &$arr[0];

  // Setup
  $service->addMethod(
    'twofactor.setup.email',
    'tf_setup_email',
    array(
      'email' => array(
        'flags' => WS_PARAM_OPTIONAL,
        'info' => 'To check the user email address'
      ),
      'code' => array(
        'flags' => WS_PARAM_OPTIONAL,
        'type' => WS_TYPE_POSITIVE,
        'info' => 'Totp code'
      ),
      'pwg_token' => array(),
    ),
    'Step 1: send only email / Step 2: send totp code',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => false,
    )
  );

  $service->addMethod(
    'twofactor.setup.externalApp',
    'tf_setup_external_app',
    array(
      'code' => array(
        'flags' => WS_PARAM_OPTIONAL,
        'type' => WS_TYPE_POSITIVE,
        'info' => 'Totp code'
      ),
      'pwg_token' => array(),
    ),
    '',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => false,
    )
  );

  $service->addMethod(
    'twofactor.setup.sms',
    'tf_setup_sms',
    array(
      'phone_number' => array(
        'flags' => WS_PARAM_OPTIONAL,
        'info' => 'Phone number to verify'
      ),
      'code' => array(
        'flags' => WS_PARAM_OPTIONAL,
        'type' => WS_TYPE_POSITIVE,
        'info' => 'SMS code'
      ),
      'pwg_token' => array(),
    ),
    'Step 1: send SMS / Step 2: confirm SMS code',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => false,
    )
  );

  // Others method
  $service->addMethod(
    'twofactor.status',
    'tf_status',
    array(
      'user_id' => array(
        'flags' => WS_PARAM_OPTIONAL,
        'type' => WS_TYPE_ID,
        'info' => 'Only webmaster can see 2FA status for an another user'
      ),
    ),
    '',
    null,
    array(
      'hidden' => false,
      'post_only' => false,
      'admin_only' => false,
    )
  );

  $service->addMethod(
    'twofactor.setConfig',
    'tf_set_config',
    array(
      'config' => array(
        'flags' => WS_PARAM_FORCE_ARRAY,
        'info' => 'Must be an array',
      ),
      'pwg_token' => array(),
    ),
    '',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => true,
    )
  );

  $service->addMethod(
    'twofactor.sendEmail',
    'tf_send_email',
    array(
      'pwg_token' => array(),
    ),
    '',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => false,
    )
  );

  $service->addMethod(
    'twofactor.sendSms',
    'tf_send_sms',
    array(
      'pwg_token' => array(),
    ),
    '',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => false,
    )
  );

  $service->addMethod(
    'twofactor.deactivate',
    'tf_deactivate',
    array(
      'two_factor_method' => array(
        'info' => 'Only email, sms or external_app'
      ),
      'pwg_token' => array(),
    ),
    '',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => false,
    )
  );

  $service->addMethod(
    'twofactor.adminDeactivate',
    'tf_admin_deactivate',
    array(
      'user_id' => array(
        'type' => WS_TYPE_ID,
        'info' => 'Only webmaster can deactivate 2FA for an another user'
      ),
      // 'next_login' => array(
      //   'default' => false,
      //   'type' => WS_TYPE_BOOL,
      //   'info' => 'Deactivate only for next connection for both method. default: false'
      // ),
      'pwg_token' => array(),
    ),
    'Only for webmaster',
    null,
    array(
      'hidden' => false,
      'post_only' => true,
      'admin_only' => true,
    )
  );
}

function tf_setup_generic($params, $method)
{
  global $logger, $user;

  // We can only set the 2FA if we are connected with pwg_ui
  // or not a guest
  if (is_a_guest() || !connected_with_pwg_ui())
  {
    return new PwgError(401, 'Access Denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!preg_match('/(email|sms|external_app)/', $method))
  {
    return new PwgError(401, 'Method can be only email, sms or external_app');
  }

  $tf = new PwgTwoFactor($method);

  if (isset($params['code']))
  {
    $activated = $tf->finaliseSetup($params['code']);
    if ($activated)
    {
      $logger->info('[two_factor][user_id='.$user['id'].'][method='.$method.'][setup_step=finalized]');
      return true;
    }
    else
    {
      return false;
    }
  }

  $setup = $tf->setup();
  if (!$setup)
  {
    return new PwgError(401, $tf->getLastError() ?: 'Error during initialisation two factor for method:' . $method);
  }

  // logger
  $logger->info('[two_factor][user_id='.$user['id'].'][method='.$method.'][setup_step=initialized]');
  return $setup;
}

/**
 * `Two Factor` : Setup email
 */
function tf_setup_email($params)
{
  global $user;
  if (!$user['email'])
  {
    return new PwgError(401, 'Unable to activate 2FA by email');
  }

  if (isset($params['email']) && $user['email'] !== $params['email'])
  {
    return new PwgError(401, 'Unable to activate 2FA by email');
  }

  if (!PwgTwoFactor::isActivated('email'))
  {
    return new PwgError(401, 'Unable to activate 2FA by email');
  }

  if (!isset($params['code']))
  {
    $limit_rate = tf_mail_rate_limit(time(), TF_SESSION_MAIL_SETUP_RATE_LIMIT);
    if (true !== $limit_rate)
    {
      return new PwgError(403, l10n('Please wait %s seconds before sending an email again.', $limit_rate));
    }
  }

  return tf_setup_generic($params, 'email');
}

/**
 * `Two Factor` : Setup external app
 */
function tf_setup_external_app($params)
{
  if (!PwgTwoFactor::isActivated('external_app'))
  {
    return new PwgError(401, 'Unable to activate 2FA by application');
  }
  return tf_setup_generic($params, 'external_app');
}

/**
 * `Two Factor` : Setup SMS
 */
function tf_setup_sms($params)
{
  global $user;

  if (!PwgTwoFactor::isActivated('sms'))
  {
    return new PwgError(401, 'Unable to activate 2FA by SMS');
  }

  if (isset($params['code']))
  {
    return tf_setup_generic($params, 'sms');
  }

  $phone_number = null;
  if (tf_use_cpt_profile_phone() && !tf_allow_manual_sms_phone())
  {
    $candidate = tf_get_sms_setup_phone_candidate((int) $user['id']);
    if (empty($candidate['normalized_phone']))
    {
      return new PwgError(401, $candidate['error'] ?? l10n('Please add a valid contact phone number in My Profile first.'));
    }

    $phone_number = $candidate['normalized_phone'];
    if (!empty($params['phone_number']))
    {
      $submitted_phone = tf_normalize_phone_number($params['phone_number']);
      if ($submitted_phone !== $phone_number)
      {
        return new PwgError(403, l10n('The submitted phone number does not match your profile phone number.'));
      }
    }
  }

  if (null === $phone_number && empty($params['phone_number']))
  {
    return new PwgError(401, l10n('Please enter a valid phone number'));
  }

  if (null === $phone_number)
  {
    $phone_number = tf_normalize_phone_number($params['phone_number']);
    if (!$phone_number)
    {
      return new PwgError(401, l10n('Please enter a valid phone number'));
    }
  }

  $phone_owner = tf_get_sms_phone_owner($phone_number, $user['id']);
  if (null !== $phone_owner)
  {
    return new PwgError(403, l10n('This phone number is already used by another account.'));
  }

  $limit_rate = tf_rate_limit(time(), TF_SESSION_SMS_SETUP_RATE_LIMIT, tf_get_sms_resend_delay());
  if (true !== $limit_rate)
  {
    return new PwgError(403, l10n('Please wait %s seconds before sending an SMS again.', $limit_rate));
  }

  pwg_set_session_var(TF_SESSION_SMS_PHONE_NUMBER, $phone_number);

  return tf_setup_generic($params, 'sms');
}

/**
 * `Two Factor` : Get 2FA Status
 */
function tf_status($params)
{
  global $user;

  if (is_a_guest())
  {
    return new PwgError(401, 'Acess Denied');
  }

  if (!is_webmaster() && isset($params['user_id']) && $user['id'] != $params['user_id'])
  {
    return new PwgError(401, 'Acess Denied');
  }

  $user_id = $params['user_id'] ?? $user['id'];

  return array(
    'external_app' => PwgTwoFactor::isEnabled($user_id, 'external_app'),
    'email' => PwgTwoFactor::isEnabled($user_id, 'email'),
    'sms' => PwgTwoFactor::isEnabled($user_id, 'sms')
  );
}

/**
 * `Two Factor` : Set config
 */
function tf_set_config($params)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!connected_with_pwg_ui() || !is_webmaster())
  {
    return new PwgError(401, 'Access Denied');
  }

  if (
    !isset($params['config']['general'])
    || !isset($params['config']['external_app'])
    || !isset($params['config']['email'])
    || !isset($params['config']['sms'])
    )
  {
    return new PwgError(403, 'Missing parameter, must have: general, external_app, email, sms');
  }

  $validated_conf = array();
  foreach ($params['config'] as $key => $config)
  {
    switch ($key)
    {
      case 'general':
        if (
          !isset($config['max_attempts']) 
          || !preg_match('/^[1-9]\d*$/', $config['max_attempts'])
          || !isset($config['lockout_duration']) 
          || !preg_match('/^\d+$/', $config['lockout_duration'])
        ) {
          return new PwgError(403, 'Missing parameter general, must have: max_attempts, lockout_duration both as integer positive');
        }
        $validated_conf[$key]['max_attempts'] = intval($config['max_attempts']);
        $validated_conf[$key]['lockout_duration'] = intval($config['lockout_duration']);
        break;

      case 'external_app':
        if (!isset($config['enabled']))
        {
          return new PwgError(403, 'Missing parameter external_app, must have: enabled as bool');
        }

        $validated_conf[$key]['enabled'] = get_boolean($config['enabled']);
        break;

      case 'email':
        if (!isset($config['enabled']))
        {
          return new PwgError(403, 'Missing parameter email, must have: enabled as bool');
        }

        $validated_conf[$key]['enabled'] = get_boolean($config['enabled']);
        break;

      case 'sms':
        if (
          !isset($config['enabled'])
          || !isset($config['base_url'])
          || !isset($config['api_key'])
          || !isset($config['sender_text'])
          || !isset($config['code_ttl'])
          || !preg_match('/^[1-9]\d*$/', $config['code_ttl'])
          || !isset($config['resend_delay'])
          || !preg_match('/^\d+$/', $config['resend_delay'])
          || !isset($config['debug'])
        ) {
          return new PwgError(403, 'Missing parameter sms, must have: enabled, base_url, api_key, sender_text, code_ttl, resend_delay, debug');
        }

        $base_url = rtrim(trim($config['base_url']), '/');
        if (!preg_match('#^https://[^\s]+$#i', $base_url))
        {
          return new PwgError(403, 'SMS base URL must be a valid HTTPS URL');
        }

        $sender_text = trim($config['sender_text']);
        if (strlen($sender_text) > 11)
        {
          return new PwgError(403, 'SMS sender text must not exceed 11 characters');
        }

        $validated_conf[$key]['enabled'] = get_boolean($config['enabled']);
        $validated_conf[$key]['base_url'] = $base_url;
        $validated_conf[$key]['api_key'] = trim($config['api_key']);
        $validated_conf[$key]['sender_text'] = $sender_text;
        $validated_conf[$key]['code_ttl'] = intval($config['code_ttl']);
        $validated_conf[$key]['resend_delay'] = intval($config['resend_delay']);
        $validated_conf[$key]['debug'] = get_boolean($config['debug']);
        break;
    }
  }

  conf_update_param('two_factor', $validated_conf, true);
  $tf_config = safe_unserialize(conf_get_param('two_factor'));

  return array(
    'message' => 'The configuration has been successfully saved.',
    'configuration' => tf_normalize_conf($tf_config),
  );
}

/**
 * `Two Factor` : Reset config
 */
function tf_reset_config()
{
  //
}

/**
 * `Two Factor` : Send Totp code by mail
 */
function tf_send_email($params)
{
  global $user, $conf;

  if (is_a_guest() || !connected_with_pwg_ui())
  {
    return new PwgError(401, 'Access Denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $limit_rate = tf_mail_rate_limit(time(), TF_SESSION_MAIL_VERIFY_RATE_LIMIT);
  if (true !== $limit_rate)
  {
    return new PwgError(403, l10n('Please wait %s seconds before sending an email again.', $limit_rate));
  }

  if (!PwgTwoFactor::isEnabled($user['id'], 'email'))
  {
    return new PwgError(401, 'Email isn\'t initialized');
  }

  $generated_code = (new PwgTwoFactor('email'))->generateCode();
  include_once(PHPWG_ROOT_PATH . 'include/functions_mail.inc.php');

  $message = tf_generate_mail_template($user['username'], $generated_code, false);

  $send_email = @pwg_mail(
    $user['email'],
    array(
      'subject' => '[' . $conf['gallery_title'] . '] ' . l10n('Two Factor Authentication'),
      'content' => $message,
      'content_format' => 'text/html',
    )
  );

  return $send_email;
}

/**
 * `Two Factor` : Send verification code by SMS
 */
function tf_send_sms($params)
{
  global $user;

  if (is_a_guest() || !connected_with_pwg_ui())
  {
    return new PwgError(401, 'Access Denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $limit_rate = tf_rate_limit(time(), TF_SESSION_SMS_VERIFY_RATE_LIMIT, tf_get_sms_resend_delay());
  if (true !== $limit_rate)
  {
    return new PwgError(403, l10n('Please wait %s seconds before sending an SMS again.', $limit_rate));
  }

  if (!PwgTwoFactor::isActivated('sms') || !PwgTwoFactor::isEnabled($user['id'], 'sms'))
  {
    return new PwgError(401, 'SMS isn\'t initialized');
  }

  $tf = new PwgTwoFactor('sms');
  $phone_number = $tf->getPhoneNumber();
  if (!$phone_number)
  {
    return new PwgError(401, 'SMS phone number is missing');
  }

  $generated_code = $tf->generateCode();
  $send_sms = tf_send_sms_message($phone_number, $generated_code, false, $user['id']);
  if (!$send_sms['success'])
  {
    pwg_unset_session_var(TF_SESSION_SMS_CODE);
    pwg_unset_session_var(TF_SESSION_SMS_SENT_AT);
    return new PwgError(500, $send_sms['message']);
  }

  return true;
}

/**
 * `Two Factor` : Deactivate Two Factor
 */
function tf_deactivate($params)
{
  global $user, $logger;

  if (is_a_guest() || !connected_with_pwg_ui())
  {
    return new PwgError(401, 'Access Denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  if (!preg_match('/(email|sms|external_app)/', $params['two_factor_method']))
  {
    return new PwgError(401, 'Method can be only email, sms or external_app');
  }

  $user_id = $user['id'];

  if (PwgTwoFactor::isEnabled($user_id, $params['two_factor_method']))
  {
    (new PwgTwoFactor($params['two_factor_method']))->deleteSecret(pwg_db_real_escape_string($user_id));
    // logger
    $logger->info('[two_factor][user_id='.$user_id.'][method='.$params['two_factor_method'].'][action=deactivated]');
    return true;
  }

  return false;
}

/**
 * `Two Factor` : Admin deactivate Two Factor
 */
function tf_admin_deactivate($params)
{
  global $user, $logger;

  if (!is_webmaster() || !connected_with_pwg_ui())
  {
    return new PwgError(401, 'Access Denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $user_id = $params['user_id'];

  if (PwgTwoFactor::isEnabled($user_id, 'external_app'))
  {
    $delete_external = (new PwgTwoFactor('external_app'))->deleteSecret(pwg_db_real_escape_string($user_id));
    if (!$delete_external)
    {
      return new PwgError(500, 'Error external app');
    }
    // logger
    $logger->info('[two_factor][user_id='.$user_id.'][method=external_app][action=deactivated][by_user_id='.$user['id'].']');
  }

  if (PwgTwoFactor::isEnabled($user_id, 'email'))
  {
    $delete_email = (new PwgTwoFactor('email'))->deleteSecret(pwg_db_real_escape_string($user_id));
    if (!$delete_email)
    {
      return new PwgError(500, 'Error email');
    }
    // logger
    $logger->info('[two_factor][user_id='.$user_id.'][method=email][action=deactivated][by_user_id='.$user['id'].']');
  }

  if (PwgTwoFactor::isEnabled($user_id, 'sms'))
  {
    $delete_sms = (new PwgTwoFactor('sms'))->deleteSecret(pwg_db_real_escape_string($user_id));
    if (!$delete_sms)
    {
      return new PwgError(500, 'Error sms');
    }
    $logger->info('[two_factor][user_id='.$user_id.'][method=sms][action=deactivated][by_user_id='.$user['id'].']');
  }

  return true;
}
