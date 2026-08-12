<?php

namespace App\Console\Commands;

use App\Services\AttendanceService;
use Illuminate\Console\Command;

class SyncAttendance extends Command
{
    protected $signature = 'attendance:sync-ended';
    protected $description = 'Conciliates ended course sessions with Zoom attendance reports.';

    public function handle(AttendanceService $service): int
    {
        $result = $service->syncDueSessions();
        $this->info("Processed: {$result['processed']}; failed: {$result['failed']}");
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
