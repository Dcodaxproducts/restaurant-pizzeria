<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\CentralLogics\CustomerLogic;
use App\Http\Controllers\Controller;
use App\Model\BusinessSetting;
use App\Model\CustomerAddress;
use App\Model\DeliveryMan;
use App\Model\AddOn;
use App\Model\Order;
use App\Model\TableOrder;
use App\User;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;
use DateTime;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Renderable;
use App\CentralLogics\WoltService;


class OrderController extends Controller
{
    public function __construct(
        private Order           $order,
        private TableOrder      $table_order,
        private CustomerAddress $customer_address,
        private OrderLogic      $order_logic,
        private User            $user,
        private BusinessSetting $business_setting,
        private DeliveryMan     $delivery_man,
		private WoltService     $woltService

    )
    {}

    /**
     * @param Request $request
     * @param $status
     * @return Renderable
     */
    public function list(Request $request, $status): Renderable
    {
        $query_param = [];
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];
        
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $query = $this->order->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            })
                ->when($from && $to, function ($query) use ($from, $to) {
                    $query->whereBetween('created_at', [$from, $to]);
                });
            $query_param = ['search' => $request['search']];
        }
        else {
         
            if (session()->has('branch_filter') == false) {
                session()->put('branch_filter', 0);
            }
            $this->order->where(['checked' => 0])->update(['checked' => 1]);

            //all branch
            if (session('branch_filter') == 0) {
               
                if ($status == 'schedule') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->schedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });

                } elseif ($status != 'all') {
                    $status = $status == 'dine' ? 'dine_in' : $status;
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where(['order_type' => $status])
                        ->notSchedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });
                } else {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });
                }
            } //selected branch
            else {
                if ($status == 'schedule') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where('branch_id', session('branch_filter'))
                        ->schedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });

                } elseif ($status != 'all') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where(['order_type' => $status, 'branch_id' => session('branch_filter')])
                        ->notSchedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });
                        
                } else {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where(['branch_id' => session('branch_filter')])
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });
                }
            }
            $query_param = ['branch' => $request->branch, 'from' => $request->from, 'to' => $request->to];
        }

        // Orders analytics
        $analytics = [
            'total_earning' => 0,
            'total_dine_in' => 0,
            'total_take_away' => 0,
            'total_home_delivery' => 0,
            'earning_change' => 0,
            'dine_in_change' => 0,
            'take_away_change' => 0,
            'home_delivery_change' => 0,
            'earning_trend' => 'up',
            'dine_in_trend' => 'up',
            'take_away_trend' => 'up',
            'home_delivery_trend' => 'up',
        ];

        $calFrom = $request->from ? $request->from : now()->startOfMonth();
        $calTo = $request->to ? $request->to : now();

        $prevCalFrom = $request->from ? $request->from : now()->subMonth()->startOfMonth();
        $prevCalTo = $request->to ? $request->to : now()->subMonth()->endOfMonth();

        // Earning Stats
        $totalEarning = $this->order
            ->where('order_status', 'delivered')
            ->where('payment_status', 'paid')
            ->when($from && $to, function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
            })
            ->sum('order_amount');

        $currentMonthEarnings = $this->order
            ->where('order_status', 'delivered')
            ->whereBetween('created_at', [$calFrom, $calTo])
            ->sum('order_amount');

        $previousMonthEarnings = $this->order
            ->where('order_status', 'delivered')
            ->whereBetween('created_at', [$prevCalFrom, $prevCalTo])
            ->sum('order_amount');
        $currentEarnings = $currentMonthEarnings;
        $previousEarnings = $previousMonthEarnings;
        $analytics['total_earning'] = $totalEarning;
        $analytics['earning_change'] = $previousEarnings > 0 ? (($currentEarnings - $previousEarnings) / $previousEarnings) * 100 : 0;
        $analytics['earning_trend'] = $currentEarnings >= $previousEarnings ? 'up' : 'down';
        
        // Dine in stats
        $totalDineOrder = $this->order
            ->where('order_type', 'dine_in')
            ->when($from && $to, function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
            })
            ->count('id');
        $currentMonthDineEarnings = $this->order
            ->where('order_type', 'dine_in')
            ->whereBetween('created_at', [$calFrom, $calTo])
            ->count('id');
        $previousMonthDineEarnings = $this->order
            ->where('order_type', 'dine_in')
            ->whereBetween('created_at', [$prevCalFrom, $prevCalTo])
            ->count('id');
        $currentDineEarnings = $currentMonthDineEarnings;
        $previousDineEarnings = $previousMonthDineEarnings;
        $analytics['total_dine_in'] = $totalDineOrder;
        $analytics['dine_in_change'] = $previousDineEarnings > 0 ? (($currentDineEarnings - $previousDineEarnings) / $previousDineEarnings) * 100 : 0;
        $analytics['dine_in_trend'] = $currentDineEarnings >= $previousDineEarnings ? 'up' : 'down';

        // Takeaway orders stats 
        $totalTakeawayOrder = $this->order
            ->where('order_type', 'takeaway')
            ->when($from && $to, function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, $to]);
            })
            ->count('id');
        $currentMonthTakeawayEarnings = $this->order
            ->where('order_type', 'takeaway')
            ->whereBetween('created_at', [$calFrom, $calTo])
            ->count('id');
        $previousMonthTakeawayEarnings   = $this->order
            ->where('order_type', 'takeaway')
            ->whereBetween('created_at', [$prevCalFrom, $prevCalTo])
            ->count('id');
        $currentTakeawayEarnings = $currentMonthTakeawayEarnings;
        $previousTakeawayEarnings = $previousMonthTakeawayEarnings;
        $analytics['total_take_away'] = $totalTakeawayOrder;
        $analytics['take_away_change'] = $previousTakeawayEarnings > 0 ? (($currentTakeawayEarnings - $previousTakeawayEarnings) / $previousTakeawayEarnings) * 100 : 0;
        $analytics['take_away_trend'] = $currentTakeawayEarnings >= $previousTakeawayEarnings ? 'up' : 'down';
        
        // delivery orders stats 
        $totalDeliveryOrder = $this->order
            ->where('order_type', 'delivery')
            ->when($from && $to, function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
            })
            ->count('id');
        $currentMonthDeliveryEarnings = $this->order
            ->where('order_type', 'delivery')
            ->whereBetween('created_at', [$calFrom, $calTo])
            ->count('id');
        $previousMonthDeliveryEarnings = $this->order
            ->where('order_type', 'delivery')
            ->whereBetween('created_at', [$prevCalFrom, $prevCalTo])
            ->count('id');
        $currentDeliveryEarnings = $currentMonthDeliveryEarnings;
        $previousDeliveryEarnings = $previousMonthDeliveryEarnings;
        $analytics['total_home_delivery'] = $totalDeliveryOrder;
        $analytics['home_delivery_change'] = $previousDeliveryEarnings > 0 ? (($currentDeliveryEarnings - $previousDeliveryEarnings) / 6) * 100 : 0;
        $analytics['home_delivery_trend'] = $currentDeliveryEarnings >= $previousDeliveryEarnings ? 'up' : 'down';
      
        // Single orders analytics
        $currentMonthStart = $calFrom;
        $currentMonthEnd = $calTo;
        $previousMonthStart = $prevCalFrom;
        $previousMonthEnd = $prevCalTo;

        $statusTotals = $this->order
            ->selectRaw('COALESCE(SUM(order_amount), 0) as all_earning')
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->first();

        $currentTotals = $this->order
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->selectRaw('COALESCE(SUM(order_amount), 0) as earning')
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' AND payment_status = 'paid' THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->first();

        $previousTotals = $this->order
            ->whereBetween('created_at', [$previousMonthStart, $previousMonthEnd])
            ->selectRaw('COALESCE(SUM(order_amount), 0) as earning')
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' AND payment_status = 'paid' THEN 1 ELSE 0 END) as delivered_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'processing' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->first();

        $allEarning = (float) ($statusTotals->all_earning ?? 0);
        $currentMonthEarnings = (float) ($currentTotals->earning ?? 0);
        $previousMonthEarnings = (float) ($previousTotals->earning ?? 0);
        $analytics['single_earning'] = $allEarning;
        $analytics['single_earning_change'] = $previousMonthEarnings > 0 
            ? (($currentMonthEarnings - $previousMonthEarnings) / $previousMonthEarnings) * 100 
            : ($currentMonthEarnings > 0 ? 100 : 0);
        $analytics['single_earning_trend'] = $currentMonthEarnings >= $previousMonthEarnings ? 'up' : 'down';
    
        // Completed
        $allCompleted = (int) ($statusTotals->delivered_count ?? 0);
        $currentMonthCompleted = (int) ($currentTotals->delivered_count ?? 0);
        $previousMonthCompleted = (int) ($previousTotals->delivered_count ?? 0);
        $analytics['completed'] = $allCompleted;
        $analytics['completed_change'] = $previousMonthCompleted > 0 
            ? (($currentMonthCompleted - $previousMonthCompleted) / $previousMonthCompleted) * 100 
            : ($currentMonthCompleted > 0 ? 100 : 0);
        $analytics['completed_trend'] = $currentMonthCompleted >= $previousMonthCompleted ? 'up' : 'down';
       
        // Confirmed
        $allConfirmed = (int) ($statusTotals->confirmed_count ?? 0);
        $currentMonthConfirmed = (int) ($currentTotals->confirmed_count ?? 0);
        $previousMonthConfirmed = (int) ($previousTotals->confirmed_count ?? 0);
        $analytics['confirmed'] = $allConfirmed;
        $analytics['confirmed_change'] = $previousMonthConfirmed > 0 
            ? (($currentMonthConfirmed - $previousMonthConfirmed) / $previousMonthConfirmed) * 100 
            : ($currentMonthConfirmed > 0 ? 100 : 0);
        $analytics['confirmed_trend'] = $currentMonthConfirmed >= $previousMonthConfirmed ? 'up' : 'down';
       
        // Pending
        $allPending = (int) ($statusTotals->pending_count ?? 0);
        $currentMonthPending = (int) ($currentTotals->pending_count ?? 0);
        $previousMonthPending = (int) ($previousTotals->pending_count ?? 0);
        $analytics['pending'] = $allPending;
        $analytics['pending_change'] = $previousMonthPending > 0 
            ? (($currentMonthPending - $previousMonthPending) / $previousMonthPending) * 100 
            : ($currentMonthPending > 0 ? 100 : 0);
        $analytics['pending_trend'] = $currentMonthPending >= $previousMonthPending ? 'up' : 'down';

        // Processing
        $allProcessing = (int) ($statusTotals->processing_count ?? 0);
        $currentMonthProcessing = (int) ($currentTotals->processing_count ?? 0);
        $previousMonthProcessing = (int) ($previousTotals->processing_count ?? 0);
        $analytics['processing'] = $allProcessing;
        $analytics['processing_change'] = $previousMonthProcessing > 0 
            ? (($currentMonthProcessing - $previousMonthProcessing) / $previousMonthProcessing) * 100 
            : ($currentMonthProcessing > 0 ? 100 : 0);
        $analytics['processing_trend'] = $currentMonthProcessing >= $previousMonthProcessing ? 'up' : 'down';

        // failed
        $allFailed = (int) ($statusTotals->failed_count ?? 0);
        $currentMonthFailed = (int) ($currentTotals->failed_count ?? 0);
        $previousMonthFailed = (int) ($previousTotals->failed_count ?? 0);
        $analytics['failed'] = $allFailed;
        $analytics['failed_change'] = $previousMonthFailed > 0 
            ? (($currentMonthFailed - $previousMonthFailed) / $previousMonthFailed) * 100 
            : ($currentMonthFailed > 0 ? 100 : 0);
        $analytics['failed_trend'] = $currentMonthFailed >= $previousMonthFailed ? 'up' : 'down';

        // dd($analytics);

        $customer_id = isset($order_filter->customer_name) ? $order_filter->customer_name : null;
        $payment_type = isset($order_filter->payment_type) ? $order_filter->payment_type : null;
        $orderstatus = isset($order_filter->orderStatus) ? $order_filter->orderStatus : [];

        // orders charts
        $data = [];
        $from = Carbon::now()->startOfYear();
        $to = Carbon::now()->endOfYear();
        $order_statistics_chart_data = $this->order
            ->where('order_status', 'delivered')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
            ->orderBy(DB::raw('YEAR(created_at)'), 'asc')
            ->orderBy(DB::raw('MONTH(created_at)'), 'asc')
            ->get([
                DB::raw('COUNT(*) as total'),
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month')
            ]);
        
        $monthly_totals = array_fill_keys(range(1, 12), 0);
        
        foreach ($order_statistics_chart_data as $dataPoint) {
            $monthly_totals[$dataPoint->month] = $dataPoint->total;
        }
        $order_statistics_chart = $order_statistics_chart = array_slice($monthly_totals, 0, null, true);

       
        $orders = $query->notPos()->latest()->paginate(Helpers::getPagination())->appends($query_param);
        $customers = $this->user->select('id', 'f_name', 'l_name')->whereNull('user_type')->get();

         // End new Dashboard
        return view('admin-views.order.list', compact('orders',
        'status',
        'search',
        'from',
        'to',
        'customer_id',
        'payment_type',
        'orderstatus',
        'order_statistics_chart',
        'analytics',
        'customers'
        ));
    }

    /**
     * @param $id
     * @return Renderable|RedirectResponse
     */
    public function details($id): Renderable|RedirectResponse
    {
        $order = $this->order->with(['details.product', 'customer', 'delivery_address', 'branch', 'delivery_man', 'table'])
            ->where(['id' => $id])
            ->first();
		
        if (!isset($order)) {
            Toastr::info(translate('No order found!'));
            return back();
        }

        $delivery_man = $this->delivery_man->where(['is_active'=>1])
            ->where(function($query) use ($order) {
                $query->where('branch_id', $order->branch_id)
                    ->orWhere('branch_id', 0);
            })
            ->get();

        //remaining delivery time
        $delivery_date_time = $order['delivery_date'] . ' ' . $order['delivery_time'];
        $ordered_time = Carbon::createFromFormat('Y-m-d H:i:s', date("Y-m-d H:i:s", strtotime($delivery_date_time)));
        $remaining_time = $ordered_time->add($order['preparation_time'], 'minute')->format('Y-m-d H:i:s');
        $order['remaining_time'] = $remaining_time;
        $addonMap = $this->getOrderAddonMap($order);
        $deliveryOrigin = null;
        $deliveryCurrent = null;
        $address = $order->delivery_address;

        if ($order['order_status'] == 'out_for_delivery') {
            $deliveryHistoryQuery = \App\Model\DeliveryHistory::where([
                'deliveryman_id' => $order['delivery_man_id'],
                'order_id' => $order['id'],
            ]);
            $deliveryOrigin = (clone $deliveryHistoryQuery)->first();
            $deliveryCurrent = (clone $deliveryHistoryQuery)->latest()->first();
        }

        $timeZone = Helpers::get_business_settings('time_zone') ?? 'UTC';

        return view('admin-views.order.order-view', compact('order', 'delivery_man', 'addonMap', 'deliveryOrigin', 'deliveryCurrent', 'address', 'timeZone'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $key = explode(' ', $request['search']);
        $orders = $this->order
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            })->get();

        return response()->json([
            'view' => view('admin-views.order.partials._table', compact('orders'))->render()
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function status(Request $request): RedirectResponse
    {
        $order = $this->order->find($request->id);

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
                $ol = $this->order_logic->create_transaction($order, 'admin');
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
        $order->save();

        $fcm_token = null;
        if (isset($order->customer)) {
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
            Toastr::warning(translate('Push notification send failed for Customer!'));
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
		
		
		if ($request->order_status == 'confirmed') {
            try {
                $result = $this->woltService->createDelivery($order);
                
                if(isset($result['url'])){
                    $order->wolt_tracking_url = $result['url'];
                    $order->wolt_driver = 1;
                    $order->save();
                }

            } catch (\Exception $e) {
                Toastr::warning(translate('Push notification failed!'));
            }
       }
		

        //kitchen order notification
       // if ($request->order_status == 'confirmed') {
        //    $data = [
           //     'title' => translate('You have a new order - (Order Confirmed).'),
            //    'description' => $order->id,
            //    'order_id' => $order->id,
            //    'image' => '',
         //   ];

         //   try {
        //        Helpers::send_push_notif_to_topic($data, "kitchen-{$order->branch_id}", 'general');

        //    } catch (\Exception $e) {
        //        Toastr::warning(translate('Push notification failed!'));
       //     }
      //  }
        $table_order = $this->table_order->where(['id' => $order->table_order_id])->first();

        if ($request->order_status == 'completed' && $order->payment_status == 'paid') {
            if (isset($table_order->id)) {
                $orders = $this->order->where(['table_order_id' => $table_order->id])->get();
                $status = 1;
                foreach ($orders as $order) {
                    if ($order->order_status != 'completed') {
                        $status = 0;
                        break;
                    }
                }

                if ($status == 1) {
                    $table_order->branch_table_token_is_expired = 1;
                    $table_order->save();
                }
            }
        }

        if ($request->order_status == 'canceled') {

            if (isset($table_order->id)) {
                $orders = $this->order->where(['table_order_id' => $table_order->id])->get();
                $status = 1;
                foreach ($orders as $order) {
                    if ($order->order_status != 'canceled') {
                        $status = 0;
                        break;
                    }
                }

                if ($status == 1) {
                    $table_order->branch_table_token_is_expired = 1;
                    $table_order->save();
                }
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

        Toastr::success(translate('Order preparation time updated'));
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

        $order = $this->order->find($order_id);
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
                    'order_id' => $order_id,
                    'image' => '',
                    'type' => 'order_status',
                ];
                Helpers::send_push_notif_to_device($fcm_token, $data);
                if (isset($order->customer)) {
                    $data['description'] = Helpers::order_status_update_message('customer_notify_message');
                }
                if (isset($customer_fcm_token)) {
                    Helpers::send_push_notif_to_device($customer_fcm_token, $data);
                }
            }
        } catch (\Exception $e) {
            Toastr::warning(translate('Push notification failed for DeliveryMan!'));
        }

        return response()->json(['status' => true], 200);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function payment_status(Request $request): RedirectResponse
    {
        $order = $this->order->find($request->id);
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
            'contact_person_number' => 'required|min:5|max:20',
            'address' => 'required'
        ]);

        $address = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'road' => $request->road,
            'house' => $request->house,
            'floor' => $request->floor,
            'address' => $request->address,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'created_at' => now(),
            'updated_at' => now()
        ];

        if ($id) {
            $this->customer_address->where('id', $id)->update($address);
            Toastr::success(translate('Address updated!'));

        } else {
            $address = $this->customer_address;
            $address->contact_person_name = $request->input('contact_person_name');
            $address->contact_person_number = $request->input('contact_person_number');
            $address->address_type = $request->input('address_type');
            $address->address = $request->input('address');
            $address->longitude = $request->input('longitude');
            $address->latitude = $request->input('latitude');
            $address->user_id = $request->input('user_id');
            $address->house = $request->house;
            $address->floor = $request->floor;
            $address->address = $request->address;
            $address->save();
            $this->order->where('id', $request->input('order_id'))->update(['delivery_address_id' => $address->id]);
            Toastr::success(translate('Address added!'));
        }

        return back();
    }

    /**
     * @param $id
     * @return Renderable
     */
    public function generate_invoice($id): Renderable
    {
        $order = $this->order->with(['details.product', 'customer', 'delivery_address'])->where('id', $id)->first();
        $addonMap = $this->getOrderAddonMap($order);
        $businessSettings = $this->business_setting
            ->whereIn('key', ['restaurant_name', 'address', 'phone', 'footer_text'])
            ->pluck('value', 'key');
        return view('admin-views.order.invoice', compact('order', 'addonMap', 'businessSettings'));
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
        $this->order->where(['id' => $id])->update([
            'transaction_reference' => $request['transaction_reference']
        ]);

        Toastr::success(translate('Payment reference code is added!'));
        return back();
    }

    /**
     * @param $id
     * @return RedirectResponse
     */
    public function branch_filter($id): RedirectResponse
    {
        session()->put('branch_filter', $id);
        return back();
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|string
     * @throws \Box\Spout\Common\Exception\IOException
     * @throws \Box\Spout\Common\Exception\InvalidArgumentException
     * @throws \Box\Spout\Common\Exception\UnsupportedTypeException
     * @throws \Box\Spout\Writer\Exception\WriterNotOpenedException
     */
    public function export_data(): \Symfony\Component\HttpFoundation\StreamedResponse|string
    {
        $orders = $this->order->lazy();
        return (new FastExcel($orders))->download('orders.xlsx');
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|string|RedirectResponse
     * @throws \Box\Spout\Common\Exception\IOException
     * @throws \Box\Spout\Common\Exception\InvalidArgumentException
     * @throws \Box\Spout\Common\Exception\UnsupportedTypeException
     * @throws \Box\Spout\Writer\Exception\WriterNotOpenedException
     */
    public function export_excel(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse|string|RedirectResponse
    {
        $status = $request->status;
        $query_param = [];
        $search = $request['search'];
        $from = $request['from'];
        $to = $request['to'];

        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $query = $this->order->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            })
                ->when($from && $to, function ($query) use ($from, $to) {
                    $query->whereBetween('created_at', [$from, $to]);
                });
        } else {
            if (session()->has('branch_filter') == false) {
                session()->put('branch_filter', 0);
            }

            //all branch
            if (session('branch_filter') == 0) {
                if ($status == 'schedule') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->schedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });;

                } elseif ($status != 'all') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where(['order_status' => $status])
                        ->notSchedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });;

                } else {

                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });;
                }
            } //selected branch
            else {
                if ($status == 'schedule') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where('branch_id', session('branch_filter'))
                        ->schedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });;

                } elseif ($status != 'all') {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where(['order_status' => $status, 'branch_id' => session('branch_filter')])
                        ->notSchedule()
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });

                } else {
                    $query = $this->order
                        ->with(['customer', 'branch'])
                        ->where(['branch_id' => session('branch_filter')])
                        ->when($from && $to, function ($query) use ($from, $to) {
                            $query->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()]);
                        });
                }
            }
        }

        $query = $query->notPos()->notDineIn()->latest();
        if (!$query->exists()) {
            Toastr::warning('No Data Available');
            return back();
        }

        $data = function () use ($query) {
            foreach ($query->lazy() as $key => $order) {
                yield [
                    'SL' => ++$key,
                'Order ID' => $order->id,
                'Order Date' => date('d M Y h:m A', strtotime($order['created_at'])),
                'Customer Info' => $order['user_id'] == null ? 'Walk in Customer' : ($order->customer == null ? 'Customer Unavailable' : $order->customer['f_name'] . ' ' . $order->customer['l_name']),
                'Branch' => $order->branch ? $order->branch->name : 'Branch Deleted',
                'Total Amount' => Helpers::set_symbol($order['order_amount']),
                'Payment Status' => $order->payment_status == 'paid' ? 'Paid' : 'Unpaid',
                'Order Status' => $order['order_status'] == 'pending' ? 'Pending' : ($order['order_status'] == 'confirmed' ? 'Confirmed' : ($order['order_status'] == 'processing' ? 'Processing' : ($order['order_status'] == 'delivered' ? 'Delivered' : ($order['order_status'] == 'picked_up' ? 'Out For Delivery' : str_replace('_', ' ', $order['order_status']))))),
                ];
            }
        };

        return (new FastExcel($data()))->download('Order_List.xlsx');
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

    public function filter(Request $request)
    {
        // dd($request->all());

        session()->put('order_filter', json_encode($request->all()));
        return back();
    }

    public function filter_reset(Request $request)
    {
        session()->forget('order_filter');
        return back();
    }

}
