<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('matricula')->nullable()->unique();
            $t->string('role')->default('student');
        });
        Schema::create('labs', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('location');
            $t->unsignedInteger('capacity')->default(30);
            $t->timestamps();
        });
        Schema::create('kits', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('type');
            $t->string('location');
            $t->text('description');
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->foreignId('kit_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category');
            $t->string('abc')->default('C');
            $t->string('location');
            $t->string('status')->default('available');
            $t->timestamp('diagnosed_at')->nullable();
            $t->decimal('value', 12, 2)->default(0);
            $t->timestamps();
            $t->index(['status', 'category']);
        });
        Schema::create('practices', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('lab_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->string('kit_type');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedInteger('capacity')->default(20);
            $t->text('instructions')->nullable();
            $t->timestamps();
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('practice_id')->constrained();
            $t->foreignId('kit_id')->constrained();
            $t->timestamp('slot_at');
            $t->timestamp('pickup_at');
            $t->timestamp('until_at');
            $t->string('status')->default('reserved');
            $t->timestamp('accepted_at');
            $t->timestamp('arrived_at')->nullable();
            $t->timestamp('service_started_at')->nullable();
            $t->timestamps();
            $t->index(['kit_id', 'status']);
        });
        Schema::create('reservation_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained();
            $t->unique(['reservation_id', 'asset_id']);
        });
        Schema::create('loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->unique()->constrained();
            $t->foreignId('delivered_by')->constrained('users');
            $t->foreignId('received_by')->nullable()->constrained('users');
            $t->timestamp('delivered_at');
            $t->timestamp('due_at');
            $t->timestamp('received_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('status')->default('active');
            $t->text('delivery_notes')->nullable();
            $t->text('return_notes')->nullable();
            $t->timestamps();
        });
        Schema::create('qr_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->foreignId('reported_by')->constrained('users');
            $t->string('type');
            $t->text('description');
            $t->string('status')->default('open');
            $t->text('resolution')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->string('action');
            $t->json('details')->nullable();
            $t->string('offline_folio')->nullable()->unique();
            $t->timestamp('occurred_at');
            $t->timestamps();
        });
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('reservation_id')->constrained();
            $t->string('type');
            $t->string('title');
            $t->text('body');
            $t->timestamp('scheduled_at');
            $t->string('status')->default('pending');
            $t->timestamp('delivered_at')->nullable();
            $t->string('mail_status')->default('not_configured');
            $t->timestamps();
            $t->unique(['reservation_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['notices', 'audit_events', 'incidents', 'qr_tokens', 'loans', 'reservation_assets', 'reservations', 'practices', 'assets', 'kits', 'labs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['matricula', 'role']));
    }
};
