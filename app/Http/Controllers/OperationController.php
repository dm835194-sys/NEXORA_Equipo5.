<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\Loan;
use App\Models\QrToken;
use App\Models\Reservation;
use App\Services\OperationService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationController extends Controller
{
    private function gate(Request $r): void
    {
        abort_unless($r->user()->isOperator(), 403);
    }

    public function index(Request $request): View
    {
        $this->gate($request);

        return view('operations.index', ['reservations' => Reservation::with(['user', 'practice.lab', 'kit'])->whereIn('status', ['reserved', 'ready'])->orderBy('pickup_at')->get(), 'loans' => Loan::with(['reservation.user', 'reservation.kit', 'reservation.assets'])->whereIn('status', ['active', 'review'])->orderBy('due_at')->get()]);
    }

    public function prepare(Request $request, Reservation $reservation, ReservationService $service): RedirectResponse
    {
        $this->gate($request);
        $service->prepare($request->user(), $reservation);

        return back()->with('success', 'Kit preparado. Todos sus componentes quedaron asignados.');
    }

    public function delivery(Request $request, Reservation $reservation): View
    {
        $this->gate($request);
        $reservation->load(['user', 'practice.lab', 'kit', 'assets', 'loan']);
        if ($reservation->status === 'ready' && ! $reservation->service_started_at) {
            $reservation->update(['service_started_at' => now()]);
        }

        return view('operations.delivery', ['reservation' => $reservation, 'token' => $request->string('token')->toString()]);
    }

    public function scan(Request $request): RedirectResponse
    {
        $this->gate($request);
        $value = trim($request->input('code', ''));
        if (str_contains($value, '/q/')) {
            $value = basename(parse_url($value, PHP_URL_PATH));
        }
        $token = QrToken::where('token_hash', hash('sha256', $value))->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $token) {
            throw ValidationException::withMessages(['code' => 'El QR no existe o venció. Pide al estudiante actualizarlo.']);
        }

        return redirect()->route('operations.delivery', ['reservation' => $token->reservation_id, 'token' => $value]);
    }

    public function fromQr(Request $request, string $token): RedirectResponse
    {
        $request->merge(['code' => $token]);

        return $this->scan($request);
    }

    public function deliver(Request $request, Reservation $reservation, OperationService $service): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['matricula' => 'required|string|max:40', 'kit_code' => 'required|string|max:40', 'components' => 'required|array', 'components.*' => 'integer', 'token' => 'nullable|string|max:500', 'assisted' => 'nullable|boolean', 'reason' => 'nullable|string|max:500']);
        $service->deliver($request->user(), $reservation, $data);

        return redirect()->route('operations.index')->with('success', 'Entrega confirmada. Se registraron responsable, activos y técnico.');
    }

    public function receive(Request $request, Loan $loan, OperationService $service): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['components' => 'nullable|array', 'components.*' => 'integer', 'fault' => 'nullable|boolean', 'checked' => 'nullable|boolean', 'notes' => 'nullable|string|max:1000']);
        $service->receive($request->user(), $loan, $data);

        return back()->with('success', 'Devolución registrada. Revisa el estado de disponibilidad del kit.');
    }

    public function inspect(Request $request, Loan $loan, OperationService $service): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['notes' => 'required|string|min:8|max:1000']);
        $service->inspect($request->user(), $loan, $data['notes']);

        return back()->with('success', 'Revisión completada y componentes disponibles.');
    }

    public function incidents(Request $request): View
    {
        $this->gate($request);

        return view('incidents', ['incidents' => Incident::with(['reservation.user', 'asset', 'reporter'])->latest()->paginate(12), 'assets' => Asset::whereIn('status', ['missing', 'quarantine', 'pending_review', 'maintenance'])->orderBy('code')->get()]);
    }

    public function incidentStore(Request $request): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['type' => 'required|in:damage,missing,other', 'asset_id' => 'nullable|exists:assets,id', 'description' => 'required|string|min:8|max:1500']);
        DB::transaction(function () use ($data, $request) {
            $asset = ! empty($data['asset_id']) ? Asset::lockForUpdate()->findOrFail($data['asset_id']) : null;
            $r = $asset ? Reservation::whereIn('status', ['active', 'review', 'ready'])->whereHas('assets', fn ($q) => $q->whereKey($asset->id))->first() : null;
            Incident::create($data + ['reported_by' => $request->user()->id, 'reservation_id' => $r?->id]);
            if ($asset && $data['type'] === 'damage') {
                $asset->update(['status' => 'quarantine']);
            }
            if ($asset && $data['type'] === 'missing') {
                $asset->update(['status' => 'missing']);
            }
        });

        return back()->with('success', 'Incidencia registrada. La responsabilidad requiere revisión de evidencias.');
    }

    public function resolve(Request $request, Incident $incident): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['resolution' => 'required|string|min:10|max:1500']);
        DB::transaction(function () use ($incident, $data, $request) {
            $i = Incident::lockForUpdate()->findOrFail($incident->id);
            if ($i->status === 'resolved') {
                throw ValidationException::withMessages(['resolution' => 'Esta incidencia ya está resuelta.']);
            }
            $i->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_at' => now()]);
            if ($i->reservation_id) {
                Reservation::find($i->reservation_id)?->assets()->where('status', 'missing')->update(['status' => 'pending_review']);
            }
            if ($i->asset_id && $i->asset?->status === 'missing') {
                $i->asset->update(['status' => 'pending_review']);
            }
            AuditEvent::create(['user_id' => $request->user()->id, 'reservation_id' => $i->reservation_id, 'asset_id' => $i->asset_id, 'action' => 'incident.resolved', 'details' => $data, 'occurred_at' => now()]);
        });

        return back()->with('success', 'Incidencia resuelta. La revisión técnica sigue siendo necesaria antes de liberar equipo.');
    }

    public function contingency(Request $request): View
    {
        $this->gate($request);

        return view('contingency', ['events' => AuditEvent::with('reservation')->whereNotNull('offline_folio')->latest()->take(10)->get()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->gate($request);
        $rows = Reservation::with(['user', 'kit', 'assets', 'practice'])->whereIn('status', ['ready', 'active'])->orderBy('pickup_at')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['folio', 'operacion', 'reserva', 'matricula', 'kit', 'fecha', 'observaciones'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, ['', '', $r->code, $r->user->matricula, $r->kit->code, '', 'Componentes: '.$r->assets->pluck('code')->implode(' | ')], ',', '"', '');
            }fclose($out);
        }, 'turnolab-respaldo-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function import(Request $request, OperationService $service): RedirectResponse
    {
        $this->gate($request);
        $request->validate(['file' => 'required|file|max:1024', 'authority' => 'accepted']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }
        if ($header !== ['folio', 'operacion', 'reserva', 'matricula', 'kit', 'fecha', 'observaciones']) {
            throw ValidationException::withMessages(['file' => 'Usa la plantilla CSV del respaldo y conserva sus columnas.']);
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== 7) {
                throw ValidationException::withMessages(['file' => 'Cada fila debe tener siete columnas.']);
            }
            $entry = array_combine($header, $row);
            if (! trim($entry['operacion'])) {
                continue;
            }$rows[] = $entry;
            if (count($rows) > 300) {
                throw ValidationException::withMessages(['file' => 'Importa como máximo 300 movimientos por archivo.']);
            }
        }fclose($handle);
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Completa folio, operación y fecha en al menos una fila.']);
        }
        usort($rows, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));
        $count = 0;
        $skipped = 0;
        DB::transaction(function () use ($rows, $request, $service, &$count, &$skipped) {
            foreach ($rows as $row) {
                if (! preg_match('/^[A-Za-z0-9-]{4,50}$/', $row['folio'])) {
                    throw ValidationException::withMessages(['file' => 'Cada folio debe ser único y contener de 4 a 50 letras, números o guiones.']);
                }
                if (AuditEvent::where('offline_folio', $row['folio'])->exists()) {
                    $skipped++;

                    continue;
                }
                try {
                    $at = Carbon::parse($row['fecha'], config('app.timezone'));
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['file' => 'La fecha de un movimiento no es válida. Usa AAAA-MM-DD HH:MM:SS.']);
                }
                if ($at->isFuture() || $at->lessThan(now()->subDays(30))) {
                    throw ValidationException::withMessages(['file' => 'La fecha debe estar en los últimos 30 días y no ser futura.']);
                }
                $r = Reservation::where('code', $row['reserva'])->first();
                if (! $r || $r->user->matricula !== $row['matricula'] || $r->kit->code !== strtoupper($row['kit'])) {
                    throw ValidationException::withMessages(['file' => 'Una fila no coincide con la reserva, matrícula y kit del respaldo.']);
                }
                if ($row['operacion'] === 'entrega') {
                    $service->deliver($request->user(), $r, ['matricula' => $row['matricula'], 'kit_code' => $row['kit'], 'components' => $r->assets->pluck('id')->all(), 'assisted' => true, 'reason' => 'Contingencia offline: '.$row['observaciones'], 'folio' => $row['folio']], $at);
                } elseif ($row['operacion'] === 'devolucion') {
                    if (! $r->loan || $at->lessThan($r->loan->delivered_at)) {
                        throw ValidationException::withMessages(['file' => 'La devolución requiere una entrega anterior.']);
                    }
                    $service->receive($request->user(), $r->loan, ['components' => $r->assets->pluck('id')->all(), 'checked' => false, 'notes' => 'Respaldo offline; revisión pendiente. '.$row['observaciones'], 'folio' => $row['folio']], $at);
                } else {
                    throw ValidationException::withMessages(['file' => 'Operaciones permitidas: entrega o devolucion.']);
                }
                $count++;
            }
        }, 5);

        return back()->with('success',"Conciliación completa: {$count} movimientos importados y {$skipped} folios ya registrados omitidos.");
    }
}
