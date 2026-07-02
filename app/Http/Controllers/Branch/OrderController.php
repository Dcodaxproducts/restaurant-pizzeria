<?php

namespace App\Http\Controllers\Branch;

use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Http\Controllers\Controller;
use App\Model\AddOn;
use App\Model\BusinessSetting;
use App\Model\CustomerAddress;
use App\Model\DeliveryHistory;
use App\Model\DeliveryMan;
use App\Model\Order;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(
        private Order           $order,
        private User            $user,
        private BusinessSetting $business_setting,
        private CustomerAddress $customer_addresses,
        private DeliveryMan     $delivery_man,
    ){}

    /**
     * @param $status
     * @param Request $request
     * @return Renderable
     */
    public function list($status, Request $request): Renderable
    {
        $from = $request['from'];
        $to = $request['to'];

        $this->order->where(['checked' => 0, 'branch_id' => auth('branch')->id()])->update(['checked' => 1]);

        $analytics = [];

        if ($status == 'all') {
            $orders = $this->order
                ->with(['customer'])
                ->where(['branch_id' => auth('branch')->id()]);

            $currentMonthStart = now()->startOfMonth();
            $currentMonthEnd = now()->endOfMonth();
            $previousMonthStart = now()->subMonthNoOverflow()->startOfMonth();
            $previousMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

            $allTimeQuery  = $this->order->newQuery()->where('branch_id', auth('branch')->id());
            $currentMonthQuery = (clone $allTimeQuery)->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd]);
            $previousMonthQuery = (clone $allTimeQuery)->whereBetween('created_at', [$previousMonthStart, $previousMonthEnd]);

            $allStats = (clone $allTimeQuery)->selectRaw("
                COALESCE(SUM(order_amount), 0) as earnings,
                SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_count,
                SUM(CASE WHEN order_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN order_status = 'cancel' THEN 1 ELSE 0 END) as cancel_count
            ")->first();

            $currentStats = (clone $currentMonthQuery)->selectRaw("
                COALESCE(SUM(order_amount), 0) as earnings,
                SUM(CASE WHEN order_status = 'delivered' AND payment_status = 'paid' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_count,
                SUM(CASE WHEN order_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN order_status = 'cancel' THEN 1 ELSE 0 END) as cancel_count
            ")->first();

            $previousStats = (clone $previousMonthQuery)->selectRaw("
                COALESCE(SUM(order_amount), 0) as earnings,
                SUM(CASE WHEN order_status = 'delivered' AND payment_status = 'paid' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_count,
                SUM(CASE WHEN order_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN order_status = 'cancel' THEN 1 ELSE 0 END) as cancel_count
            ")->first();

            $allEarning = $allStats->earnings ?? 0;
            $currentMonthEarnings = $currentStats->earnings ?? 0;
            $previousMonthEarnings = $previousStats->earnings ?? 0;
            
            $analytics['single_earning'] = $allEarning;
            $analytics['single_earning_change'] = $previousMonthEarnings > 0 
                ? (($currentMonthEarnings - $previousMonthEarnings) / $previousMonthEarnings) * 100 
                : ($currentMonthEarnings > 0 ? 100 : 0);
            $analytics['single_earning_trend'] = $currentMonthEarnings >= $previousMonthEarnings ? 'up' : 'down';
        
            // Completed
            $allCompleted = $allStats->delivered_count ?? 0;
            $currentMonthCompleted = $currentStats->delivered_count ?? 0;
            $previousMonthCompleted = $previousStats->delivered_count ?? 0;
            $analytics['completed'] = $allCompleted;
            $analytics['completed_change'] = $previousMonthCompleted > 0 
                ? (($currentMonthCompleted - $previousMonthCompleted) / $previousMonthCompleted) * 100 
                : ($currentMonthCompleted > 0 ? 100 : 0);
            $analytics['completed_trend'] = $currentMonthCompleted >= $previousMonthCompleted ? 'up' : 'down';
        
            // Confirmed
            $allConfirmed = $allStats->confirmed_count ?? 0;
            $currentMonthConfirmed = $currentStats->confirmed_count ?? 0;
            $previousMonthConfirmed = $previousStats->confirmed_count ?? 0;
            $analytics['confirmed'] = $allConfirmed;
            $analytics['confirmed_change'] = $previousMonthConfirmed > 0 
                ? (($currentMonthConfirmed - $previousMonthConfirmed) / $previousMonthConfirmed) * 100 
                : ($currentMonthConfirmed > 0 ? 100 : 0);
            $analytics['confirmed_trend'] = $currentMonthConfirmed >= $previousMonthConfirmed ? 'up' : 'down';
        
            // Pending
            $allPending = $allStats->pending_count ?? 0;
            $currentMonthPending = $currentStats->pending_count ?? 0;
            $previousMonthPending = $previousStats->pending_count ?? 0;
            $analytics['pending'] = $allPending;
            $analytics['pending_change'] = $previousMonthPending > 0 
                ? (($currentMonthPending - $previousMonthPending) / $previousMonthPending) * 100 
                : ($currentMonthPending > 0 ? 100 : 0);
            $analytics['pending_trend'] = $currentMonthPending >= $previousMonthPending ? 'up' : 'down';

            // Processing
            $allProcessing = $allStats->processing_count ?? 0;
            $currentMonthProcessing = $currentStats->processing_count ?? 0;
            $previousMonthProcessing = $previousStats->processing_count ?? 0;
            $analytics['processing'] = $allProcessing;
            $analytics['processing_change'] = $previousMonthProcessing > 0 
                ? (($currentMonthProcessing - $previousMonthProcessing) / $previousMonthProcessing) * 100 
                : ($currentMonthProcessing > 0 ? 100 : 0);
            $analytics['processing_trend'] = $currentMonthProcessing >= $previousMonthProcessing ? 'up' : 'down';

            // failed
            $allFailed = $allStats->failed_count ?? 0;
            $currentMonthFailed = $currentStats->failed_count ?? 0;
            $previousMonthFailed = $previousStats->failed_count ?? 0;
            $analytics['failed'] = $allFailed;
            $analytics['failed_change'] = $previousMonthFailed > 0 
                ? (($currentMonthFailed - $previousMonthFailed) / $previousMonthFailed) * 100 
                : ($currentMonthFailed > 0 ? 100 : 0);
            $analytics['failed_trend'] = $currentMonthFailed >= $previousMonthFailed ? 'up' : 'down';

            // failed
            $allCancel = $allStats->cancel_count ?? 0;
            $currentMonthCancel = $currentStats->cancel_count ?? 0;
            $previousMonthCancel = $previousStats->cancel_count ?? 0;
            $analytics['cancel'] = $allCancel;
            $analytics['cancel_change'] = $previousMonthCancel > 0 
                ? (($currentMonthCancel - $previousMonthCancel) / $previousMonthCancel) * 100 
                : ($currentMonthCancel > 0 ? 100 : 0);
            $analytics['cancel_trend'] = $currentMonthCancel >= $previousMonthCancel ? 'up' : 'down';


        } elseif ($status == 'schedule') {
            $orders = $this->order
                ->whereDate('delivery_date', '>', \Carbon\Carbon::now()->format('Y-m-d'))
                ->where(['branch_id' => auth('branch')->id()]);

        } else {
            $orders = $this->order
                ->with(['customer'])
                ->where(['order_status' => $status, 'branch_id' => auth('branch')->id()])
                ->whereDate('delivery_date', '<=', \Carbon\Carbon::now()->format('Y-m-d'));
        }

        $query_param = [];
        $search = $request['search'];

        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $orders = $this->order
                ->where(['branch_id' => auth('branch')->id()])
                ->whereDate('delivery_date', '<=', Carbon::now()->format('Y-m-d'))
                ->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('id', 'like', "%{$value}%")
                            ->orWhere('order_status', 'like', "%{$value}%")
                            ->orWhere('transaction_reference', 'like', "%{$value}%");
                    }
                });
            $query_param = ['search' => $request['search']];
        }

        if ($from && $to) {
            $orders = $this->order->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()]);
            $query_param = ['from' => $from, 'to' => $to];
        }
       
        $orders = $orders->notPos()->notDineIn()->latest()->paginate(Helpers::getPagination())->appends($query_param);
        session()->put('order_data_export', $orders);

        return view('branch-views.order.list', compact('orders', 'status', 'search', 'from', 'to','analytics'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $key = explode(' ', $request['search']);
        $orders = $this->order
            ->where(['branch_id' => auth('branch')->id()])
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            })->with('customer')->get();

        return response()->json([
            'view' => view('branch-views.order.partials._table', compact('orders'))->render()
        ]);
    }

    /**
     * @param $id
     * @return Renderable|RedirectResponse
     */
    public function details($id): Renderable|RedirectResponse
    {
        $order = $this->order
            ->with(['details.product', 'customer', 'delivery_address', 'branch', 'delivery_man', 'table'])
            ->where(['id' => $id, 'branch_id' => auth('branch')->id()])
            ->first();

        if (!isset($order)) {
            Toastr::info(translate('Order not found!'));
            return back();
        }

        //remaining delivery time
        $delivery_date_time = $order['delivery_date'] . ' ' . $order['delivery_time'];
        $ordered_time = Carbon::createFromFormat('Y-m-d H:i:s', date("Y-m-d H:i:s", strtotime($delivery_date_time)));
        $remaining_time = $ordered_time->add($order['preparation_time'], 'minute')->format('Y-m-d H:i:s');
        $order['remaining_time'] = $remaining_time;
        $delivery_man = $this->delivery_man->where(['is_active'=>1])
            ->where(function($query) use ($order) {
                $query->where('branch_id', $order->branch_id)
                    ->orWhere('branch_id', 0);
            })
            ->get();
        $addonMap = $this->getOrderAddonMap($order);
        $deliveryOrigin = null;
        $deliveryCurrent = null;
        $address = $order->delivery_address;

        if ($order['order_status'] == 'out_for_delivery') {
            $deliveryHistoryQuery = DeliveryHistory::where([
                'deliveryman_id' => $order['delivery_man_id'],
                'order_id' => $order['id'],
            ]);
            $deliveryOrigin = (clone $deliveryHistoryQuery)->first();
            $deliveryCurrent = (clone $deliveryHistoryQuery)->latest()->first();
        }

        $timeZone = Helpers::get_business_settings('time_zone') ?? 'UTC';

        return view('branch-views.order.order-view', compact('order', 'delivery_man', 'addonMap', 'deliveryOrigin', 'deliveryCurrent', 'address', 'timeZone'));
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function status(Request $request): RedirectResponse
    {
        $order = $this->order
            ->where(['id' => $request->id, 'branch_id' => auth('branch')->id()])
            ->first();

        if (in_array($order->order_status, ['delivered', 'failed'])) {
            Toastr::warning(translate('you_can_not_change_the_status_of_a_completed_order'));
            return back();
        }

        if ($request->order_status == 'delivered' && $order['transaction_reference'] == null && !in_array($order['payment_method'], ['cash_on_delivery', 'wallet'])) {
            Toastr::warning(translate('add_your_payment_reference_first'));
            return back();
        }

        if (($request->order_status == 'delivered' || $request->order_status == 'out_for_delivery') && $order['delivery_man_id'] == null && $order['order_type'] != 'take_away') {
            Toastr::warning(translate('Please assign delivery man first!'));
            return back();
        }

        if ($request->order_status == 'completed' && $order->payment_status != 'paid') {
            Toastr::warning(translate('Please update payment status first!'));
            return back();
        }

        if ($request->order_status == 'delivered') {
            if ($order->user_id) CustomerLogic::create_loyalty_point_transaction($order->user_id, $order->id, $order->order_amount, 'order_place');

            if ($order->transaction == null) {
                $ol = OrderLogic::create_transaction($order, 'admin');
//                if (!$ol) {
//                    Toastr::warning(translate('failed_to_create_order_transaction'));
//                    return back();
//                }
            }

            $user = $this->user->find($order->user_id);
            $is_first_order = $this->order->where('user_id', $user->id)->count('id');
            $referred_by_user = $this->user->find($user->refer_by);

            if ($is_first_order < 2 && isset($user->refer_by) && isset($referred_by_user)) {
                if (($this->business_setting->where('key', 'ref_earning_status')->first()?->value ?? 0) == 1) {
                    CustomerLogic::referral_earning_wallet_transaction($order->user_id, 'referral_order_place', $referred_by_user->id);
                }
            }
        }

        $order->order_status = $request->order_status;
        if ($request->order_status == 'delivered') {
            $order->payment_status = 'paid';
        }
        $order->save();

        $fcm_token = null;
        if ($order->customer) {
            $fcm_token = $order->customer->cm_firebase_token;
        }
        $value = Helpers::order_status_update_message($request->order_status);
        try {
            if ($value) {
                $data = [
                    'title' => translate('Order'),
                    'description' => $value,
                    'order_id' => $order['id'],
                    'image' => '',
                    'type' => 'order_status',
                ];
                if (isset($fcm_token)) {
                    Helpers::send_push_notif_to_device($fcm_token, $data);
                }
            }

        } catch (\Exception $e) {
            Toastr::warning(translate('Push notification failed for Customer!'));
        }

        //delivery man notification
        if ($request->order_status == 'processing' || $request->order_status == 'out_for_delivery') {

            if (isset($order->delivery_man)) {
                $fcm_token = $order->delivery_man->fcm_token;
            }

            $value = translate('One of your order is on processing');
            $out_for_delivery_value = translate('One of your order is out for delivery');
            try {
                if ($value) {
                    $data = [
                        'title' => translate('Order'),
                        'description' => $request->order_status == 'processing' ? $value : $out_for_delivery_value,
                        'order_id' => $order['id'],
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    if (isset($fcm_token)) {
                        Helpers::send_push_notif_to_device($fcm_token, $data);
                    }

                }
            } catch (\Exception $e) {
                Toastr::warning(translate('Push notification failed for DeliveryMan!'));
            }
        }

        Toastr::success(translate('Order status updated!'));
        return back();
    }

    /**
     * @param Request $request
     * @param $id
     * @return RedirectResponse
     * @throws \Exception
     */
    public function preparation_time(Request $request, $id): RedirectResponse
    {
        $order = $this->order->with(['customer'])->find($id);
        $delivery_date_time = $order['delivery_date'] . ' ' . $order['delivery_time'];

        $ordered_time = Carbon::createFromFormat('Y-m-d H:i:s', date("Y-m-d H:i:s", strtotime($delivery_date_time)));
        $remaining_time = $ordered_time->add($order['preparation_time'], 'minute')->format('Y-m-d H:i:s');

        //if delivery time is not over
        if (strtotime(date('Y-m-d H:i:s')) < strtotime($remaining_time)) {
            $delivery_time = new DateTime($remaining_time); //time when preparation will be over
            $current_time = new DateTime(); // time now
            $interval = $delivery_time->diff($current_time);
            $remainingMinutes = $interval->i;
            $remainingMinutes += $interval->days * 24 * 60;
            $remainingMinutes += $interval->h * 60;
            //$order->preparation_time += ($request->extra_minute - $remainingMinutes);
            $order->preparation_time = 0;
        } else {
            //if delivery time is over
            $delivery_time = new DateTime($remaining_time);
            $current_time = new DateTime();
            $interval = $delivery_time->diff($current_time);
            $diffInMinutes = $interval->i;
            $diffInMinutes += $interval->days * 24 * 60;
            $diffInMinutes += $interval->h * 60;
            //$order->preparation_time += $diffInMinutes + $request->extra_minute;
            $order->preparation_time = 0;
        }

        $new_delivery_date_time = Carbon::now()->addMinutes($request->extra_minute);
        $order->delivery_date = $new_delivery_date_time->format('Y-m-d');
        $order->delivery_time = $new_delivery_date_time->format('H:i:s');
        $order->save();

        //notification send
        $customer = $order->customer;
        $fcm_token = null;
        if (isset($customer)) {
            $fcm_token = $customer->cm_firebase_token;
        }
        $value = Helpers::order_status_update_message('customer_notify_message_for_time_change');

        try {
            if ($value) {
                $data = [
                    'title' => translate('Order'),
                    'description' => $value,
                    'order_id' => $order['id'],
                    'image' => '',
                    'type' => 'order_status',
                ];
                Helpers::send_push_notif_to_device($fcm_token, $data);
            } else {
                throw new \Exception(translate('failed'));
            }

        } catch (\Exception $e) {
            Toastr::warning(translate('Push notification send failed for Customer!'));
        }

        Toastr::success(translate('Order preparation time increased'));
        return back();
    }

    /**
     * @param $order_id
     * @param $delivery_man_id
     * @return JsonResponse
     */
    public function add_delivery_man($order_id, $delivery_man_id): JsonResponse
    {
        if ($delivery_man_id == 0) {
            return response()->json([], 401);
        }
        $order = $this->order->where(['id' => $order_id, 'branch_id' => auth('branch')->id()])->first();
        if ($order->order_status == 'delivered' || $order->order_status == 'returned' || $order->order_status == 'failed' || $order->order_status == 'canceled' || $order->order_status == 'scheduled') {
            return response()->json(['status' => false], 200);
        }
        $order->delivery_man_id = $delivery_man_id;
        $order->save();

        $fcm_token = $order->delivery_man->fcm_token;
        $customer_fcm_token = null;
        if (isset($order->customer)) {
            $customer_fcm_token = $order->customer->cm_firebase_token;
        }
        $value = Helpers::order_status_update_message('del_assign');
        try {
            if ($value) {
                $data = [
                    'title' => translate('Order'),
                    'description' => $value,
                    'order_id' => $order['id'],
                    'image' => '',
                    'type' => 'order_status',
                ];
                Helpers::send_push_notif_to_device($fcm_token, $data);
            }
        } catch (\Exception $e) {
            Toastr::warning(translate('Push notification failed for DeliveryMan!'));
        }

        Toastr::success(translate('Order deliveryman added!'));
        return response()->json(['status' => true], 200);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function payment_status(Request $request): RedirectResponse
    {
        $order = $this->order->where(['id' => $request->id, 'branch_id' => auth('branch')->id()])->first();
        if ($request->payment_status == 'paid' && $order['transaction_reference'] == null && $order['payment_method'] != 'cash_on_delivery' && $order['order_type'] != 'dine_in') {
            Toastr::warning(translate('Add your payment reference code first!'));
            return back();
        }
        $order->payment_status = $request->payment_status;
        $order->save();

        Toastr::success(translate('Payment status updated!'));
        return back();
    }

    /**
     * @param Request $request
     * @param $id
     * @return RedirectResponse
     */
    public function update_shipping(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required'
        ]);

        $this->customer_addresses->where('id', $id)->update([
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'floor' => $request->floor,
            'house' => $request->house,
            'road' => $request->road,
            'address' => $request->address,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        Toastr::success(translate('Address updated!'));
        return back();
    }

    /**
     * @param $id
     * @return Renderable
     */
    public function generate_invoice($id): Renderable
    {
        $order = $this->order->with(['details.product', 'customer', 'delivery_address'])
            ->where(['id' => $id, 'branch_id' => auth('branch')->id()])
            ->first();
        $addonMap = $this->getOrderAddonMap($order);
        $businessSettings = $this->business_setting
            ->whereIn('key', ['restaurant_name', 'address', 'phone', 'footer_text'])
            ->pluck('value', 'key');
        return view('branch-views.order.invoice', compact('order', 'addonMap', 'businessSettings'));
    }

    private function getOrderAddonMap(?Order $order)
    {
        if (!$order) {
            return collect();
        }

        $addonIds = $order->details
            ->flatMap(function ($detail) {
                return json_decode($detail['add_on_ids'], true) ?: [];
            })
            ->filter()
            ->unique()
            ->values();

        return $addonIds->isEmpty() ? collect() : AddOn::whereIn('id', $addonIds)->get()->keyBy('id');
    }

    /**
     * @param Request $request
     * @param $id
     * @return RedirectResponse
     */
    public function add_payment_ref_code(Request $request, $id): RedirectResponse
    {
        $this->order->where(['id' => $id, 'branch_id' => auth('branch')->id()])->update([
            'transaction_reference' => $request['transaction_reference']
        ]);

        Toastr::success(translate('Payment reference code is added!'));
        return back();
    }

    /**
     * @return StreamedResponse|string
     * @throws \Box\Spout\Common\Exception\IOException
     * @throws \Box\Spout\Common\Exception\InvalidArgumentException
     * @throws \Box\Spout\Common\Exception\UnsupportedTypeException
     * @throws \Box\Spout\Writer\Exception\WriterNotOpenedException
     */
    public function export_excel(): StreamedResponse|string
    {
        $data = [];
        $orders = session('order_data_export');
        foreach ($orders as $key => $order) {
            $data[$key]['SL'] = ++$key;
            $data[$key]['Order ID'] = $order->id;
            $data[$key]['Order Date'] = date('d M Y h:m A', strtotime($order['created_at']));
            $data[$key]['Customer Info'] = $order['user_id'] == null ? 'Walk in Customer' : ($order->customer == null ? 'Customer Unavailable' : $order->customer['f_name'] . ' ' . $order->customer['l_name']);
            $data[$key]['Branch'] = $order->branch ? $order->branch->name : 'Branch Deleted';
            $data[$key]['Total Amount'] = Helpers::set_symbol($order['order_amount']);
            $data[$key]['Payment Status'] = $order->payment_status == 'paid' ? 'Paid' : 'Unpaid';
            $data[$key]['Order Status'] = $order['order_status'] == 'pending' ? 'Pending' : ($order['order_status'] == 'confirmed' ? 'Confirmed' : ($order['order_status'] == 'processing' ? 'Processing' : ($order['order_status'] == 'delivered' ? 'Delivered' : ($order['order_status'] == 'picked_up' ? 'Out For Delivery' : str_replace('_', ' ', $order['order_status'])))));
        };
        return (new FastExcel($data))->download('orders.xlsx');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function ajax_change_delivery_time_date(Request $request): JsonResponse
    {
        $order = $this->order->where('id', $request->order_id)->first();
        if (!$order) {
            return response()->json(['status' => false]);
        }
        $order->delivery_date = $request->input('delivery_date') ?? $order->delivery_date;
        $order->delivery_time = $request->input('delivery_time') ?? $order->delivery_time;
        $order->save();

        return response()->json(['status' => true]);

    }
}
