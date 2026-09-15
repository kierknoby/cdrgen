# CDRgen

CDRgen is a realistic synthetic CDR workload generator for FreePBX/Asterisk report testing. The standalone command writes tagged rows to `asteriskcdrdb.cdr`; the reusable core generates complete logical CDRs entirely in memory and has no FreePBX or database dependency.

Core version: `1.1.0-dev`. `CdrGen\Version::BASE_REVISION` records the reviewed upstream base (`f3dcc9f...`); `SOURCE_REVISION` remains `null` until a packager or importer can record an exact committed source revision. This is a test/validation utility, not a production CDR writer.

## Safety

The normal CLI bootstraps FreePBX and inserts synthetic rows in a transaction. Live generation requires the `asteriskcdrdb.cdr` table to use InnoDB; CDRgen verifies the active table engine immediately before insertion and does not alter or convert it automatically. This requirement makes rollback reliable when pre-commit safety verification fails. Every database run gets a cryptographically random 56-bit suffix: `CCTEST` plus exactly 14 lowercase hexadecimal characters, for example `CCTEST0123456789ab`. This fills, but never exceeds, the common FreePBX `varchar(20)` accountcode. The installed schema is inspected before every live write; a shorter or unbounded/non-CHAR accountcode definition is rejected.

Live runs are temporary by default. After reporting, CDRgen deletes only the exact run accountcode and verifies both that the run has zero rows and that no other `CCTEST` rows remain. There is no interactive KEEP choice. Specialist development retention requires `--keep` before generation. To remove one deliberately retained run manually:

```sql
DELETE FROM cdr WHERE accountcode = 'CCTEST0123456789ab';
```

Before commit, CDRgen queries the exact accountcode inside the transaction and requires the expected row count, catching silent database truncation. Live mode requires PHP `pcntl`; SIGINT, SIGTERM, SIGHUP, and PHP shutdown paths attempt verified cleanup for temporary runs. The root-owned `/var/lib/cdrgen/active-run.json` sidecar is written atomically and fsynced when the PHP runtime supports it, but filesystem rename is not treated as a power-loss guarantee.

Power-loss recovery is independently anchored in the CDR database. Live mode requires a `userfield` column capable of storing CDRgen's marker. A strict new-format accountcode is automatically recoverable without a sidecar only when every row under that exact accountcode also has that marker. Each positively identified run is deleted by exact byte identity and verified separately. Ambiguous, malformed, partially marked, or legacy rows are reported and block startup rather than being deleted. A root-owned process lock prevents concurrent live runs.

CDRgen refuses to start a new live dataset if unexpected `CCTEST` rows exist. It reports each exact accountcode and count and never performs a broad `DELETE LIKE 'CCTEST%'`. Recovery records authorize only their single validated exact accountcode. If verified cleanup cannot complete, the recovery record remains and CDRgen exits non-zero with manual recovery details. `/var/lib/cdrgen` must be `root:root` mode `0700`; lock and recovery paths must be regular, administrator-owned files and may not be symlinks. Use `--dry-run` for generation without FreePBX, locks, recovery files, or database access.

CDRgen models realistic reporting data, not complete Asterisk signalling, CEL events, every multi-CDR topology, or every production dialplan application. Run it on a test PBX and review generated data before relying on a validation result.

## Traffic model

Profiles use the following workload sizes:

| Profile | Rows | Range | Answered duration range |
|---|---:|---:|---:|
| Light | 1,000 | 1 day | 15–720 seconds |
| Medium | 5,000 | 1 day | 15–1,200 seconds |
| Heavy | 20,000 | 1 day | 15–2,400 seconds |

Generation models weekday/weekend demand, morning/lunch/afternoon/evening variations, minute-of-hour effects, rare traffic spikes, and burst clustering. Calls are inbound, outbound, or internal with time-sensitive direction and disposition probabilities. Dispositions are `ANSWERED`, `NO ANSWER`, `BUSY`, and `FAILED`; answered durations use short/medium/long classes and ring time, while unsuccessful calls are shorter with zero billsec.

Inbound traffic includes direct-extension, ring-group-like, queue-like, and IVR-like records. Logical rows retain rich routing, recording, hangup, caller-name, DID, trunk/carrier, queue, start/answer/end, unique/linked ID, and channel metadata even when the installed schema cannot store every field. Endpoint technologies default to PJSIP and SIP.

Trunks can be discovered from FreePBX, provided explicitly, or created as CDR-only fake profiles. Names influence traffic: primary/preferred, secondary/overflow, backup/failover, inbound/outbound, toll-free, international, fax, and emergency traits are recognised, including joined words and useful small typos such as `incomming` and `prefered`.

## CLI

Existing commands remain valid:

```bash
php cdrgen.php --profile=light
php cdrgen.php --profile=medium --seed=202
php cdrgen.php --profile=heavy --seed=303
```

Explicit inputs and database-free generation:

```bash
php cdrgen.php --profile=medium --rows=5000 \
  --start="2026-05-01 00:00:00" --end="2026-05-08 00:00:00" \
  --timezone=UTC --seed=202 \
  --trunks=PJSIP/Primary-In,PJSIP/Primary-Out,SIP/Failover-Test --dry-run
```

Options:

```text
--profile=light|medium|heavy
--seed=N
--rows=N
--start="YYYY-MM-DD HH:MM:SS"
--end="YYYY-MM-DD HH:MM:SS"
--timezone=Area/Location
--trunks=PJSIP/name,SIP/name
--fake-trunks=N
--concurrency-semantics=answered|cdr
--fixture-accountcode
--keep
--dry-run
--help
```

Running without arguments retains an interactive profile/seed/trunk confirmation workflow. Automatic discovery ignores disabled FreePBX trunks when the schema exposes that flag. Fake trunks exist only in CDR data and may not appear in reports that require configured FreePBX trunks.

`--fixture-accountcode` is deliberately separate from `--seed` and is intended only for disposable fixtures. Its 20-character tag derives from the complete canonical dataset identity, so different profiles, ranges, or inventories sharing a seed do not share a cleanup tag. Normal runs receive fresh cryptographically random tags. `--keep` is the only way a completed live run deliberately remains after CDRgen exits; its recovery record ensures a later live invocation cleans it before generating again.

## Reproducibility

The generator is exactly deterministic only for the complete input: core version, random-source identity, profile, start/end instants, row count, timezone, ordered extension/name inventory, ordered profiled trunk inventory, endpoint technology policy, and generation options. A seed alone is not the whole contract.

The database run accountcode is excluded from the logical dataset fingerprint. Consequently repeated CLI insertions may have byte-identical traffic apart from their independently generated accountcodes. Callers wanting exact fixture rows must supply the same explicit accountcode too.

`SeededRandomSource` maps the traditional integer CLI seed onto a platform-stable SHA-256 stream. Generation restarts a named traffic substream for every call, while trunk profiling uses an independent named substream; prior consumption and repeated `generate()` calls cannot alter a canonical request. `HashStreamRandomSource` accepts a 128-bit or larger scenario identity without collapsing it into 32-bit state. It uses SHA-256 of the complete identity and a 64-bit counter, and range generation uses rejection sampling to avoid modulo bias. Neither source requires packages, network access, or Git.

## Reusable API

Load the small dependency-free autoloader (or bundle the `src` tree with your own PSR-4 loader):

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

$result = (new CdrGen\Generator())->generate($request);
foreach ($result->rows() as $cdr) {
    // Complete logical CDR; no database side effects occurred.
}

echo $result->datasetIdentity();
echo $result->metadata()['core_version'];
```

The intentionally small public surface is `Generator::generate(GenerationRequest)`, `GenerationResult`, `TrafficProfile`, the two random sources, and `Version`. `TrunkProfiler` is public for callers converting trunk inventory to generation metadata. Result statistics are derived from returned rows and include direction, disposition, trunk, and inbound-flow distributions.

The core requires PHP 7.4+, no Composer installation, no particular checkout path, no Git metadata, and no network access. Schema discovery, mapping, insertion, transaction handling, cleanup, terminal output, argument parsing, prompts, and FreePBX bootstrapping remain outside it.

## Expected concurrency

Expected concurrency is independently calculated from generated rows in aligned 3,600-second chunks with bounded memory and inclusive endpoints. A call ending in the same second another begins overlaps in that second. Only `ANSWERED` rows are eligible and intervals are capped at 86,400 seconds as protection against corrupt data.

- `answered` (standalone default) preserves CDRgen's historical expectation: answer timestamp through end, inclusive. This represents answered-media occupancy and is retained for backward comparison.
- `cdr` uses calldate through `calldate + duration`, inclusive. This matches the current Concurrency Count CDR contract, including ringing time.

Output includes global, per-trunk, per-extension handled-call, and per-extension visible-channel-leg peaks. Internal calls do not count against trunks.

## Schema and insertion

The generator always creates the complete logical row. `SchemaMapper` projects only fields supported by `SHOW COLUMNS` metadata, omits auto-increment columns, and permits missing nullable/defaulted optional columns. It rejects unknown mandatory columns and values whose byte length exceeds discovered CHAR/VARCHAR bounds rather than relying on SQL strict mode. This byte-based check is intentionally conservative on multibyte UTF-8 schemas because basic `SHOW COLUMNS` metadata does not prove the active character repertoire; it may reject a representable non-ASCII value but cannot permit silent truncation. `CdrRepository` uses prepared statements and verifies both the exact accountcode row count and byte-exact recovery marker count before committing. MySQL/MariaDB writes require a positively identified InnoDB `cdr` table at the write boundary because these verification guarantees depend on real transactional rollback; CDRgen never converts the table automatically. Cleanup accepts only the full `CCTEST` plus 14-hex shape and verifies zero afterwards.

## Testing and development

Pure tests need no PBX or database:

```bash
php tests/run.php
php tests/benchmark.php heavy
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

The suite covers profiles, both deterministic random identities, exact reproduction, inventory sensitivity, row invariants and coverage, traffic weights/bursts, trunk traits and typo recognition, concurrency modes/chunk boundaries/inclusive overlap, schema projections, and dataset/run identity separation. A live PBX integration run is intentionally separate and optional; `--dry-run` is the normal development smoke test.

## Installation

```bash
git clone https://github.com/kierknoby/cdrgen.git ~/cdrgen
sudo ~/cdrgen/install.sh
```

The installer makes `cdrgen.php` executable, links it as `/usr/local/bin/cdrgen`, and creates `/var/lib/cdrgen` as `root:root` mode `0700` for the live-run lock and recovery record. Live CDRgen is therefore an administrative command; the Asterisk service account receives no write access to trusted safety state.

## Licence

MIT. See [LICENSE](LICENSE).

Developed with AI assistance; changes still require human review before use.
