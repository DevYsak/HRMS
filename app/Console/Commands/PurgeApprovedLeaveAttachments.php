<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Data-retention for leave evidence: medical certificates and similar files
 * on leave requests approved more than N days ago.
 *
 * D10 (8 Oct 2026): permanent deletion of leave / medical evidence is a human
 * decision. By default — and on the schedule — this only REPORTS what is
 * eligible. A person deletes with --delete, after reviewing the list.
 */
class PurgeApprovedLeaveAttachments extends Command
{
    protected $signature = 'leave:purge-attachments
        {--days=30 : Eligible this many days after approval}
        {--delete : Permanently delete the eligible files (otherwise report only)}';

    protected $description = 'Report (or, with --delete, permanently remove) leave attachments approved more than N days ago';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $disk = Storage::disk('public');
        $files = 0;
        $delete = (bool) $this->option('delete');

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
            ->chunkById(200, function ($requests) use ($disk, &$files, $delete) {
                foreach ($requests as $request) {
                    if (! $delete) {
                        $files += ($request->attachment_path ? 1 : 0) + $request->attachments->count()
                            + $request->messages->whereNotNull('attachment_path')->count();

                        continue;
                    }

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

        $this->info($delete
            ? "Purged {$files} leave attachment file(s) approved more than {$days} days ago."
            : "{$files} leave attachment file(s) approved more than {$days} days ago are eligible for removal. Nothing was deleted — a person runs this with --delete after review.");

        return self::SUCCESS;
    }
}
