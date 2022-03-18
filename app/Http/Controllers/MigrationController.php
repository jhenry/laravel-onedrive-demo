<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use App\TokenStore\TokenCache;
use App\Services\OneDriveService;

class MigrationController extends Controller
{
    //protected OneDriveService $oneDriveService;

/*     public function __construct(OneDriveService $oneDriveService)
    {
        $this->oneDriveService = $oneDriveService;
    } */

    public function migration()
    {
        $viewData = $this->loadViewData();

        $graph = $this->getGraph();

        //$filePath = Storage::path('rainbow.MOV');
        $files = Storage::files('videos');
        $viewData['files'] = $files;

        //return response()->json($files);
        return view('migration', $viewData);
    }

    public function migrateSingleFile(Request $request, OneDriveService $oneDriveService)
    {
        // Validate required fields
        $request->validate(['filePath' => 'required|string']);
        $viewData = $this->loadViewData();

        //$this->oneDriveService = $oneDriveService;
        //$graph = $this->getGraph();
        $file = Storage::path($request->filePath);
        $fileName = basename($file);
        $result = $oneDriveService->uploadLargeItem('root', $fileName, $file);
        //$response = $graph->createRequest("PUT", "/me/drive/root/children/" . $fileName . "/content")->upload($file);

        $viewData['result'] = $result;

        return view('migration', $viewData);
    }
    public function migrateAllFiles(Request $request){

        $viewData = $this->loadViewData();
        return view('migration', $viewData);
    }

    private function getGraph(): Graph
    {
        // Get the access token from the cache
        $tokenCache = new TokenCache();
        $accessToken = $tokenCache->getAccessToken();

        // Create a Graph client
        $graph = new Graph();
        $graph->setAccessToken($accessToken);
        return $graph;
    }
}
