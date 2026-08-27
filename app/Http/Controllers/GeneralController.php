<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

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
        $target = public_path($destination);

        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $file->move($target, $filename);
        $path = $destination . '/' . $filename;

        return Response::json([
            'success' => true,
            'path' => $path,
            'url' => '/' . ltrim($path, '/'),
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
        $target = public_path('uploads');

        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $file->move($target, $filename);
        $path = 'uploads/' . $filename;

        return Response::json([
            'success' => true,
            'path' => $path,
            'url' => '/' . ltrim($path, '/'),
        ]);
    }

    public function publicStorageFile(string $path)
    {
        $path = ltrim($path, '/');

        abort_unless(Storage::disk('public')->exists($path), 404);

        return Response::file(Storage::disk('public')->path($path));
    }
}
