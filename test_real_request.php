<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Auth;

// Start session like StartSession middleware would
$session = $app['session']->driver();
$session->start();

// Login admin
$result = Auth::guard('admin')->attempt(['email' => 'admin@takamol.example.com', 'password' => 'ChangeMe123!']);
echo 'LOGIN_RESULT=' . var_export($result, true) . PHP_EOL;

// Get session ID
$sessionId = $session->getId();
echo 'SESSION_ID=' . $sessionId . PHP_EOL;

// Save session
$session->save();

// Now create a new request with the session cookie
$request = \Illuminate\Http\Request::create('/admin/dashboard', 'GET');
$request->cookies->set('laravel-session', $sessionId);
$request->setLaravelSession($app['session']->driver()->driver());

// The session driver needs to read from the database using the session ID
// But we need to set up the request properly

// Let's try a different approach - use the HttpKernel to handle a real request
$response = $kernel->handle($request);
echo 'RESPONSE_STATUS=' . $response->getStatusCode() . PHP_EOL;

// Check if we were redirected
if ($response->isRedirect()) {
    echo 'REDIRECTED_TO=' . $response->getTargetUrl() . PHP_EOL;
}

Auth::guard('admin')->logout();
