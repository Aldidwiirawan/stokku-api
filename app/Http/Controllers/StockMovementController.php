<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Http\Requests\StockMovementIndexRequest;
use App\Http\Requests\StoreStockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockMovementController extends Controller
{
    /**
     * GET /api/v1/stock-movements (semua peran)
     * Admin melihat semua transaksi; Staf hanya transaksi yang ia catat sendiri.
     */
    public function index(StockMovementIndexRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $from = $request->fromInUtc();
        $to = $request->toInUtc();

        $movements = StockMovement::query()
            // Bahan & pencatat semua baris di halaman ini diambil sekaligus (tanpa N+1).
            ->with(['item', 'user'])
            ->when(! $user->isAdmin(), fn ($query) => $query->where('user_id', $user->id))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->validated('type')))
            ->when($request->filled('item_id'), fn ($query) => $query->where('item_id', $request->validated('item_id')))
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return StockMovementResource::collection($movements);
    }

    /**
     * POST /api/v1/stock-movements (semua peran; adjust khusus Admin) → 201
     */
    public function store(StoreStockMovementRequest $request, StockService $stockService): StockMovementResource
    {
        $reason = $request->validated('reason');

        $movement = $stockService->record(
            // (int): nilai dari form bisa berupa teks "5"; sudah divalidasi sebagai bilangan bulat.
            itemId: (int) $request->validated('item_id'),
            user: $request->user(),
            type: MovementType::from($request->validated('type')),
            quantity: (int) $request->validated('quantity'),
            reason: $reason !== null ? WasteReason::from($reason) : null,
            note: $request->validated('note'),
        );

        return new StockMovementResource($movement);
    }
}
