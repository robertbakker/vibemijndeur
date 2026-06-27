<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Roadwork;
use App\Models\Slug;
use Illuminate\Http\RedirectResponse;

class ProjectController extends Controller
{
    public function redirectFromId(int $id): RedirectResponse
    {
        $current = Slug::query()
            ->where('sluggable_type', (new Roadwork)->getMorphClass())
            ->where('sluggable_id', $id)
            ->where('is_current', true)
            ->firstOrFail();

        return redirect('/'.$current->slug, 301);
    }
}
