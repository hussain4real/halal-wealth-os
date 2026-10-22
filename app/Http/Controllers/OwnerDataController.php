<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Release\OwnerExport;
use App\Support\Release\PrivateExportDownload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class OwnerDataController
{
    public function index(): Response
    {
        return Inertia::render('settings/Data');
    }

    public function download(Request $request, OwnerExport $export): PrivateExportDownload
    {
        abort_if($request->query->count() !== 0, 400);
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);
        try {
            return $export->download($owner);
        } catch (Throwable) {
            abort(503, 'Your complete export is unavailable. Check release readiness before trying again.');
        }
    }
}
