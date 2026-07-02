<?php

namespace App\Http\Controllers\Branch;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Model\AddOn;
use App\Model\Admin;
use App\Model\Branch;
use App\Model\Category;
use App\Model\DeliveryHistory;
use App\Model\DeliveryMan;
use App\Model\Notification;
use App\Model\Product;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Model\ProductByBranch;
use App\Model\BusinessSetting;
use App\Model\Table;
use App\User;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Support\Renderable;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class POSController extends Controller
{
    public function __construct(
        private Category        $category,
        private Order           $order,
        private User            $user,
        private Table           $table,
        private Product         $product,
        private Admin           $admin,
        private Branch          $branch,
        private ProductByBranch $product_by_Branch,
        private DeliveryMan     $delivery_man
    )
    {
    }


    /**
     * @param Request $request
     * @return Renderable
     */
    public function index(Request $request): Renderable
    {
        $category = $request->query('category_id', 0);
        $categories = $this->category->active()->get();
        $keyword = $request->keyword;
        $key = explode(' ', $keyword);
        $selected_customer = $this->user->where('id', session('customer_id'))->first();
        $selected_table = $this->table->where('id', session('table_id'))->first();

        $branchId = auth('branch')->id();
        $products = $this->product
            ->with(['branch_products' => function ($q) use ($branchId) {
                $q->select('id', 'product_id', 'branch_id', 'price', 'discount_type', 'discount', 'is_available');
            }])
            ->with(['product_by_branch' => function ($q) use ($branchId) {
                $q->where(['is_available' => 1, 'branch_id' => $branchId])
                    ->select('id', 'product_id', 'branch_id', 'price', 'discount_type', 'discount', 'is_available');
            }])
            ->whereHas('product_by_branch', function ($q) use ($branchId) {
                $q->where(['is_available' => 1, 'branch_id' => $branchId]);
            })
            ->when($request->has('category_id') && $request['category_id'] != 0, function ($query) use ($request) {
                $query->whereJsonContains('category_ids', [['id' => (string)$request['category_id']]]);
            })
            ->when($keyword, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->active()
            ->latest()
            ->select('id', 'name', 'image', 'price', 'discount_type', 'discount', 'status', 'category_ids')
            ->paginate(Helpers::getPagination());

        $branch = $this->branch->find(auth('branch')->id());
        $tables = $this->table->where(['branch_id' => auth('branch')->id()])->get();
        $mapApiClientKey = BusinessSetting::where('key', 'map_api_client_key')->value('value');
        $order = null;
        $addOns = collect();
        $businessSettings = [];

        if (session('last_order')) {
            $order = $this->order->with(['details.product', 'customer', 'delivery_address'])
                ->where(['id' => session('last_order'), 'branch_id' => auth('branch')->id()])
                ->first();
            $addOns = $this->getOrderAddOns($order);
            $businessSettings = $this->businessSettings(['restaurant_name', 'address', 'phone', 'footer_text']);
        }

        $selectedCategory = $category;
        $cartViewData = $this->getCartViewData();
        return view('branch-views.pos.index', array_merge(
            compact('categories', 'products', 'category', 'keyword', 'branch', 'tables', 'selected_table', 'selected_customer', 'selectedCategory', 'mapApiClientKey', 'order', 'addOns', 'businessSettings'),
            $cartViewData
        ));
    }

    public function getProducts(Request $request)
    {
        $category = $request->input('category_id', 0);
        $keyword = $request->input('keyword');

        $selected_branch = session()->get('branch_id') ?? 1;
        session()->put('branch_id', $selected_branch);

        $key = explode(' ', $keyword);

        $products = $this->product
            ->where('branch_id', auth('branch')->id())
            ->with(['branch_products' => function ($q) use ($selected_branch) {
                $q->where(['is_available' => 1, 'branch_id' => $selected_branch])
                    ->select('id', 'product_id', 'branch_id', 'price', 'discount_type', 'discount', 'is_available');
            }])
            ->whereHas('branch_products', function ($q) use ($selected_branch) {
                $q->where(['is_available' => 1, 'branch_id' => $selected_branch]);
            })
            ->when($request->has('category_id') && $request['category_id'] != 0, function ($query) use ($request) {
                $query->whereJsonContains('category_ids', [['id' => (string)$request['category_id']]]);
            })
            ->when($keyword, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->active()
            ->latest()
            ->select('id', 'name', 'image', 'price', 'discount_type', 'discount', 'status', 'category_ids')
            ->paginate(Helpers::getPagination());

        return view('admin-views.pos.order.partials._product_partial', compact('products'))->render();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function quick_view(Request $request): JsonResponse
    {
        $product = $this->product->with('product_by_branch')->findOrFail($request->product_id);
        $addOnIds = json_decode($product->add_ons, true) ?: [];
        $selectedAddOns = AddOn::whereIn('id', $addOnIds)->get();

        return response()->json([
            'success' => 1,
            'view' => view('branch-views.pos._quick-view-data', compact('product', 'selectedAddOns'))->render(),
        ]);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function variant_price(Request $request): array
    {
        $product = $this->product->find($request->id);
        $price = $product->price;
        $addon_price = 0;

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
            }
        }

        $branch_product = $this->product_by_Branch->where(['product_id' => $request->id, 'branch_id' => auth('branch')->id()])->first();

        if (isset($branch_product)) {
            $branch_product_variations = $branch_product->variations;
            $discount_data = [
                'discount_type' => $branch_product['discount_type'],
                'discount' => $branch_product['discount']
            ];

            if ($request->variations && count($branch_product_variations)) {
                $price_total = $branch_product['price'] + Helpers::new_variation_price($branch_product_variations, $request->variations);
                $price = $price_total - Helpers::discount_calculate($discount_data, $price_total);
            } else {
                $price = $branch_product['price'] - Helpers::discount_calculate($discount_data, $branch_product['price']);
            }
        }
        return array('price' => Helpers::set_symbol(($price * $request->quantity) + $addon_price));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function get_customers(Request $request): JsonResponse
    {
        $key = explode(' ', $request['q']);
        $data = $this->user
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            })
            ->whereNotNull(['f_name', 'l_name', 'phone'])
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);

        $data[] = (object)['id' => false, 'text' => translate('walk_in_customer')];

        return response()->json($data);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function update_tax(Request $request): RedirectResponse
    {
        if ($request->tax < 0) {
            Toastr::error(translate('Tax_can_not_be_less_than_0_percent'));
            return back();
        } elseif ($request->tax > 100) {
            Toastr::error(translate('Tax_can_not_be_more_than_100_percent'));
            return back();
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['tax'] = $request->tax;
        $request->session()->put('cart', $cart);

        return back();
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function update_discount(Request $request): RedirectResponse
    {
        if ($request->type == 'percent' && $request->discount < 0) {
            Toastr::error(translate('Extra_discount_can_not_be_less_than_0_percent'));
            return back();
        } elseif ($request->type == 'percent' && $request->discount > 100) {
            Toastr::error(translate('Extra_discount_can_not_be_more_than_100_percent'));
            return back();
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['extra_discount_type'] = $request->type;
        $cart['extra_discount'] = $request->discount;

        $request->session()->put('cart', $cart);
        return back();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function updateQuantity(Request $request): JsonResponse
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart = $cart->map(function ($object, $key) use ($request) {
            if ($key == $request->key) {
                $object['quantity'] = $request->quantity;
            }
            return $object;
        });
        $request->session()->put('cart', $cart);

        return response()->json([], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function addToCart(Request $request): JsonResponse
    {
        $product = $this->product->find($request->id);

        $data = array();
        $data['id'] = $product->id;
        $str = '';
        $variations = [];
        $price = 0;
        $addon_price = 0;
        $variation_price = 0;
        $addon_total_tax = 0;

        $branch_product = $this->product_by_Branch->where(['product_id' => $request->id, 'branch_id' => auth('branch')->id()])->first();
        $branch_product_price = 0;
        $discount_data = [];

        if (isset($branch_product)) {
            $branch_product_variations = $branch_product->variations;

            if ($request->variations && count($branch_product_variations)) {
                foreach ($request->variations as $key => $value) {

                    if ($value['required'] == 'on' && isset($value['values']) == false) {
                        return response()->json([
                            'data' => 'variation_error',
                            'message' => translate('Please select items from') . ' ' . $value['name'],
                        ]);
                    }
                    if (isset($value['values']) && $value['min'] != 0 && $value['min'] > count($value['values']['label'])) {
                        return response()->json([
                            'data' => 'variation_error',
                            'message' => translate('Please select minimum ') . $value['min'] . translate(' For ') . $value['name'] . '.',
                        ]);
                    }
                    if (isset($value['values']) && $value['max'] != 0 && $value['max'] < count($value['values']['label'])) {
                        return response()->json([
                            'data' => 'variation_error',
                            'message' => translate('Please select maximum ') . $value['max'] . translate(' For ') . $value['name'] . '.',
                        ]);
                    }
                }
                $variation_data = Helpers::get_varient($branch_product_variations, $request->variations);
                $variation_price = $variation_data['price'];
                $variations = $request->variations;

            }

            $branch_product_price = $branch_product['price'];
            $discount_data = [
                'discount_type' => $branch_product['discount_type'],
                'discount' => $branch_product['discount']
            ];

        }
        $price = $branch_product_price + $variation_price;
        $data['variation_price'] = $variation_price;

        $discount_on_product = Helpers::discount_calculate($discount_data, $price);

        $data['variations'] = $variations;
        $data['variant'] = $str;

        $data['quantity'] = $request['quantity'];
        $data['price'] = $price;
        $data['name'] = $product->name;
        $data['discount'] = $discount_on_product;
        $data['image'] = $product->image;
        $data['add_ons'] = [];
        $data['add_on_qtys'] = [];
        $data['add_on_prices'] = [];
        $data['add_on_tax'] = [];

        if ($request['addon_id']) {
            $addons = AddOn::whereIn('id', $request['addon_id'])->get()->keyBy('id');
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
                $data['add_on_qtys'][] = $request['addon-quantity' . $id];

                $add_on = $addons->get($id);
                $data['add_on_prices'][] = $add_on['price'];
                $add_on_tax = ($add_on['price'] * $add_on['tax']/100);
                $addon_total_tax += (($add_on['price'] * $add_on['tax']/100) * $request['addon-quantity' . $id]);
                $data['add_on_tax'][] = $add_on_tax;
            }
            $data['add_ons'] = $request['addon_id'];
        }

        $data['addon_price'] = $addon_price;
        $data['addon_total_tax'] = $addon_total_tax;

        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->push($data);
        } else {
            $cart = collect([$data]);
            $request->session()->put('cart', $cart);
        }

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function place_order(Request $request)
    {	
		
     
        if ($request->session()->get('cart')) {
            if (count($request->session()->get('cart')) < 1) {
                Toastr::error(translate('cart_empty_warning'));
                return back();
            }
        } else {
            Toastr::error(translate('cart_empty_warning'));
            return back();
        }
        if (session('people_number') != null && (session('people_number') > 99 || session('people_number') < 1)) {
            Toastr::error(translate('enter valid people number'));
            return back();
        }

        $cart = $request->session()->get('cart');
        $total_tax_amount = 0;
        $total_addon_price = 0;
        $total_addon_tax = 0;
        $product_price = 0;
        $order_details = [];

        $order_id = 100000 + $this->order->all()->count() + 1;
        if ($this->order->find($order_id)) {
            $order_id = $this->order->orderBy('id', 'DESC')->first()->id + 1;
        }

        $order = $this->order;
        $order->id = $order_id;

        $order->user_id = session()->get('customer_id') ?? null;
        $order->coupon_discount_title = $request->coupon_discount_title == 0 ? null : 'coupon_discount_title';
        $order->payment_status = $request->type == 'pay_after_eating' ? 'unpaid' : 'paid';
        $order->order_status = session()->get('table_id') ? 'confirmed' : 'delivered';
        $order->order_type = session()->get('table_id') ? 'dine_in' : 'pos';
        $order->coupon_code = $request->coupon_code ?? null;
        $order->payment_method = $request->type;
        $order->transaction_reference = $request->transaction_reference ?? null;
        $order->delivery_charge = 0; //since pos, no distance, no d. charge
        $order->delivery_address_id = $request->delivery_address_id ?? null;
        $order->delivery_date = Carbon::now()->format('Y-m-d');
        $order->delivery_time = Carbon::now()->format('H:i:s');
        $order->order_note = null;
        $order->checked = 1;
        $order->created_at = now();
        $order->updated_at = now();

        $total_product_main_price = 0;

        // check if discount is more than total price
        $total_price_for_discount_validation = 0;
        $cartProductIds = collect($cart)->filter(fn($item) => is_array($item))->pluck('id')->filter()->unique()->values();
        $cartAddOnIds = collect($cart)->filter(fn($item) => is_array($item))
            ->flatMap(fn($item) => $item['add_ons'] ?? [])
            ->filter()
            ->unique()
            ->values();
        $cartProducts = $this->product->whereIn('id', $cartProductIds)->get()->keyBy('id');
        $cartBranchProducts = $this->product_by_Branch
            ->where('branch_id', auth('branch')->id())
            ->whereIn('product_id', $cartProductIds)
            ->get()
            ->keyBy('product_id');
        $cartAddOns = AddOn::whereIn('id', $cartAddOnIds)->get()->keyBy('id');

        foreach ($cart as $c) {
            if (is_array($c)) {
                $discount_on_product = 0;
                $discount = 0;
                $product_subtotal = ($c['price']) * $c['quantity'];
                $discount_on_product += ($c['discount'] * $c['quantity']);

                $total_price_for_discount_validation += $c['price'];

                $product = $cartProducts->get($c['id']);
                if ($product) {
                    $price = $c['price'];

                    $product = Helpers::product_data_formatting($product);
                    $addon_data = Helpers::calculate_addon_price($cartAddOns->only($c['add_ons'])->values(), $c['add_on_qtys']);

                    //*** addon quantity integer casting ***
                    array_walk($c['add_on_qtys'], function (&$add_on_qtys) {
                        $add_on_qtys = (int)$add_on_qtys;
                    });
                    //***end***

                    $branch_product = $cartBranchProducts->get($c['id']);
                    $discount_data = [];
                    $variation_data = ['variations' => []];
                    if (isset($branch_product)) {
                        $variation_data = Helpers::get_varient($branch_product->variations, $c['variations']);
                        $discount_data = [
                            'discount_type' => $branch_product['discount_type'],
                            'discount' => $branch_product['discount']
                        ];
                    }

                    $discount = Helpers::discount_calculate($discount_data, $price);
                    $variations = $variation_data['variations'];

                    $or_d = [
                        'product_id' => $c['id'],
                        'product_details' => $product,
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_product' => $discount,
                        'discount_type' => 'discount_on_product',
                        //'variant' => json_encode($c['variant']),
                        'variation' => json_encode($variations),
                        'add_on_ids' => json_encode($addon_data['addons']),
                        'add_on_qtys' => json_encode($c['add_on_qtys']),
                        'add_on_prices' => json_encode($c['add_on_prices']),
                        'add_on_taxes' => json_encode($c['add_on_tax']),
                        'add_on_tax_amount' => $c['addon_total_tax'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_tax_amount += $or_d['tax_amount'] * $c['quantity'];
                    $total_addon_price += $addon_data['total_add_on_price'];

                    $total_addon_tax += $c['addon_total_tax'];

                    $product_price += $product_subtotal - $discount_on_product;
                    $total_product_main_price += $product_subtotal;
                    $order_details[] = $or_d;
                }
            }
        }

        $total_price = $product_price + $total_addon_price;
        if (isset($cart['extra_discount'])) {
            $extra_discount = $cart['extra_discount_type'] == 'percent' && $cart['extra_discount'] > 0 ? (($total_product_main_price * $cart['extra_discount']) / 100) : $cart['extra_discount'];
            $total_price -= $extra_discount;
        }
        if (isset($cart['extra_discount']) && $cart['extra_discount_type'] == 'amount') {
            if ($cart['extra_discount'] > $total_price_for_discount_validation) {
                Toastr::error(translate('discount_can_not_be_more_total_product_price'));
                return back();
            }
        }
        $tax = isset($cart['tax']) ? $cart['tax'] : 0;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : $total_tax_amount;
        try {
            $order->extra_discount = $extra_discount ?? 0;
            $order->total_tax_amount = $total_tax_amount;

            $overall_tax = BusinessSetting::where('key', 'overall_tax')->first()?->value ?? 0;

            $order->order_amount = $total_price + $total_tax_amount + $order->delivery_charge+$total_addon_tax + $overall_tax;

            $order->coupon_discount_amount = 0.00;
            $order->branch_id = auth('branch')->id();
            $order->table_id = session()->get('table_id');
            $order->number_of_people = session()->get('people_number');

            $order->save();

            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);

            session()->forget('cart');
            session(['last_order' => $order->id]);

            session()->forget('customer_id');
            session()->forget('branch_id');
            session()->forget('table_id');
            session()->forget('people_number');

            Toastr::success(translate('order_placed_successfully'));

            //send notification to kitchen
            if ($order->order_type == 'dine_in') {
                $notification = new Notification;
                $notification->title = "You have a new order from POS - (Order Confirmed). ";
                $notification->description = $order->id;
                $notification->status = 1;

                try {
                    Helpers::send_push_notif_to_topic($notification, "kitchen-{$order->branch_id}", 'general');
                    Toastr::success(translate('Notification sent successfully!'));
                } catch (\Exception $e) {
                    Toastr::warning(translate('Push notification failed!'));
                }
            }

            return back();
        } catch (\Exception $e) {
            info($e);
        }

        Toastr::warning(translate('failed_to_place_order'));
        return back();
    }

    /**
     * @return Renderable
     */
    public function cart_items(): Renderable
    {
        return view('branch-views.pos._cart', $this->getCartViewData());
    }

    /**
     * @return JsonResponse
     */
    public function emptyCart(): JsonResponse
    {
        session()->forget('cart');
        return response()->json([], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function removeFromCart(Request $request): JsonResponse
    {
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->forget($request->key);
            $request->session()->put('cart', $cart);
        }

        return response()->json([], 200);
    }

    /**
     * @param Request $request
     * @return Renderable
     */
    public function order_list(Request $request): Renderable
    {
        $from = $request->from;
        $to = $request->to;
        $query_param = [];
        $search = $request['search'];

        $this->order->where(['checked' => 0])->update(['checked' => 1]);
        $query = $this->order->pos()->with(['customer', 'branch'])->where('branch_id', auth('branch')->id());

        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $query = $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            });
            $query_param = ['search' => $request['search']];
        }

        if ($from && $to) {
            $query = $query->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()]);
            $query_param = array_merge($query_param, ['from' => $from, 'to' => $to]);
        }

        $orders = $query->latest()->paginate(Helpers::getPagination())->appends($query_param);

        return view('branch-views.pos.order.list', compact('orders', 'search', 'from', 'to'));
    }

    public function export_excel(Request $request): StreamedResponse|string
    {
        $query = $this->order->pos()->with(['customer', 'branch'])->where('branch_id', auth('branch')->id());

        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $query = $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('order_status', 'like', "%{$value}%")
                        ->orWhere('transaction_reference', 'like', "%{$value}%");
                }
            });
        }

        if ($request->from && $request->to) {
            $query = $query->whereBetween('created_at', [Carbon::parse($request->from)->startOfDay(), Carbon::parse($request->to)->endOfDay()]);
        }

        $storage = function () use ($query) {
            foreach ($query->latest()->lazy() as $key => $order) {
                yield [
                    'SL' => ++$key,
                    'Order ID' => $order->id,
                    'Order Date' => date('d M Y h:i A', strtotime($order['created_at'])),
                    'Customer Info' => $order['user_id'] == null ? 'Walk in Customer' : ($order->customer == null ? 'Customer Unavailable' : $order->customer['f_name'] . ' ' . $order->customer['l_name']),
                    'Branch' => $order->branch ? $order->branch->name : 'Branch Deleted',
                    'Total Amount' => Helpers::set_symbol($order['order_amount']),
                    'Payment Status' => $order->payment_status == 'paid' ? 'Paid' : 'Unpaid',
                    'Order Status' => $order['order_status'] == 'pending' ? 'Pending' : ($order['order_status'] == 'confirmed' ? 'Confirmed' : ($order['order_status'] == 'processing' ? 'Processing' : ($order['order_status'] == 'delivered' ? 'Delivered' : ($order['order_status'] == 'picked_up' ? 'Out For Delivery' : str_replace('_', ' ', $order['order_status']))))),
                ];
            }
        };

        return (new FastExcel($storage()))->download('pos-orders.xlsx');
    }

    /**
     * @param $id
     * @return Renderable|RedirectResponse
     */
    public function order_details($id): Renderable|RedirectResponse
    {
        $order = $this->order->with(['details.product', 'customer', 'delivery_address', 'branch', 'delivery_man', 'table'])
            ->where(['id' => $id, 'branch_id' => auth('branch')->id()])
            ->first();

        if (!isset($order)) {
            Toastr::info('No more orders!');
            return back();
        }

        $delivery_man = $this->delivery_man->where(['is_active'=>1])
            ->where(function($query) use ($order) {
                $query->where('branch_id', $order->branch_id)
                    ->orWhere('branch_id', 0);
            })
            ->get();
        $addonMap = $this->getOrderAddOns($order);
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

        return view('branch-views.pos.order-view', compact('order', 'delivery_man', 'addonMap', 'deliveryOrigin', 'deliveryCurrent', 'address', 'timeZone'));
    }


    public function generate_invoice($id)
    {
        $order = $this->order->with(['details.product', 'customer', 'delivery_address'])
            ->where('id', $id)
            ->first();

        $addOns = $this->getOrderAddOns($order);
        $businessSettings = $this->businessSettings(['restaurant_name', 'address', 'phone', 'footer_text']);

        return view('branch-views.pos.order.invoice', compact('order', 'addOns', 'businessSettings'));
        
    }

    /**
     * @return RedirectResponse
     */
    public function clear_session_data(): RedirectResponse
    {
        session()->forget('customer_id');
        session()->forget('table_id');
        session()->forget('people_number');
        Toastr::success(translate('clear data successfully'));

        return back();
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function customer_store(Request $request): RedirectResponse
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'phone' => 'required',
            'email' => 'required|email',
        ]);

        $user_phone = $this->user->where('phone', $request->phone)->first();
        if (isset($user_phone)){
            Toastr::error(translate('The phone is already taken'));
            return back();
        }

        $user_email = $this->user->where('email', $request->email)->first();
        if (isset($user_email)){
            Toastr::error(translate('The email is already taken'));
            return back();
        }

        $this->user->create([
            'f_name' => $request->f_name,
            'l_name' => $request->l_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt('password'),
        ]);

        Toastr::success(translate('customer added successfully'));
        return back();
    }

    private function getCartViewData(): array
    {
        $cartItems = collect(session()->get('cart', collect([])))
            ->filter(fn($cartItem) => is_array($cartItem));

        $productIds = $cartItems->pluck('id')->filter()->unique()->values();
        $addOnIds = $cartItems->flatMap(fn($cartItem) => $cartItem['add_ons'] ?? [])
            ->filter()
            ->unique()
            ->values();

        return [
            'cartProducts' => $productIds->isEmpty() ? collect() : $this->product->whereIn('id', $productIds)->get()->keyBy('id'),
            'cartAddOns' => $addOnIds->isEmpty() ? collect() : AddOn::whereIn('id', $addOnIds)->get()->keyBy('id'),
        ];
    }

    private function getOrderAddOns(?Order $order)
    {
        $addOnIds = collect($order?->details ?? [])
            ->flatMap(fn($detail) => json_decode($detail['add_on_ids'], true) ?: [])
            ->filter()
            ->unique()
            ->values();

        return AddOn::whereIn('id', $addOnIds)->get()->keyBy('id');
    }

    private function businessSettings(array $keys): array
    {
        return BusinessSetting::whereIn('key', $keys)->pluck('value', 'key')->toArray();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store_keys(Request $request): JsonResponse
    {
        session()->put($request['key'], $request['value']);
        return response()->json($request['key'], 200);
    }

}
