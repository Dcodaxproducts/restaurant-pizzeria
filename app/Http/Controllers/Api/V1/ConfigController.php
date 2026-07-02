<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Model\BusinessSetting;
use App\Model\Currency;
use App\Model\SocialMedia;
use App\Model\TimeSchedule;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __construct(
        private Currency        $currency,
        private Branch          $branch,
        private TimeSchedule    $time_schedule,
        private BusinessSetting $business_setting,
    )
    {
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->business_setting->where(['key' => $key])->first()?->value ?? $default;
    }

    private function jsonSetting(string $key, array $default = []): array
    {
        $value = $this->setting($key);
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : $default;
    }

    private function statusSetting(string $key, int $default = 0): int
    {
        return (int)($this->jsonSetting($key, ['status' => $default])['status'] ?? $default);
    }


    /**
     * @return JsonResponse
     */
    public function configuration(): JsonResponse
    {
        $currency_symbol = $this->currency->where(['currency_code' => Helpers::currency_code()])->first()?->currency_symbol ?? '$';
        $cod = $this->jsonSetting('cash_on_delivery', ['status' => 0]);
        $dp = $this->jsonSetting('digital_payment', ['status' => 0]);
        $payconiq_payment = $this->jsonSetting('payconiq_payment', ['status' => 0]);
        $bancontact = $payconiq_payment['status'] ?? 0;
	    $paypal_payment = $this->jsonSetting('paypal', ['status' => 0]);
        $paypal = $paypal_payment['status'] ?? 0;
		$wolt_delivery = $this->jsonSetting('wolt_service', ['status' => 0]);
        $wolt = $wolt_delivery['status'] ?? 0;
		$paystack_payment = $this->jsonSetting('paystack', ['status' => 0]);
        $paystack = $paystack_payment['status'] ?? 0;
        $stripe_payment = $this->jsonSetting('stripe', ['status' => 0]);
		$language = $this->jsonSetting('language', []);
        $dineInn = $this->jsonSetting('dine_in', ['status' => 0]);
        $takeWay = $this->jsonSetting('take_way', ['status' => 0]);
        $delivery = $this->jsonSetting('deliver', ['status' => 0]);
        $dm_config = Helpers::get_business_settings('delivery_management') ?: [];
        $delivery_management = array(
            "status" => (int)($dm_config['status'] ?? 0),
            "min_shipping_charge" => (float)($dm_config['min_shipping_charge'] ?? 0),
            "shipping_per_km" => (float)($dm_config['shipping_per_km'] ?? 0),
        );
        $play_store_config = Helpers::get_business_settings('play_store_config') ?: [];
        $app_store_config = Helpers::get_business_settings('app_store_config') ?: [];

        //schedule time
        $schedules = $this->time_schedule->select('day', 'opening_time', 'closing_time')->get();
        $branch_promotion = $this->branch->with('branch_promotion')->where(['branch_promotion_status' => 1])->get();

        $google = $this->setting('google_social_login', 0);
        $facebook = $this->setting('facebook_social_login', 0);

        $map_key = $this->setting('map_api_server_key');

        $digital_payment_status_value = $dp;

        $active_method_list = [];

        if (($digital_payment_status_value['status'] ?? 0) == 1) {
            $digital_payment_methods = ['ssl_commerz_payment', 'razor_pay','payconiq_payment', 'paypal', 'stripe', 'senang_pay', 'paystack', 'bkash', 'paymob', 'flutterwave', 'mercadopago'];
            $data = $this->business_setting->whereIn('key', $digital_payment_methods)->get();
            foreach ($data as $d) {
                $value = json_decode($d['value'], true);
                if (is_array($value) && ($value['status'] ?? 0) == 1) {
                    $active_method_list[] = $d['key'];
                }
            }
        }

        $cookies_config = Helpers::get_business_settings('cookies') ?: [];
        $cookies_management = array(
            "status" => (int)($cookies_config['status'] ?? 0),
            "text" => $cookies_config['text'] ?? '',
        );

        return response()->json([
            'restaurant_name' => $this->setting('restaurant_name', ''),
            'restaurant_open_time' => $this->setting('restaurant_open_time', '00:00'),
            'restaurant_close_time' => $this->setting('restaurant_close_time', '23:59'),
            'restaurant_schedule_time' => $schedules,
            'restaurant_logo' => $this->setting('logo', ''),
            'restaurant_address' => $this->setting('address', ''),
            'restaurant_phone' => $this->setting('phone', ''),
            'restaurant_email' => $this->setting('email_address', ''),
            'restaurant_location_coverage' => $this->branch->where(['id' => 1])->first(['longitude', 'latitude', 'coverage']),
            'minimum_order_value' => (float)$this->setting('minimum_order_value', 0),

            'base_urls' => [
                'product_image_url' => asset('/storage/product'),
                'customer_image_url' => asset('/storage/profile'),
                'banner_image_url' => asset('/storage/banner'),
                'category_image_url' => asset('/storage/category'),
                'category_banner_image_url' => asset('/storage/category/banner'),
                'review_image_url' => asset('/storage/review'),
                'notification_image_url' => asset('/storage/notification'),
                'restaurant_image_url' => asset('/storage/restaurant'),
                'delivery_man_image_url' => asset('/storage/delivery-man'),
                'chat_image_url' => asset('/storage/conversation'),
                'promotional_url' => asset('/storage/promotion'),
                'kitchen_image_url' => asset('/storage/kitchen'),
                'branch_image_url' => asset('/storage/branch'),
            ],
            'currency_symbol' => $currency_symbol,
            'delivery_charge' => (float)$this->setting('delivery_charge', 0),
            'delivery_management' => $delivery_management,
            'cash_on_delivery' => ($cod['status'] ?? 0) == 1 ? 'true' : 'false',
            'digital_payment' => ($dp['status'] ?? 0) == 1 ? 'true' : 'false',
            'branches' => $this->branch->all(['id', 'name', 'email', 'longitude', 'latitude', 'address', 'coverage', 'status', 'image', 'cover_image']),
            'terms_and_conditions' => $this->setting('terms_and_conditions', ''),
            'privacy_policy' => $this->setting('privacy_policy', ''),
            'about_us' => $this->setting('about_us', ''),
            /*'terms_and_conditions' => route('terms-and-conditions'),
            'privacy_policy' => route('privacy-policy'),
            'about_us' => route('about-us')*/
            'email_verification' => (boolean)Helpers::get_business_settings('email_verification') ?? 0,
            'phone_verification' => (boolean)Helpers::get_business_settings('phone_verification') ?? 0,
            'currency_symbol_position' => Helpers::get_business_settings('currency_symbol_position') ?? 'right',
            'maintenance_mode' => (boolean)Helpers::get_business_settings('maintenance_mode') ?? 0,
            'country' => Helpers::get_business_settings('country') ?? 'BD',
            'self_pickup' => (boolean)Helpers::get_business_settings('self_pickup') ?? 1,
            'delivery' => (boolean)Helpers::get_business_settings('delivery') ?? 1,
            'play_store_config' => [
                "status" => (boolean)($play_store_config['status'] ?? false),
                "link" => $play_store_config['link'] ?? null,
                "min_version" => $play_store_config['min_version'] ?? null
            ],
            'app_store_config' => [
                "status" => (boolean)($app_store_config['status'] ?? false),
                "link" => $app_store_config['link'] ?? null,
                "min_version" => $app_store_config['min_version'] ?? null
            ],
            'social_media_link' => SocialMedia::orderBy('id', 'desc')->active()->get(),
            'software_version' => (string)env('SOFTWARE_VERSION') ?? null,
            'footer_text' => Helpers::get_business_settings('footer_text'),
            'decimal_point_settings' => (int)(Helpers::get_business_settings('decimal_point_settings') ?? 2),
            'schedule_order_slot_duration' => (int)(Helpers::get_business_settings('schedule_order_slot_duration') ?? 30),
            'time_format' => (string)(Helpers::get_business_settings('time_format') ?? '12'),
            'promotion_campaign' => $branch_promotion,
            'social_login' => [
                'google' => (integer)$google,
                'facebook' => (integer)$facebook,
            ],
			'languages' => $language,
            'map_key' => $map_key,
            'wallet_status' => (integer)$this->setting('wallet_status', 0),
            'loyalty_point_status' => (integer)$this->setting('loyalty_point_status', 0),
            'ref_earning_status' => (integer)$this->setting('ref_earning_status', 0),
            'loyalty_point_item_purchase_point' => (float)$this->setting('loyalty_point_item_purchase_point', 0),
            'loyalty_point_exchange_rate' => (float)$this->setting('loyalty_point_exchange_rate', 0),
            'loyalty_point_minimum_point' => (float)$this->setting('loyalty_point_minimum_point', 0),
            'digital_payment_status' => (integer)($digital_payment_status_value['status'] ?? 0),
            'active_payment_method_list' => $active_method_list,
            'whatsapp' => $this->jsonSetting('whatsapp', ['status' => 0, 'number' => '']),
            'cookies_management' => $cookies_management,
            'toggle_dm_registration' => (integer)(Helpers::get_business_settings('dm_self_registration') ?? 0) ,
            'is_veg_non_veg_active' => (integer)(Helpers::get_business_settings('toggle_veg_non_veg') ?? 0) ,
            'otp_resend_time' => Helpers::get_business_settings('otp_resend_time') ?? 60,
            'theme' => (int) $this->setting('theme', 1),
            'bancontact' => (bool) $bancontact,
			'paypal'   => (bool) $paypal,
			'wolt_service' => (bool) $wolt,
			'paystack'   => (bool) $paystack,
            'stripe' => [
                'status' => (bool)($stripe_payment['status'] ?? false),
                'stripe_published_key' => $stripe_payment['published_key'] ?? null,
                'stripe_secret_key' => $stripe_payment['api_key'] ?? null,
            ],
            'dine_in'   => $dineInn,
            'take_way'   => $takeWay,
            'deliver'   => $delivery,
        ], 200);
    }
}
