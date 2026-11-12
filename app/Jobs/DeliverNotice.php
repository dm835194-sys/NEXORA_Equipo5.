<?php

namespace App\Jobs;

use App\Models\Notice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class DeliverNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $noticeId) {}

    public function handle(): void
    {
        $notice = Notice::with('user')->find($this->noticeId);
        if (! $notice || in_array($notice->status, ['cancelled', 'delivered'])) {
            return;
        }
        $notice->update(['status' => 'delivered', 'delivered_at' => now()]);
        if (config('turnolab.mail_enabled')) {
            try {
                Mail::raw($notice->body, fn ($m) => $m->to($notice->user->email)->subject($notice->title));
                $notice->update(['mail_status' => 'sent']);
            } catch (\Throwable $e) {
                $notice->update(['mail_status' => 'failed']);
                report($e);
            }
        }
    }
}
