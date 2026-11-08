<?php

namespace App\Http\Controllers;

use App\Http\Requests\ItemIndexRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

    /**
     * POST /api/v1/items (Admin) → 201
     * JSON atau multipart (kalau ada foto). Stok bahan baru selalu 0.
     */
    public function store(ItemRequest $request): ItemResource
    {
        $item = new Item($this->itemData($request));
        // Diisi eksplisit: default 0 di database tidak otomatis terbaca oleh model ini,
        // sehingga tanpa baris ini respons akan berisi "stock": null.
        $item->stock = 0;
        $newPhotoPath = $this->storePhoto($request);
        $item->photo_path = $newPhotoPath;

        try {
            $item->save();
        } catch (UniqueConstraintViolationException) {
            // Foto sudah terlanjur disimpan; hapus supaya tidak jadi file sampah.
            $this->deletePhoto($newPhotoPath);
            $this->throwSkuTaken();
        }

        $item->load('category');

        return new ItemResource($item);
    }

    /**
     * PUT /api/v1/items/{item} (Admin) → 200
     * Dari aplikasi dikirim sebagai POST + field _method=PUT (lihat file penjelasan).
     */
    public function update(ItemRequest $request, Item $item): ItemResource
    {
        $oldPhotoPath = $item->photo_path;
        $newPhotoPath = $this->storePhoto($request);

        $item->fill($this->itemData($request));
        if ($newPhotoPath !== null) {
            $item->photo_path = $newPhotoPath;
        }

        try {
            $item->save();
        } catch (UniqueConstraintViolationException) {
            $this->deletePhoto($newPhotoPath);
            $this->throwSkuTaken();
        }

        // Foto lama baru dihapus setelah data baru tersimpan.
        if ($newPhotoPath !== null) {
            $this->deletePhoto($oldPhotoPath);
        }

        $item->load('category');

        return new ItemResource($item);
    }

    /**
     * DELETE /api/v1/items/{item} (Admin) → 204
     * Soft delete: baris dan fotonya tetap ada, supaya riwayat transaksi tetap lengkap.
     */
    public function destroy(Item $item): Response
    {
        $item->delete();

        return response()->noContent();
    }

    /**
     * Kolom yang boleh diisi dari form. `stock` tidak pernah termasuk.
     *
     * @return array<string, mixed>
     */
    private function itemData(ItemRequest $request): array
    {
        return $request->safe()->only(['sku', 'name', 'category_id', 'unit', 'price', 'min_stock', 'description']);
    }

    /**
     * Simpan foto kalau ada di request. Hasil: path seperti "photos/items/<acak>.jpg", atau null.
     */
    private function storePhoto(ItemRequest $request): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        return $request->file('photo')->store('photos/items', 'public');
    }

    private function deletePhoto(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Validasi biasanya sudah menolak SKU kembar. Kalau dua Admin menyimpan SKU yang
     * sama di saat bersamaan, index unik items_sku_unique di database yang menolak;
     * diubah menjadi 422 dengan pesan yang sama, bukan 500.
     */
    private function throwSkuTaken(): never
    {
        throw ValidationException::withMessages([
            'sku' => ItemRequest::SKU_TAKEN_MESSAGE,
        ]);
    }
}
