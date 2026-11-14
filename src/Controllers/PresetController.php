<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CityRepository;

final class PresetController extends Controller
{
    public function cities(Request $r): Response
    {
        $q = $r->q('q');
        $rows = mb_strlen($q) >= 2 ? (new CityRepository($this->db()))->search($q, min(25, max(1, $r->int('limit', 12)))) : [];
        return Response::json(['data' => $rows])->header('Cache-Control', 'private, max-age=300');
    }

    public function businessTypes(Request $r): Response
    {
        $types = json_decode((string) file_get_contents(base_path('database/presets/business-types.json')), true) ?: [];
        $q = mb_strtolower($r->q('q'));
        if ($q !== '') {
            $types = array_values(array_filter($types, static fn ($t) => str_contains(mb_strtolower($t), $q)));
        }
        return Response::json(['data' => $types]);
    }
}
