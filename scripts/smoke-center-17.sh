#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ "${1:-}" == "--live" ]]; then
    echo "Refusing live booking submission from the smoke script."
    echo "A live hold/reservation/payment requires an explicit confirmation in the interactive browser workflow."
    exit 2
fi

# Safe smoke coverage: provider normalization, exact-center session lookup,
# temporary-hold binding, and final BookingService reservation/credit routing.
php artisan test \
    tests/Unit/TakamolProviderLookupTest.php \
    tests/Feature/SvpSessionCenterVerificationTest.php \
    tests/Feature/SvpHoldControllerTest.php \
    tests/Feature/CoreServicesTest.php \
    --filter='(center|Center 17|temporary|hold|session)'

echo "Center 17 smoke test passed without submitting a live reservation or payment."
