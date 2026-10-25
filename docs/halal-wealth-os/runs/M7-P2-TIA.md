# M7-P2 native TIA evidence

Status: PASS for the scoped native baseline, affected-file selection and exact source restoration. Full uncached release and actual live evaluation are separate gates.

A fresh native Pest run at implementation checkpoint `4606ec8d3e6406bb0e17915f684334e158c5d036` executed1,447 tests/11,054 assertions in293,321ms. The earlier Composer timeout and subsequent three stale integration expectations remain retained as failed attempts; neither is relabeled a pass. The corrected current HTTP evidence phase label was applied after that baseline and before the probes.

A concrete PHP constructor call was changed to equivalent named arguments. Native TIA selected `CopilotAnswerTest.php` and `OpenAiAnswerPlannerTest.php`; selected and restored runs passed. A visible Vue chat heading was changed; native TIA selected `CopilotAnswerTest.php`, and selected/restored runs passed. Both files retained exact original bytes after each probe.

The restored executable source digest was `6e7eebfc686e0f10dd0ee163605a635da97b9bcb7b2456df8f1ee3af5bea5513`, identical before/after the probes. That digest precedes this manual record and its acceptance-map hash binding. Final release source/commit/tree evidence must be checked separately. The unchanged replay passed but is cached evidence, not another full execution. PHP tests select the Vue-associated page checks; they do not execute Vue behavior. Actual browser proof is recorded separately in the rendered review.

Private log SHA256 identities:

- `cycle3-tia-record.log`: `9336abf3c7a17f69a56ef3e2a9af971db9c12a812aba028db29e57bea98b1608`
- `cycle3-tia-php-selected.log`: `cd4866a5055ad723f641e8aa2f5d357e8c7d5ded0ed59c73549b818c1bfdb977`
- `cycle3-tia-php-restored.log`: `c2076a2d51c0916540e636edb233d8c00ea456f39f74202a5907dc46d2094732`
- `cycle3-tia-vue-selected.log`: `11140c5257cacef4cfb53dbeeb72e786f567ccbf75bcddbc94e491590192dcad`
- `cycle3-tia-vue-restored.log`: `3bec92509e2e4a9a838861619ff97aa64eba4fda668a17510b3905310ce0e471`
- `cycle3-tia-cached-replay.log`: `6fcab048d8a4c0da4233643fd5d62879f822a6950b226b97feac26f6f3198646`
