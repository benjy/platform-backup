Automated backup script that pulls the database, compresses, and syncs to an
S3 bucket. Designed for Platform.sh

## Install

You can either clone this repo and use as its own project or you can require it as a dependency into your existing project.

`composer require benjy/platform-backup`

## Setup

- composer install
- Create IAM user with write access to a S3 bucket.
- Add backups directory to .platform.app.yaml
```
mounts:
    "/backups": "shared:files/backups"
```

- Add environmental variables in Platform.sh. Be sure to add the "env:" prefix.
    - env:AWS_ACCESS_KEY_ID
    - env:AWS_SECRET_ACCESS_KEY
    - env:S3_BUCKET (The name of the bucket you created)
    - env:LOGGLY_TOKEN (Get from loggly > source setup > tokens)
    - env:AWS_REGION (Optional, defaults to us-east-1)
    - env:PUBLIC_FILES_DIRECTORY (optional: If added use, full path [e.g. /app/app/sites/default/files])
    - env:PRIVATE_FILES_DIRECTORY (optional: If added use, full path [e.g. /app/private])

- Add composer install to .platform.app.yaml
```
hooks:
    build: |
        composer install --working-dir=./jobs
```

  - Deploy and test using: php ./jobs/db_backup.php
  - Add cron task to .platform.app.yml

```
db_backup:
    spec: "0 0 * * *"
    cmd: "php ./jobs/db_backup.php"
```

Note, you might have to update the cmd to point to a different location depending on how you installed.

#### Credits

Adapted from https://bitbucket.org/snippets/kaypro4/gnB4E