<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('PHPWG_ROOT_PATH'))
{
  define('PHPWG_ROOT_PATH', dirname(__DIR__) . '/');
}

if (!defined('TF_SESSION_VALIDATED')) define('TF_SESSION_VALIDATED', 'tf_tries_validated');
if (!defined('TF_SESSION_TRIES_LEFT')) define('TF_SESSION_TRIES_LEFT', 'tf_tries_left');
if (!defined('TF_SESSION_MAIL_CODE')) define('TF_SESSION_MAIL_CODE', 'tf_mail_codes');
if (!defined('TF_SESSION_MAIL_SENT_AT')) define('TF_SESSION_MAIL_SENT_AT', 'tf_mail_sent_at');
if (!defined('TF_SESSION_SMS_CODE')) define('TF_SESSION_SMS_CODE', 'tf_sms_codes');
if (!defined('TF_SESSION_SMS_SENT_AT')) define('TF_SESSION_SMS_SENT_AT', 'tf_sms_sent_at');
if (!defined('TF_SESSION_SMS_PHONE_NUMBER')) define('TF_SESSION_SMS_PHONE_NUMBER', 'tf_sms_phone_number');
if (!defined('TF_SESSION_TMP_RECOVERY_CODES')) define('TF_SESSION_TMP_RECOVERY_CODES', 'tf_tmp_recovery_codes');
if (!defined('TF_SESSION_MAIL_SETUP_RATE_LIMIT')) define('TF_SESSION_MAIL_SETUP_RATE_LIMIT', 'tf_mail_setup_rate_limit');
if (!defined('TF_SESSION_MAIL_VERIFY_RATE_LIMIT')) define('TF_SESSION_MAIL_VERIFY_RATE_LIMIT', 'tf_mail_verify_rate_limit');
if (!defined('TF_SESSION_SMS_SETUP_RATE_LIMIT')) define('TF_SESSION_SMS_SETUP_RATE_LIMIT', 'tf_sms_setup_rate_limit');
if (!defined('TF_SESSION_SMS_VERIFY_RATE_LIMIT')) define('TF_SESSION_SMS_VERIFY_RATE_LIMIT', 'tf_sms_verify_rate_limit');
if (!defined('TF_TABLE')) define('TF_TABLE', 'piwigo_two_factor');
if (!defined('USER_INFOS_TABLE')) define('USER_INFOS_TABLE', 'piwigo_user_infos');

if (!function_exists('l10n'))
{
  function l10n($message)
  {
    $args = func_get_args();
    array_shift($args);
    return $args ? vsprintf($message, $args) : $message;
  }
}

if (!function_exists('pwg_unset_session_var'))
{
  function pwg_unset_session_var($key)
  {
    unset($_SESSION[$key]);
  }
}

if (!function_exists('pwg_set_session_var'))
{
  function pwg_set_session_var($key, $value)
  {
    $_SESSION[$key] = $value;
  }
}

if (!function_exists('pwg_get_session_var'))
{
  function pwg_get_session_var($key)
  {
    return $_SESSION[$key] ?? null;
  }
}

require_once dirname(__DIR__) . '/includes/functions.inc.php';