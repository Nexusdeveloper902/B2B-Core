<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RecyclingDeposit;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-027 (gap E1) — authorized streaming of one stored capture image.
 *
 * GET /api/v1/admin/captures/{deposit}/image — admin-only.
 *
 * Capture images live on the PRIVATE `local` disk (storage/app/private)
 * as audit artifacts and may contain students, so they are never put on
 * a public disk: this admin-authed route is the single authorized door
 * (session-cookie same-origin fetch from the EcoStation page works via
 * Sanctum's stateful middleware; a teacher/student request 403s at the
 * role wall before any byte of the file is read).
 */
class CaptureImageController extends Controller
{
    public function show(RecyclingDeposit $deposit): StreamedResponse
    {
        $path = $deposit->image_path;

        abort_if(
            $path === null || ! Storage::disk('local')->exists($path),
            404,
            __('api.capture_image_missing'),
        );

        return Storage::disk('local')->response(
            $path,
            'capture-'.$deposit->id,
            ['Cache-Control' => 'private, max-age=60'],
        );
    }
}
