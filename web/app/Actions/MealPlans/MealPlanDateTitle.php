<?php

namespace App\Actions\MealPlans;

use Carbon\CarbonInterface;

class MealPlanDateTitle
{
    public static function format(CarbonInterface $startsOn, CarbonInterface $endsOn): string
    {
        return $startsOn->format('j M').' – '.$endsOn->format('j M Y');
    }
}
