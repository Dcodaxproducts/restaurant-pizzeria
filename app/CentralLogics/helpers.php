<?php

namespace App\CentralLogics;

use App\CPU\ImageManager;
use App\Model\AddOn;
use App\Model\BusinessSetting;
use App\Model\Currency;
use App\Model\DMReview;
use App\Model\Order;
use App\Model\Product;
use App\Model\Review;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Model\DeliveryMan;

class Helpers
{
    public static function error_processor($validator)
    {
        $err_keeper = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            array_push($err_keeper, ['code' => $index, 'message' => $error[0]]);
        }
        return $err_keeper;
    }

    public static function combinations($arrays)
    {
        $result = [[]];
        foreach ($arrays as $property => $property_values) {
            $tmp = [];
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, [$property => $property_value]);
                }
            }
            $result = $tmp;
        }
        return $result;
    }
    public static function getProductCount($categoryId)
    {
        return Product::whereJsonContains('category_ids', [['id' => (string) $categoryId]])->count();
    }

    public static function variation_price($product, $variation)
    {
        if (empty(json_decode($variation, true))) {
            $result = $product['price'];
        } else {
            $match = json_decode($variation, true)[0];
            $result = 0;
            foreach (json_decode($product['variations'], true) as $property => $value) {
                if ($value['type'] == $match['type']) {
                    $result = $value['price'];
                }
            }
        }
        return self::set_price($result);
    }

    //get new variation price calculation for pos
    public static function new_variation_price($product, $variations)
    {
        $match = $variations;
        $result = 0;

        foreach ($product as $product_variation) {
            foreach ($product_variation['values'] as $option) {
                foreach ($match as $variation) {
                    if ($product_variation['name'] == $variation['name'] && isset($variation['values']) && in_array($option['label'], $variation['values']['label'])) {
                        $result += $option['optionPrice'];
                    }
                }
            }
        }
        return $result;
    }

    //new variation price calculation for order
    public static function get_varient(array $product_variations, array $variations)
    {
        $result = [];
        $variation_price = 0;

        foreach ($variations as $k => $variation) {
            foreach ($product_variations as $product_variation) {
                if (isset($variation['values']) && isset($product_variation['values']) && $product_variation['name'] == $variation['name']) {
                    $result[$k] = $product_variation;
                    $result[$k]['values'] = [];
                    foreach ($product_variation['values'] as $key => $option) {
                        if (in_array($option['label'], $variation['values']['label'])) {
                            $result[$k]['values'][] = $option;
                            $variation_price += $option['optionPrice'];
                        }
                    }
                }
            }
        }

        return ['price' => $variation_price, 'variations' => $result];
    }

    public static function product_data_formatting($data, $multi_data = false)
    {
        $storage = [];

        if ($multi_data == true) {
            foreach ($data as $item) {
                // Calculate the final price based on discount type
                if ($item['discount_type'] == 'amount') {
                    $dis = $item['discount'];
                    $final_price = $item['price'] - $dis;
                    $item['price'] = $final_price;

                } elseif ($item['discount_type'] == 'percent') {
                    $dis = ($item['price'] / 100) * $item['discount'];
                    $final_price = $item['price'] - $dis;
                    $item['price'] = $final_price;
                }

                // $item['remaining_stock'] = $item['stock'];
                // Decode JSON fields
                $addons = is_array(json_decode($item['add_ons'], true)) ? json_decode($item['add_ons'], true) : [];
                $item['category_ids'] = json_decode($item['category_ids'], true);
                $item['choice_options'] = json_decode($item['choice_options'], true);
                $item['add_ons'] = AddOn::whereIn('id', $addons)->get();
                $item['variations'] = json_decode($item['variations'], true);


                // Extract sub_category_id from category_ids where position is 2
                $sub_category_id = null;
                if (is_array($item['category_ids'])) {
                    foreach ($item['category_ids'] as $category) {
                        if (isset($category['position']) && $category['position'] == 2) {
                            $sub_category_id = $category['id'];
                            break;
                        }
                    }
                }
                $item['sub_category_id'] = $sub_category_id;

                // Handle translations
                $itemTranslations = [];
                if (isset($item['translations']) && count($item['translations'])) {
                    foreach ($item['translations'] as $translation) {
                        if ($translation->key == 'name') {
                            $itemTranslations['name'][] = [
                                'id' => $translation->id,
                                'translationable_type' => $translation->translationable_type,
                                'translationable_id' => $translation->translationable_id,
                                'locale' => $translation->locale,
                                'value' => $translation->value,
                            ];
                        }
                        if ($translation->key == 'description') {
                            $itemTranslations['description'][] = [
                                'id' => $translation->id,
                                'translationable_type' => $translation->translationable_type,
                                'translationable_id' => $translation->translationable_id,
                                'locale' => $translation->locale,
                                'value' => $translation->value,
                            ];
                        }
                    }
                }
                unset($item['translations']);
                $item['translations'] = !empty($itemTranslations) ? $itemTranslations : null;

                $storage[] = $item;
            }
            $data = $storage;
        } else {
            // Handle single data case
            $data_addons = $data['add_ons'];
            $addon_ids = [];
            if (gettype($data_addons) != 'array') {
                $addon_ids = json_decode($data_addons, true);
            } elseif (gettype($data_addons) == 'array' && isset($data_addons[0]['id'])) {
                foreach ($data_addons as $addon) {
                    $addon_ids[] = $addon['id'];
                }
            } else {
                $addon_ids = $data_addons;
            }

            // Decode JSON fields
            $data['category_ids'] = gettype($data['category_ids']) != 'array' ? json_decode($data['category_ids'], true) : $data['category_ids'];
            $data['attributes'] = gettype($data['attributes']) != 'array' ? json_decode($data['attributes'], true) : $data['attributes'];
            $data['choice_options'] = gettype($data['choice_options']) != 'array' ? json_decode($data['choice_options'], true) : $data['choice_options'];
            $data['add_ons'] = AddOn::whereIn('id', $addon_ids)->get();
            $data['variations'] = json_decode($data['variations'], true);


            // Extract sub_category_id from category_ids where position is 2
            $sub_category_id = null;
            if (is_array($data['category_ids'])) {
                foreach ($data['category_ids'] as $category) {
                    if (isset($category['position']) && $category['position'] == 2) {
                        $sub_category_id = $category['id'];
                        break;
                    }
                }
            }
            $data['sub_category_id'] = $sub_category_id;
        }

        return $data;
    }


    public static function order_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        if ($multi_data) {
            foreach ($data as $item) {
                $item['add_on_ids'] = isset($item['add_on_ids']) ? json_decode($item['add_on_ids'], true) : [];

                if (isset($item->details) && is_iterable($item->details)) {
                    foreach ($item->details as $key => $detail) {
                        $productDetails = gettype($detail['product_details']) != 'array'
                            ? (array) json_decode($detail['product_details'], true)
                            : (array) $detail['product_details'];

                        if (isset($productDetails['category_ids']) && is_array($productDetails['category_ids'])) {
                            foreach ($productDetails['category_ids'] as $category) {
                                if (isset($category['position']) && $category['position'] == 2) {
                                    $productDetails['subcategory_id'] = $category['id'];
                                    break;
                                }
                            }
                        }

                        // Calculate the final price based on discount type
                        if (isset($productDetails['discount_type']) && $productDetails['discount_type'] == 'amount') {
                            $dis = $productDetails['discount'];
                            $final_price = $productDetails['price'] - $dis;
                            $productDetails['price'] = $final_price;
                        } elseif (isset($productDetails['discount_type']) && $productDetails['discount_type'] == 'percent') {
                            $dis = ($productDetails['price'] / 100) * $productDetails['discount'];
                            $final_price = $productDetails['price'] - $dis;
                            $productDetails['price'] = $final_price;
                        }

                        $detail['product_details'] = $productDetails;

                        $detail['variation'] = isset($detail['variation']) && gettype($detail['variation']) != 'array'
                            ? (array) json_decode($detail['variation'], true)
                            : (array) ($detail['variation'] ?? []);

                        $detail['add_on_ids'] = isset($detail['add_on_ids']) && gettype($detail['add_on_ids']) != 'array'
                            ? (array) json_decode($detail['add_on_ids'], true)
                            : (array) ($detail['add_on_ids'] ?? []);

                        $detail['add_ons'] = isset($detail['add_on_ids']) && gettype($detail['add_on_ids']) != 'array'
                            ? AddOn::whereIn('id', (array) json_decode($detail['add_on_ids'], true))->get()
                            : AddOn::whereIn('id', (array) ($detail['add_on_ids'] ?? []))->get();

                        $detail['variant'] = isset($detail['variant']) && gettype($detail['variant']) != 'array'
                            ? (array) json_decode($detail['variant'], true)
                            : (array) ($detail['variant'] ?? []);

                        $detail['add_on_qtys'] = isset($detail['add_on_qtys']) && gettype($detail['add_on_qtys']) != 'array'
                            ? (array) json_decode($detail['add_on_qtys'], true)
                            : (array) ($detail['add_on_qtys'] ?? []);

                        $detail['product_details'] = Helpers::product_formatter($detail['product_details']);

                        $item->details[$key] = $detail;
                    }
                } else {
                    $item['details'] = [];
                }
                $storage[] = $item;
            }
            $data = $storage;
        } else {
            if (!is_array($data) && !is_object($data)) {
                return $data;
            }

            $data['add_on_ids'] = isset($data['add_on_ids']) ? json_decode($data['add_on_ids'], true) : [];

            if (isset($data->details) && is_iterable($data->details)) {
                foreach ($data->details as $key => $detail) {
                    $productDetails = gettype($detail['product_details']) != 'array'
                        ? (array) json_decode($detail['product_details'], true)
                        : (array) $detail['product_details'];

                    if (isset($productDetails['category_ids'])) {
                        $categoryIds = $productDetails['category_ids'];

                        if (!is_array($categoryIds)) {
                            $categoryIds = json_decode($categoryIds, true) ?? [];
                        }

                        foreach ($categoryIds as $category) {
                            if (isset($category['position']) && $category['position'] == 2) {
                                $productDetails['subcategory_id'] = $category['id'];
                                break;
                            }
                        }
                    }


                    $detail['product_details'] = $productDetails;

                    $detail['variation'] = isset($detail['variation']) && gettype($detail['variation']) != 'array'
                        ? (array) json_decode($detail['variation'], true)
                        : (array) ($detail['variation'] ?? []);

                    $detail['add_on_ids'] = isset($detail['add_on_ids']) && gettype($detail['add_on_ids']) != 'array'
                        ? (array) json_decode($detail['add_on_ids'], true)
                        : (array) ($detail['add_on_ids'] ?? []);

                    $detail['variant'] = isset($detail['variant']) && gettype($detail['variant']) != 'array'
                        ? (array) json_decode($detail['variant'], true)
                        : (array) ($detail['variant'] ?? []);

                    $detail['add_on_qtys'] = isset($detail['add_on_qtys']) && gettype($detail['add_on_qtys']) != 'array'
                        ? (array) json_decode($detail['add_on_qtys'], true)
                        : (array) ($detail['add_on_qtys'] ?? []);

                    $detail['product_details'] = Helpers::product_formatter($detail['product_details']);

                    $data->details[$key] = $detail;
                }
            } else {
                $data['details'] = [];
            }
        }

        return $data;
    }



    public static function get_business_settings($name)
    {
        $config = null;
        $data = \App\Model\BusinessSetting::where(['key' => $name])->first();
        if (isset($data)) {
            $config = json_decode($data['value'], true);
            if (is_null($config)) {
                $config = $data['value'];
            }
        }
        return $config;
    }

    public static function currency_code()
    {
        $currency_code = BusinessSetting::where(['key' => 'currency'])->first()?->value ?? 'USD';
        return $currency_code;
    }

    public static function currency_symbol()
    {
        $currency_symbol = Currency::where(['currency_code' => Helpers::currency_code()])->first()?->currency_symbol ?? '$';
        return $currency_symbol;
    }

    public static function set_symbol($amount)
    {
        $decimal_point_settings = Helpers::get_business_settings('decimal_point_settings');
        $position = Helpers::get_business_settings('currency_symbol_position');
        if (!is_null($position) && $position == 'left') {
            $string = self::currency_symbol() . '' . number_format($amount, $decimal_point_settings);
        } else {
            $string = number_format($amount, $decimal_point_settings) . '' . self::currency_symbol();
        }
        return $string;
    }

    public static function set_price($amount)
    {
        $decimal_point_settings = Helpers::get_business_settings('decimal_point_settings');
        $amount = number_format($amount, $decimal_point_settings, '.', '');

        return $amount;
    }
    public static function checkAndReassignDrivers()
    {
        $orders = Order::whereNull('delivery_man_id')
            ->where('order_status', 'confirmed')
            ->get();

        foreach ($orders as $order) {

            if ($order->notified_at && now()->diffInMinutes($order->notified_at) < 1) {
                continue;
            }

            // Fetch all active drivers
            $allActiveDrivers = DeliveryMan::active()->get();

            $data = [
                'title' => translate('You have a new order'),
                'description' => translate('You have a new message'),
                'order_id' => $order->id,
                'image' => '',
                'type' => 'new_order',
            ];

            try {
                $requestedDriverIds = $order->requested_driver_id ? json_decode($order->requested_driver_id, true) : [];

                if ($allActiveDrivers->isNotEmpty()) {
                    // Map drivers to their order count for the day
                    $driverOrderCounts = $allActiveDrivers->mapWithKeys(function ($driver) {
                        $orderCount = Order::where('delivery_man_id', $driver->id)
                            ->whereDate('created_at', now()->toDateString())
                            ->count();
                        return [$driver->id => $orderCount];
                    });

                    // Exclude already requested drivers
                    $availableDrivers = $driverOrderCounts->except($requestedDriverIds);

                    // Get the next 2 drivers with the least orders
                    $driversWithLeastOrders = $availableDrivers->sort()->keys()->take(2);

                    if ($driversWithLeastOrders->isNotEmpty()) {
                        // Remove previously requested driver IDs
                        $requestedDriverIds = [];

                        foreach ($driversWithLeastOrders as $driverId) {
                            $driver = $allActiveDrivers->firstWhere('id', $driverId);

                            // Send push notification to each driver
                            self::send_push_notif_to_device($driver->fcm_token, $data);

                            // Add new driver ID to the requested list
                            $requestedDriverIds[] = $driver->id;
                        }

                        // Update the order with the new requested driver IDs and timestamp
                        $order->requested_driver_id = json_encode($requestedDriverIds);
                        $order->notified_at = now();
                        $order->save();
                    }
                }
            } catch (\Exception $e) {
                info($e->getMessage());
            }
        }

        return response()->json(['message' => 'successfully updated!'], 200);
    }

    public static function send_push_notif_to_device($fcm_token, $data)
    {
        $key = self::get_business_settings('push_notification_key');
        $url = "https://fcm.googleapis.com/fcm/send";
        $header = array(
            "authorization: key=" . $key . "",
            "content-type: application/json"
        );

        $postdata = '{
            "to" : "' . $fcm_token . '",
            "mutable_content": true,
            "data" : {
                "title":"' . $data['title'] . '",
                "body" : "' . $data['description'] . '",
                "image" : "' . $data['image'] . '",
                "order_id":"' . $data['order_id'] . '",
                "type":"' . $data['type'] . '",
                "is_read": 0
            },
            "notification" : {
                "title" :"' . $data['title'] . '",
                "body" : "' . $data['description'] . '",
                "image" : "' . $data['image'] . '",
                "order_id":"' . $data['order_id'] . '",
                "title_loc_key":"' . $data['order_id'] . '",
                "body_loc_key":"' . $data['type'] . '",
                "type":"' . $data['type'] . '",
                "is_read": 0,
                "icon" : "new",
                "sound": "notification",
                "android_channel_id": "Dcodax efood"
            }
        }';
        $ch = curl_init();
        $timeout = 120;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        // Get URL content
        $result = curl_exec($ch);
        // close handle to release resources
        curl_close($ch);

        return $result;
    }

    public static function send_push_notif_to_topic($data, $topic, $type)
    {
        $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()?->value ?? '';

        $url = "https://fcm.googleapis.com/fcm/send";
        $header = array(
            "authorization: key=" . $key . "",
            "content-type: application/json"
        );
        if (isset($data['order_id'])) {
            $postdata = '{
                "to" : "/topics/' . $topic . '",
                "mutable_content": true,
                "data" : {
                    "title":"' . $data['title'] . '",
                    "body" : "' . $data['description'] . '",
                    "image" : "' . $data['image'] . '",
                    "order_id":"' . $data['order_id'] . '",
                    "is_read": 0,
                    "type":"' . $type . '"
                },
                "notification" : {
                    "title":"' . $data['title'] . '",
                    "body" : "' . $data['description'] . '",
                    "image" : "' . $data['image'] . '",
                    "order_id":"' . $data['order_id'] . '",
                    "title_loc_key":"' . $data['order_id'] . '",
                    "body_loc_key":"' . $type . '",
                    "type":"' . $type . '",
                    "is_read": 0,
                    "icon" : "new",
                    "sound": "notification",
                    "android_channel_id": "efood"
                  }
            }';
        } else {
            $postdata = '{
                "to" : "/topics/' . $topic . '",
                "mutable_content": true,
                "data" : {
                    "title":"' . $data['title'] . '",
                    "body" : "' . $data['description'] . '",
                    "image" : "' . $data['image'] . '",
                    "is_read": 0,
                    "type":"' . $type . '",

                },
                "notification" : {
                    "title":"' . $data['title'] . '",
                    "body" : "' . $data['description'] . '",
                    "image" : "' . $data['image'] . '",
                    "body_loc_key":"' . $type . '",
                    "type":"' . $type . '",
                    "is_read": 0,
                    "icon" : "new",
                    "sound": "notification",
                    "android_channel_id": "efood"
                  }
            }';
        }

        $ch = curl_init();
        $timeout = 120;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        // Get URL content
        $result = curl_exec($ch);
        // close handle to release resources
        curl_close($ch);

        return $result;
    }

    public static function rating_count($product_id, $rating)
    {
        return Review::where(['product_id' => $product_id, 'rating' => $rating])->count();
    }

    public static function dm_rating_count($deliveryman_id, $rating)
    {
        return DMReview::where(['delivery_man_id' => $deliveryman_id, 'rating' => $rating])->count();
    }

    public static function tax_calculate($product, $price)
    {
        if ($product['tax_type'] == 'percent') {
            $price_tax = ($price / 100) * $product['tax'];
        } else {
            $price_tax = $product['tax'];
        }
        return self::set_price($price_tax);
    }

    public static function discount_calculate($product, $price)
    {
        if ($product['discount_type'] == 'percent') {
            $price_discount = ($price / 100) * $product['discount'];
        } else {
            $price_discount = $product['discount'];
        }
        return self::set_price($price_discount);
    }

    public static function max_earning()
    {
        $data = Order::where(['order_status' => 'delivered'])->select('id', 'created_at', 'order_amount')
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('m');
            });

        $max = 0;
        foreach ($data as $month) {
            $count = 0;
            foreach ($month as $order) {
                $count += $order['order_amount'];
            }
            if ($count > $max) {
                $max = $count;
            }
        }
        return $max;
    }

    public static function max_orders()
    {
        $data = Order::select('id', 'created_at')
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('m');
            });

        $max = 0;
        foreach ($data as $month) {
            $count = 0;
            foreach ($month as $order) {
                $count += 1;
            }
            if ($count > $max) {
                $max = $count;
            }
        }
        return $max;
    }

    public static function order_status_update_message($status)
    {
        if ($status == 'pending') {
            $data = self::get_business_settings('order_pending_message');
        } elseif ($status == 'confirmed') {
            $data = self::get_business_settings('order_confirmation_msg');
        } elseif ($status == 'processing') {
            $data = self::get_business_settings('order_processing_message');
        } elseif ($status == 'out_for_delivery') {
            $data = self::get_business_settings('out_for_delivery_message');
        } elseif ($status == 'delivered') {
            $data = self::get_business_settings('order_delivered_message');
        } elseif ($status == 'delivery_boy_delivered') {
            $data = self::get_business_settings('delivery_boy_delivered_message');
        } elseif ($status == 'del_assign') {
            $data = self::get_business_settings('delivery_boy_assign_message');
        } elseif ($status == 'ord_start') {
            $data = self::get_business_settings('delivery_boy_start_message');
        } elseif ($status == 'returned') {
            $data = self::get_business_settings('returned_message');
        } elseif ($status == 'failed') {
            $data = self::get_business_settings('failed_message');
        } elseif ($status == 'canceled') {
            $data = self::get_business_settings('canceled_message');
        } elseif ($status == 'customer_notify_message') {
            $data = self::get_business_settings('customer_notify_message');
        } elseif ($status == 'customer_notify_message_for_time_change') {
            $data = self::get_business_settings('customer_notify_message_for_time_change');
        } else {
            $data['status'] = 0;
            $data['message'] = "";
            //            $data = '{"status":"0","message":""}';

        }

        if ($data == null || (array_key_exists('status', $data) && $data['status'] == 0)) {
            return 0;
        }

        return $data['message'];
    }

    public static function day_part()
    {
        $part = "";
        $morning_start = date("h:i:s", strtotime("5:00:00"));
        $afternoon_start = date("h:i:s", strtotime("12:01:00"));
        $evening_start = date("h:i:s", strtotime("17:01:00"));
        $evening_end = date("h:i:s", strtotime("21:00:00"));

        if (time() >= $morning_start && time() < $afternoon_start) {
            $part = "morning";
        } elseif (time() >= $afternoon_start && time() < $evening_start) {
            $part = "afternoon";
        } elseif (time() >= $evening_start && time() <= $evening_end) {
            $part = "evening";
        } else {
            $part = "night";
        }

        return $part;
    }

    public static function env_update($key, $value)
    {
        $path = base_path('.env');
        if (file_exists($path)) {
            file_put_contents($path, str_replace(
                $key . '=' . env($key),
                $key . '=' . $value,
                file_get_contents($path)
            ));
        }
    }

    public static function env_key_replace($key_from, $key_to, $value)
    {
        $path = base_path('.env');
        if (file_exists($path)) {
            file_put_contents($path, str_replace(
                $key_from . '=' . env($key_from),
                $key_to . '=' . $value,
                file_get_contents($path)
            ));
        }
    }

    public static function remove_dir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir")
                        Helpers::remove_dir($dir . "/" . $object);
                    else
                        unlink($dir . "/" . $object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public static function get_language_name($key)
    {
        $languages = array(
            "af" => "Afrikaans",
            "sq" => "Albanian - shqip",
            "am" => "Amharic - አማርኛ",
            "ar" => "Arabic - العربية",
            "an" => "Aragonese - aragonés",
            "hy" => "Armenian - հայերեն",
            "ast" => "Asturian - asturianu",
            "az" => "Azerbaijani - azərbaycan dili",
            "eu" => "Basque - euskara",
            "be" => "Belarusian - беларуская",
            "bn" => "Bengali - বাংলা",
            "bs" => "Bosnian - bosanski",
            "br" => "Breton - brezhoneg",
            "bg" => "Bulgarian - български",
            "ca" => "Catalan - català",
            "ckb" => "Central Kurdish - کوردی (دەستنوسی عەرەبی)",
            "zh" => "Chinese - 中文",
            "zh-HK" => "Chinese (Hong Kong) - 中文（香港）",
            "zh-CN" => "Chinese (Simplified) - 中文（简体）",
            "zh-TW" => "Chinese (Traditional) - 中文（繁體）",
            "co" => "Corsican",
            "hr" => "Croatian - hrvatski",
            "cs" => "Czech - čeština",
            "da" => "Danish - dansk",
            "nl" => "Dutch - Nederlands",
            "en" => "English",
            "en-AU" => "English (Australia)",
            "en-CA" => "English (Canada)",
            "en-IN" => "English (India)",
            "en-NZ" => "English (New Zealand)",
            "en-ZA" => "English (South Africa)",
            "en-GB" => "English (United Kingdom)",
            "en-US" => "English (United States)",
            "eo" => "Esperanto - esperanto",
            "et" => "Estonian - eesti",
            "fo" => "Faroese - føroyskt",
            "fil" => "Filipino",
            "fi" => "Finnish - suomi",
            "fr" => "French - français",
            "fr-CA" => "French (Canada) - français (Canada)",
            "fr-FR" => "French (France) - français (France)",
            "fr-CH" => "French (Switzerland) - français (Suisse)",
            "gl" => "Galician - galego",
            "ka" => "Georgian - ქართული",
            "de" => "German - Deutsch",
            "de-AT" => "German (Austria) - Deutsch (Österreich)",
            "de-DE" => "German (Germany) - Deutsch (Deutschland)",
            "de-LI" => "German (Liechtenstein) - Deutsch (Liechtenstein)",
            "de-CH" => "German (Switzerland) - Deutsch (Schweiz)",
            "el" => "Greek - Ελληνικά",
            "gn" => "Guarani",
            "gu" => "Gujarati - ગુજરાતી",
            "ha" => "Hausa",
            "haw" => "Hawaiian - ʻŌlelo Hawaiʻi",
            "he" => "Hebrew - עברית",
            "hi" => "Hindi - हिन्दी",
            "hu" => "Hungarian - magyar",
            "is" => "Icelandic - íslenska",
            "id" => "Indonesian - Indonesia",
            "ia" => "Interlingua",
            "ga" => "Irish - Gaeilge",
            "it" => "Italian - italiano",
            "it-IT" => "Italian (Italy) - italiano (Italia)",
            "it-CH" => "Italian (Switzerland) - italiano (Svizzera)",
            "ja" => "Japanese - 日本語",
            "kn" => "Kannada - ಕನ್ನಡ",
            "kk" => "Kazakh - қазақ тілі",
            "km" => "Khmer - ខ្មែរ",
            "ko" => "Korean - 한국어",
            "ku" => "Kurdish - Kurdî",
            "ky" => "Kyrgyz - кыргызча",
            "lo" => "Lao - ລາວ",
            "la" => "Latin",
            "lv" => "Latvian - latviešu",
            "ln" => "Lingala - lingála",
            "lt" => "Lithuanian - lietuvių",
            "mk" => "Macedonian - македонски",
            "ms" => "Malay - Bahasa Melayu",
            "ml" => "Malayalam - മലയാളം",
            "mt" => "Maltese - Malti",
            "mr" => "Marathi - मराठी",
            "mn" => "Mongolian - монгол",
            "ne" => "Nepali - नेपाली",
            "no" => "Norwegian - norsk",
            "nb" => "Norwegian Bokmål - norsk bokmål",
            "nn" => "Norwegian Nynorsk - nynorsk",
            "oc" => "Occitan",
            "or" => "Oriya - ଓଡ଼ିଆ",
            "om" => "Oromo - Oromoo",
            "ps" => "Pashto - پښتو",
            "fa" => "Persian - فارسی",
            "pl" => "Polish - polski",
            "pt" => "Portuguese - português",
            "pt-BR" => "Portuguese (Brazil) - português (Brasil)",
            "pt-PT" => "Portuguese (Portugal) - português (Portugal)",
            "pa" => "Punjabi - ਪੰਜਾਬੀ",
            "qu" => "Quechua",
            "ro" => "Romanian - română",
            "mo" => "Romanian (Moldova) - română (Moldova)",
            "rm" => "Romansh - rumantsch",
            "ru" => "Russian - русский",
            "gd" => "Scottish Gaelic",
            "sr" => "Serbian - српски",
            "sh" => "Serbo-Croatian - Srpskohrvatski",
            "sn" => "Shona - chiShona",
            "sd" => "Sindhi",
            "si" => "Sinhala - සිංහල",
            "sk" => "Slovak - slovenčina",
            "sl" => "Slovenian - slovenščina",
            "so" => "Somali - Soomaali",
            "st" => "Southern Sotho",
            "es" => "Spanish - español",
            "es-AR" => "Spanish (Argentina) - español (Argentina)",
            "es-419" => "Spanish (Latin America) - español (Latinoamérica)",
            "es-MX" => "Spanish (Mexico) - español (México)",
            "es-ES" => "Spanish (Spain) - español (España)",
            "es-US" => "Spanish (United States) - español (Estados Unidos)",
            "su" => "Sundanese",
            "sw" => "Swahili - Kiswahili",
            "sv" => "Swedish - svenska",
            "tg" => "Tajik - тоҷикӣ",
            "ta" => "Tamil - தமிழ்",
            "tt" => "Tatar",
            "te" => "Telugu - తెలుగు",
            "th" => "Thai - ไทย",
            "ti" => "Tigrinya - ትግርኛ",
            "to" => "Tongan - lea fakatonga",
            "tr" => "Turkish - Türkçe",
            "tk" => "Turkmen",
            "tw" => "Twi",
            "uk" => "Ukrainian - українська",
            "ur" => "Urdu - اردو",
            "ug" => "Uyghur",
            "uz" => "Uzbek - o‘zbek",
            "vi" => "Vietnamese - Tiếng Việt",
            "wa" => "Walloon - wa",
            "cy" => "Welsh - Cymraeg",
            "fy" => "Western Frisian",
            "xh" => "Xhosa",
            "yi" => "Yiddish",
            "yo" => "Yoruba - Èdè Yorùbá",
            "zu" => "Zulu - isiZulu",
        );
        return array_key_exists($key, $languages) ? $languages[$key] : $key;
    }

    public static function language_load()
    {
        if (\session()->has('language_settings')) {
            $language = \session('language_settings');
        } else {
            $language = BusinessSetting::where('key', 'language')->first();
            \session()->put('language_settings', $language);
        }
        return $language;
    }

    public static function upload(string $dir, string $format, $image = null)
    {
        if ($image != null) {
            $imageName = \Carbon\Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
            if (!Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->makeDirectory($dir);
            }
            Storage::disk('public')->put($dir . $imageName, file_get_contents($image));
        } else {
            $imageName = 'def.png';
        }

        return $imageName;
    }

    public static function update(string $dir, $old_image, string $format, $image = null)
    {
        if (Storage::disk('public')->exists($dir . $old_image)) {
            Storage::disk('public')->delete($dir . $old_image);
        }
        $imageName = Helpers::upload($dir, $format, $image);
        return $imageName;
    }

    public static function delete($full_path)
    {
        if (Storage::disk('public')->exists($full_path)) {
            Storage::disk('public')->delete($full_path);
        }
        return [
            'success' => 1,
            'message' => 'Removed successfully !'
        ];
    }

    public static function setEnvironmentValue($envKey, $envValue)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        if (is_bool(env($envKey))) {
            $oldValue = var_export(env($envKey), true);
        } else {
            $oldValue = env($envKey);
        }
        //        $oldValue = var_export(env($envKey), true);

        if (strpos($str, $envKey) !== false) {
            $str = str_replace("{$envKey}={$oldValue}", "{$envKey}={$envValue}", $str);

            //            dd("{$envKey}={$envValue}");
//            dd($str);
        } else {
            $str .= "{$envKey}={$envValue}\n";
        }
        $fp = fopen($envFile, 'w');
        fwrite($fp, $str);
        fclose($fp);
        return $envValue;
    }

    public static function requestSender($request): array
    {
        $remove = array("http://", "https://", "www.");
        $url = str_replace($remove, "", url('/'));

        $post = [
            base64_decode('dXNlcm5hbWU=') => $request['username'],//un
            base64_decode('cHVyY2hhc2Vfa2V5') => $request['purchase_key'],//pk
            base64_decode('c29mdHdhcmVfaWQ=') => base64_decode(env(base64_decode('U09GVFdBUkVfSUQ='))),//sid
            base64_decode('ZG9tYWlu') => $url,
        ];

        //session()->put('domain', 'https://' . preg_replace("#^[^:/.]*[:/]+#i", "", $request['domain']));

        $ch = curl_init('');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $response = curl_exec($ch);
        curl_close($ch);

        try {
            if (base64_decode(json_decode($response, true)['active'])) {
                return [
                    'active' => (int) base64_decode(json_decode($response, true)['active'])
                ];
            }
            return [
                'active' => 0
            ];
        } catch (\Exception $exception) {
            return [
                'active' => 1
            ];
        }
    }

    public static function getPagination()
    {
        $pagination_limit = Helpers::get_business_settings('pagination_limit');
        return $pagination_limit ?? 25;
    }

    public static function remove_invalid_charcaters($str)
    {
        return str_ireplace(['\'', '"', ';', '<', '>', '?'], ' ', $str);
    }

    public static function get_delivery_charge($distance)
    {
        $config = self::get_business_settings('delivery_management');

        if (($config['status'] ?? 0) != 1) {
            $delivery_charge = BusinessSetting::where(['key' => 'delivery_charge'])->first()?->value ?? 0;
            return $delivery_charge;
        } else {
            $delivery_charge = 0;
            $min_shipping_charge = $config['min_shipping_charge'] ?? 0;
            $shipping_per_km = $config['shipping_per_km'] ?? 0;

            $delivery_charge = $shipping_per_km * $distance;

            if ($delivery_charge > $min_shipping_charge) {
                return self::set_price($delivery_charge);
            } else {
                return self::set_price($min_shipping_charge);
            }
        }
    }

    public static function calculate_addon_price($addons, $add_on_qtys)
    {
        $add_ons_cost = 0;
        $data = [];
        if ($addons) {
            foreach ($addons as $key2 => $addon) {
                if ($add_on_qtys == null) {
                    $add_on_qty = 1;
                } else {
                    $add_on_qty = $add_on_qtys[$key2];
                }
                $data[] = $addon->id;
                $add_ons_cost += $addon['price'] * $add_on_qty;
            }
            return ['addons' => $data, 'total_add_on_price' => self::set_price($add_ons_cost)];
        }
        info('yahana error h djsjdsbc', $data);
        return null;
    }


    public static function get_default_language()
    {
        $data = self::get_business_settings('language');
        $default_lang = 'en';
        if ($data && array_key_exists('code', $data)) {
            foreach ($data as $lang) {
                if ($lang['default'] == true) {
                    $default_lang = $lang['code'];
                }
            }
        }

        return $default_lang;
    }

    public static function module_permission_check($mod_name)
    {
        $permission = auth('admin')->user()->role->module_access ?? null;
        if (isset($permission) && in_array($mod_name, (array) json_decode($permission)) == true) {
            return true;
        }

        if (auth('admin')->user()->admin_role_id == 1) {
            return true;
        }
        return false;
    }

    public static function file_remover(string $dir, $image)
    {
        if (!isset($image))
            return true;

        if (Storage::disk('public')->exists($dir . $image))
            Storage::disk('public')->delete($dir . $image);

        return true;
    }

    public static function order_details_formatter($details)
    {
        if ($details->count() > 0) {
            foreach ($details as $detail) {
                $detail['product_details'] = gettype($detail['product_details']) != 'array' ? (array) json_decode($detail['product_details'], true) : (array) $detail['product_details'];
                $detail['variation'] = gettype($detail['variation']) != 'array' ? (array) json_decode($detail['variation'], true) : (array) $detail['variation'];
                $detail['add_on_ids'] = gettype($detail['add_on_ids']) != 'array' ? (array) json_decode($detail['add_on_ids'], true) : (array) $detail['add_on_ids'];
                $detail['variant'] = gettype($detail['variant']) != 'array' ? (array) json_decode($detail['variant'], true) : (array) $detail['variant'];
                $detail['add_on_qtys'] = gettype($detail['add_on_qtys']) != 'array' ? (array) json_decode($detail['add_on_qtys'], true) : (array) $detail['add_on_qtys'];
                $detail['add_on_prices'] = gettype($detail['add_on_prices']) != 'array' ? (array) json_decode($detail['add_on_prices'], true) : (array) $detail['add_on_prices'];
                $detail['add_on_taxes'] = gettype($detail['add_on_taxes']) != 'array' ? (array) json_decode($detail['add_on_taxes'], true) : (array) $detail['add_on_taxes'];



                //                if(count($detail->variation) > 0) {
//                    $detail['variation'] = $detail->variation[0] ?? null; //first element is given, since variation can't be multiple
//                } else {
//                    $detail['variation'] = null;
//                }

                if (!isset($detail['reviews_count'])) {
                    $detail['review_count'] = Review::where(['order_id' => $detail['order_id'], 'product_id' => $detail['product_id']])->count();
                }

                $detail['product_details'] = Helpers::product_formatter($detail['product_details']);

                $product_availability = Product::where('id', $detail['product_id'])->first();
                $detail['is_product_available'] = isset($product_availability) ? 1 : 0;
            }
        }

        return $details;
    }

    public static function product_formatter($product)
    {
        // Normalize 'variations'
        if (empty($product['variations']) || $product['variations'] === "[]") {
            $product['variations'] = [];
        } else {
            $product['variations'] = (gettype($product['variations']) !== 'array')
                ? (array) json_decode($product['variations'], true)
                : (array) $product['variations'];
        }

        // Normalize 'add_ons'
        if (empty($product['add_ons']) || $product['add_ons'] === "[]") {
            $product['add_ons'] = [];
        } else {
            $product['add_ons'] = (gettype($product['add_ons']) !== 'array')
                ? (array) json_decode($product['add_ons'], true)
                : (array) $product['add_ons'];
        }

        // Normalize 'attributes'
        if (empty($product['attributes']) || $product['attributes'] === "[]") {
            $product['attributes'] = [];
        } else {
            $product['attributes'] = (gettype($product['attributes']) !== 'array')
                ? (array) json_decode($product['attributes'], true)
                : (array) $product['attributes'];
        }

        // Normalize 'category_ids'
        if (empty($product['category_ids']) || $product['category_ids'] === "[]") {
            $product['category_ids'] = [];
        } else {
            $product['category_ids'] = (gettype($product['category_ids']) !== 'array')
                ? (array) json_decode($product['category_ids'], true)
                : (array) $product['category_ids'];
        }

        // Normalize 'tags'
        if (empty($product['tags']) || $product['tags'] === "[]") {
            $product['tags'] = [];
        } else {
            $product['tags'] = (gettype($product['tags']) !== 'array')
                ? (array) json_decode($product['tags'], true)
                : (array) $product['tags'];
        }

        // Normalize 'translations'
        if (empty($product['translations']) || $product['translations'] === "[]") {
            $product['translations'] = [
                'name' => [],
                'description' => [],
            ];
        } else {
            $product['translations'] = (gettype($product['translations']) !== 'array')
                ? (array) json_decode($product['translations'], true)
                : (array) $product['translations'];

            $formattedTranslations = [
                'name' => [],
                'description' => [],
            ];

            foreach ($product['translations'] as $translation) {
                if (isset($translation['key']) && $translation['key'] === 'name') {
                    $formattedTranslations['name'][] = [
                        'id' => $translation['id'],
                        'locale' => $translation['locale'],
                        'value' => $translation['value'],
                    ];
                } elseif (isset($translation['key']) && $translation['key'] === 'description') {
                    $formattedTranslations['description'][] = [
                        'id' => $translation['id'],
                        'locale' => $translation['locale'],
                        'value' => $translation['value'],
                    ];
                }
            }

            $product['translations'] = $formattedTranslations;
        }

        // Normalize 'choice_options'
        if (empty($product['choice_options']) || $product['choice_options'] === "[]") {
            $product['choice_options'] = [];
        } else {
            $product['choice_options'] = (gettype($product['choice_options']) !== 'array')
                ? (array) json_decode($product['choice_options'], true)
                : (array) $product['choice_options'];
        }

        try {
            $addons = [];
            foreach ($product['add_ons'] as $add_on_id) {
                $addon = AddOn::find($add_on_id);
                if (isset($addon)) {
                    $addons[] = $addon;
                }
            }
            $product['add_ons'] = $addons;
        } catch (\Exception $exception) {
            // Handle exception if needed, e.g. log error
        }

        return $product;
    }

    public static function generate_referer_code()
    {
        $ref_code = Str::random('20');
        if (User::where('refer_code', '=', $ref_code)->exists()) {
            return generate_referer_code();
        }
        return $ref_code;
    }

    public static function mighty_get_distance_matrix($pick_lat, $pick_lng, $drop_lat, $drop_lng, $traffic = false)
    {

        $google_map_api_key = \App\Model\BusinessSetting::where(['key' => 'map_api_server_key'])->select('value')->first();

        $response = Http::withHeaders([
            'Accept-Language' => request('language'),
        ])->get('https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . $pick_lat . ',' . $pick_lng . '&destinations=' . $drop_lat . ',' . $drop_lng . '&key=' . $google_map_api_key['value'] . '&mode=driving');

        $responses = $response->json();


        return $responses;
    }

    public static function get_dialing_code($key)
    {
        $dialingCodes = array(
            'AF' => '+93',
            'AX' => '+358',
            'AL' => '+355',
            'DZ' => '+213',
            'AS' => '+1 684',
            'AD' => '+376',
            'AO' => '+244',
            'AI' => '+1 264',
            'AQ' => '+672',
            'AG' => '+1 268',
            'AR' => '+54',
            'AM' => '+374',
            'AW' => '+297',
            'AU' => '+61',
            'AT' => '+43',
            'AZ' => '+994',
            'BS' => '+1 242',
            'BH' => '+973',
            'BD' => '+880',
            'BB' => '+1 246',
            'BY' => '+375',
            'BE' => '+32',
            'BZ' => '+501',
            'BJ' => '+229',
            'BM' => '+1 441',
            'BT' => '+975',
            'BO' => '+591',
            'BQ' => '+599',
            'BA' => '+387',
            'BW' => '+267',
            'BV' => '',
            'BR' => '+55',
            'IO' => '+246',
            'BN' => '+673',
            'BG' => '+359',
            'BF' => '+226',
            'BI' => '+257',
            'KH' => '+855',
            'CM' => '+237',
            'CA' => '+1',
            'CV' => '+238',
            'KY' => '+1 345',
            'CF' => '+236',
            'TD' => '+235',
            'CL' => '+56',
            'CN' => '+86',
            'CX' => '+61',
            'CC' => '+61',
            'CO' => '+57',
            'KM' => '+269',
            'CG' => '+242',
            'CD' => '+243',
            'CK' => '+682',
            'CR' => '+506',
            'CI' => '+225',
            'HR' => '+385',
            'CU' => '+53',
            'CW' => '+599',
            'CY' => '+357',
            'CZ' => '+420',
            'DK' => '+45',
            'DJ' => '+253',
            'DM' => '+1 767',
            'DO' => '+1 809',
            'EC' => '+593',
            'EG' => '+20',
            'SV' => '+503',
            'GQ' => '+240',
            'ER' => '+291',
            'EE' => '+372',
            'ET' => '+251',
            'FK' => '+500',
            'FO' => '+298',
            'FJ' => '+679',
            'FI' => '+358',
            'FR' => '+33',
            'GF' => '+594',
            'PF' => '+689',
            'TF' => '+262',
            'GA' => '+241',
            'GM' => '+220',
            'GE' => '+995',
            'DE' => '+49',
            'GH' => '+233',
            'GI' => '+350',
            'GR' => '+30',
            'GL' => '+299',
            'GD' => '+1 473',
            'GP' => '+590',
            'GU' => '+1 671',
            'GT' => '+502',
            'GG' => '+44',
            'GN' => '+224',
            'GW' => '+245',
            'GY' => '+592',
            'HT' => '+509',
            'HM' => '',
            'VA' => '+379',
            'HN' => '+504',
            'HK' => '+852',
            'HU' => '+36',
            'IS' => '+354',
            'IN' => '+91',
            'ID' => '+62',
            'IR' => '+98',
            'IQ' => '+964',
            'IE' => '+353',
            'IM' => '+44',
            'IL' => '+972',
            'IT' => '+39',
            'JM' => '+1 876',
            'JP' => '+81',
            'JE' => '+44',
            'JO' => '+962',
            'KZ' => '+7',
            'KE' => '+254',
            'KI' => '+686',
            'KP' => '+850',
            'KR' => '+82',
            'KW' => '+965',
            'KG' => '+996',
            'LA' => '+856',
            'LV' => '+371',
            'LB' => '+961',
            'LS' => '+266',
            'LR' => '+231',
            'LY' => '+218',
            'LI' => '+423',
            'LT' => '+370',
            'LU' => '+352',
            'MO' => '+853',
            'MK' => '+389',
            'MG' => '+261',
            'MW' => '+265',
            'MY' => '+60',
            'MV' => '+960',
            'ML' => '+223',
            'MT' => '+356',
            'MH' => '+692',
            'MQ' => '+596',
            'MR' => '+222',
            'MU' => '+230',
            'YT' => '+262',
            'MX' => '+52',
            'FM' => '+691',
            'MD' => '+373',
            'MC' => '+377',
            'MN' => '+976',
            'ME' => '+382',
            'MS' => '+1 664',
            'MA' => '+212',
            'MZ' => '+258',
            'MM' => '+95',
            'NA' => '+264',
            'NR' => '+674',
            'NP' => '+977',
            'NL' => '+31',
            'NC' => '+687',
            'NZ' => '+64',
            'NI' => '+505',
            'NE' => '+227',
            'NG' => '+234',
            'NU' => '+683',
            'NF' => '+672',
            'MP' => '+1 670',
            'NO' => '+47',
            'OM' => '+968',
            'PK' => '+92',
            'PW' => '+680',
            'PS' => '+970',
            'PA' => '+507',
            'PG' => '+675',
            'PY' => '+595',
            'PE' => '+51',
            'PH' => '+63',
            'PN' => '+64',
            'PL' => '+48',
            'PT' => '+351',
            'PR' => '+1 787',
            'QA' => '+974',
            'RE' => '+262',
            'RO' => '+40',
            'RU' => '+7',
            'RW' => '+250',
            'BL' => '+590',
            'SH' => '+290',
            'KN' => '+1 869',
            'LC' => '+1 758',
            'MF' => '+590',
            'PM' => '+508',
            'VC' => '+1 784',
            'WS' => '+685',
            'SM' => '+378',
            'ST' => '+239',
            'SA' => '+966',
            'SN' => '+221',
            'RS' => '+381',
            'SC' => '+248',
            'SL' => '+232',
            'SG' => '+65',
            'SX' => '+1 721',
            'SK' => '+421',
            'SI' => '+386',
            'SB' => '+677',
            'SO' => '+252',
            'ZA' => '+27',
            'GS' => '+500',
            'SS' => '+211',
            'ES' => '+34',
            'LK' => '+94',
            'SD' => '+249',
            'SR' => '+597',
            'SJ' => '+47',
            'SZ' => '+268',
            'SE' => '+46',
            'CH' => '+41',
            'SY' => '+963',
            'TW' => '+886',
            'TJ' => '+992',
            'TZ' => '+255',
            'TH' => '+66',
            'TL' => '+670',
            'TG' => '+228',
            'TK' => '+690',
            'TO' => '+676',
            'TT' => '+1 868',
            'TN' => '+216',
            'TR' => '+90',
            'TM' => '+993',
            'TC' => '+1 649',
            'TV' => '+688',
            'UG' => '+256',
            'UA' => '+380',
            'AE' => '+971',
            'GB' => '+44',
            'US' => '+1',
            'UM' => '',
            'UY' => '+598',
            'UZ' => '+998',
            'VU' => '+678',
            'VE' => '+58',
            'VN' => '+84',
            'VG' => '+1 284',
            'VI' => '+1 340',
            'WF' => '+681',
            'EH' => '',
            'YE' => '+967',
            'ZM' => '+260',
            'ZW' => '+263',
        );

        return array_key_exists($key, $dialingCodes) ? $dialingCodes[$key] : $key;
    }



}

function translate($key)
{
    $local = session()->has('local') ? session('local') : 'en';
    App::setLocale($local);
    $lang_array = include(base_path('resources/lang/' . $local . '/messages.php'));
    $processed_key = ucfirst(str_replace('_', ' ', Helpers::remove_invalid_charcaters($key)));
    if (!array_key_exists($key, $lang_array)) {
        $lang_array[$key] = $processed_key;
        $str = "<?php return " . var_export($lang_array, true) . ";";
        file_put_contents(base_path('resources/lang/' . $local . '/messages.php'), $str);
        $result = $processed_key;
    } else {
        $result = __('messages.' . $key);
    }
    return $result;
}
