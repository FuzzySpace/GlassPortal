<?php

/*
|--------------------------------------------------------------------------
| Pilot / product-test runtime targets (Phase 29 addendum)
|--------------------------------------------------------------------------
|
| The canonical pilot target is the GlassPortal runtime. A separate legacy
| billing runtime is still running on a different public port and is treated as
| LEGACY / REFERENCE until a later, explicitly-approved runtime consolidation
| phase. The pilot readiness checks warn if the operator appears to be testing
| the legacy billing URL instead of the canonical GlassPortal URL.
|
| This is configuration + guidance only. It does NOT change port mappings, host
| NAT, Traefik, Nginx, or redirect one runtime to the other.
|
*/

return [

    // Canonical GlassPortal pilot target (the app to test against).
    'canonical_url' => env('PILOT_CANONICAL_URL', 'http://40.160.61.180:18188'),

    // Legacy/reference standalone billing runtime — NOT the pilot target.
    'legacy_billing_url' => env('PILOT_LEGACY_BILLING_URL', 'http://40.160.61.180:18180'),

];
