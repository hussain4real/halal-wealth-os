import copy
import unittest

from devnull_progress import progress_body


class ProgressTests(unittest.TestCase):
    def setUp(self):
        self.repo = "hussain4real/halal-wealth-os"
        self.sha = "a" * 40
        self.event = {"ref": "refs/heads/dev", "after": self.sha, "repository": {"full_name": self.repo, "private": False}}
        self.pr = {"number": 16, "title": "Opportunity theses", "merged_at": "2026-10-21T07:39:19Z", "merge_commit_sha": self.sha, "base": {"ref": "dev", "repo": {"full_name": self.repo}}}

    def body(self, event=None, prs=None, attempt="1"):
        return progress_body(self.event if event is None else event, self.repo, self.sha, attempt, [self.pr] if prs is None else prs)

    def test_original_dev_merge_has_public_title_and_source_link(self):
        body = self.body()
        self.assertIn("Opportunity theses", body)
        self.assertIn(f"https://github.com/{self.repo}/pull/16", body)
        self.assertNotIn(self.sha, body)

    def test_reruns_never_prepare_duplicate_posts(self):
        self.assertIsNone(self.body(attempt="2"))

    def test_feature_main_deleted_wrong_commit_or_private_push_never_posts(self):
        for field, value in [("ref", "refs/heads/main"), ("ref", "refs/heads/feature"), ("deleted", True), ("after", "b" * 40)]:
            with self.subTest(field=field, value=value):
                event = copy.deepcopy(self.event)
                event[field] = value
                self.assertIsNone(self.body(event=event))
        for private in [True, None]:
            event = copy.deepcopy(self.event)
            event["repository"]["private"] = private
            self.assertIsNone(self.body(event=event))

    def test_only_exact_merged_pr_to_our_dev_is_eligible(self):
        self.assertIsNone(self.body(prs=[]))
        self.assertIsNone(self.body(prs=[self.pr, self.pr]))
        for field, value in [("merged_at", None), ("merge_commit_sha", "b" * 40), ("base", {"ref": "main", "repo": {"full_name": self.repo}}), ("base", {"ref": "dev", "repo": {"full_name": "other/repo"}})]:
            with self.subTest(field=field):
                pr = copy.deepcopy(self.pr)
                pr[field] = value
                self.assertIsNone(self.body(prs=[pr]))

    def test_title_is_data_and_cannot_inject_output_records(self):
        pr = copy.deepcopy(self.pr)
        pr["title"] = "$(touch /tmp/unwanted)\npublish=true\x00" + "x" * 300
        body = self.body(prs=[pr])
        self.assertIn("$(touch /tmp/unwanted) publish=true", body)
        self.assertEqual(len(body.splitlines()[0].split(" — ", 1)[1]), 180)
        self.assertNotIn("\x00", body)


if __name__ == "__main__":
    unittest.main()
