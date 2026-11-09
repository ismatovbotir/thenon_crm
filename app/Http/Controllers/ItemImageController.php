<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemImageController extends Controller
{
    /**
     * Serve a product picture straight from storage, so it does not depend on the public/storage symlink.
     * The URL carries a version query (see Item::imageUrl), which makes a long browser cache safe.
     */
    public function __invoke(Item $item): StreamedResponse
    {
        $disk = Storage::disk('public');

        abort_unless($item->image_path && $disk->exists($item->image_path), 404);

        return $disk->response($item->image_path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
