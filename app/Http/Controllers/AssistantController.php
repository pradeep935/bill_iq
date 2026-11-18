<?php

namespace App\Http\Controllers;

use App\Services\AssistantService;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function chat(Request $request, AssistantService $assistant)
    {
        $data = $request->validate([
            'message' => 'required|string|max:1000',
            'history' => 'nullable|array|max:20',
            'history.*.role' => 'required_with:history|string',
            'history.*.content' => 'required_with:history|string|max:4000',
            'page' => 'nullable|string|max:120',
        ]);

        return response()->json($assistant->ask($data['message'], $data['history'] ?? [], ['page' => $data['page'] ?? null]));
    }
}
