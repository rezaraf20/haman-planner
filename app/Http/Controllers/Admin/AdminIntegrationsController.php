<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\Controller;
use App\Models\CalendarConnection;
use App\Support\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin → Integrations: Google Calendar OAuth client (secret stored encrypted, shown masked). */
final class AdminIntegrationsController extends Controller
{
    public function show(): View
    {
        $fields = [];
        foreach (array_keys(IntegrationSettings::FIELDS) as $f) {
            $fields[$f] = ['masked' => IntegrationSettings::masked($f), 'source' => IntegrationSettings::source($f)];
        }
        return view('admin.integrations', [
            'fields' => $fields,
            'redirectUri' => CalendarController::redirectUri('google'),
            'connections' => CalendarConnection::withoutGlobalScopes()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $request->validate([
            'google_client_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'google_client_secret' => ['nullable', 'string', 'max:255'],
        ]);
        IntegrationSettings::put([
            'google_client_id' => $request->boolean('clear_google') ? null : (string) $request->input('google_client_id', ''),
            'google_client_secret' => $request->boolean('clear_google') ? null : (string) $request->input('google_client_secret', ''),
        ]);
        return redirect()->route('admin.integrations')->with('status', __('admin.int_saved'));
    }
}
