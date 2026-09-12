# CDRgen

CDRgen is a realistic synthetic CDR workload generator for FreePBX/Asterisk report testing. The standalone command writes tagged rows to `asteriskcdrdb.cdr`; the reusable core generates complete logical CDRs entirely in memory and has no FreePBX or database dependency.

Core version: `1.1.0-dev`. `CdrGen\Version::BASE_REVISION` records the reviewed upstream base (`f3dcc9f...`); `SOURCE_REVISION` remains `null` until a packager or importer can record an exact committed source revision. This is a test/validation utility, not a production CDR writer.

## Safety

The normal CLI bootstraps FreePBX and inserts synthetic rows in a transaction. Every database run gets a cryptographically random 64-bit `CCTEST` suffix (for example, `CCTEST0123456789abcdef`). This is a strong practical identifier, not a mathematical uniqueness guarantee. The final prompt can delete only that exact accountcode; `KEEP` retains it. To remove one retained run:

```sql
DELETE FROM cdr WHERE accountcode = 'CCTEST0123456789abcdef';
```

An interruption after commit but before cleanup leaves recognisably tagged rows. Find them with `WHERE accountcode LIKE 'CCTEST%'`, inspect them, and delete only the intended exact tag. CDRgen never alters the CDR schema. Use `--dry-run` for generation without FreePBX or database access.

CDRgen models realistic reporting data, not complete Asterisk signalling, CEL events, every multi-CDR topology, or every production dialplan application. Run it on a test PBX and review generated data before relying on a validation result.

## Traffic model

Profiles retain the established workload sizes:

| Profile | Rows | Range | Answered duration range |
|---|---:|---:|---:|
| Light | 250 | 1 day | 15–720 seconds |
| Medium | 2,500 | 7 days | 15–1,200 seconds |
| Heavy | 15,000 | 30 days | 15–2,400 seconds |

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
--dry-run
--help
```

Running without arguments retains an interactive profile/seed/trunk confirmation workflow. Automatic discovery ignores disabled FreePBX trunks when the schema exposes that flag. Fake trunks exist only in CDR data and may not appear in reports that require configured FreePBX trunks.

`--fixture-accountcode` is deliberately separate from `--seed` and is intended only for disposable fixtures. Its tag derives from the complete canonical dataset identity, so different profiles, ranges, or inventories sharing a seed do not share a cleanup tag. Normal runs receive fresh cryptographically random tags.

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
    ['timezone' => 'UTC', 'accountcode' => 'CCTESTfixture']
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

The generator always creates the complete logical row. `SchemaMapper` projects only fields supported by `SHOW COLUMNS` metadata, omits auto-increment columns, and permits missing nullable/defaulted optional columns. It rejects an unknown mandatory column rather than fabricating misleading empty, zero, or current-time values. `CdrRepository` uses prepared statements, inserts the run in one transaction, rolls back on error, and reports only the committed count. Cleanup accepts only an exact `CCTEST` accountcode.

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

The installer makes `cdrgen.php` executable and links it as `/usr/local/bin/cdrgen`.

## Licence

MIT. See [LICENSE](LICENSE).

Developed with AI assistance; changes still require human review before use.
