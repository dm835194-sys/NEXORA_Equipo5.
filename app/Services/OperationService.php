<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Kit;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\QrToken;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OperationService
{
    public function __construct(private ReservationService $rules) {}

    public function deliver(User $actor, Reservation $reservation, array $data, ?Carbon $at = null): Loan
    {
        return DB::transaction(function () use ($actor, $reservation, $data, $at) {
            $time = $at ?? now();
            $r = Reservation::lockForUpdate()->findOrFail($reservation->id);
            Kit::whereKey($r->kit_id)->lockForUpdate()->firstOrFail();
            $assets = $r->assets()->orderBy('assets.id')->lockForUpdate()->get();
            $this->rules->check($r->status === 'ready' && ! $r->loan()->exists(), 'El kit debe estar preparado y no tener un préstamo activo.');
            $this->rules->check(hash_equals((string) $r->user->matricula, trim($data['matricula'] ?? '')), 'La matrícula no corresponde a la reserva.');
            $this->rules->check(strtoupper(trim($data['kit_code'] ?? '')) === $r->kit->code, 'El QR del kit no corresponde a esta reserva.');
            $this->rules->check($assets->every(fn ($a) => $a->status === 'prepared'), 'Hay componentes que no están listos para entregar.');
            $confirmed = collect($data['components'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
            $this->rules->check($confirmed === $assets->pluck('id')->sort()->values()->all(), 'Comprueba todos los componentes del kit.');
            if (! config('turnolab.demo') && ! $at) {
                $this->rules->check($time->between($r->pickup_at->copy()->subMinutes(10), $r->practice->starts_at->copy()->addMinutes(10)), 'La entrega está fuera de la ventana autorizada.');
            }
            $assisted = (bool) ($data['assisted'] ?? false);
            if ($assisted) {
                $this->rules->check(mb_strlen(trim($data['reason'] ?? '')) >= 10, 'Indica el motivo de la atención asistida, con al menos 10 caracteres.');
            } else {
                $token = trim($data['token'] ?? '');
                if (str_contains($token, '/q/')) {
                    $token = basename(parse_url($token, PHP_URL_PATH));
                }
                $qr = QrToken::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
                $this->rules->check($qr && $qr->reservation_id === $r->id && ! $qr->used_at && $qr->expires_at->greaterThan(now()), 'El QR no es válido, venció o ya fue utilizado. Solicita uno nuevo al estudiante.');
                $qr->update(['used_at' => now()]);
            }
            $r->update(['status' => 'active']);
            $assets->each(fn ($a) => $a->update(['status' => 'loaned']));
            $loan = Loan::create(['reservation_id' => $r->id, 'delivered_by' => $actor->id, 'delivered_at' => $time, 'due_at' => $r->practice->ends_at, 'status' => 'active', 'delivery_notes' => $assisted ? ($data['reason'] ?? '') : null]);
            QrToken::where('reservation_id', $r->id)->whereNull('used_at')->update(['expires_at' => now()]);
            Notice::where('reservation_id', $r->id)->where('type', 'pickup')->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
            $this->rules->audit($actor, $r, $assisted ? 'loan.delivered.assisted' : 'loan.delivered', ['kit' => $r->kit->code, 'matricula_verified' => true, 'reason' => $assisted ? ($data['reason'] ?? '') : null], $data['folio'] ?? null, $time);

            return $loan;
        }, 5);
    }

    public function receive(User $actor, Loan $loan, array $data, ?Carbon $at = null): void
    {
        DB::transaction(function () use ($actor, $loan, $data, $at) {
            $l = Loan::lockForUpdate()->findOrFail($loan->id);
            $r = Reservation::lockForUpdate()->findOrFail($l->reservation_id);
            $this->rules->check($l->status === 'active', 'Este préstamo ya fue recibido.');
            $assets = $r->assets()->orderBy('assets.id')->lockForUpdate()->get();
            $present = collect($data['components'] ?? [])->map(fn ($id) => (int) $id)->unique();
            $this->rules->check($present->diff($assets->pluck('id'))->isEmpty(), 'Se incluyó un componente ajeno al préstamo.');
            $missing = $assets->whereNotIn('id', $present->all());
            $fault = (bool) ($data['fault'] ?? false);
            $checked = (bool) ($data['checked'] ?? false);
            $notes = trim($data['notes'] ?? '');
            $this->rules->check((! $fault && $missing->isEmpty()) || mb_strlen($notes) >= 8, 'Describe la falla o los faltantes con al menos 8 caracteres.');
            $clean = $checked && ! $fault && $missing->isEmpty();
            $time = $at ?? now();
            $l->update(['received_by' => $actor->id, 'received_at' => $time, 'return_notes' => $notes, 'status' => $clean ? 'completed' : 'review', 'closed_at' => $clean ? $time : null]);
            $r->update(['status' => $clean ? 'completed' : 'review']);
            foreach ($assets as $a) {
                $state = $missing->contains('id', $a->id) ? 'missing' : ($clean ? 'available' : ($fault ? 'quarantine' : 'pending_review'));
                $a->update(['status' => $state, 'diagnosed_at' => $clean ? $time : $a->diagnosed_at]);
            }
            if ($fault || $missing->isNotEmpty()) {
                Incident::create(['reservation_id' => $r->id, 'reported_by' => $actor->id, 'type' => $missing->isNotEmpty() ? 'missing' : 'damage', 'description' => $notes.($missing->isNotEmpty() ? ' · Faltan: '.$missing->pluck('code')->implode(', ') : ''), 'status' => 'open']);
            }
            Notice::where('reservation_id', $r->id)->where('type', 'return')->whereIn('status', ['pending', 'queued'])->update(['status' => 'cancelled']);
            $this->rules->audit($actor, $r, 'loan.received', ['condition' => $clean ? 'checked_ok' : 'pending_review', 'missing' => $missing->pluck('code')->values()->all(), 'notes' => $notes], $data['folio'] ?? null, $time);
        }, 5);
    }

    public function inspect(User $actor, Loan $loan, string $notes): void
    {
        DB::transaction(function () use ($actor, $loan, $notes) {
            $l = Loan::lockForUpdate()->findOrFail($loan->id);
            $r = Reservation::lockForUpdate()->findOrFail($l->reservation_id);
            $this->rules->check($l->status === 'review', 'El préstamo no tiene una revisión pendiente.');
            $this->rules->check(! Incident::where('reservation_id', $r->id)->where('status', '!=', 'resolved')->exists(), 'Resuelve primero las incidencias de este préstamo.');
            $assets = $r->assets()->orderBy('assets.id')->lockForUpdate()->get();
            $this->rules->check(! $assets->contains('status', 'missing'), 'No se puede liberar un kit con componentes faltantes.');
            $assets->each(fn ($a) => $a->update(['status' => 'available', 'diagnosed_at' => now()]));
            $l->update(['status' => 'completed', 'closed_at' => now(), 'return_notes' => trim($l->return_notes.' '.$notes)]);
            $r->update(['status' => 'completed']);
            $this->rules->audit($actor,$r,'inspection.completed',['notes' => $notes]);
        }, 5);
    }
}
