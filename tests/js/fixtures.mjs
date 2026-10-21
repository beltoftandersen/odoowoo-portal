// Browser replies produced by the real PHP routes (tests/BrowserFixturesTest.php writes them).
// Regenerate: ODOOWOO_REGEN_FIXTURES=1 /root/plugin-tests/run.sh <plugin dir> --filter BrowserFixturesTest
import { readFileSync } from 'node:fs';

export function fixture( name ) {
	return JSON.parse( readFileSync( new URL( './fixtures/' + name + '.json', import.meta.url ), 'utf8' ) );
}

/** The fixture as a fetch response. */
export function fixtureRes( name, headers = {} ) {
	const { status, body } = fixture( name );
	const h = Object.fromEntries( Object.entries( headers ).map( ( [ k, v ] ) => [ k.toLowerCase(), v ] ) );

	return {
		ok: status >= 200 && status < 300,
		status,
		headers: { get: ( k ) => h[ String( k ).toLowerCase() ] ?? null },
		text: () => Promise.resolve( JSON.stringify( body ) ),
	};
}
