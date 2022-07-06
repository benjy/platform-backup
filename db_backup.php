<?php

// Support using this file as its own project or as composer requirement.
foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../autoload.php'] as $file) {
  if (file_exists($file)) {
    require_once $file;
    break;
  }
}

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Handler\LogglyHandler;

define('S3_BUCKET', getenv('S3_BUCKET'));
define('LOGGLY_TOKEN', getenv('LOGGLY_TOKEN'));

$home_dir = getenv('PLATFORM_DIR');
$keep_n_days = getenv('BACKUP_KEEP_N_DAYS') ?: 5;
$fixedBranch = strtolower(preg_replace('/[\W\s\/]+/', '-', getenv('PLATFORM_BRANCH')));
$projectName = getenv('BACKUP_PROJECT_NAME') ?: getenv('PLATFORM_APPLICATION_NAME');
$baseDirectory = "platform/$projectName/$fixedBranch";
$branchAndProject = $projectName . ' > ' . $fixedBranch;

$logger = new Logger('backup_logger');
$logger->pushHandler(new LogglyHandler(LOGGLY_TOKEN . '/tag/backup_logger', Logger::INFO));
$logger->pushHandler(new StreamHandler('php://stdout'));

$psh = new Platformsh\ConfigReader\Config();

if ($psh->isAvailable()) {
  $s3 = new Aws\S3\S3Client([
    'version' => 'latest',
    'region' => getenv('AWS_REGION') ?: 'us-east-1',
    'credentials' => [
      'key' => getenv('AWS_ACCESS_KEY_ID'),
      'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
    ],
  ]);

  try {
    $sql_filename = date('Y-m-d_H:i:s') . '.gz';
    $backup_path = $home_dir . "/backups/";

    $database = $psh->relationships['database'][0];
    putenv("MYSQL_PWD={$database['password']}");
    exec("mysqldump --opt -h {$database['host']} -u {$database['username']} {$database['path']} | gzip > $backup_path$sql_filename");

    $s3->putObject([
      'Bucket' => S3_BUCKET,
      'Key' => "$baseDirectory/database/$sql_filename",
      'Body' => fopen($backup_path . $sql_filename, 'r'),
    ]);

    // Remove local backup files that are older than $keep_n_days.
    $fileSystemIterator = new FilesystemIterator($backup_path);
    $now = time();
    foreach ($fileSystemIterator as $file) {
      if ($now - $file->getCTime() >= 60 * 60 * 24 * $keep_n_days) {
        unlink($backup_path . $file->getFilename());
      }
    }

    $logger->info("Successfully backed up database $sql_filename for $branchAndProject");
  }
  catch (Exception $e) {
    $logger->error("Database backup error for $branchAndProject: " . $e->getMessage());
  }

  if ($privateFilesDir = getenv('PRIVATE_FILES_DIRECTORY')) {
    try {
      $s3->uploadDirectory($privateFilesDir, S3_BUCKET . "/$baseDirectory/files-private");

      $logger->addInfo("Successfully backed up files $privateFilesDir for $branchAndProject");
    }
    catch (Exception $e) {
      $logger->addError("Files backup error for $branchAndProject ($privateFilesDir): " . $e->getMessage());
    }
  }

  if ($publicFilesDir = getenv('PUBLIC_FILES_DIRECTORY')) {
    try {
      $s3->uploadDirectory($publicFilesDir, S3_BUCKET . "/$baseDirectory/files-public");

      $logger->addInfo("Successfully backed up files $publicFilesDir for $branchAndProject");
    }
    catch (Exception $e) {
      $logger->addError("Files backup error for $branchAndProject ($publicFilesDir): " . $e->getMessage());
    }
  }

}
