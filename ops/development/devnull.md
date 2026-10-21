# Development progress on /dev/null

The owner requested public HalalOS updates after a merged `dev` commit passes
CI. The `Publish dev progress` job depends on the existing `ci` job. It never
posts from pull-request runs, feature branches, `main`, failed CI, private
repositories or reruns. A direct push without an exactly matching merged PR
also produces no post. No default-branch workflow or promotion is needed.

Only the public merged PR title, number and link are included. No commit body,
source files, financial records, test logs, identities or credentials are sent.
The text says development is ongoing; a merge does not claim phase acceptance,
deployment or production readiness. Titles enter the action as data, through an
output and action input; they are never interpolated into a shell command.

The official action is pinned to `ee0ca417d219567146f633f69069790de89fd3e9`.
It loads the dedicated signing key into a temporary SSH agent, signs under the
`devnull-commit` namespace and removes its temporary resources. The private key
stays on the GitHub runner; /dev/null receives the public key and signature.
The runner checks out only the verified merge with no persisted Git credential.
Its GitHub token has repository-content and pull-request read permissions.

## Credential setup

Use a dedicated Ed25519 key registered on the owner's GitHub account as a
**signing key**, not an authentication key. Store its private half only in the
repository Actions secret `DEVNULL_SSH_KEY`. Keep both key files outside this
public checkout, delete local copies after verified setup, and never reuse a
personal authentication key. Registration and secret installation need the
owner's explicit authorization. No new OAuth scope is needed if the owner
registers the public key through GitHub's ordinary settings page.

Without that secret, an eligible publisher job fails visibly. The independent
required `ci` job still reports its own result. A successful publishing response
can say **pending review**: it proves submission, not public moderation approval.
The action prints the post URL and moderation status in the job annotation.

## Delivery and retries

Both the workflow and preparation script exclude reruns. Concurrent deliveries
for the same merge are serialized. These controls avoid ordinary rerun posts;
they do not claim exactly-once external delivery. If a response is lost after
acceptance, inspect the owner's /dev/null profile before any manual repost.
Do not rerun a failed original CI result to relabel it as passing.

Local verification:

```sh
python3 -m unittest discover -s .github/scripts -p 'test_*.py'
```

Hosted validation requires a normal PR, the original green merge CI, and the
resulting publisher job. Its actual post URL and moderation response remain
separate from local fixture checks.
