"""Prepare a public progress post only for an original, green dev merge."""

import json
import os
import re
import urllib.request
import uuid
from pathlib import Path


def progress_body(event, repository, sha, attempt, pull_requests):
    if (
        event.get("ref") != "refs/heads/dev"
        or event.get("deleted", False)
        or event.get("after") != sha
        or event.get("repository", {}).get("full_name") != repository
        or event.get("repository", {}).get("private") is not False
        or str(attempt) != "1"
    ):
        return None
    matches = [
        pr
        for pr in pull_requests
        if pr.get("merged_at")
        and pr.get("merge_commit_sha") == sha
        and pr.get("base", {}).get("ref") == "dev"
        and pr.get("base", {}).get("repo", {}).get("full_name") == repository
    ]
    if len(matches) != 1:
        return None
    pr = matches[0]
    number = pr.get("number")
    if type(number) is not int or number <= 0:
        raise ValueError("Expected a public pull request number")
    title = re.sub(r"[\x00-\x20\x7f]+", " ", pr.get("title", "")).strip()[:180]
    if not title:
        raise ValueError("Expected a public pull request title")
    return (
        f"Progress on HalalOS: merged #{number} into dev — {title}\n\n"
        "Required CI passed on the merge commit. Development is ongoing.\n\n"
        f"https://github.com/{repository}/pull/{number}"
    )


def main():
    repository = os.environ["GITHUB_REPOSITORY"]
    sha = os.environ["GITHUB_SHA"]
    if repository != "hussain4real/halal-wealth-os" or not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ValueError("Unexpected repository or commit")
    event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
    request = urllib.request.Request(
        f"https://api.github.com/repos/{repository}/commits/{sha}/pulls?per_page=100",
        headers={
            "Authorization": f"Bearer {os.environ['GH_TOKEN']}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "HalalOS-devnull-progress",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        prs = json.load(response)
    body = progress_body(event, repository, sha, os.environ["GITHUB_RUN_ATTEMPT"], prs)
    with Path(os.environ["GITHUB_OUTPUT"]).open("a") as output:
        output.write(f"publish={'true' if body else 'false'}\n")
        if body:
            delimiter = uuid.uuid4().hex
            output.write(f"body<<{delimiter}\n{body}\n{delimiter}\n")
    if not body:
        print("No eligible merged dev PR; no post was sent.")


if __name__ == "__main__":
    main()
