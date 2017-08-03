<?php

// Support using this file as its own project or as composer requirement.
foreach (array(__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../autoload.php') as $file) {
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
$fixedBranch = strtolower(preg_replace('/[\W\s\/]+/', '-', getenv('PLATFORM_BRANCH')));
$baseDirectory = 'platform/' . getenv('PLATFORM_APPLICATION_NAME') . '/' . $fixedBranch;
$projectName = getenv('BACKUP_PROJECT_NAME') ?: getenv('PLATFORM_APPLICATION_NAME');
$branchAndProject = $projectName . ' > ' . $fixedBranch;

$logger = new Logger('backup_logger');
$logger->pushHandler(new LogglyHandler(LOGGLY_TOKEN . '/tag/backup_logger', Logger::INFO));
$logger->pushHandler(new StreamHandler('php://stdout'));

$psh = new Platformsh\ConfigReader\Config();

if ($psh->isAvailable()) {
  try {
    $sql_filename = date('Y-m-d_H:i:s') . '.gz';
    $backup_path = $home_dir . "/backups/";

    $database = $psh->relationships['database'][0];
    putenv("MYSQL_PWD={$database['password']}");
    exec("mysqldump --opt -h {$database['host']} -u {$database['username']} {$database['path']} | gzip > $backup_path$sql_filename");

    $s3 = new Aws\S3\S3Client([
      'version' => 'latest',
      'region' => getenv('AWS_REGION') ?: 'us-east-1',
      'credentials' => [
        'key' => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
      ],
    ]);

    $s3->putObject([
      'Bucket' => S3_BUCKET,
      'Key' => "$baseDirectory/database/$sql_filename",
      'Body' => fopen($backup_path . $sql_filename, 'r'),
    ]);

    // Remove local backup files that are older than 5 days
    $fileSystemIterator = new FilesystemIterator($backup_path);
    $now = time();
    foreach ($fileSystemIterator as $file) {
      if ($now - $file->getCTime() >= 60 * 60 * 24 * 5) {
        unlink($backup_path . $file->getFilename());
      }
    }

    $logger->info("Successfully backed up database $sql_filename for $branchAndProject");
  }
  catch (Exception $e) {
    $logger->error("Database backup error for $branchAndProject: " . $e->getMessage());
  }

}
