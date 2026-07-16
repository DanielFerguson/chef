<?php

use App\Enums\ShoppingListItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->string('category')->default(ShoppingListItemCategory::Other->value)->after('source_kind');
            $table->index(['shopping_list_id', 'category', 'position']);
        });

        DB::table('shopping_list_items')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    DB::table('shopping_list_items')
                        ->where('id', $item->id)
                        ->update(['category' => ShoppingListItemCategory::classify($item->name)->value]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropIndex(['shopping_list_id', 'category', 'position']);
            $table->dropColumn('category');
        });
    }
};
