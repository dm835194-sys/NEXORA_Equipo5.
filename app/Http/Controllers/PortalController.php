<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Kit;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\Practice;
use App\Models\Reservation;
use App\Services\QrService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalController extends Controller
{
    public function dashboard(Request $request): View
    {
        $operator = $request->user()->isOperator();
        $reservations = Reservation::with(['user', 'practice.lab', 'kit', 'loan'])->when(! $operator, fn ($q) => $q->where('user_id', $request->user()->id))->whereNotIn('status', ['cancelled', 'completed'])->orderBy('pickup_at')->take(7)->get();
        $active = Loan::where('status', 'active')->count();

        return view('dashboard', compact('operator', 'reservations', 'active') + [
            'inventoryTotal' => Asset::count(),
            'stats' => ['available' => Asset::where('status', 'available')->count(), 'prepared' => Reservation::where('status', 'ready')->count(), 'review' => Asset::whereIn('status', ['pending_review', 'quarantine', 'missing'])->count(), 'late' => Loan::where('status', 'active')->where('due_at', '<', now())->count()],
            'events' => AuditEvent::with(['user', 'reservation'])->latest('occurred_at')->when(! $operator, fn ($q) => $q->whereHas('reservation', fn ($r) => $r->where('user_id', $request->user()->id)))->take(5)->get(),
            'practices' => Practice::with('lab')->where('starts_at', '>', now())->orderBy('starts_at')->take(3)->get(),
        ]);
    }

    public function inventory(Request $request): View
    {
        $query = Asset::with('kit')->orderBy('code');
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%')->orWhere('code', 'like', '%'.$request->string('q').'%'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('abc')) {
            $query->where('abc', $request->string('abc'));
        }

        return view('inventory', ['assets' => $query->paginate(16)->withQueryString(), 'counts' => Asset::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function assetQr(Asset $asset, QrService $qr): Response
    {
        return response($qr->svg($asset->code))->header('Content-Type', 'image/svg+xml');
    }

    public function assetUpdate(Request $request, Asset $asset): RedirectResponse
    {
        abort_unless($request->user()->isOperator(), 403);
        $data = $request->validate(['status' => 'required|in:available,pending_review,quarantine,maintenance,missing', 'notes' => 'required|string|min:8|max:500']);
        DB::transaction(function () use ($asset, $data, $request) {
            $a = Asset::lockForUpdate()->findOrFail($asset->id);
            $busy = Reservation::whereIn('status', ['ready', 'active', 'review'])->whereHas('assets', fn ($q) => $q->whereKey($a->id))->exists();
            if ($busy) {
                throw ValidationException::withMessages(['status' => 'Este equipo pertenece a un préstamo o kit pendiente. Realiza la revisión desde ventanilla.']);
            }
            $a->update(['status' => $data['status'], 'diagnosed_at' => $data['status'] === 'available' ? now() : $a->diagnosed_at]);
            AuditEvent::create(['user_id' => $request->user()->id, 'asset_id' => $a->id, 'action' => 'asset.updated', 'details' => $data, 'occurred_at' => now()]);
        });

        return back()->with('success', 'Estado actualizado y registrado en bitácora.');
    }

    public function practices(Request $request): View
    {
        return view('practices', ['practices' => Practice::with(['lab', 'teacher'])->withCount(['reservations' => fn ($q) => $q->where('status', '!=', 'cancelled')])->where('ends_at', '>', now())->orderBy('starts_at')->paginate(12), 'labs' => Lab::all(), 'types' => Kit::distinct()->pluck('type')]);
    }

    public function practiceStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canPlan(), 403);
        $data = $request->validate(['title' => 'required|string|max:100', 'lab_id' => 'required|exists:labs,id', 'kit_type' => 'required|in:medicion,electronica,redes', 'starts_at' => 'required|date|after:now', 'ends_at' => 'required|date|after:starts_at', 'capacity' => 'required|integer|min:1|max:60', 'instructions' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($data, $request) {
            $lab = Lab::whereKey($data['lab_id'])->lockForUpdate()->firstOrFail();
            if ($data['capacity'] > $lab->capacity || Practice::where('lab_id', $lab->id)->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Revisa el cupo del laboratorio y los horarios: existe un conflicto.']);
            }
            Practice::create($data + ['teacher_id' => $request->user()->id]);
        });

        return back()->with('success', 'Práctica programada. Ya puede recibir reservas.');
    }

    public function notices(Request $request): View
    {
        return view('notices', ['notices' => Notice::where('user_id', $request->user()->id)->latest('scheduled_at')->paginate(15)]);
    }

    public function rules(): View
    {
        return view('rules');
    }

    public function metrics(Request $request): View
    {
        abort_unless($request->user()->isOperator() || $request->user()->canPlan(), 403);
        $loans = Loan::with('reservation')->get();
        $due = $loans->where('due_at', '<=', now());
        $late = $due->filter(fn ($l) => ! $l->received_at || $l->received_at->greaterThan($l->due_at))->count();
        $waits = $loans->filter(fn ($l) => $l->reservation->arrived_at && $l->reservation->service_started_at)->map(fn ($l) => max(0, $l->reservation->arrived_at->diffInSeconds($l->reservation->service_started_at, false) / 60));
        $service = $loans->filter(fn ($l) => $l->reservation->service_started_at)->map(fn ($l) => max(0, $l->reservation->service_started_at->diffInSeconds($l->delivered_at, false)));

        return view('metrics', ['total' => Asset::count(), 'unknown' => Asset::whereNull('diagnosed_at')->count(), 'loans' => $loans, 'due' => $due->count(), 'late' => $late, 'wait' => $waits->count() ? $waits->avg() : null, 'service' => $service->count() ? $service->avg() : null, 'samples' => $waits->count(), 'events' => AuditEvent::with(['user', 'reservation'])->latest('occurred_at')->paginate(15)]);
    }

    public function simulation(Request $request): View
    {
        abort_unless($request->user()->isOperator() || $request->user()->canPlan(), 403);
        $volume = in_array($request->integer('volume'), [185, 220]) ? $request->integer('volume') : 185;
        $percent = $request->integer('reservations', 80);
        $percent = max(50, min(100, $percent));
        $peaks = [9 * 3600, 11 * 3600, 13 * 3600];
        $events = [];
        $peakCount = (int) round($volume * .8);
        for ($i = 0; $i < $volume; $i++) {
            $wave = $i % 3;
            $class = $peaks[$wave];
            $isPeak = $i < $peakCount;
            $n = intdiv($i, 3);
            $oldArrival = $isPeak ? $class - 900 + ($n * 47 % 900) : 8 * 3600 + ($i * 137 % 25200);
            $reserved = ($i * 73 % $volume) < round($volume * $percent / 100);
            $newArrival = $reserved ? $class - 2700 + ($n * 45 % 2700) : $oldArrival;
            $return = $class + 5400 + ($n * 17 % 600);
            $events[] = ['old' => $oldArrival, 'new' => $newArrival, 'type' => 'pickup', 'reserved' => $reserved];
            $events[] = ['old' => $return, 'new' => $return, 'type' => 'return', 'reserved' => false];
        }
        $run = function (string $mode) use ($events) {
            $sorted = $events;
            usort($sorted, fn ($a, $b) => $a[$mode] <=> $b[$mode]);
            $free = [0, 0];
            $waits = [];
            $returns = [];
            foreach ($sorted as $e) {
                $idx = $mode === 'old' ? ($free[0] <= $free[1] ? 0 : 1) : ($e['type'] === 'pickup' ? 0 : 1);
                $service = $e['type'] === 'pickup' ? ($mode === 'old' ? 210 : 30) : 60;
                $wait = max(0, $free[$idx] - $e[$mode]);
                $free[$idx] = max($free[$idx], $e[$mode]) + $service;
                if ($e['type'] === 'pickup') {
                    $waits[] = $wait / 60;
                } else {
                    $returns[] = $wait / 60;
                }
            }
            sort($waits);

            return ['average' => array_sum($waits) / count($waits), 'p95' => $waits[(int) floor((count($waits) - 1) * .95)], 'max' => max($waits), 'returns' => array_sum($returns) / count($returns)];
        };

        return view('simulation', ['volume' => $volume, 'percent' => $percent, 'actualPercent' => round(100 * count(array_filter($events,fn ($e) => $e['type'] === 'pickup' && $e['reserved'])) / $volume,1), 'before' => $run('old'), 'after' => $run('new')]);
    }
}
