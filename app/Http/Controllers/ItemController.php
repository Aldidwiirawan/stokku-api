<?php

namespace App\Http\Controllers;

use App\Http\Requests\ItemIndexRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    /**
     * GET /api/v1/items?search=&category_id=&low_stock=1&page=
     * Daftar bahan aktif, 20 per halaman.
     */
    public function index(ItemIndexRequest $request): AnonymousResourceCollection
    {
        $items = Item::query()
            // Eager load: kategori semua bahan di halaman ini diambil dalam 1 query.
            ->with('category')
            ->search($request->validated('search'))
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', $request->validated('category_id'));
            })
            ->when($request->boolean('low_stock'), function ($query) {
                $query->lowStock();
            })
            // id sebagai urutan kedua supaya urutan halaman tetap sama walaupun ada nama kembar.
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            // Link halaman berikutnya ikut membawa search/category_id/low_stock.
            ->withQueryString();

        return ItemResource::collection($items);
    }

    /**
     * GET /api/v1/items/{item}
     * Detail bahan + 10 transaksi terakhir beserta nama pencatatnya.
     * Bahan yang sudah dihapus (soft delete) tidak ditemukan → 404.
     */
    public function show(Item $item): ItemResource
    {
        $item->load([
            'category',
            'stockMovements' => function ($query) {
                $query->with('user')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(10);
            },
        ]);

        return new ItemResource($item);
    }
}
