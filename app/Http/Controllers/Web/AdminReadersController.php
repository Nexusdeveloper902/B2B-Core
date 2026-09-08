<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Reader;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Contracts\View\View;

/**
 * TASK-027 — the reader management desk (/admin/readers): rename a
 * reader and switch its active mode. The dashboard's quick mode-form
 * stays as-is; this page is the full management surface (label + mode
 * in one update via PUT /api/v1/admin/readers/{reader}).
 *
 * TASK-029 — the desk is LIVE: roster frames (realtime.js booted from
 * the hidden [data-realtime] node) repaint label/mode cells the
 * moment any surface commits a reader change.
 */
class AdminReadersController extends Controller
{
    public function page(): View
    {
        return view('admin.readers', [
            'readers' => Reader::orderBy('label')->get(),
            'realtimeToken' => RealtimeToken::issue((int) auth()->id()),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}
