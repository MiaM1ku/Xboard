<?php

namespace App\Http\Controllers\V2\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public function getConfig(Request $request)
    {
        $config = [
            'app_info' => [
                'app_name' => admin_setting('app_name', 'Xboard Distribution'),
                'app_description' => admin_setting('app_description', 'Private access distribution'),
                'app_url' => admin_setting('app_url'),
                'logo' => admin_setting('logo'),
                'version' => admin_setting('app_version', '1.0.0'),
            ],
            'features' => [
                'enable_register' => (bool) admin_setting('app_enable_register', false),
                'requires_admin_activation' => true,
                'enable_traffic_log' => true,
                'enable_server_ping' => true,
                'enable_invite_system' => false,
                'enable_commission_system' => false,
                'enable_auto_renewal' => false,
                'enable_coupon_system' => false,
                'enable_payment_system' => false,
            ],
            'security_config' => [
                'is_email_verify' => (int) admin_setting('email_verify', 0),
                'is_captcha' => (int) admin_setting('captcha_enable', 0),
                'session_transport' => 'http_only_cookie',
            ],
            'last_updated' => time(),
        ];
        $config['config_hash'] = md5(json_encode($config));

        return response()->json(['data' => $config]);
    }

    public function getVersion(Request $request)
    {
        if (
            str_contains((string) $request->header('user-agent'), 'tidalab/4.0.0')
            || str_contains((string) $request->header('user-agent'), 'tunnelab/4.0.0')
        ) {
            $isWindows = str_contains((string) $request->header('user-agent'), 'Win64');
            $data = [
                'version' => admin_setting($isWindows ? 'windows_version' : 'macos_version'),
                'download_url' => admin_setting($isWindows ? 'windows_download_url' : 'macos_download_url'),
            ];
        } else {
            $data = [
                'windows_version' => admin_setting('windows_version'),
                'windows_download_url' => admin_setting('windows_download_url'),
                'macos_version' => admin_setting('macos_version'),
                'macos_download_url' => admin_setting('macos_download_url'),
                'android_version' => admin_setting('android_version'),
                'android_download_url' => admin_setting('android_download_url'),
            ];
        }

        return $this->success($data);
    }
}
