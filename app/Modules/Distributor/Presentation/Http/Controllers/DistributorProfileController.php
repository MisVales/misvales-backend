<?php

namespace App\Modules\Distributor\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Presentation\Http\Resources\DistributorAdminDetailResource;
use Illuminate\Http\Request;

class DistributorProfileController extends Controller
{
    public function show(Request $request)
    {
        $userId = $request->user()?->id;

        $distributor = Distributor::where('user_id', $userId)->firstOrFail();

        $this->authorize('viewSelf', $distributor);

        return new DistributorAdminDetailResource($distributor);
    }
}
