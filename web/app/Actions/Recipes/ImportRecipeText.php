<?php

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ImportRecipeText
{
    public function __construct(private readonly CreateRecipe $createRecipe) {}

    public function handle(Team $team, User $user, string $sourceText, ?string $sourceUrl = null): Recipe
    {
        $lines = collect(preg_split('/\R/', trim($sourceText)) ?: [])->map(fn (string $line) => trim($line));
        $title = $lines->first(fn (string $line) => $line !== '');

        if ($title === null) {
            throw ValidationException::withMessages(['source_text' => 'Paste a recipe title, ingredients, and steps.']);
        }

        $section = 'ingredients';
        $ingredients = [];
        $steps = [];

        foreach ($lines->skipUntil(fn (string $line) => $line === $title)->skip(1) as $line) {
            if ($line === '') {
                continue;
            }

            $heading = mb_strtolower(trim($line, "# :\t"));

            if (in_array($heading, ['ingredients', 'ingredient'], true)) {
                $section = 'ingredients';

                continue;
            }

            if (in_array($heading, ['steps', 'method', 'instructions', 'directions'], true)) {
                $section = 'steps';

                continue;
            }

            $content = trim((string) preg_replace('/^(?:[-*•]|\d+[.)])\s*/u', '', $line));

            if ($section === 'ingredients') {
                $ingredients[] = ['name' => $content];
            } else {
                $steps[] = ['instruction' => $content];
            }
        }

        if ($ingredients === [] || $steps === []) {
            throw ValidationException::withMessages(['source_text' => 'Use Ingredients and Steps headings so Chef can import the recipe safely.']);
        }

        return $this->createRecipe->handle($team, $user, $title, null, 2, null, null, $ingredients, $steps, sourceUrl: $sourceUrl);
    }
}
