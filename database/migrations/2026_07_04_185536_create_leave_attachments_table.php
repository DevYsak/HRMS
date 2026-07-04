<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple categorised attachments per leave request (medical certificate,
 * manager letter, doctor certificate, travel ticket, supporting document,
 * voice note), alongside the existing single leave_requests.attachment_path
 * (kept as-is for backward compatibility — it mirrors the first attachment).
 *
 * Also adds 'more_info_requested' to the leave request status vocabulary
 * for the "Request More Information" approval action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // medical_certificate|manager_letter|doctor_certificate|travel_ticket|supporting_document|voice_note
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index(['leave_request_id', 'type']);
        });

        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending','pending_hr','approved','rejected','cancelled','withdrawn','more_info_requested') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE leave_requests SET status = 'pending' WHERE status = 'more_info_requested'");
        DB::statement("ALTER TABLE leave_requests MODIFY status ENUM('pending','pending_hr','approved','rejected','cancelled','withdrawn') NOT NULL DEFAULT 'pending'");
        Schema::dropIfExists('leave_attachments');
    }
};
