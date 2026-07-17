<?php

namespace App\Actions\Privacy;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ExportTeamData
{
    /** @var array<int, string> */
    public const TEAM_TABLES = [
        'automation_approvals',
        'automation_reconciliations',
        'automation_runs',
        'automation_steps',
        'browser_connections',
        'budgets',
        'consent_records',
        'constraints',
        'conversation_feedback',
        'conversations',
        'ingredients',
        'meal_feedback',
        'meal_outcomes',
        'meal_plan_milestones',
        'meal_plan_revisions',
        'meal_plans',
        'meal_proposals',
        'meal_slots',
        'messages',
        'order_lines',
        'orders',
        'operational_events',
        'people',
        'planned_meal_recipe_preparations',
        'planned_meals',
        'preference_candidates',
        'preferences',
        'product_matches',
        'product_preferences',
        'recipe_versions',
        'recipes',
        'shopping_list_item_sources',
        'shopping_list_items',
        'shopping_list_meal_resolutions',
        'shopping_list_revisions',
        'shopping_lists',
        'team_invitations',
        'team_memberships',
        'user_person_links',
        'voice_sessions',
        'voice_tool_calls',
    ];

    /** @return array<string, mixed> */
    public function handle(Team $team, User $user): array
    {
        if (! $user->can('view', $team)) {
            throw new AuthorizationException('You cannot export this family.');
        }

        $tables = [];

        foreach (self::TEAM_TABLES as $table) {
            $tables[$table] = DB::table($table)
                ->where('team_id', $team->id)
                ->orderBy('id')
                ->get()
                ->map(fn (object $record): array => $this->sanitize($table, (array) $record))
                ->all();
        }

        $recipeVersionIds = DB::table('recipe_versions')->where('team_id', $team->id)->pluck('id');
        $mealSlotIds = DB::table('meal_slots')->where('team_id', $team->id)->pluck('id');

        foreach (['recipe_equipment', 'recipe_ingredients', 'recipe_preparation_notices', 'recipe_steps'] as $table) {
            $tables[$table] = DB::table($table)
                ->whereIn('recipe_version_id', $recipeVersionIds)
                ->orderBy('id')
                ->get()
                ->map(fn (object $record): array => (array) $record)
                ->all();
        }

        $tables['meal_slot_participants'] = DB::table('meal_slot_participants')
            ->whereIn('meal_slot_id', $mealSlotIds)
            ->orderBy('id')
            ->get()
            ->map(fn (object $record): array => (array) $record)
            ->all();

        $members = DB::table('team_memberships')
            ->join('users', 'users.id', '=', 'team_memberships.user_id')
            ->where('team_memberships.team_id', $team->id)
            ->orderBy('team_memberships.id')
            ->get([
                'team_memberships.user_id',
                'team_memberships.role',
                'users.name',
                'users.email',
                'users.created_at',
            ])
            ->map(fn (object $member): array => (array) $member)
            ->all();

        return [
            'format' => 'chef-team-export.v1',
            'exported_at' => now()->toIso8601String(),
            'exported_by_user_id' => $user->id,
            'team' => $team->only(['id', 'name', 'timezone', 'created_at', 'updated_at']),
            'members' => $members,
            'data' => $tables,
        ];
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function sanitize(string $table, array $record): array
    {
        if ($table === 'browser_connections') {
            unset($record['pairing_code_hash'], $record['token_hash']);
        }

        if ($table === 'automation_steps') {
            unset($record['screenshot_path']);
        }

        if ($table === 'team_invitations') {
            unset($record['token']);
        }

        return $record;
    }
}
