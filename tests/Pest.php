<?php

// Local, per-checkout TIA cache; shared baseline fetching is deliberately opt-in
// and is not enabled. Full verification explicitly passes --no-tia.
pest()->tia()->directory('.runtime/pest/tia')->defaultBranch('dev');
