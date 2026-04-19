<?php

namespace Alareqi\SmartUpload\Http\Controllers;

use Alareqi\SmartUpload\Support\FileUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TemporaryUploadController extends Controller
{
    protected FileUploader $uploader;

    public function __construct(FileUploader $uploader)
    {
        $this->uploader = $uploader;
    }

    public function init(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => 'required|string',
        ]);

        $result = $this->uploader->init($validated);

        return response()->json($result);
    }

    public function cancel(string $uuid): JsonResponse
    {
        $success = $this->uploader->cancel($uuid);

        return response()->json(['success' => $success]);
    }
}
