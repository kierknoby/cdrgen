# cdrgen

Synthetic realistic CDR generator for FreePBX/Asterisk concurrency testing and validation.

cdrgen inserts tagged, realistic mixed telephony traffic into `asteriskcdrdb.cdr` so tools such as `concurrencycount`, CDR Reports concurrent calls, and SQL-based reporting engines can be tested against known synthetic datasets.

Only answered PJSIP calls (`disposition = 'ANSWERED'`) contribute to the expected concurrency calculation. A ringing or failed call is not a concurrent call. Non-answered calls are still inserted into CDR for traffic-mix realism.

## Quick Start

```bash
git clone https://github.com/kierknoby/cdrgen.git ~/cdrgen
sudo ~/cdrgen/install.sh
```

cdrgen is now available system-wide. Use Interactive Mode for the wizard, or Usage for direct command examples.

Run cdrgen on the FreePBX/Asterisk system as a user that can read `/etc/freepbx.conf` and write to the CDR database.

If you prefer not to install system-wide, invoke the script directly:

```bash
php ~/cdrgen/cdrgen.php
```

## How it Works

cdrgen inserts synthetic CDR rows into `asteriskcdrdb.cdr` tagged with a unique `accountcode` (`CCTESTxxxxxxxx`). After insertion it prints the expected answered-only concurrency peaks calculated independently from the generated data, so you can compare them to whatever report you're testing.

cdrgen writes synthetic rows tagged with `CCTESTxxxxxxxx`. Treat it as a test-PBX tool. Use the printed cleanup SQL, or type DELETE at the cleanup prompt, to remove the rows when finished.

## What cdrgen reports

cdrgen reports concurrency three ways:

- **Global peak**: how busy the PBX was overall.
- **Per-trunk peak**: how busy each SIP trunk was. Internal calls do not touch trunks and do not count here.
- **Per-extension peak**, in two flavours:
  - **Handled-call peak**: how many calls each user was dealing with. An internal call counts once, against the called party.
  - **Channel-leg peak**: how many channels were open against each extension. An internal call counts on both ends.

These answer different questions. Group is for sizing the PBX. Trunk is for sizing SIP capacity. Extension handled-call is for understanding user workload. Extension channel-leg is for understanding raw channel occupancy per endpoint.

## Usage

```bash
cdrgen --profile=light
```

```bash
cdrgen --profile=medium --seed=202
```

```bash
cdrgen --profile=heavy --seed=303
```

With explicit row count and date range:

```bash
cdrgen --profile=medium --rows=5000 --start="2026-05-01 00:00:00" --end="2026-05-08 00:00:00" --seed=202
```

## Interactive Mode

Run without arguments for an interactive wizard:

```bash
cdrgen
```

The wizard prompts for profile, seed, date range, and trunk options, then runs the same generation as the CLI flags.

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

## Profiles

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
cdrgen --profile=light --trunks=PJSIP/Primary-In,PJSIP/Primary-Out,PJSIP/Failover-Test
```

Expected behavior:

- `Primary-In`: primary trunk, strongly inbound-biased
- `Primary-Out`: primary trunk, strongly outbound-biased
- `Failover-Test`: low-volume backup/failover behavior with more busy/failed calls

You can force specific trunks:

```bash
cdrgen --profile=light --trunks=PJSIP/main-out,SIP/backup-failover
```

You can also add CDR-visible fake trunks explicitly:

```bash
cdrgen --profile=medium --fake-trunks=4
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
- expected answered-only per-extension handled-call peaks
- expected answered-only per-extension channel-leg peaks
- expected answered-only per-trunk peaks
- cleanup SQL commands

Non-answered calls are inserted into CDR but excluded from expected concurrency calculations.

## Algorithm Notes

Expected concurrency uses answered-only per-second occupancy with inclusive endpoints. A call active from `t_start` through `t_end` increments the occupancy counter for every second in `[t_start, t_end]`, and the peak is the maximum value across all occupied seconds.

cdrgen prints two extension views:

- Per-extension handled-call peak mirrors the original bash Extension mode: one selected extension per CDR row, preferring `dstchannel` and falling back to `channel`, with destination numbers starting `1` or `9` excluded.
- Per-extension channel-leg peak counts every distinct visible extension leg in `channel` and `dstchannel` values matching `PJSIP/NNNN-...`.

This matches the bash `concurrency-count` CLI tool, concurrencycount's `Original` engine, FreePBX CDR Reports' historical concurrent-calls implementation, and Asterisk-style runtime channel counting. A call ending at second `200` and another call starting at second `200` both count at second `200`.

Per-call contribution to the expected seconds map is clamped to 86,400 seconds, or 24 hours, to protect against bogus long-duration CDR rows.

For large datasets, cdrgen calculates expected peaks in time batches. Each batch still walks every active second inclusively; batching only limits memory use while preserving the same counting semantics.

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

## AI disclosure

This tool has been developed with AI assistance for code generation, review, testing, and documentation. Changes should still be reviewed, tested, and accepted by a human maintainer before deployment.

## Licence

MIT. See LICENSE.

## Author

@kierknoby, Kieran Byrne // FreePBX UK
