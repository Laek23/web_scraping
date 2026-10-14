<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('perPage', 25), 100));
        $executionPage = Execution::query()
            ->select(['executions.id', 'executions.created_at'])
            ->join('robots', 'robots.id', '=', 'executions.robot_id')
            ->where('robots.user_id', $request->user()->id)
            ->orderByDesc('executions.created_at')
            ->orderByDesc('executions.id')
            ->paginate($perPage);
        $executionIds = $executionPage->getCollection()->pluck('id');
        $executionRecords = Execution::query()
            ->with('robot')
            ->whereIn('id', $executionIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $executionIds
                ->map(fn ($id) => $executionRecords->get($id))
                ->filter()
                ->map(fn (Execution $execution) => app(RobotController::class)->serializeExecution($execution))
                ->values(),
            'currentPage' => $executionPage->currentPage(),
            'lastPage' => $executionPage->lastPage(),
            'total' => $executionPage->total(),
        ]);
    }

    public function show(Request $request, Execution $execution): JsonResponse
    {
        $execution = $request->user()->executions()
            ->with('robot')
            ->findOrFail($execution->id);

        return response()->json(
            app(RobotController::class)->serializeExecution($execution),
        );
    }
}
