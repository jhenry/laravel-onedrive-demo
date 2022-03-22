<?php

namespace App\Services;

use GuzzleHttp\Client;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\DriveItem;
use Microsoft\Graph\Http\GraphResponse;
use Microsoft\Graph\Model\UploadSession;
use App\TokenStore\TokenCache;

class OneDriveService extends DriveItem
{
    protected $graph;
    protected $rootFolder;
    /** @var int $chunkSize */
    private $chunkSize;
    /** @var int $largeFileUploadProgress */
    private $largeFileUploadProgress;
    /** @var int $currentLargeFileRetryInterval */
    private $currentLargeFileRetryInterval;
    // If we get a server error, retry for a maximum of 10 times in the intervals below (in seconds)
    private $retryIntervals = [0, 1, 1, 2, 3, 5, 8, 13, 21, 34];

    public function __construct(Graph $graph)
    {
        $this->graph = $this->getGraph();
        $this->rootFolder = 'me';
        $this->largeFileUploadProgress = 0;
        $this->currentLargeFileRetryInterval = 0;
    }

    /**
     * Upload a file that's larger than 4MB.
     */
    public function uploadLargeItem(string $folderId, string $filename, string $filesrc): ?\Microsoft\Graph\Model\DriveItem
    {
        // Optimal chunk size is 5-10MiB and should be a multiple of 320 KiB
        $chunkSizeBytes = 10485760; // is 10 MiB
        $fileSize = filesize($filesrc);
        $options = [
            'item' => ['@microsoft.graph.conflictBehavior' => 'rename']
        ];

        // Get the URL that we can post our file chunks to.
        $createItemUrl =
            '/' . $this->rootFolder . '/drive/items/' . $folderId . ':/' . $filename . ':/createUploadSession';
        $session = $this->graph->createRequest("POST", $createItemUrl)
            ->setReturnType(\Microsoft\Graph\Model\UploadSession::class)
            ->attachBody($options)
            ->execute();
        $uploadUrl = $session->getUploadUrl();

        // Upload the various chunks.
        // $status will be false until the process is complete.
        $status = false;
        $handle = fopen($filesrc, "rb");
        while (!$status && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $status = $this->nextChunk($uploadUrl, $fileSize, $chunk);
        }

        // The final value of $status will be the data from the API for the object
        // that has been uploaded.
        $result = false;
        if ($status !== false) {
            /** @var Model\DriveItem */
            $result = $status;
        }

        fclose($handle);

        if (!$result) {
            return null;
        }

        return $result;
    }

    /**
     * Send the next part of the file to upload.
     * @param [$chunk] the next set of bytes to send. If false will used $data passed
     * at construct time.
     *
     * Got inspiration from:
     * https://github.com/googleapis/google-api-php-client/blob/master/src/Google/Http/MediaFileUpload.php#L113-L141
     */
    private function nextChunk(string $uploadUrl, int $fileSize, $chunk = false)
    {
        if (false == $chunk) {
            $chunk = substr(null, $this->largeFileUploadProgress, $this->chunkSize);
        }
        $lastBytePos = $this->largeFileUploadProgress + strlen($chunk) - 1;
        $headers = array(
            'Content-Range' => "bytes $this->largeFileUploadProgress-$lastBytePos/$fileSize",
            'Content-Length' => strlen($chunk),
        );

        /**
         * We shouldn't send the Authorization header here (as a tempauth token is included in the $uploadUrl),
         * so we can use a plain GuzzleHttp client.
         */
        $client = new Client();
        $response = $client->request(
            "PUT",
            $uploadUrl,
            [
                'headers' => $headers,
                'body' => \GuzzleHttp\Psr7\Utils::streamFor($chunk),
                'timeout' => 90
            ]
        );

        // A 404 code indicates that the upload session no longer exists, thus requiring us to start all over.
        if ($response->getStatusCode() === 404) {
            throw new \Exception('Upload URL has expired, please create new upload session');
        }

        // Retry if we get a server error, for a maximum of 10 times with time intervals.
        if (in_array($response->getStatusCode(), [500, 502, 503, 504])) {
            if ($this->currentLargeFileRetryInterval > 9) {
                throw new \Exception('Upload failed after 10 attempts.');
            }
            // Wait for the amount of seconds defined in the retryIntervals.
            sleep($this->retryIntervals[$this->currentLargeFileRetryInterval]);
            $this->currentLargeFileRetryInterval++;
            $this->nextChunk($uploadUrl, $fileSize, $chunk);
        }

        /**
         * If we have uploaded the last chunk, we should receive a 200 or 201 Created response code,
         * including a DriveItem. We use the Graph function getResponseAsObject to get the DriveItem object.
         */
        if (($fileSize - 1) == $lastBytePos) {
            /**
             * If a conflict occurs after the file is uploaded (for example,
             * an item with the same name was created during the upload session),
             * an error is returned when the last byte range is uploaded.
             */
            if ($response->getStatusCode() === 409) {
                throw new \Exception(
                    'File already exists. A file with the same name might have been created during the upload session.'
                );
            }

            if (in_array($response->getStatusCode(), [200, 201])) {
                $response = new GraphResponse(
                    $this->graph->createRequest('', ''),
                    $response->getBody(),
                    $response->getStatusCode(),
                    $response->getHeaders()
                );

                $item = $response->getResponseAsObject(\Microsoft\Graph\Model\DriveItem::class);
                return $item;
            }

            throw new \Exception(
                'Unknown error occured while uploading last part of file. HTTP response code is '
                . $response->getStatusCode()
            );
        }

        /**
         * If we didn't receive a 202 Accepted response from the Graph API, something has gone wrong.
         */
        if ($response->getStatusCode() !== 202) {
            throw new \Exception(
                'Unknown error occured while trying to upload file chunk. HTTP status code is '
                . $response->getStatusCode()
            );
        }

        /**
         * If we received a 202 Accepted response, it will include a nextExpectedRanges key, which will tell
         * us the next range we'll upload.
         */
        $body = json_decode($response->getBody()->getContents(), true);
        $nextExpectedRanges = $body['nextExpectedRanges']; // e.g. ["12345-55232","77829-99375"]
        $nextRange = $nextExpectedRanges[0]; // e.g. "12345-55232"
        $nextRangeExploded = explode('-', $nextRange); // e.g. ["12345", "55232"]

        $this->largeFileUploadProgress = $nextRangeExploded[0];

        // Upload not complete yet, return false.
        return false;
    }

    /**
    * Gets the webUrl
    * A URL that opens the item in the browser on the OneDrive website.
    *
    * @return string|null The webUrl
    */
    public function getWebUrl()
    {
        if (array_key_exists("webUrl", $this->_propDict)) {
            return $this->_propDict["webUrl"];
        } else {
            return null;
        }
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
