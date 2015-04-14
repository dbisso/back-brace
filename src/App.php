<?php

namespace DBisso\BackBrace;

use League\Flysystem\Filesystem;
use Aws\S3\S3Client;
use League\Flysystem\MountManager;
use League\Flysystem\AwsS3v2\AwsS3Adapter;
use League\Flysystem\Adapter\Local as LocalAdapter;
use League\Flysystem\Cached\CachedAdapter;
use League\Flysystem\Cached\Storage\Memory as CacheStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Basic backup of files and database.
 *
 * @author Dan Bissonnet <dan@danisadesigner.com>
 */
class App
{
    /**
     * Is the selected file new on the remote system.
     */
    const IS_NEW_FILE = 2;

    /**
     * Should the file be updated.
     */
    const IS_UPDATE = 4;

    /**
     * Cache of the remote files indexed by their path.
     *
     * @var array
     */
    private $remoteFilesByPath;

    /**
     * Compound filesystem.
     *
     * @var MountManager
     */
    private $filesystem;

    /**
     * Path to the config directory.
     *
     * @var string
     */
    private $configPath = 'config';

    /**
     * Hold the config data.
     *
     * @var array
     */
    private $config = [];

    /**
     * Don't carry out any actual transfers.
     *
     * @var bool
     */
    private $dryRun = false;

    /**
     * Logs should be printed.
     *
     * @var bool
     */
    private $log = true;

    /**
     * Default file count limit.
     *
     * @var int
     */
    private $fileCountLimit;

    /**
     * File tallies.
     *
     * @var [type]
     */
    private $counts = [];

    private $backupDB = false;

    private $backupFiles = false;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->parseConfig();
        $this->parseOpts();
        $this->buildFilesystem();
    }

    /**
     * Simple logging.
     *
     * @param string $message The log message
     */
    private function log($message)
    {
        if ($this->log) {
            echo $message."\n";
        }
    }

    /**
     * Trigger the file backup.
     */
    public function backup()
    {
        if ($this->dryRun) {
            $this->log('*** DRY RUN ***');
        }

        if ($this->backupFiles) {
            $this->update();
            $this->delete();
            $this->summary();
        }

        if ($this->backupDB) {
            $this->dumpDatabase();
        }
    }

    /**
     * Parse the command line options.
     */
    private function parseOpts()
    {
        $short = 'n';

        $long = [
            'dry-run',
            'db',
            'files',
            'limit::',
        ];

        $options = getopt($short, $long);

        if (isset($options['n']) || isset($options['dry-run'])) {
            $this->dryRun = true;
        }

        if (isset($options['limit'])) {
            $this->fileCountLimit = $options['limit'];
        }

        if (isset($options['db'])) {
            $this->backupDB = true;
        }

        if (isset($options['files'])) {
            $this->backupFiles = true;
        }
    }

    /**
     * Parse the config files.
     */
    private function parseConfig()
    {
        $configs = [
            'remote',
            'local',
            'db',
        ];

        if (function_exists('getenv')) {
            $home = getenv('HOME');
            if (!empty($home) && file_exists($home.DIRECTORY_SEPARATOR.'.backbrace')) {
                $this->configPath = $home.DIRECTORY_SEPARATOR.'.backbrace';
            }
        }

        foreach ($configs as $configName) {
            if (false !== ($config = @file_get_contents($this->configPath."/$configName.yml", true))) {
                $this->config[$configName] = Yaml::parse($config);
            } else {
                throw new \Exception("Configuration file {$configName}.yml could not be found", 1);
            }
        }
    }

    /**
     * Update the remote files if needed.
     */
    private function update()
    {
        $files = $this->filesystem->listContents('local://', true);

        if ($this->fileCountLimit) {
            $files = array_slice($files, 0, $this->fileCountLimit);
        }

        foreach ($files as $key => $file) {
            if ($this->isExcluded($file)) {
                echo "Excluding file: {$file['path']}\n";
                continue;
            }

            if ('dir' === $file['type']) {
                continue;
            }

            if (false !== ($update = $this->isUpdate($file))) {
                $verb = $update === self::IS_NEW_FILE ? 'Uploading new file:' : 'Updating';
                $this->log("$verb {$file['path']}");

                if ($update === self::IS_UPDATE) {
                    $this->maybeDelete('remote://' . $file[path]);
                }

                $this->maybeCopy('local://'.$file['path'], 'remote://'.$file['path']);
            } else {
                $this->log("File exists and is up to date: {$file['path']}");
            }
        }
    }

    /**
     * Purges old files from the remote backup.
     */
    private function delete()
    {
        $this->log("\nRemoving old files");
        $this->log('-------------------------------------------');

        foreach ($this->filesystem->listContents('remote://', true) as $key => $file) {
            if (! $this->localHas($file)) {
                $this->log("Deleting file from remote: {$file['path']}");
                $this->maybeDelete('remote://'.$file['path']);
            }
        }
    }

    /**
     * Copy a file unless this is a dry run.
     *
     * @param string $local  Local file path
     * @param string $remote Remove file path
     */
    private function maybeCopy($local, $remote)
    {
        if (! isset($this->counts['sent'])) {
            $this->counts['sent'] = 0;
        }

        $this->counts['sent']++;

        if (! $this->dryRun) {
            $this->filesystem->copy($local, $remote);
        }
    }

    /**
     * Delete a file unless this is a dry run.
     *
     * @param string $file Filepath to delete
     */
    private function maybeDelete($file)
    {
        if (! isset($this->counts['deleted'])) {
            $this->counts['deleted'] = 0;
        }

        $this->counts['deleted']++;

        if (! $this->dryRun && $this->filesystem->has($file)) {
            $this->filesystem->delete($file);
        }
    }

    /**
     * Write a stream to a destination unless this is a dry run.
     *
     * @param string $path  File path to write to
     * @param stream $steam The stream to write
     */
    private function maybeWriteStream($path, $stream)
    {
        if (! $this->dryRun) {
            $this->filesystem->writeStream($path, $stream);
        }
    }

    /**
     * Is a file is in the local filesystem.
     *
     * @param array $file File array
     *
     * @return bool
     */
    private function localHas(array $file)
    {
        return $this->filesystem->has('local://'.$file['path']);
    }

    /**
     * Get the remote files and cache them in a property.
     *
     * @return array[] Array of remote files
     */
    private function getRemoteFilesByPath()
    {
        foreach ($this->filesystem->listContents('remote://', true) as $key => $_file) {
            $this->remoteFilesByPath[$_file['path']] = $_file;
        }

        return $this->remoteFilesByPath;
    }

    /**
     * Is a file in the remote filesystem.
     *
     * @param array $file File array
     *
     * @return bool
     */
    private function remoteHas(array $file)
    {
        $remoteFiles = $this->getRemoteFilesByPath();

        return isset($remoteFiles[$file['path']]);
    }

    /**
     * Is the local file newer than the remote one.
     *
     * @param string $path File path to compare
     *
     * @return bool
     */
    private function isLocalNewer($path)
    {
        $remoteFiles = $this->getRemoteFilesByPath();

        return $this->filesystem->getTimestamp('local://'.$path) > $remoteFiles[$path]['timestamp'];
    }

    /**
     * Should the file be excluded from the backup.
     *
     * @param array $file File array
     *
     * @return bool
     */
    private function isExcluded(array $file)
    {
        $excludes = [
            '.DS_Store',
        ];

        if (isset($this->config['local']['local']['exclude'])) {
            $excludes = array_unique( array_merge($excludes, $this->config['local']['local']['exclude']) );
        }

        foreach ($excludes as $exclude) {
            if (strpos($file['path'], $exclude) !== false) {
                if (! isset($this->counts['excluded'])) {
                    $this->counts['excluded'] = 0;
                }

                $this->counts['excluded']++;

                return true;
            }
        }

        return false;
    }

    /**
     * Should the file be updated.
     *
     * @param array $file File array
     *
     * @return bool
     */
    private function isUpdate(array $file)
    {
        $update = false;

        // The file does not exist
        if (! $this->remoteHas($file)) {
            $update = self::IS_NEW_FILE;
        } elseif ($this->isLocalNewer($file['path'])) {
            $update = self::IS_UPDATE;
        }

        return $update;
    }

    /**
     * Constructs the MountManager compound filesystem.
     */
    private function buildFilesystem()
    {
        if (! isset($this->config['remote']) || ! isset($this->config['remote']['s3'])) {
            throw new Exception('AWS configuration is missing');
        }

        $s3Client = S3Client::factory([
            'key'    => $this->config['remote']['s3']['key'],
            'secret' => $this->config['remote']['s3']['secret'],
            'region' => $this->config['remote']['s3']['region'],
        ]);

        $s3Adapter       = new AwsS3Adapter($s3Client, $this->config['remote']['s3']['bucket'], $this->config['remote']['s3']['prefix']);
        $remoteAdapterDB = new AwsS3Adapter($s3Client, $this->config['remote']['s3']['bucket'], $this->config['remote']['s3']['prefixDB']);

        $cacheStore    = new CacheStore();
        $remoteAdapter = new CachedAdapter($s3Adapter, $cacheStore);

        if (! isset($this->config['local'])) {
            throw new Exception('Local configuration is missing');
        }

        $localAdapter     = new LocalAdapter($this->config['local']['local']['path']);
        $localFilesystem  = new Filesystem($localAdapter);
        $remoteFilesystem = new Filesystem($remoteAdapter);

        $this->filesystem = new MountManager([
            'local' => $localFilesystem,
            'remote' => $remoteFilesystem,
            'remoteDB' => new Filesystem($remoteAdapterDB),
        ]);
    }

    /**
     * Print a summary of files transferred.
     */
    private function summary()
    {
        $this->log("\nSummary");
        $this->log('-------------------------------------------');
        $this->log("Excluded {$this->counts['excluded']} files");
        $this->log("Sent {$this->counts['sent']} files");
        $this->log("Deleted {$this->counts['deleted']} files");
    }

    /**
     * Dump the database and transfer it.
     */
    private function dumpDatabase()
    {
        if (! isset($this->config['db']) || ! is_array($this->config['db'])) {
            throw new \Exception('Database configuration is missing');
        }

        $this->log("\nDumping database...");
        $this->log('-------------------------------------------');

        $path = $this->config['db']['local']['database'].'_'.date('Y-m-d').'.sql';

        if ($this->filesystem->has('remoteDB://'.$path.($this->shouldZip() ? '.zip' : ''))) {
            $this->log("File $path already exists. Skipping DB backup");
        } else {
            $dumper = new DatabaseDumper($this->config['db']['local']);
            $stream = $dumper->dump();

            if ($this->shouldZip()) {
                $stream = $this->zipStream($stream, $path);
                $path .= ($this->shouldZip() ? '.zip' : '');
            }

            $this->log("Sending {$path}");
            $this->maybeWriteStream('remoteDB://'.$path, $stream);
        }
    }

    private function zipStream($stream, $path)
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('First argument should be a resource. '.gettype($stream).' supplied');
        }

        if (! is_string($path)) {
            throw new \InvalidArgumentException('File path must be a string');
        }

        $filename    = stream_get_meta_data($stream)['uri'];
        $zipFilename = tempnam(sys_get_temp_dir(), 'backbrace');

        $zip = new \ZipArchive();
        $zip->open($zipFilename);
        $zip->addFile($filename, $path);
        $zip->close();

        return fopen($zipFilename, 'r+b');
    }

    private function shouldZip()
    {
        if (class_exists("\ZipArchive")) {
            return true;
        }
    }
}
