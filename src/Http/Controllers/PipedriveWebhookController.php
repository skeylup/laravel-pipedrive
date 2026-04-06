<?php

namespace Skeylup\LaravelPipedrive\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Skeylup\LaravelPipedrive\Services\PipedriveMergeDetectionService;
use Skeylup\LaravelPipedrive\Services\PipedriveWebhookService;

class PipedriveWebhookController
{
    /**
     * Handle incoming Pipedrive webhook
     */
    public function handle(
        Request $request,
        PipedriveWebhookService $webhookService,
        PipedriveMergeDetectionService $mergeDetectionService
    ) {
        try {
            $meta = $request->input('meta', []);
            $version = $meta['version'] ?? '1.0';

            // Log webhook reception for debugging
            Log::info('Pipedrive webhook received', [
                'version' => $version,
                'event' => $request->input('event'), // v1.0 only
                'meta' => $meta,
                'has_data' => $request->has('data'), // v2.0
                'has_previous' => $request->has('previous'), // v2.0
                'retry' => $request->input('retry', 0),
                'attempt' => $meta['attempt'] ?? 1, // v2.0
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Track webhook for merge detection
            if (config('pipedrive.merge.enable_heuristic_detection', true)) {
                $mergeDetectionService->trackWebhookEvent($request->all());
            }

            // Process the webhook
            $result = $webhookService->processWebhook($request->all());

            // Get object ID based on version
            $objectId = $version === '2.0'
                ? $meta['entity_id'] ?? null
                : $meta['id'] ?? null;

            // Log successful processing
            Log::info('Pipedrive webhook processed successfully', [
                'version' => $version,
                'event' => $request->input('event'),
                'object_id' => $objectId,
                'result' => $result,
            ]);

            // Return 200 OK to acknowledge receipt
            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'processed' => $result['processed'],
                'action' => $result['action'],
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $meta = $request->input('meta', []);
            $version = $meta['version'] ?? '1.0';
            $objectId = $version === '2.0'
                ? $meta['entity_id'] ?? null
                : $meta['id'] ?? null;

            // Log error for debugging
            Log::error('Pipedrive webhook processing failed', [
                'version' => $version,
                'event' => $request->input('event'),
                'object_id' => $objectId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'retry' => $request->input('retry', 0),
                'attempt' => $meta['attempt'] ?? 1,
            ]);

            // Return 500 to trigger Pipedrive retry
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook processing failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Health check endpoint for webhook URL validation
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'Laravel Pipedrive Webhooks',
            'timestamp' => now()->toISOString(),
        ], Response::HTTP_OK);
    }
}
