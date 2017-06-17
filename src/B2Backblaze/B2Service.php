<?php

namespace B2Backblaze;

/**
 * B2Client.
 *
 * @author Kamil Zabdyr <kamilzabdyr@gmail.com>
 */
class B2Service
{
    protected $accountId;
    protected $client;
    protected $apiURL;
    protected $downloadURL;
    protected $token;
    protected $uploadURL;
    protected $downloadToken;
    protected $minimumPartSize;

    /**
     * @param String $account_id      The B2 account id for the account
     * @param String $application_key B2 application key for the account.
     * @param int    $timeout         Curl timeout.
     */
    public function __construct($account_id, $application_key, $timeout = 2000)
    {
        $this->accountId = $account_id;
        $this->client = new B2API($account_id, $application_key, $timeout);
        $this->apiURL = null;
        $this->downloadURL = null;
        $this->token = null;
        $this->minimumPartSize = 100 * (1000 * 1000);
    }

    /**
     * Authenticate with server.
     *
     * @return bool
     */
    public function authorize()
    {
        $response = $this->client->b2AuthorizeAccount();
        if ($response->isOk()) {
            $this->apiURL = $response->get('apiUrl');
            $this->token = $response->get('authorizationToken');
            $this->downloadURL = $response->get('downloadUrl');
            $this->minimumPartSize = $response->get('minimumPartSize');

            return true;
        }

        return false;
    }

    /**
     * Returns true if bucket exist.
     *
     * @param $bucketId
     *
     * @return bool
     */
    public function isBucketExist($bucketId)
    {
        $this->ensureAuthorized();
        $response = $this->client->b2ListBuckets($this->apiURL, $this->token);
        if ($response->isOk()) {
            $buckets = $response->get('buckets');
            if (!is_null($buckets)) {
                foreach ($buckets as $bucket) {
                    if ($bucketId == $bucket['bucketId']) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Returns the bucket information array.
     *
     * @param $bucketId
     *
     * @return bool
     */
    public function getBucketById($bucketId)
    {
        $this->ensureAuthorized();
        $response = $this->client->b2ListBuckets($this->apiURL, $this->token);
        if ($response->isOk()) {
            $buckets = $response->get('buckets');
            if (!is_null($buckets)) {
                foreach ($buckets as $bucket) {
                    if ($bucketId == $bucket['bucketId']) {
                        return $bucket;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Returns the file content and file metadata.
     *
     * @param String $bucketName
     * @param String $fileName
     * @param bool   $private
     * @param bool   $metadataOnly
     *
     * @return array|bool
     */
    public function get($bucketName, $fileName, $private = false, $metadataOnly = false)
    {
        $token = null;
        if ($private == true) {
            $this->ensureAuthorized();
            $token = $this->token;
        }
        $response = $this->client->b2DownloadFileByName($this->downloadURL, $bucketName, $fileName, $token, $metadataOnly);
        if ($response->isOk(false)) {
            return array('headers' => $response->getHeaders(), 'content' => $response->getRawContent());
        }

        return false;
    }

    /**
     * Get ZipArchive files
     * TODO: tests
     *
     * @param $bucketName
     * @param array $filesName
     * @param $zipFileName
     * @param bool $private
     * @return bool
     */
    public function getAllZip($bucketName, array $filesName, $zipFileName, $private = false){
        $zip = new \ZipArchive();
        if ($zip->open($zipFileName, \ZipArchive::CREATE) === true) {
            foreach ($filesName as $file) {
                $content = $this->get($bucketName,$file,$private,false);
                if($content !== false){
                    try{
                        $zip->addFromString($file, $content["content"]);
                    }catch (\Exception $e){
                        return false;
                    }
                }
            }
            $zip->close();
        }else{
            return false;
        }
        return true;
    }

    /**
     * Inserts file and returns array of file metadata.
     *
     * @param String $bucketId
     * @param mixed  $file
     * @param String $fileName
     *
     * @return array|bool|null
     *
     * @throws B2Exception
     */
    public function insert($bucketId, $file, $fileName)
    {
        $this->ensureAuthorized();
        if(!$this->downloadToken || !$this->uploadURL){
            $response = $this->client->b2GetUploadURL($this->apiURL, $this->token, $bucketId);
            if ($response->isOk()) {
                $this->downloadToken = $response->get('authorizationToken');
                $this->uploadURL     = $response->get('uploadUrl');
            }else{
                return false;
            }
        }
        $response2 = $this->client->b2UploadFile($file, $this->uploadURL, $this->downloadToken, $fileName);
        if ($response2->isOk()) {
            return $response2->getData();
        }else{
            // b2_get_upload_url returns an upload authorization token. This token lasts at most 24 hours.
            // However, it is only valid to upload data to one storage pod. If that pod is offline, full or overloaded,
            // a request to b2_upload_file again.
            $response = $this->client->b2GetUploadURL($this->apiURL, $this->token, $bucketId);
            if ($response->isOk()) {
                $this->downloadToken = $response->get('authorizationToken');
                $this->uploadURL     = $response->get('uploadUrl');
                $this->client->b2UploadFile($file, $this->uploadURL, $this->downloadToken, $fileName);
                if ($response2->isOk()) {
                    return $response2->getData();
                }
            }
        }

        return false;
    }

    /**
     * Inserts large file and returns array of file metadata.
     * Large files can range in size from 5MB to 10TB.
     * Each large file must consist of at least 2 parts, and all of the parts except the last one must be at least 5MB in size.
     * The last part must contain at least one byte.
     *
     * @param String $bucketId
     * @param mixed  $filePath
     * @param String $fileName
     *
     * @return array|bool|null
     *
     * @throws B2Exception
     */
    public function insertLarge($bucketId, $filePath, $fileName)
    {
        $this->ensureAuthorized();
        // start large file:
        $startResponse = $this->client->b2StartLargeFile($this->apiURL, $this->token, $bucketId, $fileName);
        $fileId = $startResponse->get("fileId");
        try{
            // get upload url for part file:
            $uploadUrlResponse = $this->client->b2GetUploadPartURL($this->apiURL, $this->token, $fileId);
            // upload large file:
            $responses = $this->client->b2UploadPart($uploadUrlResponse->get("uploadUrl"), $uploadUrlResponse->get("authorizationToken"), $filePath);
            $sha1s = [];
            foreach($responses as $response){
                $sha1s[] = $response->get("contentSha1");
            }
            // finish large file
            $response = $this->client->b2FinishLargeFile($this->apiURL, $this->token, $fileId, $sha1s);
            if($response->isOk()){
                return $response->getData();
            }
        }catch (B2Exception $exception){
            $this->client->b2CancelLargeFile($this->apiURL, $this->token, $fileId);
        }
        return false;
    }

    /**
     * Delete file version.
     *
     * @param String $bucketName
     * @param String $fileName
     * @param bool   $private
     *
     * @return bool
     */
    public function delete($bucketName, $fileName, $private = false)
    {
        $data = $this->get($bucketName, $fileName, $private, true);
        if ($data !== false && array_key_exists('x-bz-file-id', $data['headers'])) {
            $response = $this->client->b2DeleteFileVersion($this->apiURL, $this->token, $data['headers']['x-bz-file-id'], $fileName);

            return $response->isOk();
        }

        return false;
    }

    /**
     * Rename file.
     *
     * @param String $bucketName
     * @param String $bucketId       //For feature compatibility
     * @param String $fileName
     * @param String $targetBucketId
     * @param String $newFileName
     * @param bool   $private
     *
     * @return bool
     */
    public function rename($bucketName, $bucketId, $fileName, $targetBucketId, $newFileName, $private = false)
    {
        $data = $this->get($bucketName, $fileName, $private, false);
        if (is_array($data) && array_key_exists('x-bz-file-id', $data['headers'])) {
            $result = $this->insert($targetBucketId, $data['content'], $newFileName);
            if ($result === false) {
                return false;
            }
            $response = $this->client->b2DeleteFileVersion($this->apiURL, $this->token, $data['headers']['x-bz-file-id'], $fileName);

            return $response->isOk();
        }

        return false;
    }

    /**
     * Returns the list of files in bucket.
     *
     * @param String $bucketId
     *
     * @return array
     *
     * @throws B2Exception
     */
    public function all($bucketId)
    {
        $this->ensureAuthorized();
        $list = array();
        $nexFile = null;
        do {
            $response = $this->client->b2ListFileNames($this->apiURL, $this->token, $bucketId, $nexFile, 1000);
            if ($response->isOk()) {
                $files = $response->get('files');
                $nexFile = $response->get('nextFileName');
                if (!is_null($files)) {
                    array_merge($list, $files);
                }
                if (is_null($nexFile)) {
                    return $list;
                }
            }
        } while (true);

        return $list;
    }

    /**
     * Check if the filename exists.
     *
     *
     * @param String $bucketName
     * @param String $fileName
     *
     * @return bool
     */
    public function exists($bucketId, $fileName)
    {
        $this->ensureAuthorized();
        $response = $this->client->b2ListFileNames($this->apiURL, $this->token, $bucketId, $fileName, 1);
        if (!$response->isOk(false)) {
            return false;
        }

        return $response->get('files')[0]['fileName'] === $fileName;
    }

    private function isAuthorized()
    {
        return !is_null($this->token) && !is_null($this->apiURL);
    }

    private function ensureAuthorized()
    {
        if (!$this->isAuthorized()) {
            return $this->authorize();
        }

        return true;
    }
}
