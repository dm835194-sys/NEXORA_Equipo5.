<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Kit;
use App\Models\Notice;
use App\Models\Practice;
use App\Models\QrToken;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function reserve(User $user, int $practiceId, int $kitId, string $slot): Reservation
    {
        return DB::transaction(function () use ($user, $practiceId, $kitId, $slot) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Lock the practice, then the kit and all assets in a stable order.
            $practice = Practice::lockForUpdate()->findOrFail($practiceId);
            $kit = Kit::lockForUpdate()->findOrFail($kitId);
            $assets = $kit->assets()->orderBy('id')->lockForUpdate()->get();
            $slotAt = Carbon::parse($slot, config('app.timezone'));
            $valid = collect([45, 30, 15])->contains(fn ($m) => $practice->starts_at->copy()->subMinutes($m)->equalTo($slotAt));
            $this->check($valid && $slotAt->greaterThan(now()), 'Elige una franja futura de esta práctica.');
            $this->check($practice->kit_type === $kit->type && $assets->count() > 0, 'El kit no corresponde a esta práctica.');
            $this->check($assets->every(fn ($a) => in_array($a->status, ['available', 'prepared', 'loaned'])), 'El kit tiene activos en revisión o fuera de servicio.');
            $active = $practice->reservations()->whereNotIn('status', ['cancelled']);
            $this->check((clone $active)->count() < $practice->capacity, 'La práctica ya alcanzó su cupo.');
            $this->check(! (clone $active)->where('user_id', $user->id)->exists(), 'Ya tienes una reserva para esta práctica.');
            $slotCount = (clone $active)->where('slot_at', $slotAt)->count();
            $this->check($slotCount < config('turnolab.slot_capacity'), 'Esta franja está llena; selecciona otra.');
            $start = $slotAt->copy();
            $end = $practice->ends_at->copy()->addMinutes(15);
            $busy = Reservation::whereNotIn('status', ['cancelled', 'completed'])->where('pickup_at', '<', $end)->where('until_at', '>', $start);
            $this->check(! (clone $busy)->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $assets->pluck('id')))->exists(), 'El kit ya está asignado en ese intervalo.');
            $this->check(! (clone $busy)->where('user_id', $user->id)->exists(), 'Tienes otra reserva que se superpone con este horario.');
            // A currently loaned/prepared asset is allowed for a later reservation only if a live reservation accounts for it.
            foreach ($assets->whereIn('status', ['loaned', 'prepared']) as $asset) {
                $this->check(Reservation::whereNotIn('status', ['cancelled', 'completed'])->whereHas('assets', fn ($q) => $q->whereKey($asset->id))->exists(), 'El estado del activo requiere conciliación técnica.');
            }
            $r = Reservation::create(['code' => 'TL-'.strtoupper(Str::random(7)), 'user_id' => $user->id, 'practice_id' => $practice->id, 'kit_id' => $kit->id,
                'slot_at' => $slotAt, 'pickup_at' => $slotAt->copy()->addSeconds($slotCount * 45), 'until_at' => $end, 'status' => 'reserved', 'accepted_at' => now()]);
            $r->assets()->attach($assets->pluck('id'));
            foreach (['pickup' => [$r->pickup_at, 'Tu kit está próximo a entregarse', 'Presenta tu matrícula y el QR de tu reserva.'], 'return' => [$practice->ends_at, 'Recuerda devolver tu kit', 'Verifica todos los componentes antes de volver a ventanilla.']] as $type => [$at,$title,$body]) {
                Notice::create(['user_id' => $user->id, 'reservation_id' => $r->id, 'type' => $type, 'title' => $title, 'body' => $body, 'scheduled_at' => $at->copy()->subMinutes(15)]);
            }
            $this->audit($user, $r, 'reservation.created', ['kit' => $kit->code]);

            return $r;
        }, 5);
    }

    public function cancel(User $actor, Reservation $reservation): void
    {
        DB::transaction(function () use ($actor, $reservation) {
            $r = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $this->check(in_array($r->status, ['reserved', 'ready']), 'Este préstamo ya no puede cancelarse.');
            if ($r->status === 'ready') {
                $r->assets()->where('status', 'prepared')->update(['status' => 'available']);
            }
            $r->update(['status' => 'cancelled']);
            QrToken::where('reservation_id', $r->id)->whereNull('used_at')->update(['expires_at' => now()]);
            Notice::where('reservation_id', $r->id)->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
            $this->audit($actor, $r, 'reservation.cancelled');
        }, 5);
    }

    public function prepare(User $actor, Reservation $reservation): void
    {
        DB::transaction(function () use ($actor, $reservation) {
            $r = Reservation::lockForUpdate()->findOrFail($reservation->id);
            Kit::whereKey($r->kit_id)->lockForUpdate()->firstOrFail();
            $assets = $r->assets()->orderBy('assets.id')->lockForUpdate()->get();
            $this->check($r->status === 'reserved', 'Sólo se preparan reservas pendientes.');
            $this->check(! Reservation::where('kit_id', $r->kit_id)->where('id', '!=', $r->id)->whereNotIn('status', ['completed', 'cancelled'])->where('pickup_at', '<', $r->pickup_at)->exists(), 'Prepara primero la reserva anterior de este kit.');
            $this->check($assets->every(fn ($a) => $a->status === 'available'), 'Espera la devolución y revisión del préstamo anterior.');
            $assets->each(fn ($a) => $a->update(['status' => 'prepared']));
            $r->update(['status' => 'ready']);
            $this->audit($actor, $r, 'kit.prepared');
        }, 5);
    }

    public function audit(User $actor, ?Reservation $r, string $action, array $details = [], ?string $folio = null, ?Carbon $at = null): void
    {
        AuditEvent::create(['user_id' => $actor->id, 'reservation_id' => $r?->id, 'action' => $action, 'details' => $details, 'offline_folio' => $folio, 'occurred_at' => $at ?? now()]);
    }

    public function check(bool $condition,string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['operation' => $message]);
        }
    }
}
