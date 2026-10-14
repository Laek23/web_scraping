<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Robot;
use App\Services\RobotRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RobotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $robots = $request->user()->robots()
            ->with('latestExecution')
            ->latest()
            ->get()
            ->map(fn (Robot $robot) => $this->serializeRobot($robot));

        return response()->json($robots);
    }

    public function store(Request $request): JsonResponse
    {
        $robot = $request->user()->robots()->create($this->validatedData($request));
        $robot->load('latestExecution');

        return response()->json($this->serializeRobot($robot), 201);
    }

    public function show(Request $request, Robot $robot): JsonResponse
    {
        $robot = $request->user()->robots()->with('latestExecution')->findOrFail($robot->id);

        return response()->json($this->serializeRobot($robot));
    }

    public function update(Request $request, Robot $robot): JsonResponse
    {
        $robot = $request->user()->robots()->findOrFail($robot->id);
        $robot->update($this->validatedData($request, partial: true));
        $robot->load('latestExecution');

        return response()->json($this->serializeRobot($robot));
    }

    public function destroy(Request $request, Robot $robot): JsonResponse
    {
        $request->user()->robots()->findOrFail($robot->id)->delete();

        return response()->json(['message' => 'Robot eliminado correctamente.']);
    }

    public function run(Request $request, Robot $robot, RobotRunner $runner): JsonResponse
    {
        $robot = $request->user()->robots()->findOrFail($robot->id);
        $execution = $runner->run($robot);

        return response()->json([
            'execution' => $this->serializeExecution($execution),
        ]);
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'websiteUrl' => [
                $required,
                'string',
                'max:2048',
                'url:http,https',
            ],
            'mode' => [$required, Rule::in(['Scrape', 'Extract', 'Crawl', 'Search', 'Document'])],
            'outputFormats' => [$required, 'array', 'min:1', 'max:3'],
            'outputFormats.*' => ['required', 'string', Rule::in(['Markdown', 'HTML', 'JSON'])],
            'smartQueries' => [
                $partial ? 'sometimes' : 'nullable',
                Rule::requiredIf(fn () => ! $partial && $request->input('mode') === 'Search'),
                'nullable',
                'string',
                'max:5000',
            ],
            'monitoring' => ['sometimes', 'boolean'],
            'monitoringInterval' => ['sometimes', 'integer', 'min:5', 'max:10080'],
        ]);

        $data = [];
        foreach ([
            'name' => 'name',
            'websiteUrl' => 'website_url',
            'mode' => 'mode',
            'outputFormats' => 'output_formats',
            'smartQueries' => 'smart_queries',
            'monitoring' => 'monitoring',
            'monitoringInterval' => 'monitoring_interval',
        ] as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $data[$column] = $validated[$input];
            }
        }

        return $data;
    }

    private function serializeRobot(Robot $robot): array
    {
        return [
            'id' => (string) $robot->id,
            'name' => $robot->name,
            'websiteUrl' => $robot->website_url,
            'mode' => $robot->mode,
            'outputFormats' => $robot->output_formats,
            'smartQueries' => $robot->smart_queries ?? '',
            'monitoring' => $robot->monitoring,
            'monitoringInterval' => $robot->monitoring_interval,
            'createdAt' => $robot->created_at->toISOString(),
            'status' => $robot->latestExecution?->status === 'failed' ? 'Failed' : 'Ready',
            'lastExecution' => $robot->latestExecution
                ? $this->serializeExecution($robot->latestExecution)
                : null,
        ];
    }

    public function serializeExecution(Execution $execution): array
    {
        return [
            'id' => (string) $execution->id,
            'robotId' => (string) $execution->robot_id,
            'robotName' => $execution->robot->name,
            'status' => $execution->status,
            'result' => $execution->result,
            'errorMessage' => $execution->error_message,
            'pagesScraped' => $execution->pages_scraped,
            'startedAt' => $execution->started_at?->toISOString(),
            'completedAt' => $execution->completed_at?->toISOString(),
            'createdAt' => $execution->created_at->toISOString(),
        ];
    }
}
