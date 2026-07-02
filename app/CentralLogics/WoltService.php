<?php

namespace App\CentralLogics;

use App\Model\BusinessSetting;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class WoltService
{
    protected $client;
    protected $venueId;
    protected $baseUrl;

    public function __construct(Client $client)
    {
        
        $this->client = $client;
        $config = self::get_settings('wolt_service') ?: [];
        $this->venueId = $config['venue_id'] ?? null;
        $this->merchantId = $config['merchant_id'] ?? null;
        $this->token = $config['token'] ?? null;
        $this->status = (int)($config['status'] ?? 0);
        $this->baseUrl = ($config['environment'] ?? 'sandbox') == 'live'
            ? 'https://daas-public-api.wolt.com'
            : 'https://daas-public-api.development.dev.woltapi.com';
    }
	

    public function getShipmentPromises(array $data)
	{
        if ($this->status != 1 || !$this->venueId || !$this->token) {
            return ['error' => 'Wolt service is not configured'];
        }

		try {
			$response = $this->client->post("{$this->baseUrl}/v1/venues/{$this->venueId}/shipment-promises", [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token,
					'Content-Type'  => 'application/json',
				],
				'json' => $data
			]);

			return json_decode($response->getBody()->getContents(), true);
		} catch (GuzzleException $e) {
			$errorResponse = $e->getResponse();

			if ($errorResponse) {
				// Parse and extract the 'reason' field as a string
				$errorBody = json_decode($errorResponse->getBody()->getContents(), true);

				$reason = $errorBody['reason'] ?? 'An unknown error occurred';
				return ['error' => $reason];
			}

			// Fallback to exception message if no response body
			return ['message' => $e->getMessage()];
		}
	}

    public function createDelivery($order)
    {
        if($this->status != 1 || !$this->venueId || !$this->token){
           return true; 
        }
        try {
            
            $address = $order['delivery_address'];
            $customer = $order['customer'];
                
            $data = [
                'pickup'=> [
                    'options'=> [
                        "min_preparation_time_minutes" => $order['preparation_time'] > 0  ? $order['preparation_time'] : 5,
                    ]
                ],
                'dropoff' => [
                    'location' => [
                        'coordinates' => [
                            'lat' => $address['latitude'],
                            'lon' => $address['longitude']
                        ]
                    ]
                ],
                'price' => [
                    'amount' => (int) $order['delivery_charge'],
                    'currency' => 'DKK'
                ],
                'recipient' => [
                    'name' => $address['contact_person_name'],
                    'phone_number' => $address['contact_person_number'],
                    'email' => $customer['email']??''
                ],
                'parcels' => [
                    [
                        'price' => [
                            'amount' => (int) ($order['order_amount']-$order['delivery_charge']),
                            'currency' => 'DKK'
                        ]
                    ]
                ],
                'shipment_promise_id' => (string) $order['wolt_promise_id'],
                'customer_support' => [
                    'email' => 'Pappaspizzaria@outlook.dk',
                    'phone_number' => '+4535812918'
                ],
                'merchant_order_reference_id' => (string) $order['id'],
                'sms_notifications' => [
                    'received' => 'Your order has been assigned and will be delivered soon. You can follow it here: TRACKING_LINK',
                    'picked_up' => 'Your order has been picked up and will be delivered soon. You can follow it here: TRACKING_LINK'
                ],
            ];
        
        
            $response = $this->client->post("{$this->baseUrl}/v1/venues/{$this->venueId}/deliveries", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token, // Ensure you pass the correct access token here
                    'Content-Type' => 'application/json',
                ],
                'json' => $data
            ]);

            $result =  json_decode($response->getBody()->getContents(), true);
            if(isset($result) && isset($result['tracking'])){
                return $result['tracking'];
            }
            return false; 
        } catch (GuzzleException $e) {
            // Properly log or handle exceptions
            // return ['error' => $e->getMessage()];
            // info($e->getMessage());
        }
        return false; 
    }
    
    public function getExistingWebhooks()
    {
        if ($this->status != 1 || !$this->merchantId || !$this->token) {
            return [];
        }

        try {
            $response = $this->client->get("{$this->baseUrl}/v1/merchants/{$this->merchantId}/webhooks", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token, 
                    'Content-Type' => 'application/json',
                ]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            return $result ?? [];
        } catch (GuzzleException $e) {
            // info('Failed to retrieve existing webhooks: ' . $e->getMessage());
            return [];
        }
    }
    
    public function registerWoltWebhook()
    {
        if ($this->status != 1 || !$this->merchantId || !$this->token) {
            return false;
        }

        $webhooks = $this->getExistingWebhooks();
        
        if ($webhooks) {
            // info('Webhook already exists with ID: ' . $webhooks[0]['id']);
            return true;
        }
        // Proceed to register a new webhook if none exists
        try {
             $data = [
                'callback_config' => [
                    'exponential_retry_backoff' => [
                        'exponent_base' => 2,
                        'max_retry_count' => 10,
                    ]
                ],
                'callback_url' => config('app.url') . '/api/v1/wolt-webhook',
                'client_secret' => 'pappas_nv_'.now(), 
                'disabled' => false
            ];
    
            $response = $this->client->post("{$this->baseUrl}/v1/merchants/{$this->merchantId}/webhooks", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token, 
                    'Content-Type' => 'application/json',
                ],
                'json' => $data
            ]);
    
            $result = json_decode($response->getBody()->getContents(), true);
            if (isset($result) && isset($result['id'])) {
                return true;
            }
            return false; 
        } catch (GuzzleException $e) {
            // info('Wolt Webhook Registration Failed: ' . $e->getMessage());
        }
        return false;
    }

    public static function get_settings($name)
    {
        $config = null;
        $data = BusinessSetting::where(['key' => $name])->first();
        if (isset($data)) {
            $config = json_decode($data['value'], true);
            if (is_null($config)) {
                $config = $data['value'];
            }
        }
        return $config;
    }
}
