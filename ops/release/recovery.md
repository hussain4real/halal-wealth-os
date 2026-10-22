# Rehearse backup recovery

Run the existing full verifier against its explicitly marked disposable test
database. `ReleaseRecoveryTest` creates two fresh random databases on that same
local server. It refuses a transaction, a different base database, an occupied
name, mismatched PostgreSQL major versions or an unrecognized cleanup marker.
It never accepts a production target argument.

The rehearsal applies real migrations, seeds synthetic owners and retained
financial history, runs PostgreSQL custom-format dump, encrypts it, verifies the
whole archive, restores into the fresh empty target in one transaction, and
reconciles all 58 tables plus existing cash, position, goal reservation and
assessment records/references. It tests a wrong key without changing the target, a new
post-restore journal write and the restored immutable-posting trigger. It runs
forward migrations again and compares migration order. A transactional populated
downgrade rehearsal must refuse retained history and leave restored data intact. Only its own marked
databases and private temporary files are removed. `build/release-recovery.json`
contains hashes/counts, migration names and outcomes; no rows, keys or archives.

## Handle an operator-owned backup

These instructions describe a future authorized environment. They do not
authorize a production backup, restore, key creation or database operation.

1. Obtain explicit authorization and identify the exact source server/database,
   PostgreSQL version, immutable source release and backup custodian. Choose a
   private directory with mode 0700, `umask 077` and sufficient local capacity.
2. Use matching PostgreSQL 18 `pg_dump --format=custom --no-owner --no-acl`
   against that approved source. Supply database credentials through the
   deployment's secret store, never command arguments or transcripts. Check the
   process exit code before treating the dump as complete.
3. Encrypt the private mode-0600 dump with the already provisioned mode-0600 raw
   32-byte backup key: `php scripts/backup-file.php seal INPUT OUTPUT KEY_FILE`.
   The tool refuses unsafe permissions and existing output files. Delete the
   local plaintext after successful encryption and verify deletion; a crash can
   leave plaintext, so inspect the private spool before resuming.
4. Retain the encrypted artifact, its SHA-256, timestamp, source/build/migration
   identity and restore receipt in separately controlled storage. Escrow APP_KEY
   and the backup key separately. Database backups contain authentication data
   and encrypted two-factor secrets; an owner JSON export intentionally does not.
5. For a separately authorized restore, fetch the artifact into private storage
   and verify its recorded hash. Use `backup-file.php open INPUT OUTPUT KEY_FILE`
   to authenticate the complete archive before it emits plaintext. Wrong keys,
   damaged/truncated frames and trailing data fail and remove partial output.
6. Inspect a new empty isolated target and prove its identity and ownership.
   Restore with matching `pg_restore --single-transaction --exit-on-error
   --no-owner --no-acl`; never disable triggers or constraints. The file tool
   performs no database operation and cannot make a selected target safe.
7. Restore the exact code and separately held APP_KEY, apply only reviewed
   forward migrations, reconcile full data and existing financial readers, test
   login/2FA and new writes, and remove plaintext. Do not open traffic until the
   custodian and owner approve the evidence.

The archive format uses libsodium secretstream XChaCha20-Poly1305 with bounded
frames and an authenticated final frame. The file tool authenticates the held
seekable archive before a second decrypting pass. It does not protect against a
privileged attacker modifying local files while ignoring locks; keep archives
in operator-owned private storage throughout. Export JSON is not an import or
operational restore format. Temporary-file unlink is not physical media erasure.

## Retention and recovery objectives

The selected rehearsal retains no backup, plaintext or key after each run;
sanitized CI reports have seven-day retention. Production retention is
**UNSELECTED**. Before deployment, the owner must select destination/access,
frequency, retained generations, expiry/legal policy, RPO, RTO, key escrow,
restore-test schedule and the person authorized to delete generations. Keep the
last verified recoverable generation until a replacement is independently
restored; verify expiry without deleting the sole usable backup.
