<?php
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * 2FA modules
 * @package modules
 * @subpackage 2fa
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * @subpackage 2fa/handler
 */
class Hm_Handler_process_enable_2fa extends Hm_Handler_Module {
    public function process() {
        function enable_2fa_callback($val) { return $val; }
        function backup_2fa_callback($val) { return $val; }
        process_site_setting('2fa_enable', $this, 'enable_2fa_callback', false, true);
        if (array_key_exists('2fa_enable', $this->request->post) && $this->request->post['2fa_enable']) {
            process_site_setting('2fa_backup_codes', $this, 'backup_2fa_callback', false, false);
            $this->session->set('2fa_confirmed', true);
        }
        list($secret, $simple) = get_2fa_key($this->config);
        if ($secret) {
            if ($simple) {
                $len = 15;
            }
            else {
                $len = 64;
            }
            $username = $this->session->get('username', false);
            $secret = base32_encode_str(create_secret($secret, $username, $len));
            $app_name = $this->config->get('app_name', 'Cypht');
            $uri = sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s', $app_name, $username, $secret, $app_name);
            $this->out('2fa_svg', generate_qr_code($this->config, $username, $uri));
            $this->out('2fa_backup_codes', backup_codes($this->user_config));
            $this->out('2fa_secret', $secret);
        }
    }
}

/**
 * @subpackage 2fa/handler
 */
class Hm_Handler_2fa_check extends Hm_Handler_Module {
    public function process() {

        $enabled = $this->user_config->get('2fa_enable_setting', 0);
        if (!$enabled) {
            return;
        }

        /*if(!extension_loaded('imagick')){
            Hm_Msgs::add('ERR2FA The imagick extension is required to use 2fa feature, please contact your administrator for fixing this');
            return;
        }*/

        list($secret, $simple) = get_2fa_key($this->config);
        if (!$secret) {
            Hm_Debug::add('2FA module set enabled, but no shared secret configured');
            return;
        }

        $confirmed = $this->session->get('2fa_confirmed', false);
        if ($confirmed) {
            return;
        }

        $old_setting = $this->user_config->get('enable_2fa_setting', 0);
        if ($old_setting && $this->session->loaded) {
            Hm_Msgs::add('ERR2FA disabled because of a security issue. Go to "Settings" -> "Site" to re-enable');
        }
        $passed = false;
        $backup_codes = $this->user_config->get('2fa_backup_codes_setting', array());
        if (array_key_exists('2fa_code', $this->request->post)) {
            if ($simple) {
                $len = 15;
            }
            else {
                $len = 64;
            }
            $username = $this->session->get('username', false);
            $secret = create_secret($secret, $username, $len);
            if (check_2fa_pin($this->request->post['2fa_code'], $secret)) {
                $passed = true;
            }
            elseif (in_array(intval($this->request->post['2fa_code']), $backup_codes, true)) {
                $passed = true;
            }
            else {
                $this->out('2fa_error', '2 factor authentication code does not match');
            }
        }

        if (!$passed) {
            $this->out('no_redirect', true);
            Hm_Request_Key::load($this->session, $this->request, false);
            $this->out('2fa_key', Hm_Request_Key::generate());
            $this->out('2fa_required', true);
            $this->session->close_early();
        }
        else {
            $this->session->set('2fa_confirmed', true);
        }
    }
}

/**
 * Verify 2fa code is paired with Authenticator app before enabling 2fa
 * @subpackage 2fa/handler
 */
class Hm_Handler_2fa_setup_check extends Hm_Handler_Module {
    public function process() {

        list($secret, $simple) = get_2fa_key($this->config);
        if (!$secret) {
            Hm_Debug::add('2FA module set enabled, but no shared secret configured');
            return;
        }

        $verified = false;
        $len = $simple ? 15 : 64;

        $username = $this->session->get('username', false);
        $secret = create_secret($secret, $username, $len);

        if (check_2fa_pin($this->request->post['2fa_code'], $secret)) {
            $verified = true;
        }
        
        $this->out('ajax_2fa_verified', $verified);
    }
}

/**
 * @subpackage 2fa/output
 */
class Hm_Output_enable_2fa_setting extends Hm_Output_Module {
    protected function output() {
        $enabled = false;
        $backup_codes = $this->get('2fa_backup_codes', array());
        $settings = $this->get('user_settings', array());
        if (array_key_exists('2fa_enable', $settings)) {
            $enabled = $settings['2fa_enable'];
        }
        $res = '<tr>
                    <td colspan="2" data-target=".tfa_setting" class="settings_subtitle">'.
                        '<img alt="" src="'.Hm_Image_Sources::$unlocked.'" width="16" height="16" />'.$this->trans('2 Factor Authentication').'
                    </td>
                </tr>';

        $res .= '<tr class="tfa_setting">
                    <td>
                        '.$this->trans('Enable 2 factor authentication').'
                        <input value="1" type="checkbox" name="2fa_enable" '.($enabled ? 'checked="checked"' : '').' />';

        $svg = $this->get('2fa_svg');

        if ($svg) {
            $qr_code = '';
            if (!$enabled) {
                $qr_code .= '<div class="err settings_wrap_text tfa_mt_1">'.$this->trans('Configure your authentication app using the barcode below BEFORE enabling 2 factor authentication.').'</div>';
            }
            else {
                $qr_code .= '<div>'.$this->trans('Update your settings with the code below').'</div>';
            }

            $qr_code .= $svg;
            $qr_code .= '<div class="tfa_mb_1">'.$this->trans('If you can\'t use the QR code, you can enter the code below manually (no line breaks)').'</div>';
            $qr_code .= wordwrap($this->html_safe($this->get('2fa_secret', '')), 60, '<br />', true);
        }
        else {
            $qr_code = '<div class="tfa_mt_1">'.$this->trans('Unable to generate 2 factor authentication QR code').'</div>';
        }
        $res .= $qr_code . '</td>';

        $res .= '<td><div class="tfa_mb_1">'.$this->trans('The following backup codes can be used to access your account if you lose your device'). '</div>';

        foreach ($backup_codes as $val) {
            $res .= ' '.$val.'<input type="hidden" name="2fa_backup_codes[]" value="'.$val.'" /></br >';
        }
        $res .= '<div class="tfa_mt_1">
                    <fieldset class="tfa_confirmation_fieldset">
                        <legend>Enter the confirmation code</legend>
                        <div class="tfa_confirmation_wrapper">
                            <div class="tfa_confirmation_form">
                                <div class="tfa_confirmation_input_digits">
                                    <input class="tfa_confirmation_input_digit" type="number" aria-label="Digit 0" aria-required="true">
                                    <input class="tfa_confirmation_input_digit" type="number" aria-label="Digit 1" aria-required="true">
                                    <input class="tfa_confirmation_input_digit" type="number" aria-label="Digit 2" aria-required="true">
                                    <input class="tfa_confirmation_input_digit" type="number" aria-label="Digit 3" aria-required="true">
                                    <input class="tfa_confirmation_input_digit" type="number" aria-label="Digit 4" aria-required="true">
                                    <input class="tfa_confirmation_input_digit" type="number" aria-label="Digit 5" aria-required="true">
                                </div>
                                <button id="tfaConfirmationBtn" type="submit" class="tfa_confirmation_input_button">'.$this->trans('Verify code').'</button>
                            </div>
                            <div class="tfa_confirmation_hint"> '.$this->trans('Enter the 6 digit code from your Authenticator application').'</div>
                        </div>
                    </fieldset>
                </div>
            </td>
        </tr>';
        return $res;
    }
}

/**
 * @subpackage 2fa/output
 */
class Hm_Output_2fa_dialog extends Hm_Output_Module {
    protected function output() {

        if ($this->get('2fa_required')) {

            $lang = 'en-us';
            $dir = 'ltr';
            if ($this->lang) {
                $lang = strtolower(str_replace('_', '-', $this->lang));
            }
            if ($this->dir) {
                $dir = $this->dir;
            }
            $class = $dir."_page";

            if ($this->get('2fa_error')) {
                $error = '<div class="tfa_error">'.$this->trans($this->get('2fa_error')).'</div>';
            }
            else {
                $error = '';
            }

            echo '<!DOCTYPE html><html lang='.$this->html_safe($lang).' class="'.$this->html_safe($class).
                '" dir="'.$this->html_safe($dir).'"><head><meta charset="utf-8" />'.
                '<link href="site.css" media="all" rel="stylesheet" type="text/css" />'.
                '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">'.
                '</head><body><form class="login_form" method="POST"><h1 class="title">'.
                $this->html_safe($this->get('router_app_name')).'</h1>'. $error.'<div class="tfa_input">'.
                '<label for="2fa_code">'.$this->trans('Enter the 6 digit code from your Authenticator application').
                '</label></div><input type="hidden" name="hm_page_key" value="'.$this->get('2fa_key').'" />'.
                '<input autofocus required id="2fa_code" type="number" name="2fa_code" value="" placeholder="'.
                $this->trans('Login code').'" /><input type="submit" value="'.$this->trans('Submit').
                '" /></form></body></html>';
            Hm_Functions::cease();
        }
    }
}

/**
 * @subpackage 2fa/functions
 */
if (!hm_exists('check_2fa_pin')) {
function check_2fa_pin($pin, $secret, $pass_len=6) {
    $pin_mod = pow(10, $pass_len);
    $time = floor(time()/30);
    $time = pack('N', $time);
    $time = str_pad($time, 8, chr(0), STR_PAD_LEFT);
    $hash = hash_hmac('sha1', $time, $secret, true);
    $offset = ord(substr($hash,-1)) & 0xF;
    $input = substr($hash, $offset, strlen($hash) - $offset);
    $input = unpack("N",substr($input, 0, 4));
    $inthash = $input[1] & 0x7FFFFFFF;
    return $pin === str_pad($inthash % $pin_mod, 6, "0", STR_PAD_LEFT);
}}

/**
 * @subpackage 2fa/functions
 */
if (!hm_exists('get_2fa_key')) {
function get_2fa_key($config) {
    $settings = get_ini($config, '2fa.ini');
    $secret = false;
    $simple = false;
    if (array_key_exists('2fa_secret', $settings)) {
        $secret = $settings['2fa_secret'];
    }
    if (array_key_exists('2fa_simple', $settings)) {
        $simple = $settings['2fa_simple'];
    }
    return array($secret, $simple);
}}

/**
 * @subpackage 2fa/functions
 */
if (!hm_exists('base32_encode_str')) {
function base32_encode_str($str) {
    require_once VENDOR_PATH.'christian-riesen/base32/src/Base32.php';
    return Base32\Base32::encode($str);
}}

/**
 * @subpackage 2fa/functions
 */
if (!hm_exists('generate_qr_code')) {
function generate_qr_code($config, $username, $str) {
    require_once VENDOR_PATH.'autoload.php';
    $renderer = new ImageRenderer(
        new RendererStyle(200),
        new SvgImageBackEnd()
    );
    $writer = new Writer($renderer);
    return $writer->writeString($str);
}}

/**
 * @subpackage 2fa/functions
 */
if (!hm_exists('create_secret')) {
function create_secret($key, $user, $len) {
    return Hm_Crypt::pbkdf2($key, $user, $len, 256, 'sha512');
}}

/**
 * @subpackage 2fa/functions
 */
if (!hm_exists('backup_codes')) {
function backup_codes($config) {
    $codes = $config->get('2fa_backup_codes_setting', array());
    if (is_array($codes) && count($codes) == 3) {
        return $codes;
    }
    return array(random_int(100000000, 999999999), random_int(100000000, 999999999), random_int(100000000, 999999999));
}}
