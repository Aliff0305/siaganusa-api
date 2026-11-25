<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class DisasterController extends Controller
{
    // 1. Gempa Terbaru (BMKG autogempa)
    public function latestEarthquake()
    {
        $gempa = Cache::remember('bmkg_autogempa', 120, function () {
            $started = now()->utc();
            try {
                $res = Http::timeout(10)->get('https://data.bmkg.go.id/DataMKG/TEWS/autogempa.json');
                if (!$res->successful()) {
                    $this->logSync('bmkg_autogempa', 'failed', 0, 0, 'HTTP ' . $res->status(), $started);
                    return null;
                }

                $item = $res->json()['Infogempa']['gempa'] ?? null;
                if ($item) {
                    $this->upsertEarthquake($item, 'autogempa');
                    $this->logSync('bmkg_autogempa', 'success', 1, 1, null, $started);
                }
                return $item;
            } catch (Throwable $e) {
                $this->logSync('bmkg_autogempa', 'failed', 0, 0, $e->getMessage(), $started);
                return null;
            }
        });

        if (!$gempa) {
            $fallback = DB::table('earthquakes')->orderByDesc('occurred_at')->first();
            return response()->json(['status' => (bool) $fallback, 'source' => 'db_fallback', 'data' => $fallback]);
        }

        return response()->json(['status' => true, 'source' => 'bmkg', 'data' => $gempa]);
    }

    // Daftar Gempa M 5.0+ Terkini (BMKG gempaterkini)
    public function earthquakes()
    {
        $list = Cache::remember('bmkg_gempaterkini', 300, function () {
            $started = now()->utc();
            try {
                $res = Http::timeout(10)->get('https://data.bmkg.go.id/DataMKG/TEWS/gempaterkini.json');
                if (!$res->successful()) {
                    $this->logSync('bmkg_gempaterkini', 'failed', 0, 0, 'HTTP ' . $res->status(), $started);
                    return [];
                }

                $items = $res->json()['Infogempa']['gempa'] ?? [];
                foreach ($items as $item) {
                    $this->upsertEarthquake($item, 'gempaterkini');
                }
                $this->logSync('bmkg_gempaterkini', 'success', count($items), count($items), null, $started);
                return $items;
            } catch (Throwable $e) {
                $this->logSync('bmkg_gempaterkini', 'failed', 0, 0, $e->getMessage(), $started);
                return [];
            }
        });

        if (empty($list)) {
            $list = DB::table('earthquakes')->orderByDesc('occurred_at')->limit(15)->get();
        }

        return response()->json(['status' => true, 'data' => $list]);
    }

    // 2. Titik Panas / Karhutla (SiPongi KLHK)
    public function hotspots()
    {
        $data = Cache::remember('sipongi_hotspots', 600, function () {
            $started = now()->utc();
            try {
                $res = Http::timeout(15)
                    ->withHeaders(['Accept' => 'application/json'])
                    ->get('https://sipongi.gakkum.kehutanan.go.id/api/opsroom/indoHotspot');

                if (!$res->successful()) {
                    $this->logSync('sipongi', 'failed', 0, 0, 'HTTP ' . $res->status(), $started);
                    return null;
                }

                $json = $res->json();
                $this->logSync('sipongi', 'success', 1, 0, null, $started);
                return $json;
            } catch (Throwable $e) {
                $this->logSync('sipongi', 'failed', 0, 0, $e->getMessage(), $started);
                return null;
            }
        });

        if (!$data) {
            $fallback = DB::table('hotspots')->orderByDesc('detected_at')->limit(50)->get();
            return response()->json(['status' => true, 'source' => 'db_fallback', 'data' => $fallback]);
        }

        return response()->json(['status' => true, 'source' => 'sipongi', 'data' => $data]);
    }

    // 3. Peringatan Dini Tsunami (InaTEWS BMKG)
    public function tsunami()
    {
        $data = Cache::remember('inatews_tsunami', 120, function () {
            $started = now()->utc();
            try {
                $res = Http::timeout(10)->get('https://inatews.bmkg.go.id/ws/lastevent.json');
                if (!$res->successful()) {
                    $this->logSync('inatews', 'failed', 0, 0, 'HTTP ' . $res->status(), $started);
                    return null;
                }

                $json = $res->json();
                $this->logSync('inatews', 'success', 1, 0, null, $started);
                return $json;
            } catch (Throwable $e) {
                $this->logSync('inatews', 'failed', 0, 0, $e->getMessage(), $started);
                return null;
            }
        });

        if (!$data) {
            $fallback = DB::table('tsunami_alerts')->orderByDesc('issued_at')->first();
            return response()->json(['status' => true, 'source' => 'db_fallback', 'data' => $fallback]);
        }

        return response()->json(['status' => true, 'source' => 'inatews', 'data' => $data]);
    }

    private function upsertEarthquake(array $item, string $feed): void
    {
        $coords = explode(',', $item['Coordinates'] ?? '0,0');
        $lat = (float) ($coords[0] ?? 0);
        $lon = (float) ($coords[1] ?? 0);
        $depth = (float) preg_replace('/[^0-9.]/', '', $item['Kedalaman'] ?? '0');
        $potensi = $item['Potensi'] ?? '';
        $isTsunami = str_contains(strtolower($potensi), 'berpotensi tsunami') ? 1 : 0;
        $externalId = $item['DateTime'] ?? now()->utc()->toIso8601String();
        $occurredAt = Carbon::parse($externalId)->utc()->format('Y-m-d H:i:s');

        DB::table('earthquakes')->updateOrInsert(
            ['external_id' => $externalId],
            [
                'occurred_at'          => $occurredAt,
                'latitude'             => $lat,
                'longitude'            => $lon,
                'magnitude'            => (float) ($item['Magnitude'] ?? 0),
                'depth_km'             => $depth,
                'region'               => $item['Wilayah'] ?? '',
                'potential'            => $potensi,
                'is_tsunami_potential' => $isTsunami,
                'felt'                 => $item['Dirasakan'] ?? null,
                'shakemap'             => $item['Shakemap'] ?? null,
                'source_feed'          => $feed,
                'raw'                  => json_encode($item),
                'updated_at'           => now(),
                'created_at'           => now(),
            ]
        );
    }

    private function logSync(string $source, string $status, int $fetched, int $saved, ?string $message, $started): void
    {
        DB::table('sync_logs')->insert([
            'source'        => $source,
            'status'        => $status,
            'items_fetched' => $fetched,
            'items_saved'   => $saved,
            'message'       => $message,
            'started_at'    => $started->format('Y-m-d H:i:s'),
            'finished_at'   => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }
}