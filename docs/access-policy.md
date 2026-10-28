# Access policy engine

`AccessPolicyEngine` is the single decision boundary for a MusicWave release. It returns an immutable `AccessDecision`; renderers and the future M7 download resolver consume that decision instead of reimplementing purchase or membership logic.

The engine is deliberately deny-by-default. An invalid release, unknown mode, missing integration, unauthenticated purchaser, or unconfigured membership provider results in denial.

| Access mode | Anonymous | Customer without entitlement | Customer with entitlement | Administrator |
|---|---:|---:|---:|---:|
| `public` | allow | allow | allow | allow |
| `purchase` | deny | deny | allow after WooCommerce confirms ownership | allow (explicit preview override) |
| `membership` | deny | deny | allow when the membership provider matches a configured level | allow (explicit preview override) |
| `purchase_or_membership` | deny | deny | allow when either check passes | allow (explicit preview override) |
| `restricted` or unknown | deny | deny | deny | allow (explicit preview override) |

Manual grants are checked for authenticated users before purchase or membership checks. They are supplied by an optional `ManualAccessProvider`; no manual-grant storage is created by Core in this milestone.

## Extension points

An integration plugin can provide adapters before MusicWave Core boots:

```php
add_filter( 'music_wave_membership_provider', static function () {
	return new My_Membership_Provider(); // Implements MembershipProvider.
} );

add_filter( 'music_wave_manual_access_provider', static function () {
	return new My_Manual_Access_Provider(); // Implements ManualAccessProvider.
} );
```

The `music_wave_access_decision` filter receives the computed decision, release ID, and `AccessSubject`. It is intended for a narrowly scoped provider integration; it must return an `AccessDecision` and must never turn an invalid release into an allowed decision.

## M4 rendering boundary

The server-rendered `music-wave/release-meta` and `music-wave/access-panel` blocks use the engine. On denial they render only a generic gate or purchase/membership CTA and never expose private product mappings or asset URLs. The same policy gates `the_content` for `mw_release` on frontend requests.

M7 must call `AccessPolicyEngine::decide()` before resolving an asset and must still enforce a signed, expiring delivery token. A positive decision alone is not a download URL.
