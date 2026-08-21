<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CLIENT CONTROLLERS
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Client\ProductController as ClientProductController;
use App\Http\Controllers\Client\CartController;
use App\Http\Controllers\Client\OrderController as ClientOrderController;
use App\Http\Controllers\Client\PaymentController;

/*
|--------------------------------------------------------------------------
| AUTH CONTROLLERS
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| ADMIN CONTROLLERS
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

Route::get('/', [ClientProductController::class, 'index'])
    ->name('home');

Route::view('/about', 'client.about')
    ->name('client.about');

/*
|--------------------------------------------------------------------------
| SHOP
|--------------------------------------------------------------------------
*/

Route::prefix('shop')
    ->name('client.')
    ->group(function () {

        Route::get('/{category_slug?}', [ClientProductController::class, 'shop'])
            ->name('shop');

        Route::get('/product/{slug}', [ClientProductController::class, 'show'])
            ->name('product.show');
    });

/*
|--------------------------------------------------------------------------
| AUTH REQUIRED
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | PROFILE
    |--------------------------------------------------------------------------
    */

    Route::prefix('profile')
        ->name('profile.')
        ->group(function () {

            Route::get('/', [ProfileController::class, 'edit'])
                ->name('edit');

            Route::patch('/', [ProfileController::class, 'update'])
                ->name('update');

            Route::delete('/', [ProfileController::class, 'destroy'])
                ->name('destroy');
        });

    /*
    |--------------------------------------------------------------------------
    | CART
    |--------------------------------------------------------------------------
    */

    Route::prefix('cart')
        ->name('client.cart.')
        ->group(function () {

            Route::get('/', [CartController::class, 'index'])
                ->name('index');

            Route::post('/add', [CartController::class, 'add'])
                ->name('add');

            Route::patch('/update', [CartController::class, 'update'])
                ->name('update');

            Route::delete('/remove/{id}', [CartController::class, 'remove'])
                ->name('remove');

            Route::delete('/clear', [CartController::class, 'clear'])
                ->name('clear');
        });

    /*
    |--------------------------------------------------------------------------
    | ORDERS & CHECKOUT
    |--------------------------------------------------------------------------
    */

    Route::prefix('orders')
        ->name('client.orders.')
        ->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Checkout
            |--------------------------------------------------------------------------
            */

            Route::get('/checkout', [ClientOrderController::class, 'checkout'])
                ->name('checkout');

            Route::post('/checkout', [ClientOrderController::class, 'placeOrder'])
                ->name('place');

            Route::get('/success/{order}', [ClientOrderController::class, 'success'])
                ->name('success');

            /*
            |--------------------------------------------------------------------------
            | Orders
            |--------------------------------------------------------------------------
            */

            Route::get('/', [ClientOrderController::class, 'index'])
                ->name('index');

            Route::get('/{order}', [ClientOrderController::class, 'show'])
                ->name('show');

            Route::patch('/{order}/cancel', [ClientOrderController::class, 'cancel'])
                ->name('cancel');
        });

    /*
    |--------------------------------------------------------------------------
    | PAYMENT WITH VNPAY
    |--------------------------------------------------------------------------
    */

    Route::prefix('payment')
        ->middleware('auth')
        ->group(function () {

            Route::get(
                '/vnpay/{order}',
                [PaymentController::class, 'createVnpayPayment']
            )->name('client.payment.vnpay');

            Route::get(
                '/vnpay-return',
                [PaymentController::class, 'vnpayReturn']
            )->name('client.payment.vnpay.return');

            Route::get(
                '/momo/{order}',
                [PaymentController::class, 'createMomoPayment']
            )->name('client.payment.momo');
        });

    /*
    |--------------------------------------------------------------------------
    | CHAT / AI Conversation History
    |--------------------------------------------------------------------------
    */
    Route::prefix('chat')
        ->name('client.chat.')
        ->group(function () {
            Route::get('/conversations', [\App\Http\Controllers\ChatHistoryController::class, 'index'])->name('conversations.index');
            Route::post('/conversations', [\App\Http\Controllers\ChatHistoryController::class, 'storeConversation'])->name('conversations.store');
            Route::get('/conversations/{conversation}', [\App\Http\Controllers\ChatHistoryController::class, 'show'])->name('conversations.show');
            Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\ChatHistoryController::class, 'storeMessage'])->name('conversations.messages.store');
        });
});

Route::get(
    '/payment/vnpay-ipn',
    [PaymentController::class, 'vnpayIpn']
)->name('client.payment.vnpay.ipn');

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return view('admin.dashboard');
        })->name('dashboard');

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        Route::resource('categories', CategoryController::class)
            ->except(['show', 'create']);

        /*
        |--------------------------------------------------------------------------
        | Attributes
        |--------------------------------------------------------------------------
        */

        Route::resource('attributes', AttributeController::class)
            ->except(['show', 'create']);

        Route::post(
            'attributes/{attribute}/values',
            [AttributeController::class, 'storeValue']
        )->name('attributes.storeValue');

        Route::delete(
            'attributes/values/{value}',
            [AttributeController::class, 'destroyValue']
        )->name('attributes.destroyValue');

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        Route::resource('products', AdminProductController::class)
            ->except(['show']);

        /*
        |--------------------------------------------------------------------------
        | Brands
        |--------------------------------------------------------------------------
        */

        Route::resource('brands', BrandController::class)
            ->except(['show', 'create', 'edit']);

        /*
        |--------------------------------------------------------------------------
        | Manager USERS
        |--------------------------------------------------------------------------
        */

        Route::resource('users', UserController::class);

        Route::get(
            'users-datatable',
            [UserController::class, 'datatable']
        )->name('users.datatable');

        /*
        |--------------------------------------------------------------------------
        | Manager ORDERS
        |--------------------------------------------------------------------------
        */

        Route::resource('orders', AdminOrderController::class)
            ->only(['index', 'show']);

        Route::put(
            'orders/{order}/status',
            [AdminOrderController::class, 'updateStatus']
        )->name('orders.updateStatus');
});

/*
|--------------------------------------------------------------------------
| VENDOR ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:vendor,admin'])
    ->prefix('vendor')
    ->name('vendor.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return 'Trang quản lý gian hàng';
        })->name('dashboard');
    });

/*
|--------------------------------------------------------------------------
| DEFAULT DASHBOARD
|--------------------------------------------------------------------------
*/

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__ . '/auth.php';
