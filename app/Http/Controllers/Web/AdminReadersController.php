<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Reader;
use Illuminate\Contracts\View\View;

/**
 * TASK-027 — the reader management desk (/admin/readers): rename a
 * reader and switch its active mode. The dashboard's quick mode-form
 * stays as-is; this page is the full management surface (label + mode
 * in one update via PUT /api/v1/admin/readers/{reader}).
 */
class AdminReadersController extends Controller
{
    public function page(): View
    {
        return view('admin.readers', [
            'readers' => Reader::orderBy('label')->get(),
        ]);
    }
}
