<p align="center"><img src="https://cloud.githubusercontent.com/assets/2197005/19776979/f4abd1be-9c54-11e6-9842-212f26e765a5.png" alt="MySQL to Google BigQuery Logo" /></p>

<h1 align="center">MySQL to Google BigQuery Sync Tool</h1>

<p align="center">
  <a href="https://github.com/ivanrey/mysql-to-google-bigquery/actions/workflows/tests.yml"><img src="https://github.com/ivanrey/mysql-to-google-bigquery/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
</p>

Command line tool that copies MySQL tables into Google BigQuery. It reads rows
from MySQL, converts them to newline-delimited JSON and loads them with BigQuery
load jobs. It is meant to run unattended from a cron.

## Table of Contents

+ [Quick start](#quick-start)
+ [Requirements](#requirements)
+ [Installation](#installation)
+ [Configuration](#configuration)
  + [Variables](#variables)
  + [Where the configuration lives](#where-the-configuration-lives)
  + [Configuration from Google Cloud](#configuration-from-google-cloud)
+ [The `sync` command](#the-sync-command)
+ [Sync modes](#sync-modes)
  + [Incremental (`--order-column`)](#incremental---order-column)
  + [Full dump (`--un-buffer --delete-table`)](#full-dump---un-buffer---delete-table)
  + [Row count diff (no flags)](#row-count-diff-no-flags)
+ [Partitioning](#partitioning)
+ [Type mapping](#type-mapping)
+ [Running from cron](#running-from-cron)
+ [Troubleshooting](#troubleshooting)
+ [Development](#development)
+ [Credits](#credits)
+ [License](#license)

## Quick start

```bash
# 1. one directory per configuration, with its .env and its key
mkdir -p envs/client-a
$EDITOR envs/client-a/.env

# 2. create the destination table from the MySQL schema, without data
bin/console sync log_entries --env=client-a --create-table --no-data

# 3. first load, then keep it up to date every few minutes
bin/console sync log_entries --env=client-a --order-column id
```

For the first load of a **large** table, prefer the streaming full dump
(`--un-buffer --delete-table`) and switch to `--order-column` afterwards.

## Requirements

+ **PHP 8.1** or newer
+ PDO extension with the MySQL driver
+ A Google Cloud project with BigQuery enabled, and either a service account
  key file or Application Default Credentials on the host

## Installation

Clone the repository and install the dependencies:

```bash
git clone https://github.com/ivanrey/mysql-to-google-bigquery.git
cd mysql-to-google-bigquery
composer install
```

The entry point is `bin/console`. It can be called by absolute path from
anywhere — it does not need to be run from the project directory:

```bash
/opt/mysql-to-google-bigquery/bin/console sync log_entries --env=client-a
```

Installed as a dependency of another project, the binary lands in that
project's `vendor/bin/console` instead.

## Configuration

### Variables

Configuration is read from environment variables, usually written in a `.env`
file (parsed with [phpdotenv](https://github.com/vlucas/phpdotenv)).

**BigQuery**

| Variable | Required | Description |
|---|---|---|
| `BQ_DATASET` | yes | Destination dataset |
| `BQ_PROJECT_ID` | no | Google Cloud project. Without it, the one of the host credentials is used |
| `BQ_KEY_FILE` | no | Service account key: a path, or a `sm://` reference. Without it, the Application Default Credentials of the host are used |
| `BQ_LOCATION` | no | Dataset region (`US`, `EU`, `southamerica-east1`…). Default `US` — **set it for regional datasets**, or the load jobs fail |

**MySQL**

| Variable | Required | Description |
|---|---|---|
| `DB_HOST` | yes | MySQL host |
| `DB_USERNAME` | yes | User |
| `DB_PASSWORD` | yes | Password (a good candidate for `sm://`) |
| `DB_DATABASE_NAME` | yes | Default database, overridable per run with `--database-name` |

**Sync behaviour**

| Variable | Default | Description |
|---|---|---|
| `ORDER_COLUMN` | — | Default for `--order-column` (enables the incremental mode) |
| `IGNORE_COLUMNS` | — | Comma-separated columns never copied, e.g. `password,token` |
| `MAX_ROWS_PER_BATCH` | `600000` | Rows per batch/load job |
| `CACHE_DIR` | `<project>/cache` | Where the temporary JSON files are written. Needs free space for one batch |
| `CREATED_AT_LOOKBACK` | `-3 month` | How far back the incremental filters look, see [Incremental](#incremental---order-column) |
| `CREATED_AT_LOOKBACK_<TABLE>` | — | Per-table override of the above |
| `PARTITION_TYPE` | `MONTH` | Partitioning of the tables the tool creates: `DAY`, `MONTH`, `YEAR` or `NONE`. See [Partitioning](#partitioning) |
| `PARTITION_TYPE_<TABLE>` | — | Per-table override of the above |
| `CONFIG_DIR` | `<project>/envs` | Directory holding the environments. Read from the real environment or `--config-dir`, **not** from a `.env` |

A minimal `.env`:

```text
BQ_PROJECT_ID=my-gcp-project
BQ_DATASET=my_dataset
BQ_LOCATION=southamerica-east1
BQ_KEY_FILE=service-account-key.json

DB_HOST=mysql.internal
DB_USERNAME=reporting
DB_PASSWORD=s3cret
DB_DATABASE_NAME=my_database

IGNORE_COLUMNS=password,remember_token
```

`ENV_DIR` and `CONFIG_SOURCE` are **derived** variables the tool sets by
itself; do not define them.

### Where the configuration lives

The same installation can serve several configurations — one per client,
database or stage. Each one is a directory with its `.env` and its key, under a
config directory (`envs/` next to the project by default):

```text
envs/
├── client-a/
│   ├── .env
│   └── service-account-key.json
└── client-b/
    ├── .env
    └── service-account-key.json
```

Pick one by name, from any working directory:

```bash
bin/console sync log_entries -o id --env=client-a
```

| Option | Effect |
|---|---|
| `--env=<name>`, `-e` | Loads `<config dir>/<name>/.env` |
| `--env-file=<path\|URI>` | Loads a specific file, or a `sm://` / `pm://` reference. Mutually exclusive with `--env` |
| `--config-dir=<path>` | Directory holding the environments; relative values are resolved against the current directory. Also settable as `CONFIG_DIR` in the real environment |
| *(neither)* | Loads `<cwd>/.env`, the historical behaviour |

**Precedence.** Variables already present in the environment of the process win
over the loaded configuration, so a single value can be overridden for one run
without touching any file:

```bash
BQ_DATASET=staging bin/console sync log_entries -o id --env=client-a
```

**Relative paths.** `BQ_KEY_FILE` and `CACHE_DIR` are resolved against the
directory of the loaded `.env`, not against the current directory, so each
environment keeps its key next to its configuration and the cron does not need
to `cd` anywhere.

### Configuration from Google Cloud

Nothing sensitive has to live on disk. Authentication uses the **Application
Default Credentials** of the host (a GCE/GKE/Cloud Run service account), so the
identity that opens the secrets is the machine's, not a file.

Roles needed: `roles/secretmanager.secretAccessor` on the secrets, and
`roles/parametermanager.parameterAccessor` on the parameter if you use one.

**A single value from Secret Manager.** Any variable whose value is a `sm://`
reference is resolved at startup, whatever its source — a `.env`, a rendered
parameter, or the environment of the process:

```text
DB_PASSWORD=sm://projects/my-project/secrets/db-pass/versions/latest
DB_PASSWORD=sm://db-pass             # short form: BQ_PROJECT_ID, latest version
DB_PASSWORD=sm://db-pass/versions/3  # pinned version
```

```bash
DB_PASSWORD=sm://db-pass bin/console sync log_entries -o id --env=client-a
```

**The whole configuration.** `--env-file` also takes a reference instead of a
path:

```bash
# the payload of the secret, parsed as a .env
bin/console sync log_entries -o id --env-file=sm://client-a-env

# a rendered parameter version: its __REF__(//secretmanager.googleapis.com/…)
# come back already resolved, so the parameter holds the plain configuration
# and Secret Manager holds the sensitive values
bin/console sync log_entries -o id --env-file=pm://client-a
```

Two rules for a remote configuration:

+ The payload must be `.env` content (`NAME=value` lines), so a Parameter
  Manager parameter has to be created with the **`UNFORMATTED`** format. JSON
  and YAML parameters are rejected with a clear error.
+ There is no directory to be relative to, so `BQ_KEY_FILE` and `CACHE_DIR`
  must be **absolute** (or, for the key, a `sm://` reference). A relative value
  fails loudly instead of being resolved against whatever directory the cron
  happened to run from.

**No key file at all.** Leave `BQ_KEY_FILE` unset and BigQuery authenticates
with the same Application Default Credentials; or point it at a secret
(`BQ_KEY_FILE=sm://bq-service-account`) and the key is used from memory,
without ever being written to disk.

#### Example: a parameter holding the `.env`, with the password in a secret

The parameter keeps the configuration in the clear and points at Secret Manager
where a value is sensitive. Rendering resolves the references, so one API call
returns the complete `.env`.

Create the secret (`printf`, not `echo`: a trailing newline would end up inside
the password), then the parameter, `unformatted` because its payload is `.env`
content:

```bash
printf 's3cr3t' | gcloud secrets create db-pass --data-file=- --project=my-project
```

```bash
gcloud parametermanager parameters create client-a --parameter-format=unformatted --location=global --project=my-project
```

```bash
gcloud parametermanager parameters versions create v1 --parameter=client-a --location=global --project=my-project --payload-data="$(cat <<'EOF'
BQ_PROJECT_ID=my-project
BQ_DATASET=analytics
BQ_LOCATION=southamerica-east1

DB_HOST=10.0.0.5
DB_USERNAME=reporting
DB_PASSWORD='__REF__("//secretmanager.googleapis.com/projects/my-project/secrets/db-pass/versions/latest")'
DB_DATABASE_NAME=production

IGNORE_COLUMNS=password,remember_token
EOF
)"
```

Note the **single quotes** around `__REF__`: rendering is a textual
substitution, so the line becomes `DB_PASSWORD='s3cr3t'`. Without them a secret
containing spaces or `#` would break the `.env` parsing — and that error hides
the offending line on purpose, because it would be the password. Single rather
than double quotes so that a `$` in the password is not interpolated.

The secret is read by the **parameter's own identity**, not by the host, so the
machine running the sync only ever needs access to the parameter:

```bash
gcloud parametermanager parameters describe client-a --location=global --project=my-project --format="value(policyMember.iamPolicyUidPrincipal)"
```

```bash
gcloud secrets add-iam-policy-binding db-pass --project=my-project --member="PRINCIPAL_FROM_THE_PREVIOUS_COMMAND" --role="roles/secretmanager.secretAccessor"
```

```bash
gcloud parametermanager parameters add-iam-policy-binding client-a --location=global --project=my-project --member="serviceAccount:sync@my-project.iam.gserviceaccount.com" --role="roles/parametermanager.parameterAccessor"
```

And then:

```bash
bin/console sync log_entries -o id --env-file=pm://client-a
```

`pm://client-a` is the short form of
`pm://projects/my-project/locations/global/parameters/client-a/versions/latest`.
In production, prefer the long form pinned to a version (`…/versions/v1`), so a
configuration change only reaches the cron when you move the pointer.

`__REF__` and `sm://` can be mixed in the same parameter. `__REF__` is resolved
by Parameter Manager during the render — one API call, and the host needs no
access to the secret; `sm://` is resolved by this tool afterwards — one extra
call per reference, and the host needs `secretAccessor` itself.

Values are read on every run and never cached on disk. Regional secrets and
parameters are reached through their regional endpoint automatically.

To create a service account key file (only needed outside Google Cloud), see
[the Google Cloud console](https://console.cloud.google.com/apis/credentials/serviceaccountkey).

## The `sync` command

```bash
bin/console sync <table-name> [options]
```

| Option | Description |
|---|---|
| `-o`, `--order-column=<column>` | Sync incrementally by this column (typically the primary key). See [Incremental](#incremental---order-column) |
| `-i`, `--ignore-column=<column>` | Do not copy this column. Repeatable; defaults to `IGNORE_COLUMNS` |
| `-c`, `--create-table` | Create the BigQuery table from the MySQL schema if it does not exist |
| `--partition-type=<type>` | Partitioning of the table when it is created (`DAY`, `MONTH`, `YEAR`, `NONE`), overriding `PARTITION_TYPE`. See [Partitioning](#partitioning) |
| `-d`, `--delete-table` | Drop and recreate the table before syncing (full reload). Combined with `--un-buffer` the data is replaced instead of the table being dropped |
| `--un-buffer` | Stream the whole table instead of paginating. Requires `--delete-table`. See [Full dump](#full-dump---un-buffer---delete-table) |
| `--no-data` | Only handle the schema, copy no rows |
| `--database-name=<name>` | MySQL database, overriding `DB_DATABASE_NAME` |
| `--bigquery-table-name=<name>` | Destination table name, when it differs from the MySQL one |
| `-e`, `--env=<name>` | Configuration to run against |
| `--env-file=<path\|URI>` | Specific configuration file or reference |
| `--config-dir=<path>` | Directory holding the environments |

Errors are reported in one readable line and the exit code is non-zero, so a
failing cron run is visible. Add `-v` for the stack trace.

## Sync modes

### Incremental (`--order-column`)

The everyday mode. It asks both sides for the maximum value of the order column
and copies only what is missing:

1. `MAX(<order column>)` in MySQL and in BigQuery.
2. Same value → nothing to do.
3. Delete the BigQuery rows holding that maximum (BigQuery has no primary keys,
   and the last batch may have been cut in half), then read the maximum again.
4. Copy every MySQL row above it, in batches of `MAX_ROWS_PER_BATCH`.

**The `created_at` requirement.** To keep the BigQuery queries cheap, both the
`MAX()` and the `DELETE` are filtered by a `created_at` column, so **every table
synced incrementally must have one**. The sync fails early with a clear error if
it is missing or listed in `IGNORE_COLUMNS` — before any destructive step, so a
`--delete-table` run does not drop the table first. Tables without `created_at`
can still be synced with `--un-buffer --delete-table`. The filter only makes
the queries cheaper if the table is [partitioned](#partitioning) by
`created_at`, which is what the tool does when it creates the table.

**The lookback window** is how far back that filter looks. Any
`strtotime()`-parseable expression, and it must look backwards (`8 days`,
missing its `-`, is rejected):

```text
# global, default -3 month
CREATED_AT_LOOKBACK=-8 days

# per table: CREATED_AT_LOOKBACK_<TABLE>, where <TABLE> is the *BigQuery*
# table name uppercased, with non-alphanumerics replaced by "_"
CREATED_AT_LOOKBACK_USER_LOGS=-30 days
```

Keep the window wide enough to always reach the newest rows. If the BigQuery
table has rows but **none** inside the window — a table with no recent
activity, or a cron stopped for longer than the window — the sync cannot tell
where to resume, so it **aborts** instead of assuming the table is empty, which
would re-insert the whole MySQL table on top of the existing rows and duplicate
everything. Widen the window, or reload with `--un-buffer --delete-table`.

### Full dump (`--un-buffer --delete-table`)

Reloads the whole table, streaming it from MySQL with an unbuffered query, so
memory stays flat regardless of the table size. Use it for the first load of a
big table, for tables without `created_at`, or to repair a table whose contents
drifted.

The reload is atomic: the load job runs with `WRITE_TRUNCATE`, so the data is
replaced when the job commits and the table is **never left empty**. The table
is not physically dropped either, which means **the existing BigQuery schema is
kept**, partitioning included — to pick up a MySQL schema change or a new
partitioning, run `--delete-table` *without* `--un-buffer` once.

`--un-buffer` requires `--delete-table`: without it, a re-run would append the
whole table again and duplicate every row.

### Row count diff (no flags)

Without `--order-column`, the tool compares row counts and copies the
difference, offsetting by the number of rows already in BigQuery. It assumes an
**append-only** table: it does not notice updates, and if rows are deleted in
MySQL the counts stop matching up and the table stops syncing (it reports
"Already synced"). Prefer the incremental mode whenever the table has a
monotonic column.

## Partitioning

Every incremental run queries BigQuery twice for `MAX(<order column>)` and
once to `DELETE` the last value, all filtered by `created_at` inside the
[lookback window](#incremental---order-column). On an unpartitioned table that
filter prunes nothing: each query reads the whole `created_at` and order
columns, and the bill grows with the history of the table even if the window
is a few days.

So the tables the tool creates (`--create-table`, `--delete-table`) are
**partitioned by `created_at`**, and those queries only read the partitions
inside the window.

The granularity is resolved like the lookback, most specific first:

1. `--partition-type=<type>`
2. `PARTITION_TYPE_<TABLE>` (same table name normalization as
   `CREATED_AT_LOOKBACK_<TABLE>`)
3. `PARTITION_TYPE`
4. `MONTH`

```text
# global
PARTITION_TYPE=MONTH

# per table
PARTITION_TYPE_USER_LOGS=DAY
PARTITION_TYPE_SETTINGS=NONE
```

| Value | When |
|---|---|
| `MONTH` | The default. A lookback of days reads one or two partitions, and it is safe for full dumps of any history |
| `DAY` | Very large tables where a month is still a lot to read. **A single load job can modify at most 4000 partitions**, so a full dump of more than ~11 years of `created_at` fails with `DAY` |
| `YEAR` | Small tables with a long history |
| `NONE` | Create the table unpartitioned, like before |

Things worth knowing:

- **The partition column is always `created_at`**, the one the lookback
  filters on: partitioning by any other column would not prune those queries.
- A table without `created_at`, with `created_at` in `IGNORE_COLUMNS`, or where
  it is not a `DATE`/`DATETIME`, is created **unpartitioned**, with a warning.
- Rows with a `NULL` `created_at` are fine: BigQuery keeps them in a partition
  of their own.
- Partitioning only happens **when the table is created**. An existing table
  keeps what it has, and `--partition-type` on an existing table only prints a
  warning. `--un-buffer --delete-table` does not recreate the table either.
- Tables under 10 MB see no difference: BigQuery bills at least 10 MB per
  table referenced in a query.

**Partitioning an existing table.** Either reload it from MySQL with
`--delete-table` (without `--un-buffer`), or rewrite it inside BigQuery, which
reads it once and does not touch MySQL:

```sql
CREATE OR REPLACE TABLE `my_dataset.log_entries`
PARTITION BY DATETIME_TRUNC(created_at, MONTH)   -- DATE_TRUNC for a DATE column
AS SELECT * FROM `my_dataset.log_entries`;
```

To check it works, compare the bytes a dry run of the `MAX()` would process
before and after (`bq query --dry_run`, or the query validator in the console).

## Type mapping

The BigQuery table created with `--create-table` maps the MySQL schema like
this:

| MySQL | BigQuery |
|---|---|
| `DATE` | `DATE` |
| `DATETIME`, `TIMESTAMP` | `DATETIME` |
| `TIME` | `TIME` |
| `INT`, `SMALLINT`, `BIGINT` | `INTEGER` |
| `TINYINT` | `BOOLEAN` |
| `DECIMAL`, `FLOAT`, `DOUBLE` | `FLOAT` |
| `JSON`, `ENUM`, `VARCHAR`, `TEXT`, anything else | `STRING` |

Values are converted to the matching JSON types on the way out, and strings are
re-encoded to UTF-8.

Two mappings worth knowing: every `TINYINT` becomes a `BOOLEAN` (that is
Doctrine's MySQL mapping, not just `TINYINT(1)`), and `DECIMAL` becomes a
floating point `FLOAT` — for exact monetary values, consider ignoring the
column and handling it separately.

## Running from cron

One line per table. Use absolute paths and `--env`; no `cd` is needed:

```cron
*/15 * * * * /opt/mysql-to-google-bigquery/bin/console sync log_entries --env=client-a -o id >> /var/log/bq-sync.log 2>&1
*/15 * * * * /opt/mysql-to-google-bigquery/bin/console sync orders --env=client-a -o id >> /var/log/bq-sync.log 2>&1
```

Respect the BigQuery load job [quota](https://cloud.google.com/bigquery/quotas#load_jobs)
— every batch is one load job, and the quota is per table per day. An interval
of 15 minutes is a reasonable starting point.

## Troubleshooting

| Message | What it means |
|---|---|
| `Table 'x' has no 'created_at' column` | The incremental mode needs it for its time filter. Add the column, or use `--un-buffer --delete-table` |
| `The column 'created_at' is being excluded with --ignore-column` | Same, but the column exists and is being ignored. Remove it from `IGNORE_COLUMNS` |
| `has N rows, but none of them are inside the created_at lookback window` | The window is too short for how often this table gets new rows. Widen `CREATED_AT_LOOKBACK_<TABLE>`, or reload the table |
| `Lookback expression … resolves to a future date` | The expression is missing its `-` (`8 days` instead of `-8 days`) |
| `Invalid partition type "x" in PARTITION_TYPE…` | Use `DAY`, `MONTH`, `YEAR` or `NONE`. The message names the variable (or `--partition-type`) holding the bad value |
| A load job fails mentioning the number of partitions | The table is partitioned by `DAY` and the batch spans more than 4000 days of `created_at`. Recreate it with `MONTH` |
| `--un-buffer re-dumps the whole table and requires --delete-table` | Add `--delete-table`, or drop `--un-buffer` |
| `BigQuery table x not found` | Add `--create-table` on the first run |
| `Google Service Account JSON Key File not found: <path>` | `BQ_KEY_FILE` points nowhere. Remember it is relative to the directory of the `.env` |
| `Could not read the Google Service Account key file` | It exists but the user running the sync cannot read it — check the permissions |
| `Cannot resolve the relative path … the configuration comes from "sm://…"` | A remote configuration has no directory: make `BQ_KEY_FILE` / `CACHE_DIR` absolute |
| `The configuration read from "…" looks like JSON` | The Parameter Manager parameter must be created with the `UNFORMATTED` format |
| `The configuration read from "…" is not valid .env content` | The payload is not `NAME=value` lines. The parser error is hidden on purpose — it would quote the offending line, which may be a secret. Look for unquoted values containing spaces |
| `Could not resolve DB_PASSWORD: …` | The `sm://` reference could not be read: check the name and that the host credentials have `secretAccessor` |
| `No MySQL database selected` | Set `DB_DATABASE_NAME` or pass `--database-name` |
| 404 on a load job | `BQ_LOCATION` does not match the dataset region |

## Development

```bash
composer install
composer test        # PHPUnit
php -l <file>        # syntax check after editing
```

The test suite is unit-only: MySQL and BigQuery are doubled, and no real API is
touched. Contributions are expected to come with tests — see
[AGENTS.md](AGENTS.md) for the conventions of the project.

## Credits

Fork of [MemedDev/mysql-to-google-bigquery](https://github.com/MemedDev/mysql-to-google-bigquery).

:heart: Memed SA ([memed.com.br](https://memed.com.br))

## License

MIT license, see [LICENSE](LICENSE)
