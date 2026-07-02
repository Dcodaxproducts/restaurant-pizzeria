<div id="headerMain" class="d-none">
    <header id="header" class="navbar bg-white navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
        <div class="navbar-nav-wrap">
            <div class="navbar-brand-wrapper">
                @php($restaurant_logo=$branchLayoutSettings['logo'] ?? '')
                <a class="navbar-brand" href="{{route('branch.dashboard')}}" aria-label="">
                    <img class="navbar-brand-logo" style="object-fit: contain;"
                         onerror="this.src='{{asset('assets/admin/img/160x160/img1.jpg')}}'"
                         src="{{asset('/storage/restaurant/'.$restaurant_logo)}}" alt="Logo">
                    <img class="navbar-brand-logo-mini" style="object-fit: contain;"
                         onerror="this.src='{{asset('assets/admin/img/160x160/img1.jpg')}}'"
                         src="{{asset('/storage/restaurant/'.$restaurant_logo)}}"
                         alt="Logo">
                </a>
            </div>

            <div class="navbar-nav-wrap-content-left d-xl-none">
                <button type="button" class="js-navbar-vertical-aside-toggle-invoker close mr-3">
                    <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                       data-placement="right" title="Collapse"></i>
                    <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                       data-template='<div class="tooltip d-none d-sm-block" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'
                       data-toggle="tooltip" data-placement="right" title="Expand"></i>
                </button>
            </div>
              <div class="navbar-nav-wrap-content-left">
                <div class="d-flex flex-column">
                    <ul class="navbar-nav align-items-center flex-row">
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                                <a class="rounded-circle"
                                href="{{route('branch.dashboard')}}">

                                    <img style="width: 106px;" src="{{asset('assets/admin/img/logo1.png')}}" alt="">
                                </a>
                        </li>
                    @if (Request::is('branch'))
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class=" rounded-circle"
                            href="{{route('branch.dashboard')}}">

                                <img src="{{asset('assets/admin/img/main_header_dashboard.png')}}" alt="">
                            </a>
                        </li>
                        @elseif(Request::is('branch/pos') || Request::is('branch/pos/*'))
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class="rounded-circle"
                            href="{{route('branch.pos.index')}}">

                                <img src="{{asset('assets/admin/img/main_header_pos.png')}}" alt="">
                            </a>
                        </li>
                        @elseif(Request::is('branch/orders/*'))
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class="rounded-circle"
                            href="{{route('branch.orders.list',['all'])}}">

                                <img src="{{asset('assets/admin/img/main_header_orders.png')}}" alt="">
                            </a>
                        </li>
                        @elseif(Request::is('branch/table/order/*'))
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class="rounded-circle"
                            href="{{route('branch.table.order.list',['all'])}}">

                                <img src="{{asset('assets/admin/img/main_header_tables.png')}}" alt="">
                            </a>
                        </li>
                        @elseif(Request::is('branch/product/list') )
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class="rounded-circle"
                            href="{{route('branch.product.list')}}">

                                <img src="{{asset('assets/admin/img/main_header_menus.png')}}" alt="">
                            </a>
                        </li>
                        @elseif(Request::is('branch/table/list') || Request::is('branch/table/index') || Request::is('branch/promotion/create'))
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class="rounded-circle"
                            href="{{route('branch.table.list')}}">

                                <img src="{{asset('assets/admin/img/main_header_tables.png')}}" alt="">
                            </a>
                        </li>
                        @elseif(Request::is('branch/kitchen/list') || Request::is('branch/kitchen/add-new'))
                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                            <a class="rounded-circle"
                            href="{{route('branch.kitchen.list')}}">

                                <img src="{{asset('assets/admin/img/main_header_chef.png')}}" alt="">
                            </a>
                        </li>

                    @endif

                        <li style="margin-inline-end: 0.4rem;" class="nav-item d-none d-sm-inline-block">
                                <a class="rounded-circle" href="javascript:void(0)">
                                    @if(!Request::is('branch'))
                                        <img src="{{asset('assets/admin/img/main_header_rightArrow.png')}}" alt="">
                                    @endif
                                    <span style="color:#7E8299;">
                                   <strong>
                                    @if(Request::is('branch'))
                                        Dashboard

                                    @elseif(Request::is('branch/pos') || Request::is('branch/pos/*'))
                                        POS

                                    @elseif(Request::is('branch/orders/*'))
                                        Orders
                                    @elseif(Request::is('branch/table/order/*'))
                                        Table Order

                                    @elseif(Request::is('branch/product/list') )
                                        Product
                                    @elseif(Request::is('branch/table/list') || Request::is('branch/table/index') || Request::is('branch/promotion/create'))
                                        Tables
                                    @elseif(Request::is('branch/kitchen/list') || Request::is('branch/kitchen/add-new'))
                                        Chef

                                   @endif
                                    </strong>
                                </span>
                                </a>
                        </li>
                        <li class="nav-item d-none d-sm-inline-block">
                                <a class=" rounded-circle" href="javascript:void(0)">
                                    @if(!Request::is('branch'))
                                        <img src="{{asset('assets/admin/img/main_header_rightArrow.png')}}" alt="">
                                    @endif
                                    <span style="color:#3F4254;">
                                        <strong>
                                        @if(Request::is('branch'))
                                            OverView
                                  @elseif(Request::is('branch/pos'))
                                            New Sale
                                        @elseif(Request::is('branch/pos/*'))
                                            Order History

                                        @elseif(Request::is('branch/orders/list/all'))
                                            All
                                        @elseif(Request::is('branch/orders/list/pending'))
                                        Pending
                                        @elseif(Request::is('branch/orders/list/confirmed'))
                                        Confirmed
                                        @elseif(Request::is('branch/orders/list/processing'))
                                        Processing
                                        @elseif(Request::is('branch/orders/list/out_for_delivery'))
                                        Out of Delivery
                                        @elseif(Request::is('branch/orders/list/delivered'))
                                        Delivered
                                        @elseif(Request::is('branch/orders/list/returned'))
                                        Returned
                                        @elseif(Request::is('branch/orders/list/failed'))
                                        Failed
                                        @elseif(Request::is('branch/orders/list/canceled'))
                                        Canceled
                                        @elseif(Request::is('branch/orders/list/schedule'))
                                        Schedule
                                        @elseif(Request::is('branch/table/order/list/all'))
                                            All
                                        @elseif(Request::is('branch/table/order/list/confirmed'))
                                        Confirmed
                                        @elseif(Request::is('branch/table/order/list/cooking'))
                                        Cooking
                                        @elseif(Request::is('branch/table/order/list/done'))
                                            Ready for Serve
                                        @elseif(Request::is('branch/table/order/list/completed'))
                                        Completed
                                        @elseif(Request::is('branch/table/order/list/canceled'))
                                        Canceled
                                        @elseif(Request::is('branch/table/order/running'))
                                            On Table

                                            @elseif(Request::is('branch/product/list'))
                                             List

                                        @elseif(Request::is('branch/table/list'))
                                            List
                                        @elseif(Request::is('branch/table/index'))
                                            Avalibility
                                        @elseif( Request::is('branch/promotion/create'))
                                        Add New
                                        @elseif(Request::is('branch/kitchen/list'))
                                        All Chefs
                                        @elseif(Request::is('branch/kitchen/add-new'))
                                        Add New

                                        @endif
                                        </strong>
                                    </span>
                                </a>
                        </li>
                    </ul>
                    <h2>Dashboard</h2>
                </div>
            </div>
            <div class="navbar-nav-wrap-content-right">
                <ul class="navbar-nav align-items-center flex-row">
                    <li class="nav-item d-none d-sm-inline-block">
                        <div class="hs-unfold">
                            <div class="bg-white p-1 rounded">
                                @php( $local = session()->has('local')?session('local'):'en')
                                @php($lang = $branchLanguageSettings ?? [])
                                @php($lang = is_array($lang) ? $lang : [])
                                @php($lang = collect($lang)->map(function ($item) {
                                    return is_array($item) ? $item : [
                                        'id' => 1,
                                        'name' => $item,
                                        'code' => $item,
                                        'status' => 1,
                                        'default' => $item === 'en',
                                    ];
                                })->values()->all())
                                <div class="topbar-text dropdown disable-autohide text-capitalize">
                                    @if(isset($lang[0]) && is_array($lang[0]) && array_key_exists('code', $lang[0]))
                                        <a class="topbar-link d-flex gap-2 align-items-center font-weight-bold dropdown-toggle-empty" href="#" data-toggle="dropdown">


                                            @foreach($lang as $data)
                                                @if($data['code']==$local)
                                                    <img src="{{asset('assets/admin/img/main_header_language.png')}}" alt="">
                                                @endif
                                            @endforeach
                                        </a>
                                        <ul class="dropdown-menu">
                                            @foreach($lang as $key =>$data)
                                                @if($data['status']==1)
                                                    <li>
                                                        <a class="dropdown-item pr-8 d-flex gap-2 align-items-center"
                                                        href="{{route('admin.lang',[$data['code']])}}">
                                                            <span class="text-capitalize">{{\App\CentralLogics\Helpers::get_language_name($data['code'])}}</span>
                                                        </a>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </li>

<li class="nav-item d-none d-sm-inline-block">
    <div class="hs-unfold">
        <a class="rounded-circle"
           href="{{route('branch.order.list',['status'=>'pending'])}}">
            <img src="{{asset('assets/admin/img/main_header_bag.png')}}" alt="">

            <span class="btn-status btn-status-c1">0</span>
        </a>
    </div>
</li>
                    <li class="nav-item ml-4">

                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker navbar-dropdown-account-wrapper media gap-2" href="javascript:;"
                               data-hs-unfold-options='{
                                     "target": "#accountNavbarDropdown",
                                     "type": "css-animation"
                                   }'>

                                <div class="avatar avatar-sm avatar-circle">
                                    <img class="avatar-img"
                                         onerror="this.src='{{asset('assets/admin/img/160x160/img1.jpg')}}'"
                                         src="{{asset('/storage/branch')}}/{{auth('branch')->user()->image}}"
                                         alt="Image Description">
                                </div>
                            </a>

                            <div id="accountNavbarDropdown"
                                 class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-right navbar-dropdown-menu navbar-dropdown-account"
                                 style="width: 16rem;">
                                <div class="dropdown-item-text">
                                    <div class="media align-items-center">
                                        <div class="avatar avatar-sm avatar-circle mr-2">
                                            <img class="avatar-img"
                                                 onerror="this.src='{{asset('assets/admin/img/160x160/img1.jpg')}}'"
                                                 src="{{asset('/storage/branch')}}/{{auth('branch')->user()->image}}"
                                                 alt="Image Description">
                                        </div>
                                        <div class="media-body">
                                            <span class="card-title h5">{{auth('branch')->user()->name}}</span>
                                            <span class="card-text">{{auth('branch')->user()->email}}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="{{route('branch.settings')}}">
                                    <span class="text-truncate pr-2" title="Settings">{{translate('settings')}}</span>
                                </a>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="javascript:" onclick="Swal.fire({
                                    title: '{{translate('Do you want to logout ?')}}',
                                    showDenyButton: true,
                                    showCancelButton: true,
                                    confirmButtonColor: '#FC6A57',
                                    cancelButtonColor: '#363636',
                                    confirmButtonText: `{{translate('Yes')}}`,
                                    cancelButtonText: `{{translate('No')}}`,
                                    }).then((result) => {
                                    if (result.value) {
                                    location.href='{{route('branch.auth.logout')}}';
                                    } else{
                                        Swal.fire({
                                        title: '{{translate("Canceled")}}',
                                        confirmButtonText: '{{translate("Okay")}}',
                                        })
                                    }
                                    })">
                                    <span class="text-truncate pr-2" title="Sign out">{{translate('sign_out')}}</span>
                                </a>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </header>
</div>
<div id="headerFluid" class="d-none"></div>
<div id="headerDouble" class="d-none"></div>
<hr>
