# Live availability timeout findings

Source: https://takamol.choice-pc-sv.xyz/availability

During live verification on 2026-08-21, the availability page returned HTTP 200 and rendered category options, but the city dropdown remained empty after selecting Tailoring (category_id=59). The browser AJAX request to `/availability?category_id=59&city=` did not complete within the browser's 30-second evaluation window. The page stayed on `Loading available centers…`.

Railway HTTP logs for the live deployment `19437bd0-2c78-42ce-bdfe-9b268d6afd2d` showed:

- `GET /availability` 200 in 10.792 seconds
- `GET /availability` 200 in 5.424 seconds
- `GET /availability` 499 in 12.322 seconds; client closed request before response
- `GET /availability` 499 in 25.060 seconds; client closed request before response

No 401/403 authentication error was observed. The live page includes the read-only availability UI and the backend SVP account navigation. The current controller resolves `SvpAvailabilityTokenResolver` first, but its exception fallback still returns a session token whenever one is filled because of `filled($sessionToken)`, even when `svp.allow_session_availability_fallback` is false. The new implementation must remove that implicit fallback for the separate backend-account-only availability path.

The current availability controller performs the city lookup and full availability lookup in the same `/availability` action. The dashboard service performs one test-center request followed by one session request per center, and the provider default timeout is 30 seconds with three connection retries. This can make the category-only city AJAX request wait on availability work instead of returning a fast cached city list.
