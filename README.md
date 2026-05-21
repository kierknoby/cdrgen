# cdrgen

Synthetic realistic CDR generator for FreePBX/Asterisk concurrency testing and validation.

cdrgen inserts tagged, realistic mixed telephony traffic into `asteriskcdrdb.cdr` so tools such as `concurrencycount`, CDR Reports concurrent calls, and SQL-based reporting engines can be tested against known synthetic datasets.

## Features

- FreePBX/Asterisk-compatible PHP CLI script
- Loads `/etc/freepbx.conf`
- Inserts into `asteriskcdrdb.cdr`
- Uses PDO prepared statements
- Dynamically adapts to the installed CDR schema with `SHOW COLUMNS FROM cdr`
- Deterministic generation with `--seed`
- Light, medium, and heavy profiles
- Optional row and date range overrides
- Realistic mixed traffic:
  - inbound, outbound, and internal extension calls
  - direct extension, ring-group-like, queue-like, and IVR-like inbound paths
  - trunk and endpoint channels
  - `ANSWERED`, `NO ANSWER`, `BUSY`, and `FAILED`
  - business-hour weighting, after-hours traffic, and burst clustering
- Trunk-aware generation:
  - prefers configured FreePBX trunks when available
  - supports explicit custom trunks
  - supports CDR-visible fake trunks
  - prompts before generation if no configured trunks are found
  - infers trunk behavior from exact and fuzzy name matching
- Calculates expected answered-only concurrency:
  - global peak
  - per-extension peak
  - per-trunk peak
- Tags generated rows with `accountcode = CCTESTxxxxxxxx`
- Prints cleanup SQL
- Keeps the CLI alive with a repeating cleanup prompt every 60 seconds

## Installation

```bash
git clone https://github.com/kierknoby/cdrgen.git
```

Run it on the FreePBX/Asterisk system as a user that can read `/etc/freepbx.conf` and write to the CDR database.

## Usage

```bash
php ~/cdrgen/cdrgen.php --profile=light
```

```bash
php ~/cdrgen/cdrgen.php --profile=medium --seed=202
```

```bash
php ~/cdrgen/cdrgen.php --profile=heavy --seed=303
```

## Interactive Mode

Run without arguments for an interactive wizard:

```bash
php ~/cdrgen/cdrgen.php
```

The wizard prompts for profile, seed, date range, and trunk options, then runs the same generation as the CLI flags.

With explicit row and date range:

```bash
php ~/cdrgen/cdrgen.php --profile=medium --rows=5000 --start="2026-05-01 00:00:00" --end="2026-05-08 00:00:00" --seed=202
```

## Options

```text
--profile=light|medium|heavy
--seed=N
--rows=N
--start="YYYY-MM-DD HH:MM:SS"
--end="YYYY-MM-DD HH:MM:SS"
--trunks=PJSIP/name,SIP/name
--fake-trunks=N
--help
```

Profiles provide default row counts and date ranges:

- `light`: 250 rows over 1 day
- `medium`: 2500 rows over 7 days
- `heavy`: 15000 rows over 30 days

## Trunks

By default, cdrgen tries to load configured FreePBX trunks from the FreePBX configuration database and generate CDR rows using those trunk names. This is useful for tools that only report trunks already known to FreePBX.

If no configured trunks are detected, cdrgen stops before inserting CDR rows and prompts you to create trunks, retry detection, explicitly use CDR-only fake trunks, or quit.

### FreePBX Test Trunks

For validating trunk reports such as Concurrency Count, create a few harmless test trunks in FreePBX with names that describe how they should behave:

- `Primary-In`
- `Primary-Out`
- `Failover-Test`

Using a dummy SIP server such as `test.test.com` is fine. The generator only needs the trunk names for CDR rows; the trunks do not need to register or pass real calls.

Important: if you disable the trunk in FreePBX, some reports may hide it and cdrgen may skip it during automatic trunk discovery. For best results, leave the test trunk enabled but pointed at a non-real SIP server. If you intentionally keep it disabled, force it with `--trunks`:

```bash
php ~/cdrgen/cdrgen.php --profile=light --trunks=PJSIP/Primary-In,PJSIP/Primary-Out,PJSIP/Failover-Test
```

Expected behavior:

- `Primary-In`: primary trunk, strongly inbound-biased
- `Primary-Out`: primary trunk, strongly outbound-biased
- `Failover-Test`: low-volume backup/failover behavior with more busy/failed calls

You can force specific trunks:

```bash
php ~/cdrgen/cdrgen.php --profile=light --trunks=PJSIP/main-out,SIP/backup-failover
```

You can also add CDR-visible fake trunks explicitly:

```bash
php ~/cdrgen/cdrgen.php --profile=medium --fake-trunks=4
```

Fake trunks are only represented in CDR rows. They do not create FreePBX trunk configuration. Reports that require configured trunks may not show fake trunk names unless those trunks also exist in FreePBX. cdrgen no longer silently falls back to fake trunks when no configured trunks are detected.

### Trunk Name Intelligence

cdrgen applies exact and fuzzy matching to trunk names to infer behavior. It understands common naming patterns such as:

- inbound: `in`, `inbound`, `incoming`, `ingress`, `did`, `ddi`, `rx`, `origination`
- outbound: `out`, `outbound`, `outgoing`, `egress`, `tx`, `termination`
- primary: `primary`, `main`, `default`, `prod`, `active`, `preferred`
- secondary: `secondary`, `alt`, `alternate`, `overflow`, `spare`
- backup: `backup`, `bkp`, `failover`, `standby`, `redundant`, `dr`
- toll-free: `tollfree`, `tf`, `800`, `888`, `877`, `866`, `855`, `844`, `833`
- international/long-distance: `intl`, `international`, `global`, `ld`, `longdistance`
- special purpose: `fax`, `t38`, `e911`, `911`, `emergency`

Fuzzy matching also handles joined names and small typos, for example `mainoutbound`, `backupfailover`, or `incomming`.

## Seed

The seed controls the pseudo-random generator.

The same profile, seed, date range, row count, and trunk options should produce the same generated dataset. Different seeds produce different datasets.

## Output

After insertion, cdrgen prints:

- generated traffic mix
- disposition mix
- trunk mix
- expected answered-only global concurrency peak
- expected answered-only per-extension peaks
- expected answered-only per-trunk peaks
- cleanup SQL commands

Non-answered calls are inserted into CDR but excluded from expected concurrency calculations.

## Algorithm Notes

Expected concurrency uses answered-only per-second occupancy with inclusive endpoints. A call active from `t_start` through `t_end` increments the occupancy counter for every second in `[t_start, t_end]`, and the peak is the maximum value across all occupied seconds.

This matches the bash `concurrency-count` CLI tool, concurrencycount's `Original` engine, FreePBX CDR Reports' historical concurrent-calls implementation, and Asterisk-style runtime channel counting. A call ending at second `200` and another call starting at second `200` both count at second `200`.

Per-call contribution to the expected seconds map is clamped to 86,400 seconds, or 24 hours, to protect against bogus long-duration CDR rows.

## Cleanup

Each run uses a unique account code:

```text
CCTESTxxxxxxxx
```

At the end of a run, cdrgen prints cleanup SQL and then waits:

```text
DELETE or KEEP:
```

- Type `DELETE` to delete rows from the current run and exit.
- Type `KEEP` to retain rows and exit.
- Do nothing and the prompt repeats every 60 seconds.

Delete one run manually:

```bash
mysql asteriskcdrdb -e "DELETE FROM cdr WHERE accountcode = 'CCTESTxxxxxxxx';"
```

Delete all generated rows:

```bash
mysql asteriskcdrdb -e "DELETE FROM cdr WHERE accountcode LIKE 'CCTEST%';"
```
