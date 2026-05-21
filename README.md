# cdrgen

Synthetic realistic CDR generator for FreePBX/Asterisk concurrency testing and validation.

cdrgen creates realistic mixed telephony traffic inside `asteriskcdrdb.cdr` for validating concurrent-call reporting engines and CDR analysis tools.

Designed for:
- concurrencycount
- CDR Reports concurrent calls
- SQL-based concurrency analysis
- regression testing
- edge-case testing
- workload simulation

## Features

- Random realistic CDR generation
- Deterministic generation via `--seed`
- Light / medium / heavy traffic profiles
- Mixed dispositions:
  - ANSWERED
  - NO ANSWER
  - BUSY
  - FAILED
- Inbound and outbound calls
- Extension and trunk channels
- Expected answered-only concurrency calculation
- Tagged rows for safe cleanup
- Interactive cleanup prompt

## Installation

```bash
git clone https://github.com/kierknoby/cdrgen.git
```

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

## Seed

The seed controls the pseudo-random generator.

Same:
- profile
- seed
- date range
- row count

= same generated CDR rows.

Different seed = different generated dataset.

## Cleanup

Delete current run:

```bash
mysql asteriskcdrdb -e "DELETE FROM cdr WHERE accountcode = 'CCTESTxxxxxxxx';"
```

Delete all generated rows:

```bash
mysql asteriskcdrdb -e "DELETE FROM cdr WHERE accountcode LIKE 'CCTEST%';"
```
