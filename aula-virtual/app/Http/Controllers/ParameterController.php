<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ParameterService;
use Illuminate\Http\Request;

class ParameterController extends Controller
{
    public function __construct(
        private ParameterService $service
    ) {
    }

    public function porMaestro(int $id)
    {
        $result = $this->service->listarPorMaestro($id);

        if (!$result->ok()) {
            return response()->json([], 500);
        }

        return response()->json($result->data()['items']);
    }
}