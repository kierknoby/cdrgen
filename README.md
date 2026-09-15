# CDRgen

CDRgen creates coherent synthetic Asterisk/FreePBX call detail records (CDRs) for report testing, data validation and expected-concurrency analysis. It provides a standalone CLI and a reusable, dependency-free PHP generation core. Given the same complete inputs, generation is deterministic.

In live mode, CDRgen writes synthetic reporting data. It does not place real calls, create live channels or RTP/media, exercise dialplan execution or carrier signalling, generate CEL records, or create real recordings. A reported expected-concurrency peak of 200 means that 200 generated CDR intervals overlap at the busiest calculated second. It does not mean that 200 physical calls occurred on the PBX.

Core version: `1.1.0-dev`. `CdrGen\Version::BASE_REVISION` records the reviewed upstream base (`f3dcc9f...`); `SOURCE_REVISION` remains `null` until a packager or importer can record an exact committed source revision. This is a development build for testing and validation, not a production CDR source.

## Quick Start

Generate a database-free Light dataset:

```bash
php cdrgen.php --profile=light --seed=101 --dry-run
```

Run without arguments to use the interactive wizard:

```bash
php cdrgen.php
```

A live run bootstraps FreePBX, discovers configured trunks, validates the CDR schema and recovery state, inserts one tagged dataset, reports its statistics and expected concurrency, then deletes that exact dataset unless `--keep` was supplied. Use a test PBX and review the generated data before relying on a validation result.

## Workload Profiles

Profiles use the following workload sizes:

| Profile | Rows | Range | Average rows/day | Answered duration range |
|---|---:|---:|---:|---:|
| Light | 1,000 | 1 day | 1,000 | 15–720 seconds |
| Medium | 5,000 | 1 day | 5,000 | 15–1,200 seconds |
| Heavy | 20,000 | 1 day | 20,000 | 15–2,400 seconds |

The common one-day range makes the profiles increasing traffic-intensity classes rather than mainly increasing history depths. Light is a quick functional or smoke workload, Medium is a substantial representative workload, and Heavy is a high-density CDR/reporting workload.

`--rows` overrides the selected profile's row count:

```bash
php cdrgen.php --profile=heavy --rows=50000 --dry-run
```

Generated rows are retained in PHP memory for reporting and concurrency analysis. The standard profiles are deliberately bounded, but practical limits for larger `--rows` values depend on the PHP runtime, FreePBX environment, schema and host. More rows increase synthetic CDR density and exercise generation, database insertion, reporting and concurrency calculation more heavily, but still do not stress Asterisk's live call path.

## Synthetic Traffic Model

Generation models weekday/weekend demand, morning, lunch, afternoon and evening variations, minute-of-hour effects, rare traffic spikes and burst clustering. Calls are inbound, outbound or internal, with time-sensitive direction and disposition probabilities. Dispositions are `ANSWERED`, `NO ANSWER`, `BUSY` and `FAILED`. Answered calls use short, medium and long duration classes plus ring time; unsuccessful calls are shorter and have zero billable seconds.

Inbound traffic includes direct-extension, ring-group-like, queue-like and IVR-like records. Logical rows retain source/destination relationships, ring time, duration/billsec, routing, recording, hangup, caller-name, DID, trunk/carrier, extension, queue, accountcode, start/answer/end, unique/linked ID and channel metadata even when the installed schema cannot store every field. Endpoint technologies default to PJSIP and SIP.

Trunks can be discovered from FreePBX, supplied explicitly, or created as CDR-only fake profiles. Names influence traffic: primary/preferred, secondary/overflow, backup/failover, inbound/outbound, toll-free, international, fax and emergency traits are recognised, including joined words and useful small misspellings such as `incomming` and `prefered`.

## Live-Run Safety and Cleanup

The normal temporary lifecycle is:

1. Bootstrap FreePBX, discover trunks, acquire the root-owned process lock, resolve any recognised previous run, inspect the schema and require an InnoDB CDR table.
2. Create an exact 20-character accountcode, `CCTEST` plus 14 lowercase hexadecimal characters, and record its recovery state.
3. Insert all generated rows in one transaction and verify both the exact accountcode count and byte-exact `cdrgen ` recovery marker count before commit.
4. Report the generated traffic and expected concurrency.
5. Delete only that exact accountcode and verify that no row from the run remains before reporting cleanup success.

CDRgen never uses a broad wildcard deletion such as `DELETE ... LIKE 'CCTEST%'`, and operators should not use one either. Exact identity prevents one run's cleanup from deleting another dataset. The installed schema is checked before every live write; unsuitable accountcode/userfield columns, unknown mandatory columns, unsafe values and non-transactional storage are rejected. CDRgen never converts the table engine automatically.

Live mode requires PHP support for `pcntl_signal()` and `pcntl_async_signals()`. SIGINT, SIGTERM, SIGHUP and PHP shutdown paths attempt verified cleanup for temporary runs. The root-owned `/var/lib/cdrgen/active-run.json` recovery record is written before database mutation and retained if cleanup cannot be verified. `/var/lib/cdrgen` must be a real directory owned by `root:root` with mode `0700`; lock and recovery files must be regular administrator-owned files, not symlinks.

At the next live startup, CDRgen recovers only a strict new-format accountcode whose every row has the byte-exact marker. Ambiguous, malformed, partially marked or legacy rows block startup and are not deleted. Do not remove `active-run.json` merely to bypass a safety failure. Investigate the exact accountcode, database state and reported recovery instructions first.

CDRgen controls rows in the active CDR table only. Database replicas, backups, exports, report caches, external logs and other integrations may retain copies after exact table cleanup.

### Keeping a Dataset

`--keep` deliberately leaves the completed live dataset in the CDR table and preserves its recovery state:

```bash
sudo cdrgen --profile=medium --seed=202 --keep
```

Record the exact accountcode printed by the run. Inspect or remove only that value:

```bash
mysql asteriskcdrdb -NBe "SELECT COUNT(*) FROM cdr WHERE HEX(accountcode)=HEX('CCTEST0123456789ab');"
mysql asteriskcdrdb -e "DELETE FROM cdr WHERE HEX(accountcode)=HEX('CCTEST0123456789ab');"
mysql asteriskcdrdb -NBe "SELECT COUNT(*) FROM cdr WHERE HEX(accountcode)=HEX('CCTEST0123456789ab');"
```

Normally, leave the recovery record in place and let the next live CDRgen invocation perform its exact verified recovery before generating a new dataset. If manual database cleanup is necessary, confirm zero exact rows and follow the recovery guidance printed by CDRgen.

## Dry Run

`--dry-run` generates and analyses the complete logical dataset without bootstrapping FreePBX, connecting to the database, acquiring the live-run lock or creating recovery state. Explicit `--trunks` values are used when supplied. Otherwise dry run uses built-in sample/fallback trunk identities; these are not a discovery of the PBX's configured trunk inventory. `--fake-trunks` adds CDR-only trunk profiles that may not appear in reports requiring actual FreePBX trunk configuration.

## CLI

```text
--profile=light|medium|heavy
--seed=N
--rows=N
--start="YYYY-MM-DD HH:MM:SS"
--end="YYYY-MM-DD HH:MM:SS"
--trunks=PJSIP/name,SIP/name
--fake-trunks=N
--timezone=Area/Location
--concurrency-semantics=answered|cdr
--fixture-accountcode
--keep
--dry-run
--help
```

Value options use `--name=value`; the four flags take no value. Unknown options, missing values and valued flag forms such as `--keep=no` fail before generation or live-system access. `--help` also requires no FreePBX or database access. Running without arguments enters the interactive wizard.

Examples:

```bash
php cdrgen.php --profile=medium --seed=202
php cdrgen.php --profile=heavy --seed=303 --keep
php cdrgen.php --profile=medium --rows=5000 \
  --start="2026-05-01 00:00:00" --end="2026-05-02 00:00:00" \
  --timezone=UTC --seed=202 \
  --trunks=PJSIP/Primary-In,PJSIP/Primary-Out,SIP/Failover-Test --dry-run
```

For `--trunks`, `PJSIP/name` explicitly selects PJSIP, `SIP/name` explicitly selects SIP, and a bare name is shorthand for a PJSIP name. Configured FreePBX trunk discovery ignores disabled trunks when the installed schema exposes that flag. `--fixture-accountcode` is intended only for disposable fixtures. Its 20-character accountcode derives from the complete canonical dataset identity; normal runs use fresh cryptographically random accountcodes.

The CLI prints stage messages before opaque FreePBX, trunk, schema/recovery and concurrency work. Longer generation and database insertion show progress. Interactive terminals can update one progress line, while redirected output uses bounded checkpoints rather than producing a line for every row.

## Determinism

Generation is exactly deterministic only for the complete input: core version, random-source identity, profile, start/end instants, row count, timezone, ordered extension/name inventory, ordered profiled trunk inventory, endpoint technology policy and generation options. A seed alone is not the whole contract. CLI or wizard runs that omit explicit dates resolve profile-default times at runtime, so the same seed used later can produce a different dataset. The wizard freezes its resolved dates and random seed before confirmation so its summary matches the executed request.

The database run accountcode is excluded from the logical dataset fingerprint. Repeated CLI insertions may therefore have byte-identical traffic apart from independently generated accountcodes. Callers requiring exact fixture rows must also supply the same explicit accountcode.

`SeededRandomSource` maps an integer CLI seed onto a platform-stable SHA-256 stream. Generation restarts a named traffic substream for every call, while trunk profiling uses an independent named substream. `HashStreamRandomSource` accepts a 128-bit or larger scenario identity without collapsing it into 32-bit state. Neither source requires packages, network access or Git.

## Expected Concurrency

Expected concurrency is calculated from generated rows in aligned 3,600-second chunks with inclusive endpoints. A call ending in the same second another begins overlaps in that second. Only `ANSWERED` rows are eligible, and intervals are capped at 86,400 seconds to protect against corrupt data.

- `answered`, the standalone default, measures answer timestamp through end. It preserves CDRgen's historical answered-media expectation.
- `cdr` measures call date through call date plus duration. It matches the Concurrency Count CDR contract, including ringing time.

Output includes global, per-trunk, per-extension handled-call and per-extension visible-channel-leg peaks. Internal calls do not count against trunks. A configured trunk identity always takes precedence over extension heuristics: a numeric PJSIP endpoint known from the configured trunk inventory remains a trunk and is excluded from both extension views. Numeric PJSIP endpoints not known as trunks remain eligible extensions.

These are expected overlaps in synthetic CDR data, not measurements of real channels, media sessions or simultaneous physical calls.

## Reusable Generation Core

Load the dependency-free autoloader, or provide your own PSR-4 loader:

```php
require '/path/to/cdrgen/src/autoload.php';

$random = new CdrGen\Random\HashStreamRandomSource($scenarioIdentity);
$profile = CdrGen\TrafficProfile::named('light');
$request = new CdrGen\GenerationRequest(
    $profile,
    strtotime('2026-05-01 00:00:00 UTC'),
    strtotime('2026-05-02 00:00:00 UTC'),
    $random,
    ['2001', '2002', '2010'],
    $profiledTrunks,
    ['timezone' => 'UTC', 'accountcode' => 'CCTEST0123456789ab']
);

$progress = static function (int $completed, int $total): void {
    // Optional application-owned progress reporting.
};
$result = (new CdrGen\Generator())->generate($request, $progress);
```

The backwards-compatible generation entry point is:

```php
Generator::generate(
    GenerationRequest $request,
    ?callable $progress = null,
    int $batchSize = 500
)
```

Existing `generate($request)` callers remain valid. The optional callback receives completed and total row counts at bounded checkpoints. Changing the callback or checkpoint size does not alter rows, order, statistics or dataset identity. The core itself writes nothing to stdout.

The remaining public generation surface includes `GenerationResult`, `TrafficProfile`, the two random sources and `Version`. `TrunkProfiler` is public for converting trunk inventory into generation metadata.

The core requires PHP 7.4+, has no Composer, FreePBX, database, network or Git dependency, and generates complete logical rows in memory. Schema discovery, projection, persistence, transaction handling, cleanup/recovery, concurrency reporting, terminal output, argument parsing, prompts and FreePBX bootstrapping remain separate CLI/integration concerns.

## Schema-Aware Persistence

`SchemaMapper` projects only fields supported by `SHOW COLUMNS` metadata, omits auto-increment columns and permits missing nullable/defaulted optional columns. It rejects unknown mandatory columns and values whose byte length exceeds discovered CHAR/VARCHAR bounds rather than depending on SQL strict mode. This conservative byte check may reject some representable non-ASCII text, but it cannot permit silent truncation.

`CdrRepository` uses prepared statements and one transaction. Before commit it verifies both the exact accountcode row count and the byte-exact marker count. MySQL/MariaDB live writes require a positively identified InnoDB `asteriskcdrdb.cdr` table at the write boundary. Cleanup accepts only the full `CCTEST` plus 14-lowercase-hex shape and verifies zero rows afterwards.

## Requirements and Installation

The reusable core requires PHP 7.4 or later. The live CLI is designed for a Linux FreePBX or PBXact installation with `/etc/freepbx.conf`, a PDO MySQL driver, an accessible `asteriskcdrdb.cdr` table using InnoDB, and PHP `pcntl_signal()` plus `pcntl_async_signals()`. MariaDB 5.5/InnoDB is part of the production validation target. Dry run does not require FreePBX or a database.

```bash
git clone https://github.com/kierknoby/cdrgen.git ~/cdrgen
sudo ~/cdrgen/install.sh
```

The installer rejects a pre-existing `/var/lib/cdrgen` symlink or non-directory before changing ownership or permissions. It makes `cdrgen.php` executable, links it as `/usr/local/bin/cdrgen`, and creates or verifies `/var/lib/cdrgen` as `root:root` mode `0700`. Live CDRgen is therefore an administrative command; the Asterisk service account receives no write access to trusted safety state.

## Validation and Development

Pure tests and dry runs do not modify the PBX:

```bash
php -d error_reporting=E_ALL tests/run.php
php tests/benchmark.php light
php cdrgen.php --profile=light --seed=101 --dry-run
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
bash -n install.sh
```

A live invocation is an integration test that temporarily modifies `asteriskcdrdb.cdr` unless `--dry-run` is supplied. Use an authorised test PBX, confirm the table engine and backups, start with Light, and verify exact cleanup. `--keep` intentionally changes that lifecycle and should be used only when the retained rows are required.

The test suite covers profile definitions, deterministic identities, request/inventory sensitivity, row invariants, traffic weights and bursts, trunk classification, concurrency modes and boundaries, schema projection, transactional rollback, exact cleanup/recovery, process locking, signal cleanup, parser rejection and wizard input freezing. Environment-dependent signal subprocess tests can be skipped when their required PHP extensions are unavailable.

## Limitations and Boundaries

- CDRgen validates synthetic reporting behaviour, not PBX capacity or call quality.
- It does not create live calls, channels, media, dialplan load, CEL, carrier events or real recordings.
- Logical records model useful CDR topologies but not every production multi-leg scenario or dialplan application.
- Generated data is only as representative as the supplied extension and trunk inventories and the fixed traffic model.
- The in-memory architecture limits practical custom row counts. The standard profiles are deliberately bounded, but practical limits depend on the PHP runtime, FreePBX environment, schema and host.
- Exact active-table cleanup cannot erase copies already exported, replicated, cached, logged or backed up elsewhere.

## Release History

### 1.1.0 (Pending)

The development release adds a reusable, versioned deterministic core while retaining the standalone CLI; deterministic dataset identity; schema-aware transactional persistence; exact cleanup and database-marker recovery; improved FreePBX bootstrap compatibility; configured numeric PJSIP trunk classification; progress and wait feedback; strict CLI parsing; wizard date freezing; intensity-based profiles; and broader production validation.

## Licence

MIT. See [LICENSE](LICENSE).

## AI-Assisted Contributions and Disclosure

This project has been developed with AI assistance for code generation, review, testing and documentation. From 26 August 2026, generative AI assistance must be disclosed in every commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example: `Assisted-by: GitHub-Copilot:gpt-5.6-sol`.

The human contributor remains solely responsible for the contribution. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne
