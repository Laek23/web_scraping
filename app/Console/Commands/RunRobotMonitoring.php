<?php

namespace App\Console\Commands;

use App\Models\Robot;
use App\Services\RobotRunner;
use Illuminate\Console\Command;

class RunRobotMonitoring extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'maxun:run-monitors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run robots whose monitoring interval has elapsed.';

    /**
     * Execute the console command.
     */
    public function handle(RobotRunner $runner): int
    {
        Robot::query()
            ->where('monitoring', true)
            ->with('latestExecution')
            ->chunkById(50, function ($robots) use ($runner) {
                foreach ($robots as $robot) {
                    $lastRun = $robot->latestExecution?->completed_at;
                    if ($lastRun && $lastRun->gt(now()->subMinutes($robot->monitoring_interval))) {
                        continue;
                    }

                    $execution = $runner->run($robot);
                    $message = "Robot {$robot->id}: {$execution->status}";
                    if ($execution->status === 'completed') {
                        $this->components->info($message);
                    } else {
                        $this->components->error($message);
                    }
                }
            });

        return self::SUCCESS;
    }
}
