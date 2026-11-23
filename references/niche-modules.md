# Niche modules: safety and accuracy rules per niche

The generic build is the same for every niche; what changes is what can hurt a reader or mislead them. For each new niche, research and write a short **niche rules** list (with sources), then turn the checkable parts into audit checks.

## How to research the rules
1. Find the authorities: government agencies, professional associations, standards bodies, universities.
2. List the dangerous or misleading things: unsafe ingredients/doses/temperatures, age limits, electrical/fire safety, legal limits, "guaranteed" results, medical/financial claims.
3. Decide what every relevant page must contain (e.g. a safety note, a temperature, a "consult a professional" line) and what must never appear.
4. Show the list to the user with sources before building.

## Turning rules into audit checks
The SEO plugin's Audit runs this filter for every page:

```php
add_filter( '<p>_seo_audit_checks', function ( $issues, $post, $main_html, $main_text, $schema_nodes ) {
	// $issues: array of array( 'bad'|'warn', 'message' ). Add yours and return it.
	return $issues;
}, 10, 5 );
```

Put niche checks in the engine plugin (or a small `includes/niche-checks.php` in the SEO plugin) so the generic template stays generic.

### Example A — a cooking / food niche
```php
add_filter( '<p>_seo_audit_checks', function ( $i, $post, $main, $text, $nodes ) {
	if ( '<p>_recipe' !== $post->post_type ) {
		return $i;
	}
	$recipe = null;
	foreach ( $nodes as $n ) {
		if ( in_array( 'Recipe', (array) $n['@type'], true ) ) {
			$recipe = $n;
		}
	}
	if ( ! $recipe ) {
		$i[] = array( 'bad', 'Recipe page has no Recipe schema' );
	} elseif ( empty( $recipe['image'] ) ) {
		$i[] = array( 'bad', 'Recipe schema has no image (set a featured image)' );
	}
	if ( preg_match( '/chicken|turkey/i', $text ) && ! preg_match( '/165\s?°?\s?F/i', $text ) ) {
		$i[] = array( 'bad', 'Poultry without a safe internal temperature (165°F / 74°C)' );
	}
	return $i;
}, 10, 5 );
```
Add the audience-specific unsafe-ingredient list from your research (what is toxic differs for people, children, pets…).

### Example B — baby / kids products
Flag missing age ranges, "safe for all ages", sleep-product claims that conflict with safe-sleep guidance, and recalled models (keep a list with dates and the recall source).

### Example C — tools / electrical / outdoor
Require a safety-gear note on pages about power tools; flag "no experience needed" on dangerous jobs; check that wattage/voltage claims match the store (US 120 V vs UK 230 V).

### Example D — supplements / health / fitness
Very strict: no disease claims ("cures", "treats", "prevents"), dosage only from authoritative sources, "talk to your doctor" note, no before/after promises. Consider whether the niche is worth the risk.

### Example E — finance / software
Date-stamp prices and plans, avoid "guaranteed returns", show the plan date, add a "not financial advice" disclaimer.

## Also add to `site_check.py` runs
Pass niche expert words with `--claim-words "dentist|pediatrician|electrician"` so unproven "<expert> recommended" claims are caught.
