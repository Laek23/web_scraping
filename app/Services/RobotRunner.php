<?php

namespace App\Services;

use App\Models\Execution;
use App\Models\Robot;
use Throwable;

class RobotRunner
{
    public function __construct(private readonly WebScraper $scraper) {}

    public function run(Robot $robot): Execution
    {
        $execution = $robot->executions()->create([
            'status' => 'running',
            'started_at' => now(),
            'pages_scraped' => 0,
        ]);

        try {
            $result = $this->scraper->scrape($robot);
            $execution->update([
                'status' => 'completed',
                'result' => $result,
                'pages_scraped' => $result['pageCount'],
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $execution->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
        }

        return $execution->load('robot');
    }
}
