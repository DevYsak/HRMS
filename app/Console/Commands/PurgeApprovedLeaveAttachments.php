<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Data-retention: 30 days after a leave request is approved, delete its
 * uploaded documents (the request's own attachment plus any conversation
 * attachments) from disk and clear the stored paths. Medical certificates
 * and similar sensitive files shouldn't linger indefinitely.
 */
class PurgeApprovedLeaveAttachments extends Command
{
    protected $signature = 'leave:purge-attachments {--days=30 : Delete attachments this many days after approval}';

    protected $description = 'Delete leave attachments 30 days after the leave was approved';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $disk = Storage::disk('public');
        $files = 0;

        LeaveRequest::query()
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->where('approved_at', '<=', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('attachment_path')
                    ->orWhereHas('attachments')
                    ->orWhereHas('messages', fn ($m) => $m->whereNotNull('attachment_path'));
            })
            ->with(['attachments', 'messages'])
            ->chunkById(200, function ($requests) use ($disk, &$files) {
                foreach ($requests as $request) {
                    if ($request->attachment_path && $disk->exists($request->attachment_path)) {
                        $disk->delete($request->attachment_path);
                        $files++;
                    }
                    foreach ($request->attachments as $att) {
                        if ($att->path && $disk->exists($att->path)) {
                            $disk->delete($att->path);
                            $files++;
                        }
                    }
                    foreach ($request->messages as $msg) {
                        if ($msg->attachment_path && $disk->exists($msg->attachment_path)) {
                            $disk->delete($msg->attachment_path);
                            $files++;
                        }
                    }

                    $request->attachments()->delete();
                    $request->messages()->whereNotNull('attachment_path')
                        ->update(['attachment_path' => null, 'attachment_name' => null]);
                    $request->update(['attachment_path' => null]);
                }
            });

        $this->info("Purged {$files} leave attachment file(s) approved more than {$days} days ago.");

        return self::SUCCESS;
    }
}
