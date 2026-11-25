<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ContentController extends Controller
{
    public function emergencyContacts()
    {
        $contacts = DB::table('emergency_contacts')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['status' => true, 'data' => $contacts]);
    }

    public function mitigationGuides()
    {
        $guides = DB::table('mitigation_guides')->orderBy('sort_order')->get();
        $steps = DB::table('mitigation_steps')->orderBy('sort_order')->get()->groupBy('guide_id');

        $result = $guides->map(function ($guide) use ($steps) {
            $guide->steps = ($steps[$guide->id] ?? collect())->map(function ($s) {
                $s->actions = json_decode($s->actions, true);
                return $s;
            })->values();
            return $guide;
        });

        return response()->json(['status' => true, 'data' => $result]);
    }
}