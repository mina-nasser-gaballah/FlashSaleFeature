<?php

namespace App\Jobs;

use App\Models\Hold;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessExpiredHolds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $expiredHolds = Hold::where('status', 'active')->where('expires_at', '<=', now())->get();

        if ($expiredHolds->isEmpty()) {
            Log::debug('No expired holds to process');
            return;
        }

        $processed = 0;
        $releasedQuantity = 0;

        foreach ($expiredHolds as $hold) {
            try {
                DB::transaction(function () use ($hold, &$processed, &$releasedQuantity) {
                    $hold = Hold::lockForUpdate()->find($hold->id);

                    if (!$hold || $hold->status !== 'active') {
                        return;
                    }

                    if ($hold->expires_at->isFuture()) {
                        return;
                    }

                    $hold->update(['status' => 'expired']);

                    $hold->product->increment('stock', $hold->quantity);
                    $hold->product->decrement('reserved_quantity', $hold->quantity);

                    $processed++;
                    $releasedQuantity += $hold->quantity;

                    Log::info('Expired hold processed', [
                        'hold_id' => $hold->id,
                        'product_id' => $hold->product_id,
                        'quantity' => $hold->quantity,
                    ]);
                }, 3);
            } catch (\Exception $e) {
                Log::error('Failed to process expired hold', [
                    'hold_id' => $hold->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Expired holds processing completed', [
            'processed_count' => $processed,
            'released_quantity' => $releasedQuantity,
        ]);
    }
}

