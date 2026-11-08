<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories (semua peran)
     * Semua kategori, urut nama, beserta jumlah bahan aktif.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::withCount('items')->orderBy('name')->get();

        return CategoryResource::collection($categories);
    }

    /**
     * POST /api/v1/categories (Admin) → 201
     */
    public function store(CategoryRequest $request): CategoryResource
    {
        try {
            $category = Category::create(['name' => $request->validated('name')]);
        } catch (UniqueConstraintViolationException) {
            $this->throwNameAlreadyUsed();
        }

        // Kategori baru pasti belum punya bahan, tapi tetap dihitung supaya
        // bentuk responsnya sama dengan daftar.
        $category->loadCount('items');

        return new CategoryResource($category);
    }

    /**
     * PUT /api/v1/categories/{category} (Admin) → 200
     */
    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        try {
            $category->update(['name' => $request->validated('name')]);
        } catch (UniqueConstraintViolationException) {
            $this->throwNameAlreadyUsed();
        }

        $category->loadCount('items');

        return new CategoryResource($category);
    }

    /**
     * DELETE /api/v1/categories/{category} (Admin) → 204, atau 409 jika masih dipakai.
     */
    public function destroy(Category $category): Response
    {
        // withTrashed(): bahan yang sudah di-soft delete tetap dihitung, karena barisnya
        // masih ada dan riwayat transaksinya harus tersimpan. Database juga akan menolak
        // (FK restrict), tapi dicek di sini supaya pengguna mendapat 409 yang jelas, bukan 500.
        if ($category->items()->withTrashed()->exists()) {
            abort(409, 'Kategori tidak bisa dihapus karena masih dipakai oleh bahan, termasuk bahan yang sudah dihapus. Riwayat transaksi bahan tersebut harus tetap tersimpan.');
        }

        $category->delete();

        return response()->noContent();
    }

    /**
     * Validasi (UniqueCategoryName) biasanya sudah menolak nama kembar. Tapi kalau dua
     * Admin menyimpan nama yang sama di saat yang persis bersamaan, keduanya bisa lolos
     * validasi, lalu index unik lower(name) di database yang menolak salah satunya.
     * Penolakan itu diubah menjadi 422 yang sama seperti validasi biasa, bukan error 500.
     */
    private function throwNameAlreadyUsed(): never
    {
        throw ValidationException::withMessages([
            'name' => 'Nama kategori sudah digunakan.',
        ]);
    }
}
