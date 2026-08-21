<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pepe = App\Models\User::where('email', 'pepe@gmail.com')->first();
$request = Illuminate\Http\Request::create('/api/v1/credit-increase-requests', 'GET');
$request->setUserResolver(function () use ($pepe) { return $pepe; });

$controller = app(\App\Http\Controllers\Api\V1\Credito\SolicitudIncrementoLineaConsultaController::class);
try {
    $response = $controller->index($request);
    echo "Success!\n";
} catch (\Exception $e) {
    echo "Exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
}
