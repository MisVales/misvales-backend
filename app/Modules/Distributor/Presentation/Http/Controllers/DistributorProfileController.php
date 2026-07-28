<?php

namespace App\Modules\Distributor\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Presentation\Http\Resources\DistributorAdminDetailResource;
use Illuminate\Http\Request;

/**
 * Controlador de presentación para el perfil propio de la Distribuidora.
 * Permite a la distribuidora autenticada consultar su propia información autorizada.
 */
class DistributorProfileController extends Controller
{
    /**
     * Muestra el detalle de la distribuidora asociada al usuario autenticado.
     *
     * @tags Distributor Profile
     * @param Request $request
     * @return DistributorAdminDetailResource
     */
    public function show(Request $request)
    {
        $userId = $request->user()?->id;

        $distributor = Distributor::where('user_id', $userId)->firstOrFail();

        $this->authorize('viewSelf', $distributor);

        return new DistributorAdminDetailResource($distributor);
    }
}
