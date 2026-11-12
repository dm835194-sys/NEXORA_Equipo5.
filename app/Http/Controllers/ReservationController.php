<?php

namespace App\Http\Controllers;

use App\Models\Kit;
use App\Models\Practice;
use App\Models\QrToken;
use App\Models\Reservation;
use App\Services\QrService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function index(Request $request): View
    {
        $q = Reservation::with(['user', 'practice.lab', 'kit', 'loan'])->latest();
        if (! $request->user()->isOperator()) {
            $q->where('user_id', $request->user()->id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return view('reservations.index', ['reservations' => $q->paginate(12)->withQueryString()]);
    }

    public function create(Request $request): View
    {
        $practices = Practice::with(['lab', 'teacher'])->withCount(['reservations' => fn ($q) => $q->where('status', '!=', 'cancelled')])->where('starts_at', '>', now()->addMinutes(15))->orderBy('starts_at')->get();

        return view('reservations.create', ['practices' => $practices, 'kits' => Kit::with('assets')->get(), 'selected' => $request->integer('practice')]);
    }

    public function store(Request $request, ReservationService $service): RedirectResponse
    {
        $data = $request->validate(['practice_id' => 'required|exists:practices,id', 'kit_id' => 'required|exists:kits,id', 'slot_at' => 'required|date', 'accept' => 'accepted']);
        $r = $service->reserve($request->user(), $data['practice_id'], $data['kit_id'], $data['slot_at']);

        return redirect()->route('reservations.show', $r)->with('success', 'Reserva confirmada. Tu kit y turno ya están registrados.');
    }

    public function availability(Request $request, Practice $practice): JsonResponse
    {
        $data = $request->validate(['slot_at' => 'nullable|date']);
        $start = ! empty($data['slot_at']) ? Carbon::parse($data['slot_at']) : $practice->starts_at->copy()->subMinutes(45);
        $end = $practice->ends_at->copy()->addMinutes(15);
        $busyAssets = Reservation::whereNotIn('status', ['cancelled', 'completed'])->where('pickup_at', '<', $end)->where('until_at', '>', $start)->with('assets:id')->get()->flatMap(fn ($reservation) => $reservation->assets->pluck('id'))->unique();
        $kits = Kit::with('assets')->where('type', $practice->kit_type)->get()->map(function ($kit) use ($busyAssets) {
            $healthy = $kit->assets->isNotEmpty() && $kit->assets->every(fn ($a) => in_array($a->status, ['available', 'prepared', 'loaned']));
            $busy = $kit->assets->pluck('id')->intersect($busyAssets)->isNotEmpty();

            return ['id' => $kit->id, 'available' => $healthy && ! $busy];
        });

        return response()->json(['kits' => $kits])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Reservation $reservation): View
    {
        $this->access($request, $reservation);

        return view('reservations.show', ['reservation' => $reservation->load(['user', 'practice.lab', 'kit', 'assets', 'loan'])]);
    }

    public function cancel(Request $request, Reservation $reservation, ReservationService $service): RedirectResponse
    {
        $this->access($request, $reservation);
        $service->cancel($request->user(), $reservation);

        return back()->with('success', 'Reserva cancelada y cupo liberado.');
    }

    public function arrive(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->access($request, $reservation);
        abort_unless(in_array($reservation->status, ['reserved', 'ready']), 422);
        $reservation->update(['arrived_at' => $reservation->arrived_at ?? now()]);

        return back()->with('success', 'Tu llegada quedó registrada.');
    }

    public function qr(Request $request, Reservation $reservation, QrService $qr): JsonResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 403);
        abort_unless(in_array($reservation->status, ['reserved', 'ready']), 422);

        return DB::transaction(function () use ($reservation, $qr) {
            $r = Reservation::lockForUpdate()->findOrFail($reservation->id);
            abort_unless(in_array($r->status, ['reserved', 'ready']), 422);
            QrToken::where('reservation_id', $r->id)->whereNull('used_at')->update(['expires_at' => now()]);
            $token = Str::random(48);
            $expires = now()->addSeconds(config('turnolab.qr_seconds'));
            QrToken::create(['reservation_id' => $r->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires]);
            $url = url('/q/'.$token);

            return response()->json(['image' => 'data:image/svg+xml;base64,'.base64_encode($qr->svg($url)), 'code' => $url, 'expires_at' => $expires->toIso8601String()])->header('Cache-Control', 'no-store');
        });
    }

    private function access(Request $request, Reservation $r): void
    {
        abort_unless($r->user_id === $request->user()->id || $request->user()->isOperator(),403);
    }
}
