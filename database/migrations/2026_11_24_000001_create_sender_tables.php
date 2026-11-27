<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 30 Workspace sending IDs - sirf yahi IDs use hoti hain (allowlist)
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('from_name');
            $t->string('status', 20)->default('active'); // active | paused
            $t->string('paused_reason')->nullable();
            $t->unsignedSmallInteger('daily_cap')->default(20);
            $t->date('ramp_start')->nullable();           // pehla sending din (5 se 20 tak dheere)
            $t->string('history_id', 40)->nullable();     // Gmail history - replies padhne ke liye
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamps();
        });

        // PC se aaye ready leads (1 email + 2 follow-up likhe hue)
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('domain')->unique();               // ek domain = ek hi lead (same-domain block)
            $t->string('email')->unique();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('website')->nullable();
            $t->string('grp', 4)->nullable();             // A / B / N
            $t->char('zone', 1)->default('E');            // E = East US, W = West US (lead ka local time)
            $t->string('subject');
            $t->text('body1');
            $t->text('body2');
            $t->text('body3');
            $t->timestamp('verified_at')->nullable();     // ZeroBounce kab hua (30 din se purana = dobara)
            $t->string('status', 20)->default('queued');  // queued|active|done|replied|positive|bounced|unsubscribed|suppressed|stopped
            $t->unsignedTinyInteger('step')->default(0);  // kitne emails ja chuke (0-3)
            $t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->string('thread_id', 40)->nullable();      // Gmail thread (follow-up isi mein)
            $t->string('first_msgid')->nullable();        // pehle email ka Message-ID (In-Reply-To)
            $t->string('last_msgid')->nullable();
            $t->timestamp('next_send_at')->nullable();
            $t->unsignedInteger('lead_no')->nullable()->unique(); // positive lead ka number
            $t->string('stop_reason')->nullable();
            $t->timestamp('received_at')->nullable();     // PC se kab aaya
            $t->timestamps();
            $t->index(['status', 'next_send_at']);
            $t->index(['status', 'zone']);
        });

        // Har bheja/bhejne wala email. unique(lead, step) = crash ke baad bhi double-send nahi
        Schema::create('sends', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts');
            $t->unsignedTinyInteger('step');               // 1, 2, 3 (99 = handoff)
            $t->string('status', 12)->default('reserved'); // reserved | sent | failed
            $t->string('rfc_msgid')->unique();             // hamara Message-ID - Gmail Sent mein dhoondh ke pakka karte hain
            $t->string('gmail_id', 40)->nullable();
            $t->string('thread_id', 40)->nullable();
            $t->string('error', 500)->nullable();
            $t->date('send_day');                          // account ka din (cap ginti)
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['lead_id', 'step']);
            $t->index(['account_id', 'send_day']);
        });

        Schema::create('replies', function (Blueprint $t) {
            $t->id();
            $t->string('gmail_id', 40)->unique();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->string('thread_id', 40)->nullable();
            $t->string('from_email')->nullable();
            $t->string('subject')->nullable();
            $t->text('body')->nullable();
            $t->string('kind', 20);                        // reply | bounce | ooo
            $t->string('class', 30)->nullable();           // INTERESTED, NOT_INTERESTED, ...
            $t->string('summary', 500)->nullable();
            $t->string('action', 60)->nullable();
            $t->unsignedTinyInteger('tries')->default(0);
            $t->timestamp('handoff_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->index(['class', 'action']);
        });

        // Master do-not-email list (unsubscribe, bounce, past client) - PC ke saath do-taraf sync
        Schema::create('suppressions', function (Blueprint $t) {
            $t->id();
            $t->string('value')->unique();                 // email ya domain
            $t->string('kind', 10)->default('email');      // email | domain
            $t->string('reason', 30);                      // unsubscribe | bounce | not_interested | past_client | manual
            $t->string('source', 10)->default('cloud');    // cloud | pc
            $t->timestamps();
            $t->index('updated_at');
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        // PC ke liye badlaav ki line (status, reply, positive lead) - PC "since id" se padhta hai
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('domain')->nullable();
            $t->string('type', 30);
            $t->text('detail')->nullable();
            $t->timestamps();
            $t->index('type');
        });
    }

    public function down(): void
    {
        foreach (['events', 'settings', 'suppressions', 'replies', 'sends', 'leads', 'accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
