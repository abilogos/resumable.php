<?php

namespace Dilab;

use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Dilab\Network\Request;
use Dilab\Network\Response;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use OndrejVrto\FilenameSanitize\FilenameSanitize;

class Resumable
{
    public $debug = false;

    public $tempFolder = 'tmp';

    public $uploadFolder = 'test/files/uploads';

    public $chmodConfig = 0664;

    // for testing
    public $deleteTmpFolder = true;

    protected $request;

    protected $response;

    protected $instanceId;

    protected $params;

    protected $chunkFile;

    protected $log;

    protected $filename;

    protected $filepath;

    protected $extension;

    protected $originalFilename;

    protected $isUploadComplete = false;

    protected $resumableOption = [
        'identifier' => 'identifier',
        'filename' => 'filename',
        'chunkNumber' => 'chunkNumber',
        'chunkSize' => 'chunkSize',
        'totalSize' => 'totalSize',
        'totalChunks' => 'totalChunks'
    ];

    const WITHOUT_EXTENSION = true;

    public function __construct(Request $request, Response $response, string|null $instanceId = null)
    {
        $this->request = $request;
        $this->response = $response;
        $this->instanceId = $instanceId;

        $this->log = new Logger('debug');
        $this->log->pushHandler(new StreamHandler('debug.log', Logger::DEBUG));

        $this->preProcess();
    }

    public function setResumableOption(array $resumableOption)
    {
        $this->resumableOption = array_merge($this->resumableOption, $resumableOption);
    }

    // sets original filename and extension, blah blah
    public function preProcess()
    {
        if (!empty($this->resumableParams())) {
            if (!empty($this->request->file())) {
                $this->extension = $this->findExtension($this->resumableParam('filename'));
                $this->originalFilename = $this->resumableParam('filename');
            }
        }
    }

    public function process()
    {
        if (!empty($this->resumableParams())) {
            if (!empty($this->request->file())) {
                return $this->handleChunk();
            } else {
                return $this->handleTestChunk();
            }
        }
    }

    /**
     * Get isUploadComplete
     *
     * @return boolean
     */
    public function isUploadComplete()
    {
        return $this->isUploadComplete;
    }

    /**
     * Set final filename.
     *
     * @param string Final filename
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getOriginalFilename($withoutExtension = false)
    {
        if ($withoutExtension === static::WITHOUT_EXTENSION) {
            return $this->removeExtension($this->originalFilename);
        }

        return $this->originalFilename;
    }

    /**
     * Get final filapath.
     *
     * @return string Final filename
     */
    public function getFilepath()
    {
        return $this->filepath;
    }

    /**
     * Get final extension.
     *
     * @return string Final extension name
     */
    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * Creates a safe name
     *
     * @param string $name Original name
     * @return string A safer name
     */
    private function createSafeName(string $name): string
    {
        return FilenameSanitize::of($name)->get();
    }

    public function handleTestChunk()
    {
        $identifier = $this->resumableParam($this->resumableOption['identifier']);
        $filename = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = (int) $this->resumableParam($this->resumableOption['chunkNumber']);
        $chunkSize = (int) $this->resumableParam($this->resumableOption['chunkSize']);
        $totalChunks = (int) $this->resumableParam($this->resumableOption['totalChunks']);

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            return $this->response->header(204);
        } else {
            if ($this->isFileUploadComplete($filename, $identifier, $totalChunks)) {
                $this->isUploadComplete = true;
                $this->createFileAndDeleteTmp($identifier, $filename);
                return $this->response->header(201);
            }
            return $this->response->header(200);
        }

    }

    public function handleChunk()
    {
        $file = $this->request->file();
        $identifier = $this->resumableParam($this->resumableOption['identifier']);
        $filename = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = (int) $this->resumableParam($this->resumableOption['chunkNumber']);
        $chunkSize = (int) $this->resumableParam($this->resumableOption['chunkSize']);
        $totalChunks = (int) $this->resumableParam($this->resumableOption['totalChunks']);

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            $chunkFile = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR . $this->tmpChunkFilename($filename, $chunkNumber);
            $this->moveUploadedFile($file['tmp_name'], $chunkFile);
            chmod($chunkFile, $this->chmodConfig);
        }

        if ($this->isFileUploadComplete($filename, $identifier, $totalChunks)) {
            $this->isUploadComplete = true;
            $this->createFileAndDeleteTmp($identifier, $filename);
            return $this->response->header(201);
        }

        return $this->response->header(200);
    }

    /**
     * Create the final file from chunks
     */
    private function createFileAndDeleteTmp($identifier, $filename)
    {
        $tmpFolder = new Folder($this->tmpChunkDir($identifier));
        $chunkFiles = $tmpFolder->read(true, true, true)[1];

        // if the user has set a custom filename
        if (null !== $this->filename) {
            $finalFilename = $this->createSafeName($this->filename);
        } else {
            $finalFilename = $this->createSafeName($filename);
        }

        // replace filename reference by the final file
        $this->filepath = $this->uploadFolder . DIRECTORY_SEPARATOR;
        if (!empty($this->instanceId)) {
            $this->filepath .= $this->instanceId . DIRECTORY_SEPARATOR;
        }
        $this->filepath .= $finalFilename;

        // Rename the file with random string at the end ,if that already exists
        if(file_exists($this->filepath)){
            $pathInfo = pathinfo($this->filepath);
            $this->filepath = $pathInfo['dirname']
                .DIRECTORY_SEPARATOR.$pathInfo['filename'].'_'
                . $this->generateRandomString()
                .".".$pathInfo['extension'];
        }

        $this->extension = $this->findExtension($this->filepath);

        if ($this->createFileFromChunks($chunkFiles, $this->filepath) && $this->deleteTmpFolder) {
            $tmpFolder->delete();
            $this->isUploadComplete = true;
        }
    }

    public function getStoredFileName(): string
    {
        return pathinfo($this->filepath, PATHINFO_BASENAME);
    }

    public function generateRandomString($length = 5): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function resumableParam($shortName)
    {
        $resumableParams = $this->resumableParams();
        if (!isset($resumableParams['resumable' . ucfirst($shortName)])) {
            return null;
        }
        return $resumableParams['resumable' . ucfirst($shortName)];
    }

    public function resumableParams()
    {
        if ($this->request->is('get')) {
            return $this->request->data('get');
        }
        if ($this->request->is('post')) {
            return $this->request->data('post');
        }
    }

    public function isFileUploadComplete($filename, $identifier, $totalChunks)
    {
        for ($i = 1; $i <= $totalChunks; $i++) {
            if (!$this->isChunkUploaded($identifier, $filename, $i)) {
                return false;
            }
        }
        return true;
    }

    public function isChunkUploaded($identifier, $filename, $chunkNumber)
    {
        $file = new File($this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR . $this->tmpChunkFilename($filename, $chunkNumber));
        return $file->exists();
    }

    public function tmpChunkDir($identifier)
    {
        $tmpChunkDir = $this->tempFolder. DIRECTORY_SEPARATOR;
        if (!empty($this->instanceId)){
            $tmpChunkDir .= $this->instanceId . DIRECTORY_SEPARATOR;
        }
        $tmpChunkDir .= $this->createSafeName($identifier);
        $this->ensureDirExists($tmpChunkDir);
        return $tmpChunkDir;
    }

    /**
     * make directory if it doesn't exists (Immune against the race condition)
     *
     *
     * since the resumable is usually used with simultaneously uploads,
     * this sometimes resulted in directory creation between the *is_dir* check
     * and *mkdir* then following race condition.
     * in this setup it will shut down the mkdir error
     * then try to check if directory is created after that
     *
     * @param string $path the directoryPath to ensure
     * @return void
     * @throws \Exception
     */
    private function ensureDirExists($path)
    {
        umask(0);
        if ( is_dir($path) || @mkdir($path, 0775, true) || is_dir($path)) {
            return;
        }
        throw new \Exception("could not mkdir $path");
    }

    public function tmpChunkFilename($filename, $chunkNumber)
    {
        return $this->createSafeName($filename) . '.' . str_pad($chunkNumber, 4, 0, STR_PAD_LEFT);
    }

    public function getExclusiveFileHandle($name)
    {
        // if the file exists, fopen() will raise a warning
        $previous_error_level = error_reporting();
        error_reporting(E_ERROR);
        $handle = fopen($name, 'x');
        error_reporting($previous_error_level);
        return $handle;
    }

    public function createFileFromChunks($chunkFiles, $destFilePath)
    {
        $this->log('Beginning of create files from chunks');

        natsort($chunkFiles);

        if (!empty($this->instanceId)) {
            $this->ensureDirExists(dirname($destFilePath));
        }

        $handle = $this->getExclusiveFileHandle($destFilePath);
        if (!$handle) {
            return false;
        }

        $destFile = new File($destFilePath);
        $destFile->handle = $handle;
        foreach ($chunkFiles as $chunkFile) {
            $file = new File($chunkFile);
            $destFile->append($file->read());

            $this->log('Append ', ['chunk file' => $chunkFile]);
        }
        chmod($destFilePath, $this->chmodConfig);

        $this->log('End of create files from chunks');
        return $destFile->exists();
    }

    public function moveUploadedFile($file, $destFile)
    {
        //workaround cakephp error regarding: TMP not defined
        define("TMP",sys_get_temp_dir());

        $file = new File($file);
        if ($file->exists()) {
            return $file->copy($destFile);
        }
        return false;
    }

    public function setRequest($request)
    {
        $this->request = $request;
    }

    public function setResponse($response)
    {
        $this->response = $response;
    }

    private function log($msg, $ctx = array())
    {
        if ($this->debug) {
            $this->log->addDebug($msg, $ctx);
        }
    }

    private function findExtension($filename)
    {
        $parts = explode('.', basename($filename));

        return end($parts);
    }

    private function removeExtension($filename)
    {
        $parts = explode('.', basename($filename));
        $ext = end($parts); // get extension

        // remove extension from filename if any
        return str_replace(sprintf('.%s', $ext), '', $filename);
    }
}

