<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Persistence\Models\Notification;
use App\Modules\Notification\Presentation\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Controlador de presentación para la API de Notificaciones de la aplicación.
 * Permite listar de forma paginada y marcar notificaciones propias como leídas.
 */
class NotificationController extends Controller
{
    /**
     * Lista de forma paginada las notificaciones asociadas al usuario autenticado.
     *
     * @tags Notifications
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', Auth::id());

        if ($request->has('status')) {
            $status = strtoupper($request->get('status'));
            if (in_array($status, [NotificationStatus::UNREAD->value, NotificationStatus::READ->value])) {
                $query->where('status', $status);
            }
        }

        $notifications = $query->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return NotificationResource::collection($notifications);
    }

    /**
     * Marca de forma idempotente una notificación individual como leída.
     * Validando estrictamente mediante Policy la propiedad del recurso.
     *
     * @tags Notifications
     *
     * @return NotificationResource
     */
    public function read(Request $request, Notification $notification)
    {
        $this->authorize('read', $notification);

        if ($notification->status === NotificationStatus::UNREAD->value) {
            $notification->update([
                'status' => NotificationStatus::READ->value,
                'read_at' => now(),
            ]);
        }

        return new NotificationResource($notification);
    }
}
