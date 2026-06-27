<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Roadworks\SuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typeahead endpoint: ranked area / authority suggestions for a term, each
 * linking to a pretty listing URL. Backed by {@see SuggestionService}.
 */
class SuggestController extends Controller
{
    public function __construct(private readonly SuggestionService $suggestions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $suggestions = $this->suggestions->suggest(
            $validated['q'] ?? null,
            (int) ($validated['limit'] ?? 10),
        );

        return response()->json(['suggestions' => $suggestions]);
    }
}
