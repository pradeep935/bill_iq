<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class GeneralController extends Controller
{
    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $destination = $request->boolean('temp') ? 'temp' : 'uploads';
        $file = $request->file('file');
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '', $file->getClientOriginalName());
        $filename = time() . '_' . $name;
        $path = $file->storeAs($destination, $filename, 'public');

        return Response::json([
            'success' => true,
            'path' => $path,
            'url' => asset('storage/' . $path),
        ]);
    }

    public function uploadPhoto(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|max:5120',
        ]);

        $file = $request->file('photo');
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = time() . '_' . $name . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('uploads', $filename, 'public');

        return Response::json([
            'success' => true,
            'path' => $path,
            'url' => asset('storage/' . $path),
        ]);
    }
}
