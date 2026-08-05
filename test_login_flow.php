<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Auth;

// Simulate a request with session
$request = \Illuminate\Http\Request::create('/admin/dashboard', 'GET');
$request->setLaravelSession($app['session']->driver());

// Test admin login
$result = Auth::guard('admin')->attempt(['email' => 'admin@takamol.example.com', 'password' => 'ChangeMe123!']);
echo 'LOGIN_RESULT=' . var_export($result, true) . PHP_EOL;

// Get the admin user
$admin = Auth::guard('admin')->user();
echo 'ADMIN_USER=' . ($admin ? $admin->email : 'NULL') . PHP_EOL;
echo 'ADMIN_ID=' . ($admin ? $admin->id : 'NULL') . PHP_EOL;

// Check session data
$session = $app['session']->driver();
$sessionData = $session->all();
echo 'SESSION_KEYS=' . implode(',', array_keys($sessionData)) . PHP_EOL;

// Test request->user('admin')
$requestUser = $request->user('admin');
echo 'REQUEST_USER_ADMIN=' . ($requestUser ? $requestUser->email : 'NULL') . PHP_EOL;

// Test request->user()
$requestUserDefault = $request->user();
echo 'REQUEST_USER_DEFAULT=' . ($requestUserDefault ? $requestUserDefault->email : 'NULL') . PHP_EOL;

// Now simulate the middleware logic
$user = $request->user('admin') ?? $request->user('web');
echo 'MIDDLEWARE_USER=' . ($user ? $user->email : 'NULL') . PHP_EOL;

Auth::guard('admin')->logout();
